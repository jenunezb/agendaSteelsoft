<?php

declare(strict_types=1);

date_default_timezone_set((string) ((require __DIR__ . '/config.php')['app_timezone'] ?? 'America/Bogota'));

session_start();

header('Content-Type: application/json; charset=utf-8');
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonResponse(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function writeAppLog(string $channel, string $message, array $context = []): void
{
    $logDirectory = __DIR__ . '/logs';

    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0777, true);
    }

    $logFile = $logDirectory . '/app.log';
    $entry = [
        'timestamp' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'channel' => $channel,
        'message' => $message,
        'context' => $context,
    ];

    @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
}

function getPayload(): array
{
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || $rawBody === '') {
        return [];
    }

    $payload = json_decode($rawBody, true);
    return is_array($payload) ? $payload : [];
}

function getConnection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_name']
    );

    $pdo = new PDO(
        $dsn,
        $config['db_user'],
        $config['db_password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec(sprintf("SET time_zone = '%s'", getMysqlTimezoneOffset()));

    ensureSchema($pdo, $config['db_name']);

    return $pdo;
}

function getMysqlTimezoneOffset(): string
{
    $timezone = new DateTimeZone(date_default_timezone_get());
    $offsetSeconds = $timezone->getOffset(new DateTimeImmutable('now', $timezone));
    $sign = $offsetSeconds >= 0 ? '+' : '-';
    $offsetSeconds = abs($offsetSeconds);
    $hours = str_pad((string) intdiv($offsetSeconds, 3600), 2, '0', STR_PAD_LEFT);
    $minutes = str_pad((string) intdiv($offsetSeconds % 3600, 60), 2, '0', STR_PAD_LEFT);

    return sprintf('%s%s:%s', $sign, $hours, $minutes);
}

function getRequiredId(): int
{
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($id <= 0) {
        jsonResponse(['message' => 'Id invalido.'], 422);
    }

    return $id;
}

function ensureSchema(PDO $pdo, string $databaseName): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS companies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            slug VARCHAR(170) NOT NULL UNIQUE,
            account_type ENUM("business", "independent") NOT NULL DEFAULT "business",
            status ENUM("active", "inactive", "suspended") NOT NULL DEFAULT "active",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            username VARCHAR(100) NOT NULL UNIQUE,
            email VARCHAR(150) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            company_id INT UNSIGNED NULL,
            company_role VARCHAR(40) NOT NULL DEFAULT "owner",
            is_system_admin TINYINT(1) NOT NULL DEFAULT 0,
            email_verified_at DATETIME NULL,
            verification_token_hash VARCHAR(255) NULL,
            verification_token_expires_at DATETIME NULL,
            verification_sent_at DATETIME NULL,
            profile_public TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS plans (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            professional_limit SMALLINT UNSIGNED NOT NULL DEFAULT 4,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS company_subscriptions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            plan_id INT UNSIGNED NULL,
            plan_code VARCHAR(50) NOT NULL,
            plan_name VARCHAR(100) NOT NULL,
            status ENUM("active", "trial", "suspended", "cancelled") NOT NULL DEFAULT "active",
            monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            professional_limit SMALLINT UNSIGNED NOT NULL DEFAULT 4,
            started_at DATE NOT NULL,
            renewal_day TINYINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS professionals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(150) NOT NULL DEFAULT "",
            phone VARCHAR(30) NOT NULL DEFAULT "",
            linked_user_id INT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );

    ensureColumnExists(
        $pdo,
        $databaseName,
        'companies',
        'account_type',
        'ALTER TABLE companies ADD COLUMN account_type ENUM("business", "independent") NOT NULL DEFAULT "business" AFTER slug'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'email',
        'ALTER TABLE users ADD COLUMN email VARCHAR(150) NOT NULL DEFAULT "" AFTER username'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'company_id',
        'ALTER TABLE users ADD COLUMN company_id INT UNSIGNED NULL AFTER password_hash'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'company_role',
        'ALTER TABLE users ADD COLUMN company_role VARCHAR(40) NOT NULL DEFAULT "owner" AFTER company_id'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'is_system_admin',
        'ALTER TABLE users ADD COLUMN is_system_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER company_role'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'professional_id',
        'ALTER TABLE users ADD COLUMN professional_id INT UNSIGNED NULL AFTER company_role'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'email_verified_at',
        'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER is_system_admin'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'verification_token_hash',
        'ALTER TABLE users ADD COLUMN verification_token_hash VARCHAR(255) NULL AFTER email_verified_at'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'verification_token_expires_at',
        'ALTER TABLE users ADD COLUMN verification_token_expires_at DATETIME NULL AFTER verification_token_hash'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'verification_sent_at',
        'ALTER TABLE users ADD COLUMN verification_sent_at DATETIME NULL AFTER verification_token_expires_at'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'profile_public',
        'ALTER TABLE users ADD COLUMN profile_public TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'whatsapp_number',
        'ALTER TABLE users ADD COLUMN whatsapp_number VARCHAR(20) NOT NULL DEFAULT "" AFTER profile_public'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'whatsapp_notifications_enabled',
        'ALTER TABLE users ADD COLUMN whatsapp_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_number'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'telegram_chat_id',
        'ALTER TABLE users ADD COLUMN telegram_chat_id VARCHAR(30) NOT NULL DEFAULT "" AFTER whatsapp_notifications_enabled'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'telegram_notifications_enabled',
        'ALTER TABLE users ADD COLUMN telegram_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER telegram_chat_id'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'professionals',
        'linked_user_id',
        'ALTER TABLE professionals ADD COLUMN linked_user_id INT UNSIGNED NULL AFTER phone'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'activities',
        'user_id',
        'ALTER TABLE activities ADD COLUMN user_id INT UNSIGNED NULL AFTER id'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'activities',
        'company_id',
        'ALTER TABLE activities ADD COLUMN company_id INT UNSIGNED NULL AFTER user_id'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'activities',
        'professional_id',
        'ALTER TABLE activities ADD COLUMN professional_id INT UNSIGNED NULL AFTER company_id'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'activities',
        'is_public',
        'ALTER TABLE activities ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER assignee'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'activities',
        'reminder_minutes',
        'ALTER TABLE activities ADD COLUMN reminder_minutes SMALLINT UNSIGNED NULL AFTER description'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'activities',
        'reminder_sent_at',
        'ALTER TABLE activities ADD COLUMN reminder_sent_at DATETIME NULL AFTER reminder_minutes'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'general_pendings',
        'user_id',
        'ALTER TABLE general_pendings ADD COLUMN user_id INT UNSIGNED NULL AFTER id'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'general_pendings',
        'company_id',
        'ALTER TABLE general_pendings ADD COLUMN company_id INT UNSIGNED NULL AFTER user_id'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'general_pendings',
        'professional_id',
        'ALTER TABLE general_pendings ADD COLUMN professional_id INT UNSIGNED NULL AFTER company_id'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'financial_entries',
        'user_id',
        'ALTER TABLE financial_entries ADD COLUMN user_id INT UNSIGNED NULL AFTER id'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'financial_entries',
        'company_id',
        'ALTER TABLE financial_entries ADD COLUMN company_id INT UNSIGNED NULL AFTER user_id'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'financial_entries',
        'professional_id',
        'ALTER TABLE financial_entries ADD COLUMN professional_id INT UNSIGNED NULL AFTER company_id'
    );
    ensureColumnExists(
        $pdo,
        $databaseName,
        'financial_entries',
        'participation_percentage',
        'ALTER TABLE financial_entries ADD COLUMN participation_percentage DECIMAL(5,2) NULL AFTER amount'
    );

    seedDefaultPlans($pdo);
    ensureSystemAdminUser($pdo);

    ensureIndexExists($pdo, $databaseName, 'users', 'idx_users_company', 'CREATE INDEX idx_users_company ON users (company_id)');
    ensureIndexExists($pdo, $databaseName, 'users', 'idx_users_professional', 'CREATE INDEX idx_users_professional ON users (professional_id)');
    ensureIndexExists($pdo, $databaseName, 'professionals', 'idx_professionals_company_active', 'CREATE INDEX idx_professionals_company_active ON professionals (company_id, active, name)');
    ensureIndexExists($pdo, $databaseName, 'professionals', 'idx_professionals_linked_user', 'CREATE INDEX idx_professionals_linked_user ON professionals (linked_user_id)');
    ensureIndexExists($pdo, $databaseName, 'company_subscriptions', 'idx_company_subscriptions_company', 'CREATE INDEX idx_company_subscriptions_company ON company_subscriptions (company_id, status)');
    ensureIndexExists($pdo, $databaseName, 'activities', 'idx_activities_user_date', 'CREATE INDEX idx_activities_user_date ON activities (user_id, activity_date, start_time)');
    ensureIndexExists($pdo, $databaseName, 'activities', 'idx_activities_company_date', 'CREATE INDEX idx_activities_company_date ON activities (company_id, activity_date, start_time)');
    ensureIndexExists($pdo, $databaseName, 'general_pendings', 'idx_general_pendings_user_date', 'CREATE INDEX idx_general_pendings_user_date ON general_pendings (user_id, pending_date)');
    ensureIndexExists($pdo, $databaseName, 'general_pendings', 'idx_general_pendings_company_date', 'CREATE INDEX idx_general_pendings_company_date ON general_pendings (company_id, pending_date)');
    ensureIndexExists($pdo, $databaseName, 'financial_entries', 'idx_financial_entries_user_date', 'CREATE INDEX idx_financial_entries_user_date ON financial_entries (user_id, entry_date)');
    ensureIndexExists($pdo, $databaseName, 'financial_entries', 'idx_financial_entries_company_date', 'CREATE INDEX idx_financial_entries_company_date ON financial_entries (company_id, entry_date)');
}

function ensureColumnExists(
    PDO $pdo,
    string $databaseName,
    string $tableName,
    string $columnName,
    string $alterStatement
): void {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = :database_name
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $statement->execute([
        ':database_name' => $databaseName,
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    if ((int) $statement->fetchColumn() === 0) {
        $pdo->exec($alterStatement);
    }
}

function ensureIndexExists(
    PDO $pdo,
    string $databaseName,
    string $tableName,
    string $indexName,
    string $createStatement
): void {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = :database_name
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name'
    );
    $statement->execute([
        ':database_name' => $databaseName,
        ':table_name' => $tableName,
        ':index_name' => $indexName,
    ]);

    if ((int) $statement->fetchColumn() === 0) {
        $pdo->exec($createStatement);
    }
}

function seedDefaultPlans(PDO $pdo): void
{
    $statement = $pdo->prepare(
        'INSERT INTO plans (code, name, monthly_price, professional_limit, active)
         VALUES (:code, :name, :monthly_price, :professional_limit, 1)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            monthly_price = VALUES(monthly_price),
            professional_limit = VALUES(professional_limit),
            active = 1'
    );

    foreach ([
        ['code' => 'free', 'name' => 'Plan gratuito', 'monthly_price' => 0, 'professional_limit' => 4],
        ['code' => 'basic', 'name' => 'Basico empresarial', 'monthly_price' => 150000, 'professional_limit' => 4],
    ] as $plan) {
        $statement->execute([
            ':code' => $plan['code'],
            ':name' => $plan['name'],
            ':monthly_price' => $plan['monthly_price'],
            ':professional_limit' => $plan['professional_limit'],
        ]);
    }
}

function ensureSystemAdminUser(PDO $pdo): void
{
    $config = require __DIR__ . '/config.php';
    $name = trim((string) ($config['system_admin_name'] ?? ''));
    $username = strtolower(trim((string) ($config['system_admin_username'] ?? '')));
    $email = strtolower(trim((string) ($config['system_admin_email'] ?? '')));
    $password = (string) ($config['system_admin_password'] ?? '');

    if ($name === '' || $username === '' || $email === '' || $password === '') {
        return;
    }

    $statement = $pdo->prepare('SELECT id, password_hash FROM users WHERE is_system_admin = 1 LIMIT 1');
    $statement->execute();
    $existingAdmin = $statement->fetch();

    if (is_array($existingAdmin)) {
        $updateStatement = $pdo->prepare(
            'UPDATE users
             SET name = :name,
                 username = :username,
                 email = :email
             WHERE id = :id'
        );
        $updateStatement->execute([
            ':name' => $name,
            ':username' => $username,
            ':email' => $email,
            ':id' => (int) $existingAdmin['id'],
        ]);

        if (!password_verify($password, (string) $existingAdmin['password_hash'])) {
            $passwordStatement = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash
                 WHERE id = :id'
            );
            $passwordStatement->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => (int) $existingAdmin['id'],
            ]);
        }

        return;
    }

    $insertStatement = $pdo->prepare(
        'INSERT INTO users (
            name, username, email, password_hash, company_role, is_system_admin, email_verified_at
         ) VALUES (
            :name, :username, :email, :password_hash, "system_admin", 1, NOW()
         )'
    );
    $insertStatement->execute([
        ':name' => $name,
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
}

function slugify(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'empresa';
}

function generateUniqueCompanySlug(PDO $pdo, string $baseName): string
{
    $baseSlug = slugify($baseName);
    $candidate = $baseSlug;
    $counter = 2;

    while (true) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE slug = :slug');
        $statement->execute([':slug' => $candidate]);

        if ((int) $statement->fetchColumn() === 0) {
            return $candidate;
        }

        $candidate = sprintf('%s-%d', $baseSlug, $counter);
        $counter++;
    }
}

function getAuthenticatedUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $statement = getConnection()->prepare(
        'SELECT id, name, username, email
                , company_id
                , company_role
                , professional_id
                , is_system_admin
                , email_verified_at
                , profile_public
                , whatsapp_number
                , whatsapp_notifications_enabled
                , telegram_chat_id
                , telegram_notifications_enabled
         FROM users
         WHERE id = :id'
    );
    $statement->execute([':id' => (int) $_SESSION['user_id']]);
    $user = $statement->fetch();

    if (!is_array($user)) {
        unset($_SESSION['user_id']);
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'username' => $user['username'],
        'email' => (string) ($user['email'] ?? ''),
        'companyId' => isset($user['company_id']) ? (int) $user['company_id'] : 0,
        'companyRole' => (string) ($user['company_role'] ?? ''),
        'professionalId' => isset($user['professional_id']) ? (int) $user['professional_id'] : 0,
        'isSystemAdmin' => !empty($user['is_system_admin']),
        'emailVerified' => !empty($user['email_verified_at']),
        'profilePublic' => !empty($user['profile_public']),
        'publicUrl' => buildPublicProfileUrl((string) $user['username']),
        'whatsappNumber' => (string) ($user['whatsapp_number'] ?? ''),
        'whatsappNotificationsEnabled' => !empty($user['whatsapp_notifications_enabled']),
        'telegramChatId' => (string) ($user['telegram_chat_id'] ?? ''),
        'telegramNotificationsEnabled' => !empty($user['telegram_notifications_enabled']),
    ];
}

function requireAuthenticatedUser(): array
{
    $user = getAuthenticatedUser();

    if ($user === null) {
        jsonResponse(['message' => 'Sesion no iniciada.'], 401);
    }

    return $user;
}

function requireSystemAdminUser(): array
{
    $user = requireAuthenticatedUser();

    if (empty($user['isSystemAdmin'])) {
        jsonResponse(['message' => 'Acceso restringido al administrador del sistema.'], 403);
    }

    return $user;
}

function isProfessionalUser(array $user): bool
{
    return !$user['isSystemAdmin'] && (string) ($user['companyRole'] ?? '') === 'professional';
}

function requireCompanyAdminUser(): array
{
    $user = requireAuthenticatedUser();

    if (!empty($user['isSystemAdmin'])) {
        return $user;
    }

    $role = (string) ($user['companyRole'] ?? '');
    if (!in_array($role, ['owner', 'admin'], true)) {
        jsonResponse(['message' => 'Acceso restringido al administrador de la empresa.'], 403);
    }

    return $user;
}

function hasUsers(PDO $pdo): bool
{
    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

function findUserByUsername(PDO $pdo, string $username): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, name, username, email, password_hash, company_id, company_role, professional_id, is_system_admin, email_verified_at, verification_token_hash, verification_token_expires_at, verification_sent_at, profile_public, whatsapp_number, whatsapp_notifications_enabled, telegram_chat_id, telegram_notifications_enabled
         FROM users
         WHERE username = :username'
    );
    $statement->execute([':username' => $username]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function findUserByEmail(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, name, username, email, password_hash, company_id, company_role, professional_id, is_system_admin, email_verified_at, verification_token_hash, verification_token_expires_at, verification_sent_at, profile_public, whatsapp_number, whatsapp_notifications_enabled, telegram_chat_id, telegram_notifications_enabled
         FROM users
         WHERE email = :email'
    );
    $statement->execute([':email' => $email]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function buildPublicProfileUrl(string $username): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return sprintf('%s://%s/%s', $scheme, $host, rawurlencode($username));
}

function buildBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return sprintf('%s://%s', $scheme, $host);
}

function claimLegacyRecordsForUser(PDO $pdo, array $user): void
{
    $params = [
        ':user_id' => $user['id'],
        ':assignee' => $user['name'],
    ];

    $pdo->prepare(
        'UPDATE activities
         SET user_id = :user_id
         WHERE user_id IS NULL AND assignee = :assignee'
    )->execute($params);

    $pdo->prepare(
        'UPDATE general_pendings
         SET user_id = :user_id
         WHERE user_id IS NULL AND assignee = :assignee'
    )->execute($params);

    $pdo->prepare(
        'UPDATE financial_entries
         SET user_id = :user_id
         WHERE user_id IS NULL AND assignee = :assignee'
    )->execute($params);

    $companyId = (int) ($user['companyId'] ?? 0);

    if ($companyId > 0) {
        $companyParams = [
            ':user_id' => $user['id'],
            ':company_id' => $companyId,
        ];

        $pdo->prepare(
            'UPDATE activities
             SET company_id = :company_id
             WHERE user_id = :user_id AND company_id IS NULL'
        )->execute($companyParams);

        $pdo->prepare(
            'UPDATE general_pendings
             SET company_id = :company_id
             WHERE user_id = :user_id AND company_id IS NULL'
        )->execute($companyParams);

        $pdo->prepare(
            'UPDATE financial_entries
             SET company_id = :company_id
             WHERE user_id = :user_id AND company_id IS NULL'
        )->execute($companyParams);
    }
}

function initializeDefaultCompanyForUser(
    PDO $pdo,
    int $userId,
    string $userName,
    string $username,
    string $companyName = '',
    string $accountType = 'business',
    string $defaultPlanCode = 'free'
): array
{
    $normalizedAccountType = in_array($accountType, ['business', 'independent'], true)
        ? $accountType
        : 'business';
    $resolvedCompanyName = trim($companyName) !== ''
        ? trim($companyName)
        : (trim($userName) !== '' ? sprintf('%s Studio', $userName) : sprintf('Empresa %s', $username));
    $slug = generateUniqueCompanySlug($pdo, $resolvedCompanyName);

    $statement = $pdo->prepare(
        'INSERT INTO companies (name, slug, account_type, status)
         VALUES (:name, :slug, :account_type, "active")'
    );
    $statement->execute([
        ':name' => $resolvedCompanyName,
        ':slug' => $slug,
        ':account_type' => $normalizedAccountType,
    ]);
    $companyId = (int) $pdo->lastInsertId();

    $planStatement = $pdo->prepare('SELECT id, code, name, monthly_price, professional_limit FROM plans WHERE code = :code LIMIT 1');
    $planStatement->execute([':code' => $defaultPlanCode]);
    $plan = $planStatement->fetch();

    $subscriptionStatement = $pdo->prepare(
        'INSERT INTO company_subscriptions (
            company_id, plan_id, plan_code, plan_name, status, monthly_price, professional_limit, started_at, renewal_day
         ) VALUES (
            :company_id, :plan_id, :plan_code, :plan_name, "active", :monthly_price, :professional_limit, :started_at, :renewal_day
         )'
    );
    $subscriptionStatement->execute([
        ':company_id' => $companyId,
        ':plan_id' => $plan['id'] ?? null,
        ':plan_code' => $plan['code'] ?? $defaultPlanCode,
        ':plan_name' => $plan['name'] ?? ($defaultPlanCode === 'free' ? 'Plan gratuito' : 'Basico empresarial'),
        ':monthly_price' => $plan['monthly_price'] ?? ($defaultPlanCode === 'free' ? 0 : 150000),
        ':professional_limit' => $plan['professional_limit'] ?? 4,
        ':started_at' => date('Y-m-d'),
        ':renewal_day' => (int) date('j'),
    ]);

    $pdo->prepare(
        'UPDATE users
         SET company_id = :company_id, company_role = "owner"
         WHERE id = :id'
    )->execute([
        ':company_id' => $companyId,
        ':id' => $userId,
    ]);

    return [
        'id' => $companyId,
        'name' => $resolvedCompanyName,
        'slug' => $slug,
        'accountType' => $normalizedAccountType,
        'status' => 'active',
    ];
}

function getCurrentCompanyId(array $user): int
{
    return (int) ($user['companyId'] ?? 0);
}

function requireCurrentCompanyId(array $user): int
{
    $companyId = getCurrentCompanyId($user);

    if ($companyId <= 0) {
        jsonResponse(['message' => 'El usuario no tiene empresa asignada.'], 422);
    }

    return $companyId;
}

function getCurrentSubscription(PDO $pdo, int $companyId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, plan_code, plan_name, status, monthly_price, professional_limit, started_at, renewal_day
         FROM company_subscriptions
         WHERE p.company_id = :company_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $statement->execute([':company_id' => $companyId]);
    $subscription = $statement->fetch();

    return is_array($subscription) ? $subscription : null;
}

function getCompanyProfessionals(PDO $pdo, int $companyId): array
{
    $statement = $pdo->prepare(
        'SELECT
            p.id,
            p.name,
            p.email,
            p.phone,
            p.active,
            p.linked_user_id,
            u.username,
            u.email_verified_at
         FROM professionals p
         LEFT JOIN users u
           ON u.professional_id = p.id
         WHERE company_id = :company_id
         ORDER BY p.active DESC, p.name ASC'
    );
    $statement->execute([':company_id' => $companyId]);

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'active' => !empty($row['active']),
            'linkedUserId' => isset($row['linked_user_id']) ? (int) $row['linked_user_id'] : null,
            'username' => (string) ($row['username'] ?? ''),
            'emailVerified' => !empty($row['email_verified_at']),
        ];
    }, $statement->fetchAll());
}

function getCompanyContext(PDO $pdo, array $user): array
{
    $companyId = requireCurrentCompanyId($user);
    $companyStatement = $pdo->prepare(
        'SELECT id, name, slug, account_type, status
         FROM companies
         WHERE id = :id'
    );
    $companyStatement->execute([':id' => $companyId]);
    $company = $companyStatement->fetch();

    if (!is_array($company)) {
        jsonResponse(['message' => 'Empresa no encontrada.'], 404);
    }

    $subscription = getCurrentSubscription($pdo, $companyId);
    $professionals = getCompanyProfessionals($pdo, $companyId);
    $activeProfessionals = count(array_filter($professionals, static fn (array $professional): bool => $professional['active']));
    $professionalLimit = (int) ($subscription['professional_limit'] ?? 4);

    return [
        'company' => [
            'id' => (int) $company['id'],
            'name' => (string) $company['name'],
            'slug' => (string) $company['slug'],
            'accountType' => (string) ($company['account_type'] ?? 'business'),
            'status' => (string) $company['status'],
        ],
        'subscription' => [
            'id' => isset($subscription['id']) ? (int) $subscription['id'] : 0,
            'planCode' => (string) ($subscription['plan_code'] ?? 'basic'),
            'planName' => (string) ($subscription['plan_name'] ?? 'Basico empresarial'),
            'status' => (string) ($subscription['status'] ?? 'active'),
            'monthlyPrice' => isset($subscription['monthly_price']) ? (float) $subscription['monthly_price'] : 150000,
            'professionalLimit' => $professionalLimit,
            'startedAt' => (string) ($subscription['started_at'] ?? date('Y-m-d')),
            'renewalDay' => isset($subscription['renewal_day']) ? (int) $subscription['renewal_day'] : null,
        ],
        'professionals' => $professionals,
        'stats' => [
            'activeProfessionals' => $activeProfessionals,
            'availableSlots' => max($professionalLimit - $activeProfessionals, 0),
        ],
    ];
}

function getSystemAccountSummaries(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT
            u.id AS user_id,
            u.name AS owner_name,
            u.email AS owner_email,
            u.username,
            u.is_system_admin,
            u.email_verified_at,
            u.created_at,
            c.id AS company_id,
            c.name AS company_name,
            c.slug AS company_slug,
            c.account_type,
            c.status AS company_status,
            cs.plan_name,
            cs.plan_code,
            cs.status AS subscription_status,
            cs.professional_limit,
            (
                SELECT COUNT(*)
                FROM professionals p
                WHERE p.company_id = c.id AND p.active = 1
            ) AS active_professionals
         FROM users u
         LEFT JOIN companies c ON c.id = u.company_id
         LEFT JOIN company_subscriptions cs ON cs.id = (
            SELECT cs2.id
            FROM company_subscriptions cs2
            WHERE cs2.company_id = c.id
            ORDER BY cs2.id DESC
            LIMIT 1
         )
         ORDER BY u.is_system_admin DESC, u.created_at DESC'
    );

    return array_map(static function (array $row): array {
        return [
            'userId' => (int) $row['user_id'],
            'companyId' => $row['company_id'] !== null ? (int) $row['company_id'] : null,
            'ownerName' => (string) ($row['owner_name'] ?? ''),
            'ownerEmail' => (string) ($row['owner_email'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'emailVerified' => !empty($row['email_verified_at']),
            'isSystemAdmin' => !empty($row['is_system_admin']),
            'companyName' => (string) ($row['company_name'] ?? ''),
            'companySlug' => (string) ($row['company_slug'] ?? ''),
            'accountType' => (string) ($row['account_type'] ?? 'business'),
            'companyStatus' => (string) ($row['company_status'] ?? 'active'),
            'planName' => (string) ($row['plan_name'] ?? ''),
            'planCode' => (string) ($row['plan_code'] ?? ''),
            'subscriptionStatus' => (string) ($row['subscription_status'] ?? 'active'),
            'professionalLimit' => (int) ($row['professional_limit'] ?? 0),
            'activeProfessionals' => (int) ($row['active_professionals'] ?? 0),
            'createdAt' => (string) ($row['created_at'] ?? ''),
        ];
    }, $statement->fetchAll());
}

function findProfessionalById(PDO $pdo, int $companyId, int $professionalId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, name, email, phone, linked_user_id, active
         FROM professionals
         WHERE id = :id AND company_id = :company_id'
    );
    $statement->execute([
        ':id' => $professionalId,
        ':company_id' => $companyId,
    ]);
    $professional = $statement->fetch();

    return is_array($professional) ? $professional : null;
}

function resolveProfessionalAssignment(PDO $pdo, int $companyId, mixed $professionalId, string $assigneeName): array
{
    $normalizedAssignee = trim($assigneeName);
    $normalizedProfessionalId = (int) $professionalId;

    if ($normalizedProfessionalId > 0) {
        $professional = findProfessionalById($pdo, $companyId, $normalizedProfessionalId);

        if ($professional === null) {
            jsonResponse(['message' => 'Profesional no encontrado para esta empresa.'], 422);
        }

        return [
            'professionalId' => (int) $professional['id'],
            'assignee' => (string) $professional['name'],
        ];
    }

    if ($normalizedAssignee === '') {
        jsonResponse(['message' => 'Debes seleccionar un profesional.'], 422);
    }

    $statement = $pdo->prepare(
        'SELECT id, name
         FROM professionals
         WHERE company_id = :company_id AND name = :name
         LIMIT 1'
    );
    $statement->execute([
        ':company_id' => $companyId,
        ':name' => $normalizedAssignee,
    ]);
    $professional = $statement->fetch();

    if (is_array($professional)) {
        return [
            'professionalId' => (int) $professional['id'],
            'assignee' => (string) $professional['name'],
        ];
    }

    return [
        'professionalId' => null,
        'assignee' => $normalizedAssignee,
    ];
}

function countActiveProfessionals(PDO $pdo, int $companyId, ?int $excludeProfessionalId = null): int
{
    $sql = 'SELECT COUNT(*) FROM professionals WHERE company_id = :company_id AND active = 1';
    $params = [':company_id' => $companyId];

    if ($excludeProfessionalId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params[':exclude_id'] = $excludeProfessionalId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn();
}

function ensureProfessionalLimitNotExceeded(PDO $pdo, int $companyId, bool $willBeActive, ?int $excludeProfessionalId = null): void
{
    if (!$willBeActive) {
        return;
    }

    $subscription = getCurrentSubscription($pdo, $companyId);
    $professionalLimit = (int) ($subscription['professional_limit'] ?? 4);
    $activeCount = countActiveProfessionals($pdo, $companyId, $excludeProfessionalId);

    if ($activeCount >= $professionalLimit) {
        jsonResponse([
            'message' => sprintf('El plan actual permite hasta %d profesionales activos.', $professionalLimit),
        ], 422);
    }
}

function findProfessionalByLinkedUserId(PDO $pdo, int $companyId, int $userId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, name, email, phone, active, linked_user_id
         FROM professionals
         WHERE company_id = :company_id
           AND linked_user_id = :linked_user_id
         LIMIT 1'
    );
    $statement->execute([
        ':company_id' => $companyId,
        ':linked_user_id' => $userId,
    ]);
    $professional = $statement->fetch();

    return is_array($professional) ? $professional : null;
}

function generateUniqueUsername(PDO $pdo, string $seed): string
{
    $base = strtolower(trim($seed));
    $base = preg_replace('/[^a-z0-9]+/', '.', $base) ?? '';
    $base = trim($base, '.');

    if ($base === '') {
        $base = 'profesional';
    }

    $candidate = $base;
    $counter = 2;

    while (findUserByUsername($pdo, $candidate) !== null) {
        $candidate = sprintf('%s%d', $base, $counter);
        $counter++;
    }

    return $candidate;
}

function generateTemporaryPassword(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $maxIndex = strlen($alphabet) - 1;
    $password = '';

    for ($index = 0; $index < $length; $index++) {
        $password .= $alphabet[random_int(0, $maxIndex)];
    }

    return $password;
}

function createOrRefreshProfessionalAccess(PDO $pdo, int $companyId, int $professionalId, string $name, string $email): array
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['message' => 'Debes indicar un correo valido para habilitar acceso al profesional.'], 422);
    }

    $existingUser = findUserByEmail($pdo, strtolower($email));
    if ($existingUser !== null && (int) ($existingUser['professional_id'] ?? 0) !== $professionalId) {
        jsonResponse(['message' => 'Ese correo ya esta en uso por otra cuenta.'], 422);
    }

    $existingProfessionalUser = null;
    if ($existingUser !== null && (int) ($existingUser['professional_id'] ?? 0) === $professionalId) {
        $existingProfessionalUser = $existingUser;
    }

    $plainPassword = generateTemporaryPassword();
    $username = $existingProfessionalUser !== null
        ? (string) $existingProfessionalUser['username']
        : generateUniqueUsername($pdo, strstr($email, '@', true) ?: $name);

    if ($existingProfessionalUser === null) {
        $statement = $pdo->prepare(
            'INSERT INTO users (name, username, email, password_hash, company_id, company_role, professional_id)
             VALUES (:name, :username, :email, :password_hash, :company_id, "professional", :professional_id)'
        );
        $statement->execute([
            ':name' => $name,
            ':username' => $username,
            ':email' => strtolower($email),
            ':password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
            ':company_id' => $companyId,
            ':professional_id' => $professionalId,
        ]);
        $userId = (int) $pdo->lastInsertId();
    } else {
        $userId = (int) $existingProfessionalUser['id'];
        $statement = $pdo->prepare(
            'UPDATE users
             SET name = :name,
                 email = :email,
                 password_hash = :password_hash,
                 company_id = :company_id,
                 company_role = "professional",
                 professional_id = :professional_id,
                 email_verified_at = NULL
             WHERE id = :id'
        );
        $statement->execute([
            ':name' => $name,
            ':email' => strtolower($email),
            ':password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
            ':company_id' => $companyId,
            ':professional_id' => $professionalId,
            ':id' => $userId,
        ]);
    }

    $pdo->prepare(
        'UPDATE professionals
         SET linked_user_id = :linked_user_id
         WHERE id = :id AND company_id = :company_id'
    )->execute([
        ':linked_user_id' => $userId,
        ':id' => $professionalId,
        ':company_id' => $companyId,
    ]);

    $token = generateEmailVerificationToken($pdo, $userId);

    return [
        'userId' => $userId,
        'username' => $username,
        'password' => $plainPassword,
        'token' => $token,
    ];
}

function normalizeWhatsappNumber(string $value): string
{
    $normalizedValue = preg_replace('/\D+/', '', $value) ?? '';
    return trim($normalizedValue);
}

function buildWhatsappClickUrl(string $number, string $message = ''): string
{
    $normalizedNumber = normalizeWhatsappNumber($number);

    if ($normalizedNumber === '') {
        return '';
    }

    $query = ['phone' => $normalizedNumber];

    if ($message !== '') {
        $query['text'] = $message;
    }

    return 'https://wa.me/?' . http_build_query($query);
}

function normalizeReminderMinutes(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    $normalizedValue = (int) $value;

    if ($normalizedValue < 1 || $normalizedValue > 1440) {
        return null;
    }

    return $normalizedValue;
}

function normalizeTelegramChatId(string $value): string
{
    $normalizedValue = preg_replace('/[^0-9-]+/', '', $value) ?? '';
    return trim($normalizedValue);
}

function generateEmailVerificationToken(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    $expiresAt = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');
    $sentAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $statement = $pdo->prepare(
        'UPDATE users
         SET verification_token_hash = :token_hash,
             verification_token_expires_at = :expires_at,
             verification_sent_at = :sent_at
         WHERE id = :id'
    );
    $statement->execute([
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
        ':sent_at' => $sentAt,
        ':id' => $userId,
    ]);

    return $token;
}

function verifyEmailToken(PDO $pdo, string $token): ?array
{
    $statement = $pdo->query(
        'SELECT id, name, username, email, verification_token_hash, verification_token_expires_at
         FROM users
         WHERE verification_token_hash IS NOT NULL'
    );

    foreach ($statement->fetchAll() as $user) {
        $tokenHash = (string) ($user['verification_token_hash'] ?? '');
        $expiresAt = (string) ($user['verification_token_expires_at'] ?? '');

        if ($tokenHash === '' || !password_verify($token, $tokenHash)) {
            continue;
        }

        if ($expiresAt === '' || strtotime($expiresAt) < time()) {
            return ['expired' => true];
        }

        $updateStatement = $pdo->prepare(
            'UPDATE users
             SET email_verified_at = NOW(),
                 verification_token_hash = NULL,
                 verification_token_expires_at = NULL
             WHERE id = :id'
        );
        $updateStatement->execute([':id' => (int) $user['id']]);

        return [
            'expired' => false,
            'user' => [
                'id' => (int) $user['id'],
                'name' => (string) $user['name'],
                'username' => (string) $user['username'],
                'email' => (string) $user['email'],
            ],
        ];
    }

    return null;
}

function getMailConfig(): array
{
    $config = require __DIR__ . '/config.php';

    return [
        'from_name' => (string) ($config['mail_from_name'] ?? 'SteelSoft Agenda'),
        'from_email' => (string) ($config['mail_from_email'] ?? ''),
        'transport' => strtolower((string) ($config['mail_transport'] ?? 'mail')),
        'smtp_host' => (string) ($config['smtp_host'] ?? ''),
        'smtp_port' => (int) ($config['smtp_port'] ?? 587),
        'smtp_encryption' => strtolower((string) ($config['smtp_encryption'] ?? 'tls')),
        'smtp_username' => (string) ($config['smtp_username'] ?? ''),
        'smtp_password' => (string) ($config['smtp_password'] ?? ''),
    ];
}

function sendEmail(string $toEmail, string $subject, string $htmlBody, string $plainBody): void
{
    $mailConfig = getMailConfig();

    if ($mailConfig['from_email'] === '') {
        throw new RuntimeException('No se ha configurado el correo remitente del sistema.');
    }

    if ($mailConfig['transport'] === 'smtp') {
        sendSmtpEmail($mailConfig, $toEmail, $subject, $htmlBody, $plainBody);
        return;
    }

    $boundary = 'b' . bin2hex(random_bytes(8));
    $headers = [
        sprintf('From: %s <%s>', $mailConfig['from_name'], $mailConfig['from_email']),
        'MIME-Version: 1.0',
        sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundary),
    ];

    $message = [];
    $message[] = sprintf('--%s', $boundary);
    $message[] = 'Content-Type: text/plain; charset=UTF-8';
    $message[] = 'Content-Transfer-Encoding: 8bit';
    $message[] = '';
    $message[] = $plainBody;
    $message[] = sprintf('--%s', $boundary);
    $message[] = 'Content-Type: text/html; charset=UTF-8';
    $message[] = 'Content-Transfer-Encoding: 8bit';
    $message[] = '';
    $message[] = $htmlBody;
    $message[] = sprintf('--%s--', $boundary);

    if (!mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', implode("\r\n", $message), implode("\r\n", $headers))) {
        throw new RuntimeException('No fue posible enviar el correo.');
    }
}

function sendSmtpEmail(array $mailConfig, string $toEmail, string $subject, string $htmlBody, string $plainBody): void
{
    $host = $mailConfig['smtp_host'];
    $port = (int) $mailConfig['smtp_port'];
    $encryption = $mailConfig['smtp_encryption'];
    $username = $mailConfig['smtp_username'];
    $password = $mailConfig['smtp_password'];

    if ($host === '' || $username === '' || $password === '') {
        throw new RuntimeException('La configuracion SMTP esta incompleta.');
    }

    $transportHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $socket = fsockopen($transportHost, $port, $errorNumber, $errorMessage, 15);

    if ($socket === false) {
        throw new RuntimeException(sprintf('No fue posible conectar con SMTP: %s', $errorMessage));
    }

    $read = static function () use ($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    };

    $write = static function (string $command) use ($socket): void {
        fwrite($socket, $command . "\r\n");
    };

    $assertCode = static function (string $response, array $allowedCodes) use ($socket): void {
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $allowedCodes, true)) {
            fclose($socket);
            throw new RuntimeException(sprintf('SMTP respondio con error: %s', trim($response)));
        }
    };

    $assertCode($read(), [220]);
    $write('EHLO ' . parse_url(buildBaseUrl(), PHP_URL_HOST));
    $assertCode($read(), [250]);

    if ($encryption === 'tls') {
        $write('STARTTLS');
        $assertCode($read(), [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            throw new RuntimeException('No fue posible iniciar TLS con el servidor SMTP.');
        }
        $write('EHLO ' . parse_url(buildBaseUrl(), PHP_URL_HOST));
        $assertCode($read(), [250]);
    }

    $write('AUTH LOGIN');
    $assertCode($read(), [334]);
    $write(base64_encode($username));
    $assertCode($read(), [334]);
    $write(base64_encode($password));
    $assertCode($read(), [235]);

    $write('MAIL FROM: <' . $mailConfig['from_email'] . '>');
    $assertCode($read(), [250]);
    $write('RCPT TO: <' . $toEmail . '>');
    $assertCode($read(), [250, 251]);
    $write('DATA');
    $assertCode($read(), [354]);

    $boundary = 'b' . bin2hex(random_bytes(8));
    $headers = [
        sprintf('From: %s <%s>', $mailConfig['from_name'], $mailConfig['from_email']),
        sprintf('To: <%s>', $toEmail),
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundary),
        '',
    ];
    $body = [
        sprintf('--%s', $boundary),
        'Content-Type: text/plain; charset=UTF-8',
        '',
        $plainBody,
        sprintf('--%s', $boundary),
        'Content-Type: text/html; charset=UTF-8',
        '',
        $htmlBody,
        sprintf('--%s--', $boundary),
        '.',
    ];

    fwrite($socket, implode("\r\n", array_merge($headers, $body)) . "\r\n");
    $assertCode($read(), [250]);
    $write('QUIT');
    fclose($socket);
}

function sendVerificationEmail(string $email, string $name, string $token): void
{
    $verificationUrl = buildBaseUrl() . '/?verify_email=' . urlencode($token);
    $subject = 'Verifica tu correo en SteelSoft Agenda';
    $plainBody = sprintf(
        "Hola %s,\n\nVerifica tu correo abriendo este enlace:\n%s\n\nEl enlace vence en 24 horas.",
        $name,
        $verificationUrl
    );
    $htmlBody = sprintf(
        '<p>Hola %s,</p><p>Verifica tu correo abriendo este enlace:</p><p><a href="%s">%s</a></p><p>El enlace vence en 24 horas.</p>',
        htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8')
    );

    sendEmail($email, $subject, $htmlBody, $plainBody);
}

function sendProfessionalInvitationEmail(
    string $email,
    string $name,
    string $username,
    string $temporaryPassword,
    string $token
): void {
    $verificationUrl = buildBaseUrl() . '/?verify_email=' . urlencode($token);
    $subject = 'Activa tu acceso profesional en SteelSoft Agenda';
    $plainBody = sprintf(
        "Hola %s,\n\nTu acceso profesional fue creado.\nUsuario: %s\nContrasena temporal: %s\n\nVerifica tu correo aqui:\n%s\n\nDespues de verificar, ya puedes iniciar sesion.",
        $name,
        $username,
        $temporaryPassword,
        $verificationUrl
    );
    $htmlBody = sprintf(
        '<p>Hola %s,</p><p>Tu acceso profesional fue creado.</p><p><strong>Usuario:</strong> %s<br><strong>Contrasena temporal:</strong> %s</p><p>Verifica tu correo aqui:</p><p><a href="%s">%s</a></p><p>Despues de verificar, ya puedes iniciar sesion.</p>',
        htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($temporaryPassword, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8')
    );

    sendEmail($email, $subject, $htmlBody, $plainBody);
}

function notifySystemAdminOfRegistration(string $accountType, string $companyName, string $ownerName, string $ownerEmail): void
{
    $config = require __DIR__ . '/config.php';
    $adminEmail = strtolower(trim((string) ($config['system_admin_email'] ?? '')));

    if ($adminEmail === '') {
        return;
    }

    $accountLabel = $accountType === 'independent' ? 'Persona independiente' : 'Empresa';
    $subject = 'Nuevo registro en SteelSoft Agenda';
    $plainBody = sprintf(
        "%s registrada: %s\nResponsable: %s\nCorreo: %s",
        $accountLabel,
        $companyName,
        $ownerName,
        $ownerEmail
    );
    $htmlBody = sprintf(
        '<p><strong>%s registrada:</strong> %s</p><p><strong>Responsable:</strong> %s</p><p><strong>Correo:</strong> %s</p>',
        htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($ownerEmail, ENT_QUOTES, 'UTF-8')
    );

    sendEmail($adminEmail, $subject, $htmlBody, $plainBody);
}

function getWhatsappConfig(): array
{
    $config = require __DIR__ . '/config.php';
    $templateParameterFormat = (string) getenv('WHATSAPP_TEMPLATE_PARAMETER_FORMAT') ?: (string) ($config['whatsapp_template_parameter_format'] ?? 'named');
    $templateParameterFormat = strtolower(trim($templateParameterFormat));
    $provider = (string) getenv('WHATSAPP_PROVIDER') ?: (string) ($config['whatsapp_provider'] ?? 'meta');
    $provider = strtolower(trim($provider));

    if (!in_array($templateParameterFormat, ['named', 'positional'], true)) {
        $templateParameterFormat = 'named';
    }

    if (!in_array($provider, ['meta', '360dialog'], true)) {
        $provider = 'meta';
    }

    return [
        'provider' => $provider,
        'access_token' => (string) getenv('WHATSAPP_ACCESS_TOKEN') ?: (string) ($config['whatsapp_access_token'] ?? ''),
        'phone_number_id' => (string) getenv('WHATSAPP_PHONE_NUMBER_ID') ?: (string) ($config['whatsapp_phone_number_id'] ?? ''),
        'template_name' => (string) getenv('WHATSAPP_TEMPLATE_NAME') ?: (string) ($config['whatsapp_template_name'] ?? ''),
        'template_language' => (string) getenv('WHATSAPP_TEMPLATE_LANGUAGE') ?: (string) ($config['whatsapp_template_language'] ?? 'es_CO'),
        'template_parameter_format' => $templateParameterFormat,
        'graph_version' => (string) getenv('WHATSAPP_GRAPH_VERSION') ?: (string) ($config['whatsapp_graph_version'] ?? 'v23.0'),
        'cron_secret' => (string) getenv('WHATSAPP_CRON_SECRET') ?: (string) ($config['whatsapp_cron_secret'] ?? ''),
        'dialog_api_key' => (string) getenv('WHATSAPP_360DIALOG_API_KEY') ?: (string) ($config['whatsapp_360dialog_api_key'] ?? ''),
        'dialog_base_url' => (string) getenv('WHATSAPP_360DIALOG_BASE_URL') ?: (string) ($config['whatsapp_360dialog_base_url'] ?? 'https://waba-v2.360dialog.io'),
    ];
}

function getTelegramConfig(): array
{
    $config = require __DIR__ . '/config.php';

    return [
        'bot_token' => (string) getenv('TELEGRAM_BOT_TOKEN') ?: (string) ($config['telegram_bot_token'] ?? ''),
        'bot_username' => (string) getenv('TELEGRAM_BOT_USERNAME') ?: (string) ($config['telegram_bot_username'] ?? ''),
        'cron_secret' => (string) getenv('TELEGRAM_CRON_SECRET') ?: (string) ($config['telegram_cron_secret'] ?? ''),
    ];
}
