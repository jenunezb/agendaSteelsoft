<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$companyId = isset($_GET['companyId']) ? (int) $_GET['companyId'] : 0;

if ($companyId <= 0) {
    http_response_code(404);
    exit;
}

$pdo = getConnection();
$statement = $pdo->prepare(
    'SELECT brand_logo_data, brand_logo_mime, brand_logo_updated_at
     FROM companies
     WHERE id = :id
     LIMIT 1'
);
$statement->execute([':id' => $companyId]);
$logo = $statement->fetch();

if (
    !is_array($logo)
    || !is_string($logo['brand_logo_data'] ?? null)
    || $logo['brand_logo_data'] === ''
    || !is_string($logo['brand_logo_mime'] ?? null)
) {
    http_response_code(404);
    exit;
}

$etag = '"' . sha1($companyId . '|' . (string) ($logo['brand_logo_updated_at'] ?? '')) . '"';

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $logo['brand_logo_mime']);
header('Content-Length: ' . strlen($logo['brand_logo_data']));
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
echo $logo['brand_logo_data'];
