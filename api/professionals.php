<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$user = requireCompanyAdminUser();
$companyId = requireCurrentCompanyId($user);

if ($method === 'GET') {
    jsonResponse(getCompanyProfessionals($pdo, $companyId));
}

$payload = getPayload();

if ($method === 'POST') {
    $name = trim((string) ($payload['name'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $phone = trim((string) ($payload['phone'] ?? ''));
    $active = !array_key_exists('active', $payload) || !empty($payload['active']);

    if ($name === '') {
        jsonResponse(['message' => 'El nombre del profesional es obligatorio.'], 422);
    }

    ensureProfessionalLimitNotExceeded($pdo, $companyId, $active);

    $statement = $pdo->prepare(
        'INSERT INTO professionals (company_id, name, email, phone, active)
         VALUES (:company_id, :name, :email, :phone, :active)'
    );
    $statement->execute([
        ':company_id' => $companyId,
        ':name' => $name,
        ':email' => $email,
        ':phone' => $phone,
        ':active' => $active ? 1 : 0,
    ]);

    $professionalId = (int) $pdo->lastInsertId();
    $accountAccess = null;

    if ($email !== '') {
        $accountAccess = createOrRefreshProfessionalAccess($pdo, $companyId, $professionalId, $name, $email);

        try {
            sendProfessionalInvitationEmail(
                $email,
                $name,
                $accountAccess['username'],
                $accountAccess['password'],
                $accountAccess['token']
            );
        } catch (Throwable $exception) {
            writeAppLog('mail', 'No fue posible enviar la invitacion del profesional.', [
                'professional_id' => $professionalId,
                'email' => $email,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    jsonResponse([
        'id' => $professionalId,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'active' => $active,
        'linkedUserId' => $accountAccess['userId'] ?? null,
        'username' => $accountAccess['username'] ?? '',
        'emailVerified' => false,
    ], 201);
}

if ($method === 'PUT') {
    $id = getRequiredId();
    $existingProfessional = findProfessionalById($pdo, $companyId, $id);

    if ($existingProfessional === null) {
        jsonResponse(['message' => 'Profesional no encontrado.'], 404);
    }

    $name = trim((string) ($payload['name'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $phone = trim((string) ($payload['phone'] ?? ''));
    $active = !array_key_exists('active', $payload) || !empty($payload['active']);

    if ($name === '') {
        jsonResponse(['message' => 'El nombre del profesional es obligatorio.'], 422);
    }

    ensureProfessionalLimitNotExceeded($pdo, $companyId, $active, $id);

    $statement = $pdo->prepare(
        'UPDATE professionals
         SET name = :name, email = :email, phone = :phone, active = :active
         WHERE id = :id AND company_id = :company_id'
    );
    $statement->execute([
        ':name' => $name,
        ':email' => $email,
        ':phone' => $phone,
        ':active' => $active ? 1 : 0,
        ':id' => $id,
        ':company_id' => $companyId,
    ]);

    $linkedUserId = isset($existingProfessional['linked_user_id']) ? (int) $existingProfessional['linked_user_id'] : 0;
    $linkedUsername = '';
    $emailVerified = false;

    if ($email !== '') {
        $accountAccess = createOrRefreshProfessionalAccess($pdo, $companyId, $id, $name, $email);
        $linkedUserId = (int) $accountAccess['userId'];
        $linkedUsername = (string) $accountAccess['username'];

        try {
            sendProfessionalInvitationEmail(
                $email,
                $name,
                $accountAccess['username'],
                $accountAccess['password'],
                $accountAccess['token']
            );
        } catch (Throwable $exception) {
            writeAppLog('mail', 'No fue posible reenviar la invitacion del profesional.', [
                'professional_id' => $id,
                'email' => $email,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    jsonResponse([
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'active' => $active,
        'linkedUserId' => $linkedUserId > 0 ? $linkedUserId : null,
        'username' => $linkedUsername,
        'emailVerified' => $emailVerified,
    ]);
}

if ($method === 'DELETE') {
    $id = getRequiredId();
    $existingProfessional = findProfessionalById($pdo, $companyId, $id);

    if ($existingProfessional === null) {
        jsonResponse(['message' => 'Profesional no encontrado.'], 404);
    }

    $linkedUserId = isset($existingProfessional['linked_user_id']) ? (int) $existingProfessional['linked_user_id'] : 0;

    if ($linkedUserId > 0) {
        $pdo->prepare('DELETE FROM users WHERE id = :id AND professional_id = :professional_id')->execute([
            ':id' => $linkedUserId,
            ':professional_id' => $id,
        ]);
    }

    $statement = $pdo->prepare('DELETE FROM professionals WHERE id = :id AND company_id = :company_id');
    $statement->execute([
        ':id' => $id,
        ':company_id' => $companyId,
    ]);

    if ($statement->rowCount() === 0) {
        jsonResponse(['message' => 'Profesional no encontrado.'], 404);
    }

    jsonResponse(['success' => true]);
}

jsonResponse(['message' => 'Metodo no permitido.'], 405);
