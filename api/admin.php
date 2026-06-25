<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

requireSystemAdminUser();

if ($method === 'GET') {
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
