<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $statement = $pdo->query(
        'SELECT id, title, assignee, description, pending_date
         FROM general_pendings
         ORDER BY pending_date DESC, title'
    );

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
        'INSERT INTO general_pendings (title, assignee, description, pending_date)
         VALUES (:title, :assignee, :description, :pending_date)'
    );

    $statement->execute([
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':assignee' => (string) ($payload['assignee'] ?? ''),
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':pending_date' => $date,
    ]);

    jsonResponse([
        'id' => (int) $pdo->lastInsertId(),
        'title' => trim((string) ($payload['title'] ?? '')),
        'assignee' => (string) ($payload['assignee'] ?? ''),
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
         WHERE id = :id'
    );

    $statement->execute([
        ':id' => $id,
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':assignee' => (string) ($payload['assignee'] ?? ''),
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':pending_date' => $date,
    ]);

    jsonResponse([
        'id' => $id,
        'title' => trim((string) ($payload['title'] ?? '')),
        'assignee' => (string) ($payload['assignee'] ?? ''),
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => $date,
    ]);
}

if ($method === 'DELETE') {
    $id = getRequiredId();
    $statement = $pdo->prepare('DELETE FROM general_pendings WHERE id = :id');
    $statement->execute([':id' => $id]);
    jsonResponse(['success' => true]);
}

jsonResponse(['message' => 'Metodo no permitido.'], 405);
