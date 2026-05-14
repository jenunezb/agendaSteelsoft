<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuthenticatedUser();

if ($method === 'GET') {
    $statement = $pdo->prepare(
        'SELECT id, title, start_time, end_time, assignee, is_public, completed, location, description, activity_date
         FROM activities
         WHERE user_id = :user_id
         ORDER BY activity_date, start_time, end_time, title'
    );
    $statement->execute([':user_id' => $user['id']]);

    $activities = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'startTime' => substr((string) $row['start_time'], 0, 5),
            'endTime' => substr((string) $row['end_time'], 0, 5),
            'assignee' => $row['assignee'],
            'visibility' => !empty($row['is_public']) ? 'public' : 'private',
            'completed' => (bool) $row['completed'],
            'location' => $row['location'] ?? '',
            'description' => $row['description'] ?? '',
            'date' => $row['activity_date'],
        ];
    }, $statement->fetchAll());

    jsonResponse($activities);
}

$payload = getPayload();

if ($method === 'POST') {
    $statement = $pdo->prepare(
        'INSERT INTO activities
        (user_id, title, start_time, end_time, assignee, is_public, completed, location, description, activity_date)
        VALUES (:user_id, :title, :start_time, :end_time, :assignee, :is_public, :completed, :location, :description, :activity_date)'
    );

    $statement->execute([
        ':user_id' => $user['id'],
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':start_time' => (string) ($payload['startTime'] ?? ''),
        ':end_time' => (string) ($payload['endTime'] ?? ''),
        ':assignee' => $user['name'],
        ':is_public' => ($payload['visibility'] ?? 'private') === 'public' ? 1 : 0,
        ':completed' => !empty($payload['completed']) ? 1 : 0,
        ':location' => trim((string) ($payload['location'] ?? '')),
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':activity_date' => (string) ($payload['date'] ?? ''),
    ]);

    jsonResponse([
        'id' => (int) $pdo->lastInsertId(),
        'title' => trim((string) ($payload['title'] ?? '')),
        'startTime' => (string) ($payload['startTime'] ?? ''),
        'endTime' => (string) ($payload['endTime'] ?? ''),
        'assignee' => $user['name'],
        'visibility' => ($payload['visibility'] ?? 'private') === 'public' ? 'public' : 'private',
        'completed' => !empty($payload['completed']),
        'location' => trim((string) ($payload['location'] ?? '')),
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => (string) ($payload['date'] ?? ''),
    ], 201);
}

if ($method === 'PUT') {
    $id = getRequiredId();

    $statement = $pdo->prepare(
        'UPDATE activities
         SET title = :title,
             start_time = :start_time,
             end_time = :end_time,
             assignee = :assignee,
             is_public = :is_public,
             completed = :completed,
             location = :location,
             description = :description,
             activity_date = :activity_date
         WHERE id = :id
           AND user_id = :user_id'
    );

    $statement->execute([
        ':id' => $id,
        ':user_id' => $user['id'],
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':start_time' => (string) ($payload['startTime'] ?? ''),
        ':end_time' => (string) ($payload['endTime'] ?? ''),
        ':assignee' => $user['name'],
        ':is_public' => ($payload['visibility'] ?? 'private') === 'public' ? 1 : 0,
        ':completed' => !empty($payload['completed']) ? 1 : 0,
        ':location' => trim((string) ($payload['location'] ?? '')),
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':activity_date' => (string) ($payload['date'] ?? ''),
    ]);

    if ($statement->rowCount() === 0) {
        jsonResponse(['message' => 'Actividad no encontrada.'], 404);
    }

    jsonResponse([
        'id' => $id,
        'title' => trim((string) ($payload['title'] ?? '')),
        'startTime' => (string) ($payload['startTime'] ?? ''),
        'endTime' => (string) ($payload['endTime'] ?? ''),
        'assignee' => $user['name'],
        'visibility' => ($payload['visibility'] ?? 'private') === 'public' ? 'public' : 'private',
        'completed' => !empty($payload['completed']),
        'location' => trim((string) ($payload['location'] ?? '')),
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => (string) ($payload['date'] ?? ''),
    ]);
}

if ($method === 'DELETE') {
    $id = getRequiredId();
    $statement = $pdo->prepare('DELETE FROM activities WHERE id = :id AND user_id = :user_id');
    $statement->execute([
        ':id' => $id,
        ':user_id' => $user['id'],
    ]);

    if ($statement->rowCount() === 0) {
        jsonResponse(['message' => 'Actividad no encontrada.'], 404);
    }

    jsonResponse(['success' => true]);
}

jsonResponse(['message' => 'Metodo no permitido.'], 405);
