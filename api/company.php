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
    $status = (string) ($payload['status'] ?? 'active');

    if ($name === '') {
        jsonResponse(['message' => 'El nombre de la empresa es obligatorio.'], 422);
    }

    if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
        jsonResponse(['message' => 'Estado de empresa invalido.'], 422);
    }

    $statement = $pdo->prepare(
        'UPDATE companies
         SET name = :name, status = :status
         WHERE id = :id'
    );
    $statement->execute([
        ':name' => $name,
        ':status' => $status,
        ':id' => $companyId,
    ]);

    jsonResponse(getCompanyContext($pdo, $user));
}

if ($action === 'updateSubscription') {
    $subscription = getCurrentSubscription($pdo, $companyId);

    if ($subscription === null) {
        jsonResponse(['message' => 'Suscripcion no encontrada.'], 404);
    }

    $planCode = strtolower(trim((string) ($payload['planCode'] ?? 'basic')));
    $planName = trim((string) ($payload['planName'] ?? 'Basico empresarial'));
    $status = (string) ($payload['status'] ?? 'active');
    $monthlyPrice = round((float) ($payload['monthlyPrice'] ?? 0), 2);
    $professionalLimit = max(4, (int) ($payload['professionalLimit'] ?? 4));
    $renewalDay = isset($payload['renewalDay']) && $payload['renewalDay'] !== '' ? (int) $payload['renewalDay'] : null;

    if ($planCode === '' || $planName === '') {
        jsonResponse(['message' => 'Plan invalido.'], 422);
    }

    if (!in_array($status, ['active', 'trial', 'suspended', 'cancelled'], true)) {
        jsonResponse(['message' => 'Estado de suscripcion invalido.'], 422);
    }

    if ($monthlyPrice < 0) {
        jsonResponse(['message' => 'El valor mensual no puede ser negativo.'], 422);
    }

    if ($renewalDay !== null && ($renewalDay < 1 || $renewalDay > 31)) {
        jsonResponse(['message' => 'El dia de renovacion debe estar entre 1 y 31.'], 422);
    }

    if (countActiveProfessionals($pdo, $companyId) > $professionalLimit) {
        jsonResponse(['message' => 'No puedes bajar el cupo por debajo de los profesionales activos.'], 422);
    }

    $statement = $pdo->prepare(
        'UPDATE company_subscriptions
         SET plan_code = :plan_code,
             plan_name = :plan_name,
             status = :status,
             monthly_price = :monthly_price,
             professional_limit = :professional_limit,
             renewal_day = :renewal_day
         WHERE id = :id'
    );
    $statement->execute([
        ':plan_code' => $planCode,
        ':plan_name' => $planName,
        ':status' => $status,
        ':monthly_price' => $monthlyPrice,
        ':professional_limit' => $professionalLimit,
        ':renewal_day' => $renewalDay,
        ':id' => (int) $subscription['id'],
    ]);

    jsonResponse(getCompanyContext($pdo, $user));
}

jsonResponse(['message' => 'Accion no permitida.'], 422);
