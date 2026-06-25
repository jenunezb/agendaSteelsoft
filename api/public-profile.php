<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = getConnection();
$username = strtolower(trim((string) ($_GET['username'] ?? '')));

if ($username === '') {
    jsonResponse([
        'found' => false,
        'profileEnabled' => false,
        'user' => null,
        'activities' => [],
    ], 404);
}

$statement = $pdo->prepare(
    'SELECT id, name, username, company_id, company_role, professional_id, profile_public, whatsapp_number
     FROM users
     WHERE username = :username'
);
$statement->execute([':username' => $username]);
$user = $statement->fetch();

if (!is_array($user)) {
    jsonResponse([
        'found' => false,
        'profileEnabled' => false,
        'user' => null,
        'activities' => [],
    ], 404);
}

if (empty($user['profile_public'])) {
    jsonResponse([
        'found' => true,
        'profileEnabled' => false,
        'user' => [
            'name' => $user['name'],
            'username' => $user['username'],
            'publicUrl' => buildPublicProfileUrl((string) $user['username']),
            'whatsappNumber' => '',
            'whatsappContactUrl' => '',
        ],
        'activities' => [],
    ]);
}

$companyId = isset($user['company_id']) ? (int) $user['company_id'] : 0;
$companyRole = (string) ($user['company_role'] ?? '');
$professionalId = isset($user['professional_id']) ? (int) $user['professional_id'] : 0;
$isCompanyWorkspace = $companyId > 0 && in_array($companyRole, ['owner', 'admin'], true);

if ($isCompanyWorkspace) {
    $activitiesStatement = $pdo->prepare(
        'SELECT id, title, start_time, end_time, assignee, professional_id, is_public, completed, location, description, activity_date
         FROM activities
         WHERE company_id = :company_id
           AND is_public = 1
         ORDER BY activity_date, start_time, end_time, title'
    );
    $activitiesStatement->execute([':company_id' => $companyId]);

    $professionalsStatement = $pdo->prepare(
        'SELECT id, name
         FROM professionals
         WHERE company_id = :company_id
           AND active = 1
         ORDER BY name'
    );
    $professionalsStatement->execute([':company_id' => $companyId]);
    $professionals = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ];
    }, $professionalsStatement->fetchAll());
} else {
    $activitiesStatement = $pdo->prepare(
        'SELECT id, title, start_time, end_time, assignee, professional_id, is_public, completed, location, description, activity_date
         FROM activities
         WHERE user_id = :user_id
           AND is_public = 1
         ORDER BY activity_date, start_time, end_time, title'
    );
    $activitiesStatement->execute([':user_id' => (int) $user['id']]);
    $professionals = $professionalId > 0
        ? [[
            'id' => $professionalId,
            'name' => (string) $user['name'],
        ]]
        : [];
}

$activities = array_map(static function (array $row): array {
    return [
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'startTime' => substr((string) $row['start_time'], 0, 5),
        'endTime' => substr((string) $row['end_time'], 0, 5),
        'assignee' => $row['assignee'],
        'visibility' => 'public',
        'completed' => (bool) $row['completed'],
        'professionalId' => isset($row['professional_id']) ? (int) $row['professional_id'] : null,
        'location' => $row['location'] ?? '',
        'description' => $row['description'] ?? '',
        'date' => $row['activity_date'],
    ];
}, $activitiesStatement->fetchAll());

jsonResponse([
    'found' => true,
    'profileEnabled' => true,
    'user' => [
        'name' => $user['name'],
        'username' => $user['username'],
        'publicUrl' => buildPublicProfileUrl((string) $user['username']),
        'whatsappNumber' => (string) ($user['whatsapp_number'] ?? ''),
        'whatsappContactUrl' => buildWhatsappClickUrl(
            (string) ($user['whatsapp_number'] ?? ''),
            sprintf('Hola %s, quiero consultar disponibilidad en tu agenda.', $user['name'])
        ),
    ],
    'activities' => $activities,
    'professionals' => $professionals,
]);
