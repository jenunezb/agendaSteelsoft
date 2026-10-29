<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
enforceIndependentProfileVisibility($pdo);

if ($method === 'GET') {
    $user = getAuthenticatedUser();

    if ($user !== null && empty($user['isSystemAdmin'])) {
        if ((int) ($user['companyId'] ?? 0) <= 0) {
            initializeDefaultCompanyForUser($pdo, (int) $user['id'], (string) $user['name'], (string) $user['username']);
            $user = getAuthenticatedUser();
        }
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

    if (!in_array($action, ['updateProfile', 'updateNotifications', 'updateIndependentAccount'], true)) {
        jsonResponse(['message' => 'Acción no permitida.'], 422);
    }

    if ($action === 'updateIndependentAccount') {
        if (($user['accountType'] ?? '') !== 'independent') {
            jsonResponse(['message' => 'Esta configuración solo está disponible para independientes.'], 403);
        }

        $name = trim((string) ($payload['name'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            jsonResponse(['message' => 'El nombre debe tener entre 2 y 100 caracteres.'], 422);
        }

        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('UPDATE users SET name = :name WHERE id = :id');
            $statement->execute([':name' => $name, ':id' => $user['id']]);

            $companyStatement = $pdo->prepare('UPDATE companies SET name = :name WHERE id = :id AND account_type = "independent"');
            $companyStatement->execute([':name' => $name, ':id' => $user['companyId']]);

            $activityStatement = $pdo->prepare('UPDATE activities SET assignee = :name WHERE user_id = :user_id');
            $activityStatement->execute([':name' => $name, ':user_id' => $user['id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['message' => 'No fue posible actualizar el perfil independiente.'], 500);
        }
    }

    if ($action === 'updateProfile') {
        $profilePublic = !empty($payload['profilePublic']) ? 1 : 0;

        if (
            $profilePublic === 1 &&
            ($user['accountType'] ?? '') === 'independent' &&
            getCompanyServices($pdo, (int) ($user['companyId'] ?? 0), true) === []
        ) {
            jsonResponse(['message' => 'Crea al menos un servicio activo antes de publicar tu agenda.'], 422);
        }

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
        $whatsappNumber = normalizeWhatsappNumber((string) ($payload['whatsappNumber'] ?? ''));
        $whatsappNotificationsEnabled = !empty($payload['whatsappNotificationsEnabled']) ? 1 : 0;

        if ($whatsappNotificationsEnabled && $whatsappNumber === '') {
            jsonResponse(['message' => 'Debes indicar un número de WhatsApp para activar notificaciones.'], 422);
        }

        $statement = $pdo->prepare(
            'UPDATE users
             SET whatsapp_number = :whatsapp_number,
                 whatsapp_notifications_enabled = :whatsapp_notifications_enabled
             WHERE id = :id'
        );
        $statement->execute([
            ':whatsapp_number' => $whatsappNumber,
            ':whatsapp_notifications_enabled' => $whatsappNotificationsEnabled,
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
    jsonResponse(['message' => 'Método no permitido.'], 405);
}

$payload = getPayload();
$action = (string) ($payload['action'] ?? '');

if ($action === 'login') {
    $username = strtolower(trim((string) ($payload['username'] ?? '')));
    $password = (string) ($payload['password'] ?? '');
    $user = findUserByUsername($pdo, $username);

    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        jsonResponse(['message' => 'Usuario o contraseña incorrectos.'], 422);
    }

    if (empty($user['is_system_admin']) && empty($user['email_verified_at'])) {
        jsonResponse([
            'message' => 'Debes verificar tu correo electrónico antes de iniciar sesión.',
            'requiresEmailVerification' => true,
        ], 403);
    }

    $_SESSION['user_id'] = (int) $user['id'];

    if (empty($user['is_system_admin']) && (int) ($user['company_id'] ?? 0) <= 0) {
        initializeDefaultCompanyForUser($pdo, (int) $user['id'], (string) $user['name'], (string) $user['username']);
    }

    $authenticatedUser = getAuthenticatedUser();

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
    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    $password = (string) ($payload['password'] ?? '');
    $companyName = trim((string) ($payload['companyName'] ?? ''));
    $accountType = (string) ($payload['accountType'] ?? 'business');

    if ($name === '' || $username === '' || $email === '' || $password === '') {
        jsonResponse(['message' => 'Nombre, usuario, correo y contraseña son obligatorios.'], 422);
    }

    if (findUserByUsername($pdo, $username) !== null) {
        jsonResponse(['message' => 'Ese nombre de usuario ya existe.'], 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['message' => 'Ingresa un correo electrónico válido.'], 422);
    }

    if (findUserByEmail($pdo, $email) !== null) {
        jsonResponse(['message' => 'Ese correo electrónico ya está registrado.'], 422);
    }

    if (!in_array($accountType, ['business', 'independent'], true)) {
        jsonResponse(['message' => 'Tipo de cuenta inválido.'], 422);
    }

    $resolvedCompanyName = $companyName !== ''
        ? $companyName
        : ($accountType === 'independent' ? $name : sprintf('Empresa de %s', $name));

    $pdo->beginTransaction();
    $userId = 0;
    $emailDeliveryIssues = [];

    try {
        $statement = $pdo->prepare(
            'INSERT INTO users (name, username, email, password_hash, profile_public)
             VALUES (:name, :username, :email, :password_hash, 1)'
        );
        $statement->execute([
            ':name' => $name,
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $userId = (int) $pdo->lastInsertId();
        initializeDefaultCompanyForUser($pdo, $userId, $name, $username, $resolvedCompanyName, $accountType, 'free');
        $token = generateEmailVerificationToken($pdo, $userId);
        $pdo->commit();

        try {
            sendVerificationEmail($email, $name, $token);
        } catch (Throwable $exception) {
            $emailDeliveryIssues[] = 'verification_email';
            writeAppLog('mail', 'No fue posible enviar el correo de verificación.', [
                'user_id' => $userId,
                'email' => $email,
                'exception' => $exception->getMessage(),
            ]);
        }

        try {
            notifySystemAdminOfRegistration($accountType, $resolvedCompanyName, $name, $email);
        } catch (Throwable $exception) {
            $emailDeliveryIssues[] = 'system_admin_notification';
            writeAppLog('mail', 'No fue posible notificar al administrador del sistema.', [
                'user_id' => $userId,
                'email' => $email,
                'exception' => $exception->getMessage(),
            ]);
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        writeAppLog('register', 'Falló el registro de una nueva cuenta.', [
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'company_name' => $resolvedCompanyName,
            'account_type' => $accountType,
            'exception' => $exception->getMessage(),
        ]);

        jsonResponse([
            'message' => 'No fue posible completar el registro. Revisa api/logs/app.log en el servidor.',
        ], 500);
    }

    $responseMessage = 'Registro creado. Revisa tu correo para validar la cuenta antes de iniciar sesión.';

    if (in_array('verification_email', $emailDeliveryIssues, true)) {
        $responseMessage = 'La cuenta fue creada, pero falló el envío del correo de verificación. Revisa api/logs/app.log y la configuración SMTP.';
    } elseif (in_array('system_admin_notification', $emailDeliveryIssues, true)) {
        $responseMessage = 'La cuenta fue creada y el correo de verificación fue enviado, pero falló la notificación al administrador.';
    }

    jsonResponse([
        'authenticated' => false,
        'user' => null,
        'canRegister' => true,
        'requiresEmailVerification' => true,
        'message' => $responseMessage,
    ], 201);
}

if ($action === 'verifyEmail') {
    $token = trim((string) ($payload['token'] ?? ''));

    if ($token === '') {
        jsonResponse(['success' => false, 'message' => 'Token de verificación inválido.'], 422);
    }

    $verificationResult = verifyEmailToken($pdo, $token);

    if ($verificationResult === null) {
        jsonResponse(['success' => false, 'message' => 'El token de verificación no es válido.'], 422);
    }

    if (!empty($verificationResult['expired'])) {
        jsonResponse(['success' => false, 'message' => 'El token de verificación ya venció.'], 422);
    }

    jsonResponse([
        'success' => true,
        'message' => 'Correo verificado correctamente. Ya puedes iniciar sesión.',
    ]);
}

jsonResponse(['message' => 'Acción no permitida.'], 422);
