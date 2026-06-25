<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuthenticatedUser();

if ((int) ($user['companyId'] ?? 0) <= 0) {
    initializeDefaultCompanyForUser($pdo, (int) $user['id'], (string) $user['name'], (string) $user['username']);
    $user = requireAuthenticatedUser();
}

if ($method === 'GET') {
    claimLegacyRecordsForUser($pdo, $user);
    jsonResponse(getCompanyContext($pdo, $user));
}

if ($method !== 'PUT') {
    jsonResponse(['message' => 'Metodo no permitido.'], 405);
}

requireCompanyAdminUser();

$payload = getPayload();
$action = (string) ($payload['action'] ?? '');
$companyId = requireCurrentCompanyId($user);

if ($action === 'updateCompany') {
    $name = trim((string) ($payload['name'] ?? ''));

    if ($name === '') {
        jsonResponse(['message' => 'El nombre de la empresa es obligatorio.'], 422);
    }

    $statement = $pdo->prepare(
        'UPDATE companies
         SET name = :name
         WHERE id = :id'
    );
    $statement->execute([
        ':name' => $name,
        ':id' => $companyId,
    ]);

    jsonResponse(getCompanyContext($pdo, $user));
}

if ($action === 'updateSubscription') {
    jsonResponse(['message' => 'La suscripcion solo puede ser actualizada por el superadmin.'], 403);
}

jsonResponse(['message' => 'Accion no permitida.'], 422);
