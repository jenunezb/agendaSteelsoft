<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = getAuthenticatedUser();

    if ($user !== null) {
        claimLegacyRecordsForUser($pdo, $user);
    }

    jsonResponse([
        'authenticated' => $user !== null,
        'user' => $user,
        'canRegister' => true,
    ]);
}

if ($method === 'DELETE') {
    $_SESSION = [];

    if (session_id() !== '') {
        session_destroy();
    }

    jsonResponse(['success' => true]);
}

if ($method === 'PUT') {
    $user = requireAuthenticatedUser();
    $payload = getPayload();
    $action = (string) ($payload['action'] ?? '');

    if ($action !== 'updateProfile' && $action !== 'updateNotifications') {
        jsonResponse(['message' => 'Accion no permitida.'], 422);
    }

    if ($action === 'updateProfile') {
        $profilePublic = !empty($payload['profilePublic']) ? 1 : 0;
        $statement = $pdo->prepare(
            'UPDATE users
             SET profile_public = :profile_public
             WHERE id = :id'
        );
        $statement->execute([
            ':profile_public' => $profilePublic,
            ':id' => $user['id'],
        ]);
    }

    if ($action === 'updateNotifications') {
        $telegramChatId = normalizeTelegramChatId((string) ($payload['telegramChatId'] ?? ''));
        $telegramNotificationsEnabled = !empty($payload['telegramNotificationsEnabled']) ? 1 : 0;

        if ($telegramNotificationsEnabled && $telegramChatId === '') {
            jsonResponse(['message' => 'Debes indicar un chat ID de Telegram para activar notificaciones.'], 422);
        }

        $statement = $pdo->prepare(
            'UPDATE users
             SET telegram_chat_id = :telegram_chat_id,
                 telegram_notifications_enabled = :telegram_notifications_enabled
             WHERE id = :id'
        );
        $statement->execute([
            ':telegram_chat_id' => $telegramChatId,
            ':telegram_notifications_enabled' => $telegramNotificationsEnabled,
            ':id' => $user['id'],
        ]);
    }

    $refreshedUser = getAuthenticatedUser();

    jsonResponse([
        'authenticated' => $refreshedUser !== null,
        'user' => $refreshedUser,
        'canRegister' => true,
    ]);
}

if ($method !== 'POST') {
    jsonResponse(['message' => 'Metodo no permitido.'], 405);
}

$payload = getPayload();
$action = (string) ($payload['action'] ?? '');

if ($action === 'login') {
    $username = strtolower(trim((string) ($payload['username'] ?? '')));
    $password = (string) ($payload['password'] ?? '');
    $user = findUserByUsername($pdo, $username);

    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        jsonResponse(['message' => 'Usuario o contrasena incorrectos.'], 422);
    }

    $_SESSION['user_id'] = (int) $user['id'];

    $authenticatedUser = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'username' => $user['username'],
        'profilePublic' => !empty($user['profile_public']),
        'publicUrl' => buildPublicProfileUrl((string) $user['username']),
        'whatsappNumber' => (string) ($user['whatsapp_number'] ?? ''),
        'whatsappNotificationsEnabled' => !empty($user['whatsapp_notifications_enabled']),
        'telegramChatId' => (string) ($user['telegram_chat_id'] ?? ''),
        'telegramNotificationsEnabled' => !empty($user['telegram_notifications_enabled']),
    ];

    claimLegacyRecordsForUser($pdo, $authenticatedUser);

    jsonResponse([
        'authenticated' => true,
        'user' => $authenticatedUser,
        'canRegister' => true,
    ]);
}

if ($action === 'register') {
    $name = trim((string) ($payload['name'] ?? ''));
    $username = strtolower(trim((string) ($payload['username'] ?? '')));
    $password = (string) ($payload['password'] ?? '');

    if ($name === '' || $username === '' || $password === '') {
        jsonResponse(['message' => 'Nombre, usuario y contrasena son obligatorios.'], 422);
    }

    if (findUserByUsername($pdo, $username) !== null) {
        jsonResponse(['message' => 'Ese nombre de usuario ya existe.'], 422);
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (name, username, password_hash)
         VALUES (:name, :username, :password_hash)'
    );
    $statement->execute([
        ':name' => $name,
        ':username' => $username,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    $userId = (int) $pdo->lastInsertId();
    $_SESSION['user_id'] = $userId;

    jsonResponse([
        'authenticated' => true,
        'user' => [
            'id' => $userId,
            'name' => $name,
            'username' => $username,
            'profilePublic' => false,
            'publicUrl' => buildPublicProfileUrl($username),
            'whatsappNumber' => '',
            'whatsappNotificationsEnabled' => false,
            'telegramChatId' => '',
            'telegramNotificationsEnabled' => false,
        ],
        'canRegister' => true,
    ], 201);
}

jsonResponse(['message' => 'Accion no permitida.'], 422);
