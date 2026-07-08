<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = getWhatsappConfig();
$isCli = PHP_SAPI === 'cli';
$activityId = isset($_GET['activity_id']) ? (int) $_GET['activity_id'] : 0;
$forceSend = !empty($_GET['force']);
$dryRun = !empty($_GET['dry_run']);
$testNumber = normalizeWhatsappNumber((string) ($_GET['test_number'] ?? ''));

if (!$isCli) {
    $providedSecret = (string) ($_GET['key'] ?? '');
    $expectedSecret = (string) ($config['cron_secret'] ?? '');

    if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
        jsonResponse(['message' => 'No autorizado.'], 401);
    }
}

if (!isWhatsappReminderProviderConfigured($config)) {
    jsonResponse([
        'message' => 'Falta configurar el proveedor de WhatsApp.',
        'missing' => buildWhatsappReminderMissingConfig($config),
    ], 422);
}

$activities = [];
$pdo = null;

if ($testNumber !== '') {
    $activities[] = [
        'id' => 0,
        'title' => 'Prueba de WhatsApp',
        'start_time' => '09:00:00',
        'activity_date' => date('Y-m-d'),
        'location' => '',
        'reminder_minutes' => 5,
        'whatsapp_number' => $testNumber,
        'user_name' => 'Steelsoft',
    ];
} else {
    $pdo = getConnection();
$query = 'SELECT
    activities.id,
    activities.title,
    activities.start_time,
    activities.activity_date,
    activities.location,
    activities.reminder_minutes,
    users.whatsapp_number,
    users.name AS user_name
 FROM activities
 INNER JOIN users ON users.id = activities.user_id
 WHERE activities.completed = 0
   AND activities.reminder_minutes IS NOT NULL
   AND users.whatsapp_notifications_enabled = 1
   AND users.whatsapp_number <> ""';

$params = [];

if ($activityId > 0) {
    $query .= ' AND activities.id = :activity_id';
    $params[':activity_id'] = $activityId;
} else {
    $query .= ' AND activities.reminder_sent_at IS NULL
       AND TIMESTAMP(activities.activity_date, activities.start_time) > NOW()
       AND DATE_SUB(TIMESTAMP(activities.activity_date, activities.start_time), INTERVAL activities.reminder_minutes MINUTE) <= NOW()';
}

$query .= ' ORDER BY activities.activity_date, activities.start_time';

$statement = $pdo->prepare($query);
$statement->execute($params);

    $activities = $statement->fetchAll();
}
$sentCount = 0;
$errors = [];
$preview = [];

foreach ($activities as $activity) {
    try {
        if ($dryRun) {
            $preview[] = buildWhatsappPayloadPreview($config, $activity);
            continue;
        }

        $response = sendWhatsappReminder($config, $activity);
    } catch (Throwable $exception) {
        $errors[] = [
            'activityId' => (int) $activity['id'],
            'title' => $activity['title'],
            'message' => $exception->getMessage(),
        ];
        continue;
    }

    if ($response['success']) {
        if ($pdo instanceof PDO && $activityId <= 0 && !$forceSend) {
            $updateStatement = $pdo->prepare(
                'UPDATE activities
                 SET reminder_sent_at = NOW()
                 WHERE id = :id'
            );
            $updateStatement->execute([':id' => (int) $activity['id']]);
        }
        $sentCount++;
        continue;
    }

    $errors[] = [
        'activityId' => (int) $activity['id'],
        'title' => $activity['title'],
        'message' => $response['message'],
    ];
}

jsonResponse([
    'success' => true,
    'mode' => $dryRun ? 'dry-run' : ($testNumber !== '' ? 'direct-test' : ($activityId > 0 ? 'manual-test' : 'cron')),
    'processed' => count($activities),
    'sent' => $sentCount,
    'errors' => $errors,
    'preview' => $preview,
]);

function sendWhatsappReminder(array $config, array $activity): array
{
    if (($config['provider'] ?? 'textmebot') === 'textmebot') {
        return sendTextmebotWhatsappReminder($config, $activity);
    }

    return sendTwilioWhatsappReminder($config, $activity);
}

function isWhatsappReminderProviderConfigured(array $config): bool
{
    if (($config['provider'] ?? 'textmebot') === 'textmebot') {
        return resolveTextmebotReminderApiKey($config) !== '';
    }

    return
        $config['twilio_account_sid'] !== ''
        && $config['twilio_auth_token'] !== ''
        && $config['twilio_whatsapp_from'] !== '';
}

function buildWhatsappReminderMissingConfig(array $config): array
{
    if (($config['provider'] ?? 'textmebot') === 'textmebot') {
        return [
            'provider' => 'textmebot',
            'textmebot_api_key' => resolveTextmebotReminderApiKey($config) === '',
        ];
    }

    return [
        'provider' => 'twilio',
        'twilio_account_sid' => $config['twilio_account_sid'] === '',
        'twilio_auth_token' => $config['twilio_auth_token'] === '',
        'twilio_whatsapp_from' => $config['twilio_whatsapp_from'] === '',
    ];
}

function sendTextmebotWhatsappReminder(array $config, array $activity): array
{
    $phone = normalizeWhatsappNumber((string) $activity['whatsapp_number']);
    $apiKey = resolveTextmebotReminderApiKey($config);

    if ($phone === '' || $apiKey === '') {
        return [
            'success' => false,
            'message' => 'Falta el numero destino o el apikey de TextMeBot.',
            'response' => '',
        ];
    }

    $endpoint = 'https://api.textmebot.com/send.php?' . http_build_query([
        'recipient' => $phone,
        'text' => buildTwilioWhatsappBody($activity),
        'apikey' => $apiKey,
    ]);

    [$rawResponse, $statusCode, $transportError] = sendGetRequest($endpoint);

    if ($transportError !== '') {
        return [
            'success' => false,
            'message' => $transportError,
            'response' => $rawResponse,
        ];
    }

    if ($statusCode >= 400) {
        return [
            'success' => false,
            'message' => sprintf('TextMeBot devolvio HTTP %d: %s', $statusCode, $rawResponse),
            'response' => $rawResponse,
        ];
    }

    return [
        'success' => true,
        'message' => 'ok',
        'response' => $rawResponse,
    ];
}

function sendTwilioWhatsappReminder(array $config, array $activity): array
{
    $accountSid = (string) $config['twilio_account_sid'];
    $authToken = (string) $config['twilio_auth_token'];
    $sender = trim((string) ($config['twilio_reminder_whatsapp_from'] ?? ''));

    if ($sender === '') {
        $sender = (string) ($config['twilio_whatsapp_from'] ?? '');
    }

    $from = normalizeTwilioWhatsappAddress($sender);
    $to = normalizeTwilioWhatsappAddress((string) $activity['whatsapp_number']);
    $contentSid = trim((string) ($config['twilio_content_sid'] ?? ''));

    if ($from === '' || $to === '') {
        return [
            'success' => false,
            'message' => 'Los numeros de origen o destino de Twilio no son validos.',
            'response' => '',
        ];
    }

    $endpoint = sprintf(
        'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
        rawurlencode($accountSid)
    );
    $requestPayload = [
        'From' => $from,
        'To' => $to,
    ];

    if ($contentSid !== '') {
        $requestPayload['ContentSid'] = $contentSid;
        $requestPayload['ContentVariables'] = buildTwilioContentVariablesJson($activity);
    } else {
        $requestPayload['Body'] = buildTwilioWhatsappBody($activity);
    }

    [$rawResponse, $statusCode, $transportError] = sendFormRequest(
        $endpoint,
        [
            'Authorization: Basic ' . base64_encode($accountSid . ':' . $authToken),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        $requestPayload
    );

    if ($transportError !== '') {
        return [
            'success' => false,
            'message' => $transportError,
            'response' => $rawResponse,
        ];
    }

    if ($statusCode >= 400) {
        return [
            'success' => false,
            'message' => sprintf('Twilio devolvio HTTP %d: %s', $statusCode, $rawResponse),
            'response' => $rawResponse,
        ];
    }

    return [
        'success' => true,
        'message' => 'ok',
        'response' => $rawResponse,
    ];
}

function sendFormRequest(string $endpoint, array $headers, array $payload): array
{
    $formPayload = http_build_query($payload);

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $formPayload,
            CURLOPT_TIMEOUT => 30,
        ]);

        $rawResponse = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false || $curlError !== '') {
            return [
                is_string($rawResponse) ? $rawResponse : '',
                $statusCode,
                $curlError !== '' ? $curlError : 'No hubo respuesta de Twilio.',
            ];
        }

        return [$rawResponse, $statusCode, ''];
    }

    $httpHeaders = implode("\r\n", $headers);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $httpHeaders . "\r\n",
            'content' => $formPayload,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $rawResponse = @file_get_contents($endpoint, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $statusCode = extractHttpStatusCode($responseHeaders);

    if ($rawResponse === false) {
        $error = error_get_last();

        return [
            '',
            $statusCode,
            (string) ($error['message'] ?? 'No hubo respuesta de Twilio.'),
        ];
    }

    return [$rawResponse, $statusCode, ''];
}

function sendGetRequest(string $endpoint): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $rawResponse = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false || $curlError !== '') {
            return [
                is_string($rawResponse) ? $rawResponse : '',
                $statusCode,
                $curlError !== '' ? $curlError : 'No hubo respuesta de TextMeBot.',
            ];
        }

        return [$rawResponse, $statusCode, ''];
    }

    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        return sendGetRequestWithPowershell($endpoint, 'TextMeBot');
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $rawResponse = @file_get_contents($endpoint, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $statusCode = extractHttpStatusCode($responseHeaders);

    if ($rawResponse === false) {
        $error = error_get_last();

        return [
            '',
            $statusCode,
            (string) ($error['message'] ?? 'No hubo respuesta de TextMeBot.'),
        ];
    }

    return [$rawResponse, $statusCode, ''];
}

function sendGetRequestWithPowershell(string $endpoint, string $serviceLabel): array
{
    $script = "\$ProgressPreference = 'SilentlyContinue'; "
        . "\$response = Invoke-WebRequest -Uri '" . $endpoint . "' -Method GET -UseBasicParsing; "
        . "[Console]::OutputEncoding = [System.Text.Encoding]::UTF8; "
        . "Write-Output \$response.StatusCode; "
        . "Write-Output \$response.Content;";
    $command = 'powershell -NoProfile -Command ' . escapeshellarg($script);

    $output = [];
    $exitCode = 0;
    @exec($command, $output, $exitCode);

    if ($exitCode !== 0 || $output === []) {
        return ['', 0, 'No hubo respuesta de ' . $serviceLabel . '.'];
    }

    $statusCode = (int) array_shift($output);
    $rawResponse = implode("\n", $output);

    return [$rawResponse, $statusCode, ''];
}

function extractHttpStatusCode(array $responseHeaders): int
{
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function buildWhatsappPayloadPreview(array $config, array $activity): array
{
    if (($config['provider'] ?? 'textmebot') === 'textmebot') {
        $phone = normalizeWhatsappNumber((string) $activity['whatsapp_number']);

        return [
            'provider' => 'textmebot',
            'endpoint' => 'https://api.textmebot.com/send.php',
            'query' => [
                'recipient' => $phone,
                'text' => buildTwilioWhatsappBody($activity),
                'apikey' => resolveTextmebotReminderApiKey($config),
            ],
        ];
    }

    $sender = trim((string) ($config['twilio_reminder_whatsapp_from'] ?? ''));

    if ($sender === '') {
        $sender = (string) ($config['twilio_whatsapp_from'] ?? '');
    }

    $payload = [
        'From' => normalizeTwilioWhatsappAddress($sender),
        'To' => normalizeTwilioWhatsappAddress((string) $activity['whatsapp_number']),
    ];

    $contentSid = trim((string) ($config['twilio_content_sid'] ?? ''));

    if ($contentSid !== '') {
        $payload['ContentSid'] = $contentSid;
        $payload['ContentVariables'] = buildTwilioContentVariablesJson($activity);

        return $payload;
    }

    $payload['Body'] = buildTwilioWhatsappBody($activity);

    return $payload;
}

function resolveTextmebotReminderApiKey(array $config): string
{
    $apiKey = trim((string) ($config['textmebot_reminder_api_key'] ?? ''));

    if ($apiKey !== '') {
        return $apiKey;
    }

    return trim((string) ($config['textmebot_api_key'] ?? ''));
}

function buildRemainingText(int $reminderMinutes): string
{
    return match ($reminderMinutes) {
        60 => '1 hora',
        30 => '30 minutos',
        15 => '15 minutos',
        5 => '5 minutos',
        default => $reminderMinutes . ' minutos',
    };
}

function buildTwilioWhatsappBody(array $activity): string
{
    $date = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        sprintf('%s %s', $activity['activity_date'], $activity['start_time'])
    );

    if (!$date instanceof DateTimeImmutable) {
        throw new RuntimeException('No fue posible interpretar la fecha del evento.');
    }

    return sprintf(
        'Hola %s, te recordamos "%s" el %s a las %s. Faltan %s.',
        (string) $activity['user_name'],
        (string) $activity['title'],
        $date->format('d/m/Y'),
        $date->format('H:i'),
        buildRemainingText((int) $activity['reminder_minutes'])
    );
}

function normalizeTwilioWhatsappAddress(string $value): string
{
    $normalizedValue = normalizeWhatsappNumber($value);

    if ($normalizedValue === '') {
        return '';
    }

    return 'whatsapp:+' . $normalizedValue;
}

function buildTwilioContentVariablesJson(array $activity): string
{
    $date = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        sprintf('%s %s', $activity['activity_date'], $activity['start_time'])
    );

    if (!$date instanceof DateTimeImmutable) {
        throw new RuntimeException('No fue posible interpretar la fecha del evento.');
    }

    $variables = [
        '1' => $date->format('d/m'),
        '2' => $date->format('H:i'),
    ];

    $json = json_encode($variables, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException('No fue posible serializar las variables de contenido de Twilio.');
    }

    return $json;
}
