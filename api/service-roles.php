<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    $pdo = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    $user = requireCompanyAdminUser();
    $companyId = requireCurrentCompanyId($user);

    if ($method === 'GET') {
        jsonResponse(getCompanyServiceRoles($pdo, $companyId));
    }

    $payload = getPayload();

    if ($method === 'POST') {
        $name = trim((string) ($payload['name'] ?? ''));
        $active = !array_key_exists('active', $payload) || !empty($payload['active']);

        if ($name === '') {
            jsonResponse(['message' => 'El nombre de la especialidad es obligatorio.'], 422);
        }

        $statement = $pdo->prepare(
            'INSERT INTO service_roles (company_id, name, active)
             VALUES (:company_id, :name, :active)'
        );
        $statement->execute([
            ':company_id' => $companyId,
            ':name' => $name,
            ':active' => $active ? 1 : 0,
        ]);

        jsonResponse([
            'id' => (int) $pdo->lastInsertId(),
            'name' => $name,
            'active' => $active,
        ], 201);
    }

    if ($method === 'PUT') {
        $id = getRequiredId();
        $existingRole = findServiceRoleById($pdo, $companyId, $id);

        if ($existingRole === null) {
            jsonResponse(['message' => 'Especialidad no encontrada.'], 404);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $active = !array_key_exists('active', $payload) || !empty($payload['active']);

        if ($name === '') {
            jsonResponse(['message' => 'El nombre de la especialidad es obligatorio.'], 422);
        }

        $statement = $pdo->prepare(
            'UPDATE service_roles
             SET name = :name, active = :active
             WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute([
            ':name' => $name,
            ':active' => $active ? 1 : 0,
            ':id' => $id,
            ':company_id' => $companyId,
        ]);

        jsonResponse([
            'id' => $id,
            'name' => $name,
            'active' => $active,
        ]);
    }

    if ($method === 'DELETE') {
        $id = getRequiredId();
        $existingRole = findServiceRoleById($pdo, $companyId, $id);

        if ($existingRole === null) {
            jsonResponse(['message' => 'Especialidad no encontrada.'], 404);
        }

        $serviceCountStatement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM services
             WHERE company_id = :company_id
               AND role_id = :role_id'
        );
        $serviceCountStatement->execute([
            ':company_id' => $companyId,
            ':role_id' => $id,
        ]);

        if ((int) $serviceCountStatement->fetchColumn() > 0) {
            jsonResponse(['message' => 'No puedes eliminar una especialidad con servicios asociados.'], 422);
        }

        $pdo->prepare('DELETE FROM professional_roles WHERE role_id = :role_id')->execute([
            ':role_id' => $id,
        ]);

        $statement = $pdo->prepare('DELETE FROM service_roles WHERE id = :id AND company_id = :company_id');
        $statement->execute([
            ':id' => $id,
            ':company_id' => $companyId,
        ]);

        jsonResponse(['success' => true]);
    }

    jsonResponse(['message' => 'Metodo no permitido.'], 405);
} catch (Throwable $exception) {
    writeAppLog('service_roles', 'Error en service-roles.php', [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);

    jsonResponse([
        'message' => 'Error interno al guardar la especialidad.',
        'debug' => $exception->getMessage(),
    ], 500);
}
