<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuthenticatedUser();
requireCompanyAdminUser();
$companyId = requireCurrentCompanyId($user);

if ($method === 'DELETE') {
    $statement = $pdo->prepare(
        'UPDATE companies
         SET brand_logo_data = NULL,
             brand_logo_mime = NULL,
             brand_logo_updated_at = NULL
         WHERE id = :id'
    );
    $statement->execute([':id' => $companyId]);
    jsonResponse(getCompanyContext($pdo, $user));
}

if ($method !== 'POST') {
    jsonResponse(['message' => 'Metodo no permitido.'], 405);
}

$file = $_FILES['logo'] ?? null;

if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jsonResponse(['message' => 'Selecciona un logo valido.'], 422);
}

$size = (int) ($file['size'] ?? 0);
$temporaryPath = (string) ($file['tmp_name'] ?? '');

if ($size <= 0 || $size > 1_500_000 || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
    jsonResponse(['message' => 'El logo debe pesar maximo 1.5 MB.'], 422);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo !== false ? (string) finfo_file($finfo, $temporaryPath) : '';

if ($finfo !== false) {
    finfo_close($finfo);
}

$allowedMimeTypes = ['image/png', 'image/jpeg', 'image/webp'];

if (!in_array($mimeType, $allowedMimeTypes, true)) {
    jsonResponse(['message' => 'Usa un logo PNG, JPG o WebP.'], 422);
}

$imageInfo = @getimagesize($temporaryPath);

if (!is_array($imageInfo) || (int) ($imageInfo[0] ?? 0) <= 0 || (int) ($imageInfo[1] ?? 0) <= 0) {
    jsonResponse(['message' => 'El archivo no contiene una imagen valida.'], 422);
}

if ((int) $imageInfo[0] > 4000 || (int) $imageInfo[1] > 4000) {
    jsonResponse(['message' => 'El logo no puede superar 4000 x 4000 pixeles.'], 422);
}

$logoData = file_get_contents($temporaryPath);

if (!is_string($logoData) || $logoData === '') {
    jsonResponse(['message' => 'No fue posible leer el logo.'], 422);
}

$statement = $pdo->prepare(
    'UPDATE companies
     SET brand_logo_data = :logo_data,
         brand_logo_mime = :logo_mime,
         brand_logo_updated_at = NOW()
     WHERE id = :id'
);
$statement->bindValue(':logo_data', $logoData, PDO::PARAM_LOB);
$statement->bindValue(':logo_mime', $mimeType);
$statement->bindValue(':id', $companyId, PDO::PARAM_INT);
$statement->execute();

jsonResponse(getCompanyContext($pdo, $user));
