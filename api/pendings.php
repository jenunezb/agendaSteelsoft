<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuthenticatedUser();
$companyId = requireCurrentCompanyId($user);

if (isProfessionalUser($user)) {
    jsonResponse(['message' => 'El profesional no tiene acceso a pendientes generales.'], 403);
}

if ($method === 'GET') {
    $statement = $pdo->prepare(
        'SELECT id, title, assignee, professional_id, description, pending_date
         FROM general_pendings
         WHERE company_id = :company_id
         ORDER BY pending_date DESC, title'
    );
    $statement->execute([':company_id' => $companyId]);

    $pendings = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'assignee' => $row['assignee'],
            'professionalId' => isset($row['professional_id']) ? (int) $row['professional_id'] : null,
            'description' => $row['description'] ?? '',
            'date' => $row['pending_date'],
        ];
    }, $statement->fetchAll());

    jsonResponse($pendings);
}

$payload = getPayload();

if ($method === 'POST') {
    $date = (string) ($payload['date'] ?? date('Y-m-d'));
    $assignment = resolveProfessionalAssignment(
        $pdo,
        $companyId,
        $payload['professionalId'] ?? null,
        (string) ($payload['assignee'] ?? '')
    );
    $statement = $pdo->prepare(
        'INSERT INTO general_pendings (user_id, company_id, professional_id, title, assignee, description, pending_date)
         VALUES (:user_id, :company_id, :professional_id, :title, :assignee, :description, :pending_date)'
    );

    $statement->execute([
        ':user_id' => $user['id'],
        ':company_id' => $companyId,
        ':professional_id' => $assignment['professionalId'],
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':assignee' => $assignment['assignee'],
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':pending_date' => $date,
    ]);

    jsonResponse([
        'id' => (int) $pdo->lastInsertId(),
        'title' => trim((string) ($payload['title'] ?? '')),
        'assignee' => $assignment['assignee'],
        'professionalId' => $assignment['professionalId'],
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => $date,
    ], 201);
}

if ($method === 'PUT') {
    $id = getRequiredId();
    $date = (string) ($payload['date'] ?? date('Y-m-d'));
    $assignment = resolveProfessionalAssignment(
        $pdo,
        $companyId,
        $payload['professionalId'] ?? null,
        (string) ($payload['assignee'] ?? '')
    );
    $statement = $pdo->prepare(
        'UPDATE general_pendings
         SET title = :title, professional_id = :professional_id, assignee = :assignee, description = :description, pending_date = :pending_date
         WHERE id = :id
           AND company_id = :company_id'
    );

    $statement->execute([
        ':id' => $id,
        ':company_id' => $companyId,
        ':title' => trim((string) ($payload['title'] ?? '')),
        ':professional_id' => $assignment['professionalId'],
        ':assignee' => $assignment['assignee'],
        ':description' => trim((string) ($payload['description'] ?? '')),
        ':pending_date' => $date,
    ]);

    if ($statement->rowCount() === 0) {
        jsonResponse(['message' => 'Pendiente no encontrado.'], 404);
    }

    jsonResponse([
        'id' => $id,
        'title' => trim((string) ($payload['title'] ?? '')),
        'assignee' => $assignment['assignee'],
        'professionalId' => $assignment['professionalId'],
        'description' => trim((string) ($payload['description'] ?? '')),
        'date' => $date,
    ]);
}

if ($method === 'DELETE') {
    $id = getRequiredId();
    $statement = $pdo->prepare('DELETE FROM general_pendings WHERE id = :id AND company_id = :company_id');
    $statement->execute([
        ':id' => $id,
        ':company_id' => $companyId,
    ]);

    if ($statement->rowCount() === 0) {
        jsonResponse(['message' => 'Pendiente no encontrado.'], 404);
    }

    jsonResponse(['success' => true]);
}

jsonResponse(['message' => 'Metodo no permitido.'], 405);
