<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuthenticatedUser();

if ($method === 'GET') {
    $statement = $pdo->prepare(
        'SELECT id, title, entry_type, amount, participation_percentage, assignee, description, entry_date
         FROM financial_entries
         WHERE user_id = :user_id
         ORDER BY entry_date DESC, entry_type, title, amount'
    );
    $statement->execute([':user_id' => $user['id']]);

    $entries = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'type' => $row['entry_type'],
            'amount' => (float) $row['amount'],
            'participationPercentage' => $row['participation_percentage'] !== null ? (float) $row['participation_percentage'] : null,
            'participantAmount' => calculateParticipantAmount((float) $row['amount'], $row['participation_percentage']),
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
    $participationPercentage = normalizeParticipationPercentage($payload['participationPercentage'] ?? null);
    $statement = $pdo->prepare(
        'INSERT INTO financial_entries (user_id, title, entry_type, amount, participation_percentage, assignee, description, entry_date)
         VALUES (:user_id, :title, :entry_type, :amount, :participation_percentage, :assignee, :description, :entry_date)'
    );

    $statement->execute([
        ':user_id' => $user['id'],
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':entry_type' => (string) ($payload['type'] ?? 'income'),
        ':amount' => (float) ($payload['amount'] ?? 0),
        ':participation_percentage' => $participationPercentage,
        ':assignee' => $user['name'],
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':entry_date' => $date,
    ]);

    jsonResponse([
        'id' => (int) $pdo->lastInsertId(),
        'title' => trim((string) ($payload['title'] ?? '')),
        'type' => (string) ($payload['type'] ?? 'income'),
        'amount' => (float) ($payload['amount'] ?? 0),
        'participationPercentage' => $participationPercentage,
        'participantAmount' => calculateParticipantAmount((float) ($payload['amount'] ?? 0), $participationPercentage),
        'assignee' => $user['name'],
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => $date,
    ], 201);
}

if ($method === 'PUT') {
    $id = getRequiredId();
    $date = (string) ($payload['date'] ?? date('Y-m-d'));
    $participationPercentage = normalizeParticipationPercentage($payload['participationPercentage'] ?? null);
    $statement = $pdo->prepare(
        'UPDATE financial_entries
         SET title = :title, entry_type = :entry_type, amount = :amount,
             participation_percentage = :participation_percentage,
             assignee = :assignee, description = :description, entry_date = :entry_date
         WHERE id = :id
           AND user_id = :user_id'
    );

    $statement->execute([
        ':id' => $id,
        ':user_id' => $user['id'],
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':entry_type' => (string) ($payload['type'] ?? 'income'),
        ':amount' => (float) ($payload['amount'] ?? 0),
        ':participation_percentage' => $participationPercentage,
        ':assignee' => $user['name'],
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':entry_date' => $date,
    ]);

    if ($statement->rowCount() === 0) {
        jsonResponse(['message' => 'Movimiento no encontrado.'], 404);
    }

    jsonResponse([
        'id' => $id,
        'title' => trim((string) ($payload['title'] ?? '')),
        'type' => (string) ($payload['type'] ?? 'income'),
        'amount' => (float) ($payload['amount'] ?? 0),
        'participationPercentage' => $participationPercentage,
        'participantAmount' => calculateParticipantAmount((float) ($payload['amount'] ?? 0), $participationPercentage),
        'assignee' => $user['name'],
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => $date,
    ]);
}

if ($method === 'DELETE') {
    $id = getRequiredId();
    $statement = $pdo->prepare('DELETE FROM financial_entries WHERE id = :id AND user_id = :user_id');
    $statement->execute([
        ':id' => $id,
        ':user_id' => $user['id'],
    ]);

    if ($statement->rowCount() === 0) {
        jsonResponse(['message' => 'Movimiento no encontrado.'], 404);
    }

    jsonResponse(['success' => true]);
}

jsonResponse(['message' => 'Metodo no permitido.'], 405);

function normalizeParticipationPercentage(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    $normalizedValue = round((float) $value, 2);

    if ($normalizedValue < 0 || $normalizedValue > 100) {
        return null;
    }

    return $normalizedValue;
}

function calculateParticipantAmount(float $amount, mixed $participationPercentage): float
{
    if ($participationPercentage === null || $participationPercentage === '') {
        return 0;
    }

    return round($amount * ((float) $participationPercentage / 100), 2);
}
