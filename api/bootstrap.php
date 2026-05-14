<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonResponse(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
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

    ensureSchema($pdo, $config['db_name']);

    return $pdo;
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
        'CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            profile_public TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );

    ensureColumnExists(
        $pdo,
        $databaseName,
        'users',
        'profile_public',
        'ALTER TABLE users ADD COLUMN profile_public TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash'
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
        'is_public',
        'ALTER TABLE activities ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER assignee'
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
        'financial_entries',
        'user_id',
        'ALTER TABLE financial_entries ADD COLUMN user_id INT UNSIGNED NULL AFTER id'
    );

    ensureIndexExists($pdo, $databaseName, 'activities', 'idx_activities_user_date', 'CREATE INDEX idx_activities_user_date ON activities (user_id, activity_date, start_time)');
    ensureIndexExists($pdo, $databaseName, 'general_pendings', 'idx_general_pendings_user_date', 'CREATE INDEX idx_general_pendings_user_date ON general_pendings (user_id, pending_date)');
    ensureIndexExists($pdo, $databaseName, 'financial_entries', 'idx_financial_entries_user_date', 'CREATE INDEX idx_financial_entries_user_date ON financial_entries (user_id, entry_date)');
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

function getAuthenticatedUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $statement = getConnection()->prepare(
        'SELECT id, name, username
                , profile_public
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
        'profilePublic' => !empty($user['profile_public']),
        'publicUrl' => buildPublicProfileUrl((string) $user['username']),
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

function hasUsers(PDO $pdo): bool
{
    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

function findUserByUsername(PDO $pdo, string $username): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, name, username, password_hash, profile_public
         FROM users
         WHERE username = :username'
    );
    $statement->execute([':username' => $username]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function buildPublicProfileUrl(string $username): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return sprintf('%s://%s/%s', $scheme, $host, rawurlencode($username));
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
}
