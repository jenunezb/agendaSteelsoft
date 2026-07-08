<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonResponse(['message' => 'Metodo no permitido.'], 405);
}

$payload = getPayload();
$username = strtolower(trim((string) ($payload['username'] ?? '')));
$serviceId = (int) ($payload['serviceId'] ?? 0);
$professionalId = isset($payload['professionalId']) ? (int) $payload['professionalId'] : 0;
$customerName = trim((string) ($payload['customerName'] ?? ''));
$customerPhone = trim((string) ($payload['customerPhone'] ?? ''));
$date = trim((string) ($payload['date'] ?? ''));
$startTime = trim((string) ($payload['startTime'] ?? ''));
$notes = trim((string) ($payload['notes'] ?? ''));

if ($username === '' || $serviceId <= 0 || $customerName === '' || $date === '' || $startTime === '') {
    jsonResponse(['message' => 'Completa los datos de la reserva.'], 422);
}

$statement = $pdo->prepare(
    'SELECT id, name, company_id, company_role, profile_public, whatsapp_number, whatsapp_notifications_enabled
     FROM users
     WHERE username = :username
     LIMIT 1'
);
$statement->execute([':username' => $username]);
$user = $statement->fetch();

if (!is_array($user) || empty($user['profile_public'])) {
    jsonResponse(['message' => 'La agenda publica no esta disponible.'], 404);
}

$companyId = (int) ($user['company_id'] ?? 0);
$companyRole = (string) ($user['company_role'] ?? '');

if ($companyId <= 0 || !in_array($companyRole, ['owner', 'admin'], true)) {
    jsonResponse(['message' => 'La agenda publica no permite reservas generales.'], 422);
}

$service = findServiceById($pdo, $companyId, $serviceId);
if ($service === null || empty($service['active'])) {
    jsonResponse(['message' => 'Servicio no disponible para esta reserva.'], 422);
}

$roleId = (int) ($service['roleId'] ?? 0);
if ($roleId <= 0) {
    jsonResponse(['message' => 'El servicio no tiene una especialidad asociada.'], 422);
}

$endTime = addMinutesToTime($startTime, (int) ($service['durationMinutes'] ?? 30));

$professional = findAvailableProfessionalForService(
    $pdo,
    $companyId,
    $roleId,
    $date,
    $startTime,
    $endTime,
    $professionalId > 0 ? $professionalId : null
);

if ($professional === null) {
    if ($professionalId > 0) {
        jsonResponse(['message' => 'El profesional seleccionado no esta disponible o no ofrece este servicio.'], 409);
    }

    jsonResponse(['message' => 'No hay profesionales disponibles para este servicio en ese horario.'], 409);
}

$title = sprintf('Reserva web - %s - %s', (string) ($service['name'] ?? 'Servicio'), $customerName);
$descriptionParts = [
    sprintf('Servicio: %s', (string) ($service['name'] ?? '')),
    sprintf('Duracion: %d minutos', (int) ($service['durationMinutes'] ?? 30)),
    sprintf('Cliente: %s', $customerName),
];

if ($customerPhone !== '') {
    $descriptionParts[] = sprintf('Telefono: %s', $customerPhone);
}

if ($notes !== '') {
    $descriptionParts[] = sprintf('Notas: %s', $notes);
}

$statement = $pdo->prepare(
    'INSERT INTO activities
    (user_id, company_id, professional_id, title, start_time, end_time, assignee, is_public, completed, location, description, activity_date, reminder_minutes, reminder_sent_at)
    VALUES (:user_id, :company_id, :professional_id, :title, :start_time, :end_time, :assignee, 0, 0, :location, :description, :activity_date, NULL, NULL)'
);
$statement->execute([
    ':user_id' => (int) $user['id'],
    ':company_id' => $companyId,
    ':professional_id' => (int) $professional['id'],
    ':title' => $title,
    ':start_time' => $startTime,
    ':end_time' => $endTime,
    ':assignee' => (string) $professional['name'],
    ':location' => '',
    ':description' => implode("\n", $descriptionParts),
    ':activity_date' => $date,
]);

$activityId = (int) $pdo->lastInsertId();
$notificationSummary = sendBookingWhatsappNotifications(
    $pdo,
    $user,
    $professional,
    [
        'id' => $activityId,
        'serviceName' => (string) ($service['name'] ?? 'Servicio'),
        'customerName' => $customerName,
        'customerPhone' => $customerPhone,
        'date' => $date,
        'startTime' => $startTime,
        'endTime' => $endTime,
        'notes' => $notes,
    ]
);
$responseMessage = buildBookingResponseMessage($notificationSummary);

writeAppLog('public-bookings', 'Booking created from public profile.', [
    'activityId' => $activityId,
    'username' => $username,
    'serviceId' => $serviceId,
    'professionalId' => (int) ($professional['id'] ?? 0),
    'customerPhone' => normalizeWhatsappNumber($customerPhone),
    'notifications' => $notificationSummary,
]);

jsonResponse([
    'success' => true,
    'message' => $responseMessage,
    'notifications' => $notificationSummary,
], 201);

function buildBookingResponseMessage(array $notificationSummary): string
{
    $baseMessage = 'Reserva creada correctamente. La empresa recibira esta cita en la agenda del profesional.';
    $details = [];
    $sent = is_array($notificationSummary['sent'] ?? null) ? $notificationSummary['sent'] : [];
    $failed = is_array($notificationSummary['failed'] ?? null) ? $notificationSummary['failed'] : [];
    $skipped = is_array($notificationSummary['skipped'] ?? null) ? $notificationSummary['skipped'] : [];

    $customerSent = array_values(array_filter(
        $sent,
        static fn (array $result): bool => (string) ($result['recipient'] ?? '') === 'customer'
    ));
    $customerFailed = array_values(array_filter(
        $failed,
        static fn (array $result): bool => (string) ($result['recipient'] ?? '') === 'customer'
    ));
    $customerSkipped = array_values(array_filter(
        $skipped,
        static fn (string $message): bool => str_starts_with($message, 'customer:')
    ));

    if ($customerSent !== []) {
        $customerStatus = trim((string) ($customerSent[0]['status'] ?? ''));
        $details[] = 'WhatsApp cliente enviado' . ($customerStatus !== '' ? ' (' . $customerStatus . ')' : '.');
    } elseif ($customerFailed !== []) {
        $customerError = trim((string) ($customerFailed[0]['message'] ?? 'No fue posible enviar el WhatsApp al cliente.'));
        $details[] = 'WhatsApp cliente con error: ' . $customerError;
    } elseif ($customerSkipped !== []) {
        $details[] = 'WhatsApp cliente omitido: ' . preg_replace('/^customer:\s*/', '', $customerSkipped[0]);
    } else {
        $details[] = 'No hubo detalle del WhatsApp del cliente.';
    }

    return $baseMessage . ' ' . implode(' | ', $details);
}

function sendBookingWhatsappNotifications(PDO $pdo, array $ownerUser, array $professional, array $booking): array
{
    $config = getWhatsappConfig();
    $results = [];
    $professionalName = (string) ($professional['name'] ?? '');

    if (!isBookingWhatsappProviderConfigured($config)) {
        return [
            'sent' => [],
            'failed' => [],
            'skipped' => [($config['provider'] ?? 'textmebot') . '-not-configured'],
        ];
    }

    $dateLabel = formatBookingDateLabel((string) $booking['date']);
    $timeLabel = substr((string) $booking['startTime'], 0, 5);
    $serviceName = (string) $booking['serviceName'];
    $customerName = (string) $booking['customerName'];
    $customerPhone = normalizeWhatsappNumber((string) $booking['customerPhone']);
    $notes = trim((string) $booking['notes']);
    $ownerPhone = normalizeWhatsappNumber((string) ($ownerUser['whatsapp_number'] ?? ''));
    $ownerNotificationsEnabled = !empty($ownerUser['whatsapp_notifications_enabled']);

    if ($ownerNotificationsEnabled && $ownerPhone !== '') {
        $results[] = sendBookingNotification(
            $config,
            $ownerPhone,
            buildAdminBookingMessage(
                (string) ($ownerUser['name'] ?? 'Administracion'),
                $professionalName,
                $serviceName,
                $customerName,
                $customerPhone,
                $dateLabel,
                $timeLabel,
                $notes
            ),
            'admin',
            buildAdminBookingContentVariables(
                $serviceName,
                $dateLabel,
                $timeLabel,
                $customerName,
                $professionalName,
                $customerPhone
            )
        );
    } else {
        $results[] = [
            'recipient' => 'admin',
            'success' => false,
            'skipped' => true,
            'message' => 'La administradora no tiene WhatsApp configurado o habilitado.',
        ];
    }

    $professionalRecipientPhone = resolveProfessionalWhatsappNumber($pdo, (int) ($ownerUser['company_id'] ?? 0), $professional);

    if ($professionalRecipientPhone !== '' && $professionalRecipientPhone !== $ownerPhone) {
        $results[] = sendBookingNotification(
            $config,
            $professionalRecipientPhone,
            buildProfessionalBookingMessage(
                $professionalName !== '' ? $professionalName : 'Profesional',
                $serviceName,
                $customerName,
                $customerPhone,
                $dateLabel,
                $timeLabel,
                $notes
            ),
            'professional',
            buildProfessionalBookingContentVariables(
                $serviceName,
                $dateLabel,
                $timeLabel,
                $customerName,
                $customerPhone,
                $professionalName
            )
        );
    } else {
        $results[] = [
            'recipient' => 'professional',
            'success' => false,
            'skipped' => true,
            'message' => 'El profesional no tiene WhatsApp propio configurado o coincide con administracion.',
        ];
    }

    if ($customerPhone !== '') {
        $results[] = sendBookingNotification(
            $config,
            $customerPhone,
            buildCustomerBookingMessage(
                $customerName,
                $professionalName,
                $serviceName,
                $dateLabel,
                $timeLabel
            ),
            'customer',
            buildCustomerBookingContentVariables(
                $serviceName,
                $dateLabel,
                $timeLabel,
                $professionalName
            )
        );
    } else {
        $results[] = [
            'recipient' => 'customer',
            'success' => false,
            'skipped' => true,
            'message' => 'El cliente no envio numero de WhatsApp.',
        ];
    }

    return [
        'sent' => array_values(array_filter($results, static fn (array $result): bool => !empty($result['success']))),
        'failed' => array_values(array_filter($results, static fn (array $result): bool => empty($result['success']) && empty($result['skipped']))),
        'skipped' => array_values(array_map(
            static fn (array $result): string => (string) ($result['recipient'] . ': ' . $result['message']),
            array_filter($results, static fn (array $result): bool => !empty($result['skipped']))
        )),
    ];
}

function isBookingWhatsappProviderConfigured(array $config): bool
{
    if (($config['provider'] ?? 'textmebot') === 'textmebot') {
        return resolveTextmebotBookingApiKey($config) !== '';
    }

    return
        trim((string) ($config['twilio_account_sid'] ?? '')) !== ''
        && trim((string) ($config['twilio_auth_token'] ?? '')) !== ''
        && trim((string) resolveTwilioBookingSender($config)) !== '';
}

function sendBookingNotification(
    array $config,
    string $phone,
    string $message,
    string $recipient,
    array $contentVariables
): array {
    if (($config['provider'] ?? 'textmebot') === 'textmebot') {
        return sendTextmebotBookingNotification($config, $phone, $message, $recipient);
    }

    return sendTwilioBookingNotification($config, $phone, $message, $recipient, $contentVariables);
}

function sendTextmebotBookingNotification(
    array $config,
    string $phone,
    string $message,
    string $recipient
): array {
    $normalizedPhone = normalizeWhatsappNumber($phone);
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

    [$rawResponse, $statusCode, $transportError] = sendBookingGetRequest($endpoint);

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
    ];
}

function sendTwilioBookingNotification(
    array $config,
    string $phone,
    string $message,
    string $recipient,
    array $contentVariables
): array
{
    $accountSid = (string) ($config['twilio_account_sid'] ?? '');
    $authToken = (string) ($config['twilio_auth_token'] ?? '');
    $from = normalizeTwilioBookingAddress(resolveTwilioBookingSender($config));
    $to = normalizeTwilioBookingAddress($phone);

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
    $recipientContentSid = getBookingRecipientContentSid($config, $recipient);
    $payload = [
        'From' => $from,
        'To' => $to,
    ];

    if ($recipientContentSid !== '') {
        $payload['ContentSid'] = $recipientContentSid;
        $payload['ContentVariables'] = encodeTwilioContentVariables($contentVariables);
    } else {
        $payload['Body'] = $message;
    }

    [$rawResponse, $statusCode, $transportError] = sendTwilioFormRequest(
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
    ];
}

function resolveTwilioBookingSender(array $config): string
{
    $bookingSender = trim((string) ($config['twilio_booking_whatsapp_from'] ?? ''));

    if ($bookingSender !== '') {
        return $bookingSender;
    }

    return (string) ($config['twilio_whatsapp_from'] ?? '');
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

function encodeTwilioContentVariables(array $variables): string
{
    $json = json_encode($variables, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException('No fue posible serializar las variables de Twilio.');
    }

    return $json;
}

function sendTwilioFormRequest(string $endpoint, array $headers, array $payload): array
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
    $statusCode = extractTwilioHttpStatusCode($responseHeaders);

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

function sendBookingGetRequest(string $endpoint): array
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
        return sendBookingGetRequestWithPowershell($endpoint, 'TextMeBot');
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
    $statusCode = extractTwilioHttpStatusCode($responseHeaders);

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

function sendBookingGetRequestWithPowershell(string $endpoint, string $serviceLabel): array
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

function extractTwilioHttpStatusCode(array $responseHeaders): int
{
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function normalizeTwilioBookingAddress(string $value): string
{
    $normalizedValue = normalizeWhatsappNumber($value);

    if ($normalizedValue === '') {
        return '';
    }

    return 'whatsapp:+' . $normalizedValue;
}

function resolveProfessionalWhatsappNumber(PDO $pdo, int $companyId, array $professional): string
{
    $linkedUserId = isset($professional['linked_user_id']) ? (int) $professional['linked_user_id'] : 0;

    if ($linkedUserId > 0) {
        $statement = $pdo->prepare(
            'SELECT whatsapp_number, whatsapp_notifications_enabled
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute([':id' => $linkedUserId]);
        $linkedUser = $statement->fetch();

        if (is_array($linkedUser) && !empty($linkedUser['whatsapp_notifications_enabled'])) {
            $linkedPhone = normalizeWhatsappNumber((string) ($linkedUser['whatsapp_number'] ?? ''));

            if ($linkedPhone !== '') {
                return $linkedPhone;
            }
        }
    }

    return normalizeWhatsappNumber((string) ($professional['phone'] ?? ''));
}

function formatBookingDateLabel(string $isoDate): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $isoDate);

    if (!$date instanceof DateTimeImmutable) {
        return $isoDate;
    }

    return $date->format('d/m/Y');
}

function buildAdminBookingMessage(
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

function buildProfessionalBookingMessage(
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

function buildCustomerBookingMessage(
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

function buildAdminBookingContentVariables(
    string $serviceName,
    string $dateLabel,
    string $timeLabel,
    string $customerName,
    string $professionalName,
    string $customerPhone
): array {
    return [
        '1' => $serviceName,
        '2' => $dateLabel,
        '3' => $timeLabel,
        '4' => $customerName,
        '5' => $professionalName !== '' ? $professionalName : 'Profesional asignado',
        '6' => $customerPhone !== '' ? '+' . $customerPhone : 'Sin numero',
    ];
}

function buildProfessionalBookingContentVariables(
    string $serviceName,
    string $dateLabel,
    string $timeLabel,
    string $customerName,
    string $customerPhone,
    string $professionalName
): array {
    return [
        '1' => $professionalName !== '' ? $professionalName : 'Profesional',
        '2' => $serviceName,
        '3' => $dateLabel,
        '4' => $timeLabel,
        '5' => $customerName,
        '6' => $customerPhone !== '' ? '+' . $customerPhone : 'Sin numero',
    ];
}

function buildCustomerBookingContentVariables(
    string $serviceName,
    string $dateLabel,
    string $timeLabel,
    string $professionalName
): array {
    return [
        '1' => $serviceName,
        '2' => $dateLabel,
        '3' => $timeLabel,
        '4' => $professionalName !== '' ? $professionalName : 'nuestro equipo',
    ];
}

function resolveTextmebotBookingApiKey(array $config): string
{
    $apiKey = trim((string) ($config['textmebot_booking_api_key'] ?? ''));

    if ($apiKey !== '') {
        return $apiKey;
    }

    return trim((string) ($config['textmebot_api_key'] ?? ''));
}
