<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

requireSystemAdminUser();

if ($method === 'GET') {
    jsonResponse(getSystemAccountSummaries($pdo));
}

if ($method === 'DELETE') {
    $companyId = (int) ($_GET['companyId'] ?? 0);

    if ($companyId <= 0) {
        jsonResponse(['message' => 'Empresa invalida.'], 422);
    }

    $companyStatement = $pdo->prepare('SELECT id FROM companies WHERE id = :id LIMIT 1');
    $companyStatement->execute([':id' => $companyId]);

    if (!$companyStatement->fetch()) {
        jsonResponse(['message' => 'La cuenta seleccionada no existe.'], 404);
    }

    $systemAdminStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM users WHERE company_id = :company_id AND is_system_admin = 1'
    );
    $systemAdminStatement->execute([':company_id' => $companyId]);

    if ((int) $systemAdminStatement->fetchColumn() > 0) {
        jsonResponse(['message' => 'La cuenta del administrador del sistema no se puede eliminar.'], 403);
    }

    try {
        $pdo->beginTransaction();

        $deleteStatements = [
            'DELETE FROM whatsapp_notifications WHERE activity_id IN (SELECT id FROM activities WHERE company_id = :company_id)',
            'DELETE FROM professional_roles WHERE professional_id IN (SELECT id FROM professionals WHERE company_id = :company_id)',
            'DELETE FROM activities WHERE company_id = :company_id',
            'DELETE FROM personal_reminders WHERE user_id IN (SELECT id FROM users WHERE company_id = :company_id)',
            'DELETE FROM general_pendings WHERE company_id = :company_id',
            'DELETE FROM financial_entries WHERE company_id = :company_id',
            'DELETE FROM services WHERE company_id = :company_id',
            'DELETE FROM service_roles WHERE company_id = :company_id',
            'DELETE FROM users WHERE company_id = :company_id',
            'DELETE FROM professionals WHERE company_id = :company_id',
            'DELETE FROM company_subscriptions WHERE company_id = :company_id',
            'DELETE FROM companies WHERE id = :company_id',
        ];

        foreach ($deleteStatements as $sql) {
            $statement = $pdo->prepare($sql);
            $statement->execute([':company_id' => $companyId]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        writeAppLog('admin', 'No fue posible eliminar la cuenta.', [
            'company_id' => $companyId,
            'error' => $error->getMessage(),
        ]);
        jsonResponse(['message' => 'No fue posible eliminar la cuenta seleccionada.'], 500);
    }

    jsonResponse(getSystemAccountSummaries($pdo));
}

if ($method !== 'PUT') {
    jsonResponse(['message' => 'Metodo no permitido.'], 405);
}

$payload = getPayload();
$companyId = (int) ($payload['companyId'] ?? 0);
$companyStatus = (string) ($payload['companyStatus'] ?? 'active');
$planName = trim((string) ($payload['planName'] ?? ''));
$planCode = strtolower(trim((string) ($payload['planCode'] ?? '')));
$subscriptionStatus = (string) ($payload['subscriptionStatus'] ?? 'active');
$monthlyPrice = round((float) ($payload['monthlyPrice'] ?? 0), 2);
$professionalLimit = max(4, (int) ($payload['professionalLimit'] ?? 4));
$renewalDay = isset($payload['renewalDay']) && $payload['renewalDay'] !== '' ? (int) $payload['renewalDay'] : null;

if ($companyId <= 0) {
    jsonResponse(['message' => 'Empresa invalida.'], 422);
}

if (!in_array($companyStatus, ['active', 'inactive', 'suspended'], true)) {
    jsonResponse(['message' => 'Estado de empresa invalido.'], 422);
}

if ($planName === '' || $planCode === '') {
    jsonResponse(['message' => 'El plan debe incluir nombre y codigo.'], 422);
}

if (!in_array($subscriptionStatus, ['active', 'trial', 'suspended', 'cancelled'], true)) {
    jsonResponse(['message' => 'Estado de suscripcion invalido.'], 422);
}

if ($monthlyPrice < 0) {
    jsonResponse(['message' => 'El precio mensual no puede ser negativo.'], 422);
}

if ($renewalDay !== null && ($renewalDay < 1 || $renewalDay > 31)) {
    jsonResponse(['message' => 'El dia de renovacion debe estar entre 1 y 31.'], 422);
}

if (countActiveProfessionals($pdo, $companyId) > $professionalLimit) {
    jsonResponse(['message' => 'No puedes bajar el cupo por debajo de los profesionales activos.'], 422);
}

$subscription = getCurrentSubscription($pdo, $companyId);

if ($subscription === null) {
    jsonResponse(['message' => 'Suscripcion no encontrada para esta empresa.'], 404);
}

$companyStatement = $pdo->prepare(
    'UPDATE companies
     SET status = :status
     WHERE id = :id'
);
$companyStatement->execute([
    ':status' => $companyStatus,
    ':id' => $companyId,
]);

$subscriptionStatement = $pdo->prepare(
    'UPDATE company_subscriptions
     SET plan_name = :plan_name,
         plan_code = :plan_code,
         status = :status,
         monthly_price = :monthly_price,
         professional_limit = :professional_limit,
         renewal_day = :renewal_day
     WHERE id = :id'
);
$subscriptionStatement->execute([
    ':plan_name' => $planName,
    ':plan_code' => $planCode,
    ':status' => $subscriptionStatus,
    ':monthly_price' => $monthlyPrice,
    ':professional_limit' => $professionalLimit,
    ':renewal_day' => $renewalDay,
    ':id' => (int) $subscription['id'],
]);

jsonResponse(getSystemAccountSummaries($pdo));
