<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = getTelegramConfig();
$isCli = PHP_SAPI === 'cli';
$activityId = isset($_GET['activity_id']) ? (int) $_GET['activity_id'] : 0;
$forceSend = !empty($_GET['force']);
$dryRun = !empty($_GET['dry_run']);
$testChatId = normalizeTelegramChatId((string) ($_GET['test_chat_id'] ?? ''));

if (!$isCli) {
    $providedSecret = (string) ($_GET['key'] ?? '');
    $expectedSecret = (string) ($config['cron_secret'] ?? '');

    if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
        jsonResponse(['message' => 'No autorizado.'], 401);
    }
}

if ($config['bot_token'] === '') {
    jsonResponse([
        'message' => 'Falta configurar Telegram Bot API.',
        'missing' => [
            'bot_token' => true,
        ],
    ], 422);
}

$activities = [];
$pdo = null;

if ($testChatId !== '') {
    $activities[] = [
        'id' => 0,
        'title' => 'Prueba de Telegram',
        'start_time' => '09:00:00',
        'activity_date' => date('Y-m-d'),
        'location' => '',
        'reminder_minutes' => 5,
        'telegram_chat_id' => $testChatId,
        'user_name' => 'Steelsoft',
    ];
} else {
    $pdo = getConnection();
    $query = 'SELECT
        activities.id,
        activities.title,
        activities.start_time,
        activities.activity_date,
        activities.location,
        activities.reminder_minutes,
        users.telegram_chat_id,
        users.name AS user_name
     FROM activities
     INNER JOIN users ON users.id = activities.user_id
     WHERE activities.completed = 0
       AND activities.reminder_minutes IS NOT NULL
       AND users.telegram_notifications_enabled = 1
       AND users.telegram_chat_id <> ""';

    $params = [];

    if ($activityId > 0) {
        $query .= ' AND activities.id = :activity_id';
        $params[':activity_id'] = $activityId;
    } else {
        $query .= ' AND activities.reminder_sent_at IS NULL
           AND TIMESTAMP(activities.activity_date, activities.start_time) > NOW()
           AND DATE_SUB(TIMESTAMP(activities.activity_date, activities.start_time), INTERVAL activities.reminder_minutes MINUTE) <= NOW()';
    }

    $query .= ' ORDER BY activities.activity_date, activities.start_time';

    $statement = $pdo->prepare($query);
    $statement->execute($params);
    $activities = $statement->fetchAll();
}

$sentCount = 0;
$errors = [];
$preview = [];

foreach ($activities as $activity) {
    try {
        if ($dryRun) {
            $preview[] = buildTelegramPayloadPreview($activity);
            continue;
        }

        $response = sendTelegramReminder($config, $activity);
    } catch (Throwable $exception) {
        $errors[] = [
            'activityId' => (int) $activity['id'],
            'title' => $activity['title'],
            'message' => $exception->getMessage(),
        ];
        continue;
    }

    if ($response['success']) {
        if ($pdo instanceof PDO && $activityId <= 0 && !$forceSend) {
            $updateStatement = $pdo->prepare(
                'UPDATE activities
                 SET reminder_sent_at = NOW()
                 WHERE id = :id'
            );
            $updateStatement->execute([':id' => (int) $activity['id']]);
        }
        $sentCount++;
        continue;
    }

    $errors[] = [
        'activityId' => (int) $activity['id'],
        'title' => $activity['title'],
        'message' => $response['message'],
    ];
}

jsonResponse([
    'success' => true,
    'mode' => $dryRun ? 'dry-run' : ($testChatId !== '' ? 'direct-test' : ($activityId > 0 ? 'manual-test' : 'cron')),
    'processed' => count($activities),
    'sent' => $sentCount,
    'errors' => $errors,
    'preview' => $preview,
]);

function sendTelegramReminder(array $config, array $activity): array
{
    $payload = buildTelegramPayloadPreview($activity);
    $endpoint = sprintf('https://api.telegram.org/bot%s/sendMessage', $config['bot_token']);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);

    $rawResponse = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false || $curlError !== '') {
        return [
            'success' => false,
            'message' => $curlError !== '' ? $curlError : 'No hubo respuesta de Telegram.',
            'response' => $rawResponse,
        ];
    }

    if ($statusCode >= 400) {
        return [
            'success' => false,
            'message' => sprintf('Telegram devolvio HTTP %d: %s', $statusCode, $rawResponse),
            'response' => $rawResponse,
        ];
    }

    $response = json_decode($rawResponse, true);
    if (!is_array($response) || empty($response['ok'])) {
        return [
            'success' => false,
            'message' => sprintf('Telegram devolvio una respuesta invalida: %s', $rawResponse),
            'response' => $rawResponse,
        ];
    }

    return [
        'success' => true,
        'message' => 'ok',
        'response' => $rawResponse,
    ];
}

function buildTelegramPayloadPreview(array $activity): array
{
    $date = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        sprintf('%s %s', $activity['activity_date'], $activity['start_time'])
    );

    if (!$date instanceof DateTimeImmutable) {
        throw new RuntimeException('No fue posible interpretar la fecha del evento.');
    }

    $remainingText = buildRemainingText((int) $activity['reminder_minutes']);
    $dateTimeText = sprintf('%s a las %s', $date->format('d/m/Y'), $date->format('H:i'));
    $locationText = trim((string) ($activity['location'] ?? ''));

    $lines = [
        sprintf('Hola %s, tienes un recordatorio de Agenda Steelsoft.', (string) $activity['user_name']),
        sprintf('Evento: %s', (string) $activity['title']),
        sprintf('Fecha: %s', $dateTimeText),
        sprintf('Aviso: %s', $remainingText),
    ];

    if ($locationText !== '') {
        $lines[] = sprintf('Lugar: %s', $locationText);
    }

    return [
        'chat_id' => (string) $activity['telegram_chat_id'],
        'text' => implode("\n", $lines),
    ];
}

function buildRemainingText(int $reminderMinutes): string
{
    return match ($reminderMinutes) {
        60 => 'en 1 hora',
        30 => 'en 30 minutos',
        15 => 'en 15 minutos',
        5 => 'en 5 minutos',
        default => sprintf('en %d minutos', $reminderMinutes),
    };
}
