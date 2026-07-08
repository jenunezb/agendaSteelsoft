<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli') {
    $arguments = $argv ?? [];
    array_shift($arguments);

    $testNumber = '573001234567';

    foreach ($arguments as $argument) {
        if ($argument === '--dry-run') {
            $_GET['dry_run'] = '1';
            continue;
        }

        if ($argument === '--force') {
            $_GET['force'] = '1';
            continue;
        }

        if (str_starts_with($argument, '--activity-id=')) {
            $_GET['activity_id'] = (string) substr($argument, strlen('--activity-id='));
            continue;
        }

        if ($argument !== '') {
            $testNumber = $argument;
        }
    }

    $_GET['test_number'] = $testNumber;
} else {
    $_GET['test_number'] = '573001234567';
}

require __DIR__ . '/api/send-whatsapp-reminders.php';
