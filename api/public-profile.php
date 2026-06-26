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
    $companyStatement = $pdo->prepare(
        'SELECT working_hour_start, working_hour_end
         FROM companies
         WHERE id = :company_id'
    );
    $companyStatement->execute([':company_id' => $companyId]);
    $company = $companyStatement->fetch();

    $activitiesStatement = $pdo->prepare(
        'SELECT id, title, start_time, end_time, assignee, professional_id, is_public, completed, location, description, activity_date
         FROM activities
         WHERE company_id = :company_id
           AND is_public = 1
         ORDER BY activity_date, start_time, end_time, title'
    );
    $activitiesStatement->execute([':company_id' => $companyId]);

    $professionals = array_values(array_filter(
        getCompanyProfessionals($pdo, $companyId),
        static fn (array $professional): bool => !empty($professional['active'])
    ));
    $services = getCompanyServices($pdo, $companyId, true);
    $workingHourStart = isset($company['working_hour_start']) ? (int) $company['working_hour_start'] : 8;
    $workingHourEnd = isset($company['working_hour_end']) ? (int) $company['working_hour_end'] : 18;
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
            'roleIds' => [],
            'roleNames' => [],
        ]]
        : [];
    $services = [];
    $workingHourStart = 8;
    $workingHourEnd = 18;
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
    'services' => $services,
    'workingHours' => [
        'start' => $workingHourStart,
        'end' => $workingHourEnd,
    ],
]);
