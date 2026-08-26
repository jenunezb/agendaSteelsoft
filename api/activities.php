<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuthenticatedUser();
$companyId = requireCurrentCompanyId($user);
$isProfessionalUser = isProfessionalUser($user);
$professionalId = (int) ($user['professionalId'] ?? 0);

if ($method === 'GET') {
    if ($isProfessionalUser) {
        $statement = $pdo->prepare(
            'SELECT id, title, start_time, end_time, assignee, professional_id, is_public, completed, location, description, activity_date, reminder_minutes, recurrence_type, booking_status, booking_responded_at
             FROM activities
             WHERE company_id = :company_id
               AND professional_id = :professional_id
             ORDER BY activity_date, start_time, end_time, title'
        );
        $statement->execute([
            ':company_id' => $companyId,
            ':professional_id' => $professionalId,
        ]);
    } else {
        $statement = $pdo->prepare(
            'SELECT id, title, start_time, end_time, assignee, professional_id, is_public, completed, location, description, activity_date, reminder_minutes, recurrence_type, booking_status, booking_responded_at
             FROM activities
             WHERE company_id = :company_id
             ORDER BY activity_date, start_time, end_time, title'
        );
        $statement->execute([':company_id' => $companyId]);
    }

    $activities = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'startTime' => substr((string) $row['start_time'], 0, 5),
            'endTime' => substr((string) $row['end_time'], 0, 5),
            'assignee' => $row['assignee'],
            'professionalId' => isset($row['professional_id']) ? (int) $row['professional_id'] : null,
            'visibility' => !empty($row['is_public']) ? 'public' : 'private',
            'completed' => (bool) $row['completed'],
            'location' => $row['location'] ?? '',
            'description' => $row['description'] ?? '',
            'date' => $row['activity_date'],
            'reminderMinutes' => isset($row['reminder_minutes']) ? (int) $row['reminder_minutes'] : null,
            'recurrenceType' => (string) ($row['recurrence_type'] ?? 'none'),
            'bookingStatus' => $row['booking_status'] ?: null,
            'bookingRespondedAt' => $row['booking_responded_at'] ?: null,
        ];
    }, $statement->fetchAll());

    jsonResponse($activities);
}

$payload = getPayload();

if ($isProfessionalUser && $professionalId <= 0) {
    jsonResponse(['message' => 'El profesional no tiene un calendario asignado.'], 403);
}

if ($method === 'POST') {
    $reminderMinutes = normalizeReminderMinutes($payload['reminderMinutes'] ?? null);
    $recurrenceType = normalizeActivityRecurrence($payload['recurrenceType'] ?? 'none', $user);
    $assignment = $isProfessionalUser
        ? [
            'professionalId' => $professionalId,
            'assignee' => (string) ($user['name'] ?? ''),
        ]
        : resolveProfessionalAssignment(
            $pdo,
            $companyId,
            $payload['professionalId'] ?? null,
            (string) ($payload['assignee'] ?? '')
        );
    $statement = $pdo->prepare(
        'INSERT INTO activities
        (user_id, company_id, professional_id, title, start_time, end_time, assignee, is_public, completed, location, description, activity_date, reminder_minutes, reminder_sent_at, recurrence_type, reminder_sent_for_date)
        VALUES (:user_id, :company_id, :professional_id, :title, :start_time, :end_time, :assignee, :is_public, :completed, :location, :description, :activity_date, :reminder_minutes, NULL, :recurrence_type, NULL)'
    );

    $statement->execute([
        ':user_id' => $user['id'],
        ':company_id' => $companyId,
        ':professional_id' => $assignment['professionalId'],
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':start_time' => (string) ($payload['startTime'] ?? ''),
        ':end_time' => (string) ($payload['endTime'] ?? ''),
        ':assignee' => $assignment['assignee'],
        ':is_public' => ($payload['visibility'] ?? 'private') === 'public' ? 1 : 0,
        ':completed' => !empty($payload['completed']) ? 1 : 0,
        ':location' => trim((string) ($payload['location'] ?? '')),
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':activity_date' => (string) ($payload['date'] ?? ''),
        ':reminder_minutes' => $reminderMinutes,
        ':recurrence_type' => $recurrenceType,
    ]);

    jsonResponse([
        'id' => (int) $pdo->lastInsertId(),
        'title' => trim((string) ($payload['title'] ?? '')),
        'startTime' => (string) ($payload['startTime'] ?? ''),
        'endTime' => (string) ($payload['endTime'] ?? ''),
        'assignee' => $assignment['assignee'],
        'professionalId' => $assignment['professionalId'],
        'visibility' => ($payload['visibility'] ?? 'private') === 'public' ? 'public' : 'private',
        'completed' => !empty($payload['completed']),
        'location' => trim((string) ($payload['location'] ?? '')),
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => (string) ($payload['date'] ?? ''),
        'reminderMinutes' => $reminderMinutes,
        'recurrenceType' => $recurrenceType,
    ], 201);
}

if ($method === 'PUT') {
    $id = getRequiredId();
    $reminderMinutes = normalizeReminderMinutes($payload['reminderMinutes'] ?? null);
    $recurrenceType = normalizeActivityRecurrence($payload['recurrenceType'] ?? 'none', $user);
    $assignment = $isProfessionalUser
        ? [
            'professionalId' => $professionalId,
            'assignee' => (string) ($user['name'] ?? ''),
        ]
        : resolveProfessionalAssignment(
            $pdo,
            $companyId,
            $payload['professionalId'] ?? null,
            (string) ($payload['assignee'] ?? '')
        );

    $statement = $pdo->prepare(
        'UPDATE activities
         SET title = :title,
             start_time = :start_time,
             end_time = :end_time,
             professional_id = :professional_id,
             assignee = :assignee,
             is_public = :is_public,
             completed = :completed,
             location = :location,
             description = :description,
             activity_date = :activity_date,
             reminder_minutes = :reminder_minutes,
             reminder_sent_at = NULL,
             recurrence_type = :recurrence_type,
             reminder_sent_for_date = NULL
         WHERE id = :id
           AND company_id = :company_id' . ($isProfessionalUser ? ' AND professional_id = :scope_professional_id' : '')
    );

    $params = [
        ':id' => $id,
        ':company_id' => $companyId,
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':start_time' => (string) ($payload['startTime'] ?? ''),
        ':end_time' => (string) ($payload['endTime'] ?? ''),
        ':professional_id' => $assignment['professionalId'],
        ':assignee' => $assignment['assignee'],
        ':is_public' => ($payload['visibility'] ?? 'private') === 'public' ? 1 : 0,
        ':completed' => !empty($payload['completed']) ? 1 : 0,
        ':location' => trim((string) ($payload['location'] ?? '')),
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':activity_date' => (string) ($payload['date'] ?? ''),
        ':reminder_minutes' => $reminderMinutes,
        ':recurrence_type' => $recurrenceType,
    ];

    if ($isProfessionalUser) {
        $params[':scope_professional_id'] = $professionalId;
    }

    $statement->execute($params);

    if ($statement->rowCount() === 0) {
        jsonResponse(['message' => 'Actividad no encontrada.'], 404);
    }

    jsonResponse([
        'id' => $id,
        'title' => trim((string) ($payload['title'] ?? '')),
        'startTime' => (string) ($payload['startTime'] ?? ''),
        'endTime' => (string) ($payload['endTime'] ?? ''),
        'assignee' => $assignment['assignee'],
        'professionalId' => $assignment['professionalId'],
        'visibility' => ($payload['visibility'] ?? 'private') === 'public' ? 'public' : 'private',
        'completed' => !empty($payload['completed']),
        'location' => trim((string) ($payload['location'] ?? '')),
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => (string) ($payload['date'] ?? ''),
        'reminderMinutes' => $reminderMinutes,
        'recurrenceType' => $recurrenceType,
    ]);
}

if ($method === 'DELETE') {
    $id = getRequiredId();
    $statement = $pdo->prepare(
        'DELETE FROM activities
         WHERE id = :id
           AND company_id = :company_id' . ($isProfessionalUser ? ' AND professional_id = :scope_professional_id' : '')
    );
    $params = [
        ':id' => $id,
        ':company_id' => $companyId,
    ];

    if ($isProfessionalUser) {
        $params[':scope_professional_id'] = $professionalId;
    }

    $statement->execute($params);

    if ($statement->rowCount() === 0) {
        jsonResponse(['message' => 'Actividad no encontrada.'], 404);
    }

    jsonResponse(['success' => true]);
}

jsonResponse(['message' => 'Metodo no permitido.'], 405);

function normalizeActivityRecurrence(mixed $value, array $user): string
{
    if (($user['accountType'] ?? '') !== 'independent') {
        return 'none';
    }

    $recurrenceType = strtolower(trim((string) $value));
    return in_array($recurrenceType, ['daily', 'weekly', 'monthly'], true)
        ? $recurrenceType
        : 'none';
}
