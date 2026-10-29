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
    $workingHourStart = (int) ($payload['workingHourStart'] ?? 8);
    $workingHourEnd = (int) ($payload['workingHourEnd'] ?? 18);
    $primaryColor = strtolower(trim((string) ($payload['primaryColor'] ?? '#f2b705')));

    if ($name === '') {
        jsonResponse(['message' => 'El nombre de la empresa es obligatorio.'], 422);
    }

    if ($workingHourStart < 0 || $workingHourStart > 23 || $workingHourEnd < 1 || $workingHourEnd > 23) {
        jsonResponse(['message' => 'El horario laboral es invalido.'], 422);
    }

    if ($workingHourEnd <= $workingHourStart) {
        jsonResponse(['message' => 'La hora final debe ser mayor a la hora inicial.'], 422);
    }

    if (!preg_match('/^#[0-9a-f]{6}$/', $primaryColor)) {
        jsonResponse(['message' => 'El color principal no es valido.'], 422);
    }

    $statement = $pdo->prepare(
        'UPDATE companies
         SET name = :name,
             working_hour_start = :working_hour_start,
             working_hour_end = :working_hour_end,
             brand_primary_color = :brand_primary_color
         WHERE id = :id'
    );
    $statement->execute([
        ':name' => $name,
        ':working_hour_start' => $workingHourStart,
        ':working_hour_end' => $workingHourEnd,
        ':brand_primary_color' => $primaryColor,
        ':id' => $companyId,
    ]);

    jsonResponse(getCompanyContext($pdo, $user));
}

if ($action === 'updateSubscription') {
    jsonResponse(['message' => 'La suscripcion solo puede ser actualizada por el superadmin.'], 403);
}

jsonResponse(['message' => 'Accion no permitida.'], 422);
