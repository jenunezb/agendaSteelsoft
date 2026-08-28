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
        'account_type' => 'independent',
    ];
} else {
    $pdo = getConnection();
$query = 'SELECT
    activities.id,
    activities.title,
    activities.start_time,
    CURDATE() AS activity_date,
    activities.location,
    activities.reminder_minutes,
    users.whatsapp_number,
    users.name AS user_name,
    companies.account_type
 FROM activities
 INNER JOIN users ON users.id = activities.user_id
 INNER JOIN companies ON companies.id = activities.company_id
 WHERE activities.completed = 0
   AND activities.reminder_minutes IS NOT NULL
   AND users.whatsapp_notifications_enabled = 1
   AND users.whatsapp_number <> ""';

$params = [];

if ($activityId > 0) {
    $query .= ' AND activities.id = :activity_id';
    $params[':activity_id'] = $activityId;
} else {
    $query .= ' AND activities.activity_date <= CURDATE()
       AND (
            activities.recurrence_type = "daily"
            OR (activities.recurrence_type = "weekly" AND DAYOFWEEK(activities.activity_date) = DAYOFWEEK(CURDATE()))
            OR (activities.recurrence_type = "monthly" AND DAY(activities.activity_date) = DAY(CURDATE()))
            OR (activities.recurrence_type = "none" AND activities.activity_date = CURDATE())
       )
       AND (activities.reminder_sent_for_date IS NULL OR activities.reminder_sent_for_date <> CURDATE())
       AND TIMESTAMP(CURDATE(), activities.start_time) > NOW()
       AND DATE_SUB(TIMESTAMP(CURDATE(), activities.start_time), INTERVAL activities.reminder_minutes MINUTE) <= NOW()';
}

$query .= ' ORDER BY activities.activity_date, activities.start_time';

$statement = $pdo->prepare($query);
$statement->execute($params);

    $activities = $statement->fetchAll();

    if ($activityId <= 0) {
        $bookingStatement = $pdo->query(
            'SELECT activities.id, activities.title, activities.start_time, activities.activity_date,
                    activities.location, 60 AS reminder_minutes,
                    activities.booking_customer_phone AS whatsapp_number,
                    activities.booking_customer_name AS user_name,
                    activities.booking_confirmation_token,
                    1 AS is_booking_confirmation
             FROM activities
             WHERE activities.completed = 0
               AND activities.booking_status = "pending"
               AND activities.booking_customer_phone <> ""
               AND activities.booking_confirmation_token IS NOT NULL
               AND activities.booking_confirmation_sent_at IS NULL
               AND TIMESTAMP(activities.activity_date, activities.start_time) > NOW()
               AND DATE_SUB(TIMESTAMP(activities.activity_date, activities.start_time), INTERVAL 60 MINUTE) <= NOW()
             ORDER BY activities.activity_date, activities.start_time'
        );
        $activities = array_merge($activities, $bookingStatement->fetchAll());

        $personalStatement = $pdo->query(
            'SELECT personal_reminders.id, personal_reminders.title,
                    TIME(personal_reminders.remind_at) AS start_time,
                    DATE(personal_reminders.remind_at) AS activity_date,
                    "" AS location, 0 AS reminder_minutes,
                    users.whatsapp_number, users.name AS user_name,
                    "independent" AS account_type, 1 AS is_personal_reminder,
                    personal_reminders.due_date
             FROM personal_reminders
             INNER JOIN users ON users.id = personal_reminders.user_id
             WHERE personal_reminders.completed = 0
               AND personal_reminders.whatsapp_enabled = 1
               AND personal_reminders.remind_at IS NOT NULL
               AND personal_reminders.remind_at <= NOW()
               AND personal_reminders.reminder_sent_at IS NULL
               AND users.whatsapp_notifications_enabled = 1
               AND users.whatsapp_number <> ""
             ORDER BY personal_reminders.remind_at'
        );
        $activities = array_merge($activities, $personalStatement->fetchAll());
    }
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
            if (!empty($activity['is_personal_reminder'])) {
                $updateStatement = $pdo->prepare('UPDATE personal_reminders SET reminder_sent_at = NOW() WHERE id = :id');
                $updateStatement->execute([':id' => (int) $activity['id']]);
            } elseif (!empty($activity['is_booking_confirmation'])) {
                $updateStatement = $pdo->prepare('UPDATE activities SET booking_confirmation_sent_at = NOW() WHERE id = :id');
                $updateStatement->execute([':id' => (int) $activity['id']]);
            } else {
                $updateStatement = $pdo->prepare(
                    'UPDATE activities
                     SET reminder_sent_at = NOW(), reminder_sent_for_date = :occurrence_date
                     WHERE id = :id'
                );
                $updateStatement->execute([
                    ':occurrence_date' => (string) $activity['activity_date'],
                    ':id' => (int) $activity['id'],
                ]);
            }
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
    if (
        !empty($activity['is_booking_confirmation'])
        && trim((string) ($config['twilio_booking_confirmation_content_sid'] ?? '')) !== ''
    ) {
        return sendTwilioWhatsappReminder($config, $activity);
    }

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
    $messagingServiceSid = trim((string) ($config['twilio_messaging_service_sid'] ?? ''));
    $contentSid = resolveTwilioReminderContentSid($config, $activity);

    if (($from === '' && $messagingServiceSid === '') || $to === '') {
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
    $requestPayload = ['To' => $to];

    if ($messagingServiceSid !== '') {
        $requestPayload['MessagingServiceSid'] = $messagingServiceSid;
    } else {
        $requestPayload['From'] = $from;
    }

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
    $usesTwilioConfirmationTemplate = !empty($activity['is_booking_confirmation'])
        && trim((string) ($config['twilio_booking_confirmation_content_sid'] ?? '')) !== '';

    if (($config['provider'] ?? 'textmebot') === 'textmebot' && !$usesTwilioConfirmationTemplate) {
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
        'To' => normalizeTwilioWhatsappAddress((string) $activity['whatsapp_number']),
    ];
    $messagingServiceSid = trim((string) ($config['twilio_messaging_service_sid'] ?? ''));

    if ($messagingServiceSid !== '') {
        $payload['MessagingServiceSid'] = $messagingServiceSid;
    } else {
        $payload['From'] = normalizeTwilioWhatsappAddress($sender);
    }

    $contentSid = resolveTwilioReminderContentSid($config, $activity);

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

    if (!empty($activity['is_booking_confirmation'])) {
        $baseUrl = resolveConfirmationBaseUrl();
        $token = rawurlencode((string) ($activity['booking_confirmation_token'] ?? ''));
        return sprintf(
            "Hola %s, tu cita es hoy a las %s y falta 1 hora. Responde aquí:\n✅ Confirmar: %s/api/booking-response.php?token=%s&decision=confirm\n❌ Rechazar: %s/api/booking-response.php?token=%s&decision=reject",
            (string) $activity['user_name'],
            $date->format('H:i'),
            $baseUrl,
            $token,
            $baseUrl,
            $token
        );
    }

    if (!empty($activity['is_personal_reminder'])) {
        $dueText = !empty($activity['due_date'])
            ? ' Fecha limite: ' . (new DateTimeImmutable((string) $activity['due_date']))->format('d/m/Y') . '.'
            : '';
        return sprintf('Hola %s, tienes pendiente: "%s".%s Entra a Steelsoft para marcarlo como completado.',
            (string) $activity['user_name'], (string) $activity['title'], $dueText);
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

function resolveConfirmationBaseUrl(): string
{
    $config = getWhatsappConfig();
    $baseUrl = rtrim((string) ($config['app_base_url'] ?? ''), '/');
    if ($baseUrl === '') {
        throw new RuntimeException('Configura APP_BASE_URL para enviar enlaces de confirmación.');
    }
    return $baseUrl;
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

    if (!empty($activity['is_booking_confirmation'])) {
        $token = (string) ($activity['booking_confirmation_token'] ?? '');
        $variables = [
            '1' => (string) $activity['user_name'],
            '2' => $date->format('H:i'),
            '3' => $token,
            '4' => $token,
        ];
    } elseif (($activity['account_type'] ?? '') === 'independent') {
        $variables = [
            '1' => (string) $activity['user_name'],
            '2' => (string) $activity['title'],
            '3' => $date->format('d/m/Y'),
            '4' => $date->format('H:i'),
        ];
    } else {
        $variables = [
            '1' => $date->format('d/m'),
            '2' => $date->format('H:i'),
        ];
    }

    $json = json_encode($variables, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException('No fue posible serializar las variables de contenido de Twilio.');
    }

    return $json;
}

function resolveTwilioReminderContentSid(array $config, array $activity): string
{
    if (!empty($activity['is_booking_confirmation'])) {
        return trim((string) ($config['twilio_booking_confirmation_content_sid'] ?? ''));
    }

    if (($activity['account_type'] ?? '') === 'independent') {
        return trim((string) ($config['twilio_template_agendamiento_sid'] ?? ''));
    }

    return trim((string) ($config['twilio_content_sid'] ?? ''));
}
