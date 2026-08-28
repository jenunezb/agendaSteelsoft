<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuthenticatedUser();

if (($user['accountType'] ?? '') !== 'independent') {
    jsonResponse(['message' => 'Los recordatorios personales solo estan disponibles para perfiles independientes.'], 403);
}

if ($method === 'GET') {
    $statement = $pdo->prepare(
        'SELECT id, title, description, due_date, priority, completed, whatsapp_enabled, remind_at, reminder_sent_at
         FROM personal_reminders WHERE user_id = :user_id
         ORDER BY completed, COALESCE(due_date, "9999-12-31"), created_at DESC'
    );
    $statement->execute([':user_id' => (int) $user['id']]);
    jsonResponse(array_map('mapPersonalReminder', $statement->fetchAll()));
}

$payload = getPayload();
$title = trim((string) ($payload['title'] ?? ''));
if ($method !== 'DELETE' && $title === '') {
    jsonResponse(['message' => 'El titulo es obligatorio.'], 422);
}

if ($method === 'POST') {
    $values = validatePersonalReminder($payload);
    $statement = $pdo->prepare(
        'INSERT INTO personal_reminders (user_id, title, description, due_date, priority, completed, whatsapp_enabled, remind_at)
         VALUES (:user_id, :title, :description, :due_date, :priority, :completed, :whatsapp_enabled, :remind_at)'
    );
    $statement->execute([':user_id' => (int) $user['id'], ':title' => $title] + $values);
    $id = (int) $pdo->lastInsertId();
    $statement = $pdo->prepare('SELECT * FROM personal_reminders WHERE id = :id');
    $statement->execute([':id' => $id]);
    jsonResponse(mapPersonalReminder($statement->fetch()), 201);
}

$id = getRequiredId();
if ($method === 'PUT') {
    $values = validatePersonalReminder($payload);
    $statement = $pdo->prepare(
        'UPDATE personal_reminders SET
         reminder_sent_at = IF(remind_at <=> :remind_at_compare AND whatsapp_enabled = :whatsapp_compare, reminder_sent_at, NULL),
         title = :title, description = :description, due_date = :due_date,
         priority = :priority, completed = :completed, whatsapp_enabled = :whatsapp_enabled, remind_at = :remind_at,
         WHERE id = :id AND user_id = :user_id'
    );
    $statement->execute([':id' => $id, ':user_id' => (int) $user['id'], ':title' => $title,
        ':remind_at_compare' => $values[':remind_at'], ':whatsapp_compare' => $values[':whatsapp_enabled']] + $values);
    $statement = $pdo->prepare('SELECT * FROM personal_reminders WHERE id = :id AND user_id = :user_id');
    $statement->execute([':id' => $id, ':user_id' => (int) $user['id']]);
    $row = $statement->fetch();
    if (!$row) jsonResponse(['message' => 'Recordatorio no encontrado.'], 404);
    jsonResponse(mapPersonalReminder($row));
}

if ($method === 'DELETE') {
    $statement = $pdo->prepare('DELETE FROM personal_reminders WHERE id = :id AND user_id = :user_id');
    $statement->execute([':id' => $id, ':user_id' => (int) $user['id']]);
    if ($statement->rowCount() === 0) jsonResponse(['message' => 'Recordatorio no encontrado.'], 404);
    jsonResponse(['success' => true]);
}

jsonResponse(['message' => 'Metodo no permitido.'], 405);

function validatePersonalReminder(array $payload): array
{
    $priority = (string) ($payload['priority'] ?? 'medium');
    if (!in_array($priority, ['low', 'medium', 'high'], true)) $priority = 'medium';
    $dueDate = trim((string) ($payload['dueDate'] ?? '')) ?: null;
    $remindAt = trim((string) ($payload['remindAt'] ?? '')) ?: null;
    $whatsapp = !empty($payload['whatsappEnabled']);
    if ($whatsapp && $remindAt === null) jsonResponse(['message' => 'Selecciona fecha y hora para la notificacion.'], 422);
    return [':description' => trim((string) ($payload['description'] ?? '')), ':due_date' => $dueDate,
        ':priority' => $priority, ':completed' => !empty($payload['completed']) ? 1 : 0,
        ':whatsapp_enabled' => $whatsapp ? 1 : 0, ':remind_at' => $remindAt];
}

function mapPersonalReminder(array $row): array
{
    return ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'description' => (string) ($row['description'] ?? ''),
        'dueDate' => $row['due_date'] ?: null, 'priority' => (string) $row['priority'], 'completed' => !empty($row['completed']),
        'whatsappEnabled' => !empty($row['whatsapp_enabled']), 'remindAt' => $row['remind_at'] ? str_replace(' ', 'T', substr($row['remind_at'], 0, 16)) : null,
        'reminderSentAt' => $row['reminder_sent_at'] ?? null];
}
