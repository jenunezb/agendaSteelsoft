<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $statement = $pdo->query(
        'SELECT id, title, entry_type, amount, assignee, description, entry_date
         FROM financial_entries
         ORDER BY entry_date DESC, entry_type, title, amount'
    );

    $entries = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'type' => $row['entry_type'],
            'amount' => (float) $row['amount'],
            'assignee' => $row['assignee'],
            'description' => $row['description'] ?? '',
            'date' => $row['entry_date'],
        ];
    }, $statement->fetchAll());

    jsonResponse($entries);
}

$payload = getPayload();

if ($method === 'POST') {
    $date = (string) ($payload['date'] ?? date('Y-m-d'));
    $statement = $pdo->prepare(
        'INSERT INTO financial_entries (title, entry_type, amount, assignee, description, entry_date)
         VALUES (:title, :entry_type, :amount, :assignee, :description, :entry_date)'
    );

    $statement->execute([
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':entry_type' => (string) ($payload['type'] ?? 'income'),
        ':amount' => (float) ($payload['amount'] ?? 0),
        ':assignee' => (string) ($payload['assignee'] ?? ''),
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':entry_date' => $date,
    ]);

    jsonResponse([
        'id' => (int) $pdo->lastInsertId(),
        'title' => trim((string) ($payload['title'] ?? '')),
        'type' => (string) ($payload['type'] ?? 'income'),
        'amount' => (float) ($payload['amount'] ?? 0),
        'assignee' => (string) ($payload['assignee'] ?? ''),
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => $date,
    ], 201);
}

if ($method === 'PUT') {
    $id = getRequiredId();
    $date = (string) ($payload['date'] ?? date('Y-m-d'));
    $statement = $pdo->prepare(
        'UPDATE financial_entries
         SET title = :title, entry_type = :entry_type, amount = :amount,
             assignee = :assignee, description = :description, entry_date = :entry_date
         WHERE id = :id'
    );

    $statement->execute([
        ':id' => $id,
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':entry_type' => (string) ($payload['type'] ?? 'income'),
        ':amount' => (float) ($payload['amount'] ?? 0),
        ':assignee' => (string) ($payload['assignee'] ?? ''),
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':entry_date' => $date,
    ]);

    jsonResponse([
        'id' => $id,
        'title' => trim((string) ($payload['title'] ?? '')),
        'type' => (string) ($payload['type'] ?? 'income'),
        'amount' => (float) ($payload['amount'] ?? 0),
        'assignee' => (string) ($payload['assignee'] ?? ''),
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => $date,
    ]);
}

if ($method === 'DELETE') {
    $id = getRequiredId();
    $statement = $pdo->prepare('DELETE FROM financial_entries WHERE id = :id');
    $statement->execute([':id' => $id]);
    jsonResponse(['success' => true]);
}

jsonResponse(['message' => 'Metodo no permitido.'], 405);
