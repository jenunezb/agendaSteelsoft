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
    'SELECT id, title, activity_date, start_time, booking_status
     FROM activities
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

writeAppLog('booking-response', 'Customer answered booking confirmation.', [
    'activityId' => (int) $activity['id'],
    'status' => $status,
]);

renderBookingResponse(
    $status === 'confirmed' ? 'Reserva confirmada' : 'Reserva rechazada',
    $status === 'confirmed'
        ? 'Gracias. Confirmamos tu asistencia y el equipo ya puede ver tu respuesta.'
        : 'Registramos que no asistirás. El equipo ya puede ver tu respuesta.',
    $status === 'confirmed'
);

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
