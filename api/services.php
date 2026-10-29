<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$user = requireCompanyAdminUser();
$companyId = requireCurrentCompanyId($user);
$isIndependentAccount = ($user['accountType'] ?? '') === 'independent';
enforceIndependentProfileVisibility($pdo, $companyId);

if ($method === 'GET') {
    jsonResponse(getCompanyServices($pdo, $companyId));
}

$payload = getPayload();

if ($method === 'POST') {
    $name = trim((string) ($payload['name'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));
    $durationMinutes = (int) ($payload['durationMinutes'] ?? 0);
    $price = (float) ($payload['price'] ?? 0);
    $roleId = (int) ($payload['roleId'] ?? 0);
    $active = !array_key_exists('active', $payload) || !empty($payload['active']);

    if ($name === '') {
        jsonResponse(['message' => 'El nombre del servicio es obligatorio.'], 422);
    }

    if ($durationMinutes < 15 || $durationMinutes > 480) {
        jsonResponse(['message' => 'La duración del servicio debe estar entre 15 y 480 minutos.'], 422);
    }

    if (!is_finite($price) || $price < 0) {
        jsonResponse(['message' => 'El precio del servicio no puede ser negativo.'], 422);
    }

    if (!$isIndependentAccount && ($roleId <= 0 || findServiceRoleById($pdo, $companyId, $roleId) === null)) {
        jsonResponse(['message' => 'Selecciona una especialidad válida para el servicio.'], 422);
    }

    $statement = $pdo->prepare(
        'INSERT INTO services (company_id, role_id, name, duration_minutes, price, description, active)
         VALUES (:company_id, :role_id, :name, :duration_minutes, :price, :description, :active)'
    );
    $statement->execute([
        ':company_id' => $companyId,
        ':role_id' => $isIndependentAccount ? null : $roleId,
        ':name' => $name,
        ':duration_minutes' => $durationMinutes,
        ':price' => $price,
        ':description' => $description,
        ':active' => $active ? 1 : 0,
    ]);

    enforceIndependentProfileVisibility($pdo, $companyId);
    $savedService = findServiceById($pdo, $companyId, (int) $pdo->lastInsertId());
    jsonResponse($savedService, 201);
}

if ($method === 'PUT') {
    $id = getRequiredId();
    $existingService = findServiceById($pdo, $companyId, $id);

    if ($existingService === null) {
        jsonResponse(['message' => 'Servicio no encontrado.'], 404);
    }

    $name = trim((string) ($payload['name'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));
    $durationMinutes = (int) ($payload['durationMinutes'] ?? 0);
    $price = (float) ($payload['price'] ?? 0);
    $roleId = (int) ($payload['roleId'] ?? 0);
    $active = !array_key_exists('active', $payload) || !empty($payload['active']);

    if ($name === '') {
        jsonResponse(['message' => 'El nombre del servicio es obligatorio.'], 422);
    }

    if ($durationMinutes < 15 || $durationMinutes > 480) {
        jsonResponse(['message' => 'La duración del servicio debe estar entre 15 y 480 minutos.'], 422);
    }

    if (!is_finite($price) || $price < 0) {
        jsonResponse(['message' => 'El precio del servicio no puede ser negativo.'], 422);
    }

    if (!$isIndependentAccount && ($roleId <= 0 || findServiceRoleById($pdo, $companyId, $roleId) === null)) {
        jsonResponse(['message' => 'Selecciona una especialidad válida para el servicio.'], 422);
    }

    $statement = $pdo->prepare(
        'UPDATE services
         SET role_id = :role_id,
             name = :name,
             duration_minutes = :duration_minutes,
             price = :price,
             description = :description,
             active = :active
         WHERE id = :id AND company_id = :company_id'
    );
    $statement->execute([
        ':role_id' => $isIndependentAccount ? null : $roleId,
        ':name' => $name,
        ':duration_minutes' => $durationMinutes,
        ':price' => $price,
        ':description' => $description,
        ':active' => $active ? 1 : 0,
        ':id' => $id,
        ':company_id' => $companyId,
    ]);

    enforceIndependentProfileVisibility($pdo, $companyId);
    $savedService = findServiceById($pdo, $companyId, $id);
    jsonResponse($savedService);
}

if ($method === 'DELETE') {
    $id = getRequiredId();
    $existingService = findServiceById($pdo, $companyId, $id);

    if ($existingService === null) {
        jsonResponse(['message' => 'Servicio no encontrado.'], 404);
    }

    $statement = $pdo->prepare('DELETE FROM services WHERE id = :id AND company_id = :company_id');
    $statement->execute([
        ':id' => $id,
        ':company_id' => $companyId,
    ]);

    enforceIndependentProfileVisibility($pdo, $companyId);

    jsonResponse(['success' => true]);
}

jsonResponse(['message' => 'Método no permitido.'], 405);
