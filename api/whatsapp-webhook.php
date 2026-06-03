<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = require __DIR__ . '/config.php';
$verifyToken = (string) getenv('WHATSAPP_WEBHOOK_VERIFY_TOKEN');

if ($verifyToken === '') {
    $verifyToken = (string) ($config['whatsapp_webhook_verify_token'] ?? '');
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'GET') {
    $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');

    if ($mode !== 'subscribe') {
        jsonResponse(['message' => 'Modo de verificacion invalido.'], 400);
    }

    if ($verifyToken === '') {
        jsonResponse(['message' => 'Falta configurar WHATSAPP_WEBHOOK_VERIFY_TOKEN.'], 500);
    }

    if (!hash_equals($verifyToken, $token)) {
        jsonResponse(['message' => 'Token de verificacion invalido.'], 403);
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    exit;
}

if ($requestMethod === 'POST') {
    $rawBody = file_get_contents('php://input');

    if ($rawBody !== false && $rawBody !== '') {
        $logDir = __DIR__ . '/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $logLine = sprintf("[%s] %s%s", date('c'), $rawBody, PHP_EOL);
        file_put_contents($logDir . '/whatsapp-webhook.log', $logLine, FILE_APPEND | LOCK_EX);
    }

    jsonResponse(['received' => true]);
}

jsonResponse(['message' => 'Metodo no permitido.'], 405);
