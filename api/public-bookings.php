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
$professionalId = (int) ($payload['professionalId'] ?? 0);
$customerName = trim((string) ($payload['customerName'] ?? ''));
$customerEmail = strtolower(trim((string) ($payload['customerEmail'] ?? '')));
$customerPhone = trim((string) ($payload['customerPhone'] ?? ''));
$date = trim((string) ($payload['date'] ?? ''));
$startTime = trim((string) ($payload['startTime'] ?? ''));
$endTime = trim((string) ($payload['endTime'] ?? ''));
$notes = trim((string) ($payload['notes'] ?? ''));

if ($username === '' || $professionalId <= 0 || $customerName === '' || $customerEmail === '' || $date === '' || $startTime === '' || $endTime === '') {
    jsonResponse(['message' => 'Completa los datos de la reserva.'], 422);
}

if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['message' => 'Ingresa un correo valido para la reserva.'], 422);
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

$professional = findProfessionalById($pdo, $companyId, $professionalId);
if ($professional === null || empty($professional['active'])) {
    jsonResponse(['message' => 'Profesional no disponible para esta reserva.'], 422);
}

$conflictStatement = $pdo->prepare(
    'SELECT COUNT(*)
     FROM activities
     WHERE company_id = :company_id
       AND professional_id = :professional_id
       AND activity_date = :activity_date
       AND start_time < :end_time
       AND end_time > :start_time'
);
$conflictStatement->execute([
    ':company_id' => $companyId,
    ':professional_id' => $professionalId,
    ':activity_date' => $date,
    ':start_time' => $startTime,
    ':end_time' => $endTime,
]);

if ((int) $conflictStatement->fetchColumn() > 0) {
    jsonResponse(['message' => 'Ese horario ya no esta disponible para el profesional seleccionado.'], 409);
}

$title = sprintf('Reserva web - %s', $customerName);
$descriptionParts = [
    sprintf('Cliente: %s', $customerName),
    sprintf('Correo: %s', $customerEmail),
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
    ':professional_id' => $professionalId,
    ':title' => $title,
    ':start_time' => $startTime,
    ':end_time' => $endTime,
    ':assignee' => (string) $professional['name'],
    ':location' => 'Reserva web',
    ':description' => implode("\n", $descriptionParts),
    ':activity_date' => $date,
]);

jsonResponse([
    'success' => true,
    'message' => 'Reserva creada correctamente. La empresa recibira esta cita en la agenda del profesional.',
], 201);
