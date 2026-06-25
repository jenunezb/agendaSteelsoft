<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

requireSystemAdminUser();

if ($method !== 'GET') {
    jsonResponse(['message' => 'Metodo no permitido.'], 405);
}

jsonResponse(getSystemAccountSummaries($pdo));
