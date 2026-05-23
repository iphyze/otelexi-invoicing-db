<?php
// cron/documentMaintenance.php
// Daily CLI runner for cPanel Cron Jobs.
// This file must not be exposed as a web endpoint.
//
// Example cPanel Command (adjust PHP version and account path):
// /usr/local/bin/ea-php83 /home/CPANEL_USER/public_html/otelex-server/api/cron/documentMaintenance.php >/dev/null 2>&1

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'failed',
        'message' => 'This maintenance runner may only be executed by the scheduler.',
    ]);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../utils/documentMaintenance.php';

date_default_timezone_set(config('APP_TIMEZONE', 'Africa/Lagos') ?? 'Africa/Lagos');

try {
    $result = runDocumentMaintenance($conn, [
        'id' => null,
        'label' => 'Scheduled cron job',
        'trigger' => 'scheduled',
        'ip' => 'cron',
    ]);

    echo sprintf(
        "[%s] Otelex document checks completed: %d invoice(s) overdue, %d quotation(s) expired, %d proforma(s) expired, %d reminder(s) sent, %d reminder(s) failed.\n",
        date('Y-m-d H:i:s'),
        (int) $result['invoices_marked_overdue'],
        (int) $result['quotation_expired_count'],
        (int) $result['proforma_expired_count'],
        (int) $result['reminders_sent'],
        (int) $result['reminders_failed']
    );
    exit(0);
} catch (Throwable $error) {
    error_log('Cron Document Maintenance Error: ' . $error->getMessage());
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Otelex document checks failed.\n");
    exit(1);
}
