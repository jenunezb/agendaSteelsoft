<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = getWhatsappConfig();
$isCli = PHP_SAPI === 'cli';
$dryRun = !empty($_GET['dry_run']);
$recipientFilter = strtolower(trim((string) ($_GET['recipient'] ?? 'all')));
$genericTestNumber = normalizeWhatsappNumber((string) ($_GET['test_number'] ?? ''));
$adminNumber = normalizeWhatsappNumber((string) ($_GET['admin_number'] ?? $genericTestNumber));
$professionalNumber = normalizeWhatsappNumber((string) ($_GET['professional_number'] ?? $genericTestNumber));
$customerNumber = normalizeWhatsappNumber((string) ($_GET['customer_number'] ?? $genericTestNumber));
$genericContentSidOverride = trim((string) ($_GET['content_sid'] ?? ''));
$adminContentSidOverride = trim((string) ($_GET['admin_content_sid'] ?? $genericContentSidOverride));
$professionalContentSidOverride = trim((string) ($_GET['professional_content_sid'] ?? $genericContentSidOverride));
$customerContentSidOverride = trim((string) ($_GET['customer_content_sid'] ?? $genericContentSidOverride));

if (!$isCli) {
    $providedSecret = (string) ($_GET['key'] ?? '');
    $expectedSecret = (string) ($config['cron_secret'] ?? '');

    if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
        jsonResponse(['message' => 'No autorizado.'], 401);
    }
}

if (!isBookingTestProviderConfigured($config)) {
    jsonResponse([
        'message' => 'Falta configurar el proveedor de WhatsApp para las pruebas de reservas.',
        'missing' => buildBookingTestMissingConfig($config),
    ], 422);
}

$allowedRecipients = ['admin', 'professional', 'customer', 'all'];

if (!in_array($recipientFilter, $allowedRecipients, true)) {
    jsonResponse(['message' => 'El parametro recipient debe ser admin, professional, customer o all.'], 422);
}

$selectedRecipients = $recipientFilter === 'all'
    ? ['admin', 'professional', 'customer']
    : [$recipientFilter];

$booking = [
    'serviceName' => trim((string) ($_GET['service_name'] ?? 'Cita de prueba')),
    'customerName' => trim((string) ($_GET['customer_name'] ?? 'Cliente prueba')),
    'customerPhone' => $customerNumber,
    'date' => trim((string) ($_GET['date'] ?? date('Y-m-d'))),
    'startTime' => trim((string) ($_GET['start_time'] ?? '09:00:00')),
    'notes' => trim((string) ($_GET['notes'] ?? 'Reserva generada desde la prueba de TextMeBot.')),
];

$ownerUser = [
    'name' => trim((string) ($_GET['admin_name'] ?? 'Administracion')),
];

$professional = [
    'name' => trim((string) ($_GET['professional_name'] ?? 'Profesional prueba')),
];

$results = [];
$errors = [];

foreach ($selectedRecipients as $recipient) {
    $targetNumber = match ($recipient) {
        'admin' => $adminNumber,
        'professional' => $professionalNumber,
        'customer' => $customerNumber,
        default => '',
    };

    if ($targetNumber === '') {
        $errors[] = [
            'recipient' => $recipient,
            'message' => 'Debes indicar un numero de prueba para este destinatario.',
        ];
        continue;
    }

    try {
        if ($dryRun) {
            $results[] = buildBookingTestPreview(
                $config,
                $recipient,
                $targetNumber,
                resolveBookingTestContentSidOverride(
                    $recipient,
                    $adminContentSidOverride,
                    $professionalContentSidOverride,
                    $customerContentSidOverride
                ),
                $ownerUser,
                $professional,
                $booking
            );
            continue;
        }

        $results[] = sendBookingTestNotification(
            $config,
            $recipient,
            $targetNumber,
            resolveBookingTestContentSidOverride(
                $recipient,
                $adminContentSidOverride,
                $professionalContentSidOverride,
                $customerContentSidOverride
            ),
            $ownerUser,
            $professional,
            $booking
        );
    } catch (Throwable $exception) {
        $errors[] = [
            'recipient' => $recipient,
            'message' => $exception->getMessage(),
        ];
    }
}

jsonResponse([
    'success' => count($errors) === 0,
    'mode' => $dryRun ? 'dry-run' : 'send',
    'processed' => count($selectedRecipients),
    'results' => $results,
    'errors' => $errors,
]);

function buildBookingTestPreview(
    array $config,
    string $recipient,
    string $targetNumber,
    string $contentSidOverride,
    array $ownerUser,
    array $professional,
    array $booking
): array {
    [$message, $contentVariables] = buildBookingRecipientContent($recipient, $ownerUser, $professional, $booking);

    if (($config['provider'] ?? 'textmebot') === 'textmebot') {
        $normalizedNumber = normalizeWhatsappNumber($targetNumber);

        return [
            'provider' => 'textmebot',
            'recipient' => $recipient,
            'endpoint' => 'https://api.textmebot.com/send.php',
            'query' => [
                'recipient' => $normalizedNumber,
                'text' => $message,
                'apikey' => resolveTextmebotBookingApiKey($config),
            ],
        ];
    }

    $contentSid = $contentSidOverride !== '' ? $contentSidOverride : getBookingRecipientContentSid($config, $recipient);

    $payload = [
        'recipient' => $recipient,
        'From' => normalizeTwilioBookingTestAddress(resolveBookingTestSender($config)),
        'To' => normalizeTwilioBookingTestAddress($targetNumber),
    ];

    if ($contentSid !== '') {
        $payload['ContentSid'] = $contentSid;
        $payload['ContentVariables'] = encodeBookingTestContentVariables($contentVariables);
    } else {
        $payload['Body'] = $message;
        $payload['warning'] = 'No hay ContentSid configurado para este destinatario; Twilio intentara enviar Body libre.';
    }

    return $payload;
}

function sendBookingTestNotification(
    array $config,
    string $recipient,
    string $targetNumber,
    string $contentSidOverride,
    array $ownerUser,
    array $professional,
    array $booking
): array {
    [$message, $contentVariables] = buildBookingRecipientContent($recipient, $ownerUser, $professional, $booking);

    if (($config['provider'] ?? 'textmebot') === 'textmebot') {
        return sendTextmebotBookingTestNotification($config, $recipient, $targetNumber, $message);
    }

    $accountSid = (string) ($config['twilio_account_sid'] ?? '');
    $authToken = (string) ($config['twilio_auth_token'] ?? '');
    $from = normalizeTwilioBookingTestAddress(resolveBookingTestSender($config));
    $to = normalizeTwilioBookingTestAddress($targetNumber);
    $contentSid = $contentSidOverride !== '' ? $contentSidOverride : getBookingRecipientContentSid($config, $recipient);

    if ($from === '' || $to === '') {
        return [
            'recipient' => $recipient,
            'success' => false,
            'message' => 'Numero de origen o destino invalido.',
        ];
    }

    $endpoint = sprintf(
        'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
        rawurlencode($accountSid)
    );
    $payload = [
        'From' => $from,
        'To' => $to,
    ];

    if ($contentSid !== '') {
        $payload['ContentSid'] = $contentSid;
        $payload['ContentVariables'] = encodeBookingTestContentVariables($contentVariables);
    } else {
        $payload['Body'] = $message;
    }

    [$rawResponse, $statusCode, $transportError] = sendBookingTestFormRequest(
        $endpoint,
        [
            'Authorization: Basic ' . base64_encode($accountSid . ':' . $authToken),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        $payload
    );

    if ($transportError !== '') {
        return [
            'recipient' => $recipient,
            'success' => false,
            'message' => $transportError,
        ];
    }

    if ($statusCode >= 400) {
        return [
            'recipient' => $recipient,
            'success' => false,
            'message' => sprintf('Twilio devolvio HTTP %d: %s', $statusCode, $rawResponse),
        ];
    }

    $decodedResponse = json_decode($rawResponse, true);

    return [
        'recipient' => $recipient,
        'success' => true,
        'sid' => is_array($decodedResponse) ? (string) ($decodedResponse['sid'] ?? '') : '',
        'status' => is_array($decodedResponse) ? (string) ($decodedResponse['status'] ?? '') : '',
        'usedContentSid' => $contentSid !== '',
    ];
}

function isBookingTestProviderConfigured(array $config): bool
{
    if (($config['provider'] ?? 'textmebot') === 'textmebot') {
        return resolveTextmebotBookingApiKey($config) !== '';
    }

    return
        trim((string) ($config['twilio_account_sid'] ?? '')) !== ''
        && trim((string) ($config['twilio_auth_token'] ?? '')) !== ''
        && trim((string) resolveBookingTestSender($config)) !== '';
}

function buildBookingTestMissingConfig(array $config): array
{
    if (($config['provider'] ?? 'textmebot') === 'textmebot') {
        return [
            'provider' => 'textmebot',
            'textmebot_api_key' => resolveTextmebotBookingApiKey($config) === '',
        ];
    }

    return [
        'provider' => 'twilio',
        'twilio_account_sid' => trim((string) ($config['twilio_account_sid'] ?? '')) === '',
        'twilio_auth_token' => trim((string) ($config['twilio_auth_token'] ?? '')) === '',
        'twilio_whatsapp_from' => trim((string) resolveBookingTestSender($config)) === '',
    ];
}

function sendTextmebotBookingTestNotification(
    array $config,
    string $recipient,
    string $targetNumber,
    string $message
): array {
    $normalizedPhone = normalizeWhatsappNumber($targetNumber);
    $apiKey = resolveTextmebotBookingApiKey($config);

    if ($normalizedPhone === '' || $apiKey === '') {
        return [
            'recipient' => $recipient,
            'success' => false,
            'message' => 'Falta el numero destino o el apikey de TextMeBot.',
        ];
    }

    $endpoint = 'https://api.textmebot.com/send.php?' . http_build_query([
        'recipient' => $normalizedPhone,
        'text' => $message,
        'apikey' => $apiKey,
    ]);

    [$rawResponse, $statusCode, $transportError] = sendBookingTestGetRequest($endpoint);

    if ($transportError !== '') {
        return [
            'recipient' => $recipient,
            'success' => false,
            'message' => $transportError,
        ];
    }

    if ($statusCode >= 400) {
        return [
            'recipient' => $recipient,
            'success' => false,
            'message' => sprintf('TextMeBot devolvio HTTP %d: %s', $statusCode, $rawResponse),
        ];
    }

    return [
        'recipient' => $recipient,
        'success' => true,
        'status' => 'sent',
        'usedContentSid' => false,
    ];
}

function buildBookingRecipientContent(
    string $recipient,
    array $ownerUser,
    array $professional,
    array $booking
): array {
    $professionalName = (string) ($professional['name'] ?? '');
    $dateLabel = formatBookingTestDateLabel((string) ($booking['date'] ?? ''));
    $timeLabel = substr((string) ($booking['startTime'] ?? ''), 0, 5);
    $serviceName = (string) ($booking['serviceName'] ?? 'Servicio');
    $customerName = (string) ($booking['customerName'] ?? 'Cliente');
    $customerPhone = normalizeWhatsappNumber((string) ($booking['customerPhone'] ?? ''));
    $notes = trim((string) ($booking['notes'] ?? ''));

    return match ($recipient) {
        'admin' => [
            buildAdminBookingTestMessage(
                (string) ($ownerUser['name'] ?? 'Administracion'),
                $professionalName,
                $serviceName,
                $customerName,
                $customerPhone,
                $dateLabel,
                $timeLabel,
                $notes
            ),
            [
                '1' => $serviceName,
                '2' => $dateLabel,
                '3' => $timeLabel,
                '4' => $customerName,
                '5' => $professionalName !== '' ? $professionalName : 'Profesional asignado',
                '6' => $customerPhone !== '' ? '+' . $customerPhone : 'Sin numero',
            ],
        ],
        'professional' => [
            buildProfessionalBookingTestMessage(
                $professionalName !== '' ? $professionalName : 'Profesional',
                $serviceName,
                $customerName,
                $customerPhone,
                $dateLabel,
                $timeLabel,
                $notes
            ),
            [
                '1' => $professionalName !== '' ? $professionalName : 'Profesional',
                '2' => $serviceName,
                '3' => $dateLabel,
                '4' => $timeLabel,
                '5' => $customerName,
                '6' => $customerPhone !== '' ? '+' . $customerPhone : 'Sin numero',
            ],
        ],
        'customer' => [
            buildCustomerBookingTestMessage(
                $customerName,
                $professionalName,
                $serviceName,
                $dateLabel,
                $timeLabel
            ),
            [
                '1' => $serviceName,
                '2' => $dateLabel,
                '3' => $timeLabel,
                '4' => $professionalName !== '' ? $professionalName : 'nuestro equipo',
            ],
        ],
        default => throw new RuntimeException('Destinatario no soportado para la prueba.'),
    };
}

function getBookingRecipientContentSid(array $config, string $recipient): string
{
    return match ($recipient) {
        'admin' => trim((string) ($config['twilio_booking_admin_content_sid'] ?? '')),
        'professional' => trim((string) ($config['twilio_booking_professional_content_sid'] ?? '')),
        'customer' => trim((string) ($config['twilio_booking_customer_content_sid'] ?? '')),
        default => '',
    };
}

function resolveBookingTestContentSidOverride(
    string $recipient,
    string $adminContentSidOverride,
    string $professionalContentSidOverride,
    string $customerContentSidOverride
): string {
    return match ($recipient) {
        'admin' => $adminContentSidOverride,
        'professional' => $professionalContentSidOverride,
        'customer' => $customerContentSidOverride,
        default => '',
    };
}

function encodeBookingTestContentVariables(array $variables): string
{
    $json = json_encode($variables, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException('No fue posible serializar las variables de Twilio.');
    }

    return $json;
}

function sendBookingTestFormRequest(string $endpoint, array $headers, array $payload): array
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

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $formPayload,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $rawResponse = @file_get_contents($endpoint, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $statusCode = extractBookingTestHttpStatusCode($responseHeaders);

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

function sendBookingTestGetRequest(string $endpoint): array
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
        return sendBookingTestGetRequestWithPowershell($endpoint, 'TextMeBot');
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
    $statusCode = extractBookingTestHttpStatusCode($responseHeaders);

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

function sendBookingTestGetRequestWithPowershell(string $endpoint, string $serviceLabel): array
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

function extractBookingTestHttpStatusCode(array $responseHeaders): int
{
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function normalizeTwilioBookingTestAddress(string $value): string
{
    $normalizedValue = normalizeWhatsappNumber($value);

    if ($normalizedValue === '') {
        return '';
    }

    return 'whatsapp:+' . $normalizedValue;
}

function formatBookingTestDateLabel(string $isoDate): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $isoDate);

    if (!$date instanceof DateTimeImmutable) {
        return $isoDate;
    }

    return $date->format('d/m/Y');
}

function buildAdminBookingTestMessage(
    string $adminName,
    string $professionalName,
    string $serviceName,
    string $customerName,
    string $customerPhone,
    string $dateLabel,
    string $timeLabel,
    string $notes
): string {
    $message = sprintf(
        'Hola %s, se agendo una nueva cita para %s con %s el %s a las %s. Cliente: %s.',
        $adminName,
        $serviceName,
        $professionalName !== '' ? $professionalName : 'el profesional asignado',
        $dateLabel,
        $timeLabel,
        $customerName
    );

    if ($customerPhone !== '') {
        $message .= sprintf(' WhatsApp cliente: +%s.', $customerPhone);
    }

    if ($notes !== '') {
        $message .= sprintf(' Notas: %s.', $notes);
    }

    return $message;
}

function buildProfessionalBookingTestMessage(
    string $professionalName,
    string $serviceName,
    string $customerName,
    string $customerPhone,
    string $dateLabel,
    string $timeLabel,
    string $notes
): string {
    $message = sprintf(
        'Hola %s, tienes una nueva cita de %s el %s a las %s. Cliente: %s.',
        $professionalName,
        $serviceName,
        $dateLabel,
        $timeLabel,
        $customerName
    );

    if ($customerPhone !== '') {
        $message .= sprintf(' WhatsApp cliente: +%s.', $customerPhone);
    }

    if ($notes !== '') {
        $message .= sprintf(' Notas: %s.', $notes);
    }

    return $message;
}

function buildCustomerBookingTestMessage(
    string $customerName,
    string $professionalName,
    string $serviceName,
    string $dateLabel,
    string $timeLabel
): string {
    return sprintf(
        'Hola %s, tu cita de %s quedo agendada para el %s a las %s con %s.',
        $customerName,
        $serviceName,
        $dateLabel,
        $timeLabel,
        $professionalName !== '' ? $professionalName : 'nuestro equipo'
    );
}

function resolveBookingTestSender(array $config): string
{
    $bookingSender = trim((string) ($config['twilio_booking_whatsapp_from'] ?? ''));

    if ($bookingSender !== '') {
        return $bookingSender;
    }

    return (string) ($config['twilio_whatsapp_from'] ?? '');
}

function resolveTextmebotBookingApiKey(array $config): string
{
    $apiKey = trim((string) ($config['textmebot_booking_api_key'] ?? ''));

    if ($apiKey !== '') {
        return $apiKey;
    }

    return trim((string) ($config['textmebot_api_key'] ?? ''));
}
