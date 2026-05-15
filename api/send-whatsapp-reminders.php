<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = getWhatsappConfig();
$isCli = PHP_SAPI === 'cli';
$activityId = isset($_GET['activity_id']) ? (int) $_GET['activity_id'] : 0;
$forceSend = !empty($_GET['force']);
$dryRun = !empty($_GET['dry_run']);
$testNumber = normalizeWhatsappNumber((string) ($_GET['test_number'] ?? ''));

if (!$isCli) {
    $providedSecret = (string) ($_GET['key'] ?? '');
    $expectedSecret = (string) ($config['cron_secret'] ?? '');

    if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
        jsonResponse(['message' => 'No autorizado.'], 401);
    }
}

if (
    $config['access_token'] === ''
    || $config['phone_number_id'] === ''
    || $config['template_name'] === ''
) {
    jsonResponse([
        'message' => 'Falta configurar WhatsApp Cloud API.',
        'missing' => [
            'access_token' => $config['access_token'] === '',
            'phone_number_id' => $config['phone_number_id'] === '',
            'template_name' => $config['template_name'] === '',
        ],
    ], 422);
}

$pdo = getConnection();
$activities = [];

if ($testNumber !== '') {
    $activities[] = [
        'id' => 0,
        'title' => 'Prueba de WhatsApp',
        'start_time' => '09:00:00',
        'activity_date' => date('Y-m-d'),
        'location' => '',
        'reminder_minutes' => 5,
        'whatsapp_number' => $testNumber,
        'user_name' => 'Steelsoft',
    ];
} else {
$query = 'SELECT
    activities.id,
    activities.title,
    activities.start_time,
    activities.activity_date,
    activities.location,
    activities.reminder_minutes,
    users.whatsapp_number,
    users.name AS user_name
 FROM activities
 INNER JOIN users ON users.id = activities.user_id
 WHERE activities.completed = 0
   AND activities.reminder_minutes IS NOT NULL
   AND users.whatsapp_notifications_enabled = 1
   AND users.whatsapp_number <> ""';

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
            $preview[] = buildWhatsappPayloadPreview($config, $activity);
            continue;
        }

        $response = sendWhatsappReminder($config, $activity);
    } catch (Throwable $exception) {
        $errors[] = [
            'activityId' => (int) $activity['id'],
            'title' => $activity['title'],
            'message' => $exception->getMessage(),
        ];
        continue;
    }

    if ($response['success']) {
        if ($activityId <= 0 && !$forceSend) {
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
    'mode' => $dryRun ? 'dry-run' : ($testNumber !== '' ? 'direct-test' : ($activityId > 0 ? 'manual-test' : 'cron')),
    'processed' => count($activities),
    'sent' => $sentCount,
    'errors' => $errors,
    'preview' => $preview,
]);

function sendWhatsappReminder(array $config, array $activity): array
{
    $payload = buildWhatsappPayloadPreview($config, $activity);
    $endpoint = sprintf(
        'https://graph.facebook.com/%s/%s/messages',
        $config['graph_version'],
        $config['phone_number_id']
    );

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $config['access_token'],
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
            'message' => $curlError !== '' ? $curlError : 'No hubo respuesta de WhatsApp.',
        ];
    }

    if ($statusCode >= 400) {
        return [
            'success' => false,
            'message' => sprintf('WhatsApp devolvio HTTP %d: %s', $statusCode, $rawResponse),
        ];
    }

    return ['success' => true, 'message' => 'ok'];
}

function buildWhatsappPayloadPreview(array $config, array $activity): array
{
    if ($config['template_name'] === 'hello_world') {
        return [
            'messaging_product' => 'whatsapp',
            'to' => (string) $activity['whatsapp_number'],
            'type' => 'template',
            'template' => [
                'name' => 'hello_world',
                'language' => [
                    'code' => 'en_US',
                ],
            ],
        ];
    }

    $date = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        sprintf('%s %s', $activity['activity_date'], $activity['start_time'])
    );

    if (!$date instanceof DateTimeImmutable) {
        throw new RuntimeException('No fue posible interpretar la fecha del evento.');
    }

    $remainingText = buildRemainingText((int) $activity['reminder_minutes']);
    $dateTimeText = sprintf('%s a las %s', $date->format('d/m/Y'), $date->format('H:i'));

    return [
        'messaging_product' => 'whatsapp',
        'to' => (string) $activity['whatsapp_number'],
        'type' => 'template',
        'template' => [
            'name' => $config['template_name'],
            'language' => [
                'code' => $config['template_language'],
            ],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        [
                            'type' => 'text',
                            'parameter_name' => 'nombre_usuario',
                            'text' => (string) $activity['user_name'],
                        ],
                        [
                            'type' => 'text',
                            'parameter_name' => 'titulo_evento',
                            'text' => (string) $activity['title'],
                        ],
                        [
                            'type' => 'text',
                            'parameter_name' => 'fecha_hora_evento',
                            'text' => $dateTimeText,
                        ],
                        [
                            'type' => 'text',
                            'parameter_name' => 'tiempo_restante',
                            'text' => $remainingText,
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function buildRemainingText(int $reminderMinutes): string
{
    return match ($reminderMinutes) {
        60 => '1 hora',
        30 => '30 minutos',
        15 => '15 minutos',
        5 => '5 minutos',
        default => $reminderMinutes . ' minutos',
    };
}
