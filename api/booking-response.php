<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$token = strtolower(trim((string) ($_GET['token'] ?? '')));
$decision = strtolower(trim((string) ($_GET['decision'] ?? '')));

if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1 || !in_array($decision, ['confirm', 'reject'], true)) {
    renderBookingResponse('Enlace no válido', 'El enlace de confirmación no es válido o está incompleto.', false);
}

$pdo = getConnection();
$statement = $pdo->prepare(
    'SELECT activities.id, activities.title, activities.description, activities.activity_date,
            activities.start_time, activities.booking_status, activities.booking_customer_name,
            activities.booking_customer_phone,
            owner.name AS owner_name, owner.whatsapp_number AS owner_phone,
            owner.whatsapp_notifications_enabled AS owner_notifications_enabled,
            professionals.name AS professional_name, professionals.phone AS professional_phone,
            professional_user.whatsapp_number AS professional_user_phone,
            professional_user.whatsapp_notifications_enabled AS professional_user_notifications_enabled
     FROM activities
     LEFT JOIN users owner ON owner.id = activities.user_id
     LEFT JOIN professionals ON professionals.id = activities.professional_id
     LEFT JOIN users professional_user ON professional_user.id = professionals.linked_user_id
     WHERE booking_confirmation_token = :token
     LIMIT 1'
);
$statement->execute([':token' => $token]);
$activity = $statement->fetch();

if (!is_array($activity)) {
    renderBookingResponse('Enlace vencido', 'No encontramos esta reserva.', false);
}

$appointment = DateTimeImmutable::createFromFormat(
    'Y-m-d H:i:s',
    (string) $activity['activity_date'] . ' ' . (string) $activity['start_time']
);

if (!$appointment instanceof DateTimeImmutable || $appointment <= new DateTimeImmutable('now')) {
    renderBookingResponse('La cita ya inició', 'Ya no es posible cambiar la respuesta de esta reserva.', false);
}

$status = $decision === 'confirm' ? 'confirmed' : 'rejected';
$update = $pdo->prepare(
    'UPDATE activities
     SET booking_status = :status, booking_responded_at = NOW()
     WHERE id = :id'
);
$update->execute([':status' => $status, ':id' => (int) $activity['id']]);

$notificationResults = [];
if ((string) ($activity['booking_status'] ?? '') !== $status) {
    $notificationResults = notifyBookingResponseTeam($activity, $status);
}

writeAppLog('booking-response', 'Customer answered booking confirmation.', [
    'activityId' => (int) $activity['id'],
    'status' => $status,
    'notifications' => $notificationResults,
]);

renderBookingResponse(
    $status === 'confirmed' ? 'Reserva confirmada' : 'Reserva rechazada',
    $status === 'confirmed'
        ? 'Gracias. Confirmamos tu asistencia y el equipo ya puede ver tu respuesta.'
        : 'Registramos que no asistirás. El equipo ya puede ver tu respuesta.',
    $status === 'confirmed'
);

function notifyBookingResponseTeam(array $activity, string $status): array
{
    $config = getWhatsappConfig();
    $contentSid = trim((string) ($config['twilio_booking_admin_content_sid'] ?? ''));
    if ($contentSid === '') {
        $contentSid = trim((string) ($config['twilio_booking_professional_content_sid'] ?? ''));
    }

    if ($contentSid === '') {
        return [['success' => false, 'message' => 'No hay plantilla del equipo configurada.']];
    }

    $adminPhone = !empty($activity['owner_notifications_enabled'])
        ? normalizeWhatsappNumber((string) ($activity['owner_phone'] ?? ''))
        : '';
    $professionalPhone = !empty($activity['professional_user_notifications_enabled'])
        ? normalizeWhatsappNumber((string) ($activity['professional_user_phone'] ?? ''))
        : '';
    if ($professionalPhone === '') {
        $professionalPhone = normalizeWhatsappNumber((string) ($activity['professional_phone'] ?? ''));
    }

    $recipients = [];
    if ($adminPhone !== '') {
        $recipients['admin'] = $adminPhone;
    }
    if ($professionalPhone !== '' && $professionalPhone !== $adminPhone) {
        $recipients['professional'] = $professionalPhone;
    }

    $serviceName = extractBookingServiceName((string) ($activity['description'] ?? ''), (string) ($activity['title'] ?? 'Reserva'));
    $customerName = trim((string) ($activity['booking_customer_name'] ?? 'Cliente'));
    $customerName .= $status === 'confirmed' ? ' (CONFIRMÓ)' : ' (RECHAZÓ)';
    $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $activity['activity_date']);
    $variables = [
        '1' => $serviceName,
        '2' => $date instanceof DateTimeImmutable ? $date->format('d/m/Y') : (string) $activity['activity_date'],
        '3' => substr((string) $activity['start_time'], 0, 5),
        '4' => $customerName,
        '5' => trim((string) ($activity['professional_name'] ?? '')) ?: 'Profesional asignado',
        '6' => ($activity['booking_customer_phone'] ?? '') !== '' ? '+' . normalizeWhatsappNumber((string) $activity['booking_customer_phone']) : 'Sin numero',
    ];

    $results = [];
    foreach ($recipients as $recipient => $phone) {
        $results[] = sendBookingResponseTeamNotification($config, $contentSid, $phone, $recipient, $variables);
    }
    return $results;
}

function extractBookingServiceName(string $description, string $fallback): string
{
    if (preg_match('/(?:^|\R)Servicio:\s*(.+?)(?:\R|$)/u', $description, $matches) === 1) {
        return trim((string) $matches[1]);
    }
    return $fallback;
}

function sendBookingResponseTeamNotification(array $config, string $contentSid, string $phone, string $recipient, array $variables): array
{
    $accountSid = trim((string) ($config['twilio_account_sid'] ?? ''));
    $authToken = trim((string) ($config['twilio_auth_token'] ?? ''));
    $sender = trim((string) ($config['twilio_booking_whatsapp_from'] ?? ''));
    if ($sender === '') {
        $sender = trim((string) ($config['twilio_whatsapp_from'] ?? ''));
    }
    $from = 'whatsapp:+' . normalizeWhatsappNumber($sender);
    $to = 'whatsapp:+' . normalizeWhatsappNumber($phone);

    if ($accountSid === '' || $authToken === '' || $from === 'whatsapp:+' || $to === 'whatsapp:+') {
        return ['recipient' => $recipient, 'success' => false, 'message' => 'Configuración Twilio incompleta.'];
    }

    $endpoint = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode($accountSid));
    $payload = http_build_query([
        'From' => $from,
        'To' => $to,
        'ContentSid' => $contentSid,
        'ContentVariables' => json_encode($variables, JSON_UNESCAPED_UNICODE),
    ]);
    $headers = [
        'Authorization: Basic ' . base64_encode($accountSid . ':' . $authToken),
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $rawResponse = '';
    $statusCode = 0;
    $error = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 30]);
        $response = curl_exec($ch);
        $rawResponse = is_string($response) ? $response : '';
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload, 'timeout' => 30, 'ignore_errors' => true]]);
        $response = @file_get_contents($endpoint, false, $context);
        $rawResponse = is_string($response) ? $response : '';
        $statusCode = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches) === 1 ? (int) $matches[1] : 0;
        if ($response === false) {
            $error = (string) (error_get_last()['message'] ?? 'No hubo respuesta de Twilio.');
        }
    }

    $decoded = json_decode($rawResponse, true);
    $success = $error === '' && $statusCode >= 200 && $statusCode < 300;
    return [
        'recipient' => $recipient,
        'success' => $success,
        'sid' => is_array($decoded) ? (string) ($decoded['sid'] ?? '') : '',
        'status' => is_array($decoded) ? (string) ($decoded['status'] ?? '') : '',
        'message' => $success ? '' : ($error !== '' ? $error : (string) ($decoded['message'] ?? 'Twilio rechazó la notificación.')),
    ];
}

function renderBookingResponse(string $title, string $message, bool $success): never
{
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(200);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $color = $success ? '#176329' : '#8b1e22';
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $safeTitle . '</title></head><body style="margin:0;background:#f5f2ea;font-family:Arial,sans-serif;display:grid;place-items:center;min-height:100vh">'
        . '<main style="max-width:520px;margin:24px;padding:32px;border-radius:18px;background:#fff;box-shadow:0 12px 35px #0002;text-align:center">'
        . '<h1 style="color:' . $color . ';margin-top:0">' . $safeTitle . '</h1><p style="line-height:1.6;color:#333">' . $safeMessage . '</p>'
        . '<p style="color:#777;font-size:14px">Puedes cerrar esta ventana.</p></main></body></html>';
    exit;
}
