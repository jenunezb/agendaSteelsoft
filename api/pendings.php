<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuthenticatedUser();

if ($method === 'GET') {
    $statement = $pdo->prepare(
        'SELECT id, title, assignee, description, pending_date
         FROM general_pendings
         WHERE user_id = :user_id
         ORDER BY pending_date DESC, title'
    );
    $statement->execute([':user_id' => $user['id']]);

    $pendings = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'assignee' => $row['assignee'],
            'description' => $row['description'] ?? '',
            'date' => $row['pending_date'],
        ];
    }, $statement->fetchAll());

    jsonResponse($pendings);
}

$payload = getPayload();

if ($method === 'POST') {
    $date = (string) ($payload['date'] ?? date('Y-m-d'));
    $statement = $pdo->prepare(
        'INSERT INTO general_pendings (user_id, title, assignee, description, pending_date)
         VALUES (:user_id, :title, :assignee, :description, :pending_date)'
    );

    $statement->execute([
        ':user_id' => $user['id'],
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':assignee' => $user['name'],
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':pending_date' => $date,
    ]);

    jsonResponse([
        'id' => (int) $pdo->lastInsertId(),
        'title' => trim((string) ($payload['title'] ?? '')),
        'assignee' => $user['name'],
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => $date,
    ], 201);
}

if ($method === 'PUT') {
    $id = getRequiredId();
    $date = (string) ($payload['date'] ?? date('Y-m-d'));
    $statement = $pdo->prepare(
        'UPDATE general_pendings
         SET title = :title, assignee = :assignee, description = :description, pending_date = :pending_date
         WHERE id = :id
           AND user_id = :user_id'
    );

    $statement->execute([
        ':id' => $id,
        ':user_id' => $user['id'],
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':assignee' => $user['name'],
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':pending_date' => $date,
    ]);

    if ($statement->rowCount() === 0) {
        jsonResponse(['message' => 'Pendiente no encontrado.'], 404);
    }

    jsonResponse([
        'id' => $id,
        'title' => trim((string) ($payload['title'] ?? '')),
        'assignee' => $user['name'],
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => $date,
    ]);
}

if ($method === 'DELETE') {
    $id = getRequiredId();
    $statement = $pdo->prepare('DELETE FROM general_pendings WHERE id = :id AND user_id = :user_id');
    $statement->execute([
        ':id' => $id,
        ':user_id' => $user['id'],
    ]);

    if ($statement->rowCount() === 0) {
        jsonResponse(['message' => 'Pendiente no encontrado.'], 404);
    }

    jsonResponse(['success' => true]);
}

jsonResponse(['message' => 'Metodo no permitido.'], 405);
