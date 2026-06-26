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
    'SELECT id, name, company_id, company_role, profile_public
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

jsonResponse([
    'success' => true,
    'message' => 'Reserva creada correctamente. La empresa recibira esta cita en la agenda del profesional.',
], 201);
