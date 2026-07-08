<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli') {
    $arguments = $argv ?? [];
    array_shift($arguments);

    $_GET['recipient'] = 'all';
    $_GET['dry_run'] = '1';
    $_GET['test_number'] = '573001234567';

    foreach ($arguments as $argument) {
        if ($argument === '--send') {
            unset($_GET['dry_run']);
            continue;
        }

        if ($argument === '--dry-run') {
            $_GET['dry_run'] = '1';
            continue;
        }

        if (str_starts_with($argument, '--recipient=')) {
            $_GET['recipient'] = (string) substr($argument, strlen('--recipient='));
            continue;
        }

        if (str_starts_with($argument, '--admin-number=')) {
            $_GET['admin_number'] = (string) substr($argument, strlen('--admin-number='));
            continue;
        }

        if (str_starts_with($argument, '--content-sid=')) {
            $_GET['content_sid'] = (string) substr($argument, strlen('--content-sid='));
            continue;
        }

        if (str_starts_with($argument, '--admin-content-sid=')) {
            $_GET['admin_content_sid'] = (string) substr($argument, strlen('--admin-content-sid='));
            continue;
        }

        if (str_starts_with($argument, '--professional-content-sid=')) {
            $_GET['professional_content_sid'] = (string) substr($argument, strlen('--professional-content-sid='));
            continue;
        }

        if (str_starts_with($argument, '--customer-content-sid=')) {
            $_GET['customer_content_sid'] = (string) substr($argument, strlen('--customer-content-sid='));
            continue;
        }

        if (str_starts_with($argument, '--professional-number=')) {
            $_GET['professional_number'] = (string) substr($argument, strlen('--professional-number='));
            continue;
        }

        if (str_starts_with($argument, '--customer-number=')) {
            $_GET['customer_number'] = (string) substr($argument, strlen('--customer-number='));
            continue;
        }

        if (str_starts_with($argument, '--service-name=')) {
            $_GET['service_name'] = (string) substr($argument, strlen('--service-name='));
            continue;
        }

        if (str_starts_with($argument, '--customer-name=')) {
            $_GET['customer_name'] = (string) substr($argument, strlen('--customer-name='));
            continue;
        }

        if (str_starts_with($argument, '--professional-name=')) {
            $_GET['professional_name'] = (string) substr($argument, strlen('--professional-name='));
            continue;
        }

        if (str_starts_with($argument, '--date=')) {
            $_GET['date'] = (string) substr($argument, strlen('--date='));
            continue;
        }

        if (str_starts_with($argument, '--start-time=')) {
            $_GET['start_time'] = (string) substr($argument, strlen('--start-time='));
            continue;
        }

        if (str_starts_with($argument, '--notes=')) {
            $_GET['notes'] = (string) substr($argument, strlen('--notes='));
            continue;
        }

        if ($argument !== '') {
            $_GET['test_number'] = $argument;
        }
    }
}

require __DIR__ . '/api/send-whatsapp-booking-tests.php';
