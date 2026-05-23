<?php
// routes/invoices/markOverdue.php
// Backward-compatible admin endpoint for overdue invoices and scheduled reminders.
// New complete document checks use POST /automation/document-maintenance/run.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../utils/documentMaintenance.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();

    if (($user['role'] ?? '') !== 'super_admin') {
        throw new Exception('Only an administrator can run overdue processing.', 403);
    }

    $actor = [
        'id' => (int) $user['id'],
        'label' => (string) $user['email'],
        'trigger' => 'manual',
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
    ];

    $markedOverdue = markPastDueInvoices($conn, $actor);
    $reminders = processScheduledInvoiceReminders($conn, $actor);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => "{$markedOverdue} invoice(s) marked overdue. {$reminders['sent']} reminder email(s) sent.",
        'data' => [
            'marked_overdue' => $markedOverdue,
            'reminders_sent' => $reminders['sent'],
            'reminders_failed' => $reminders['failed'],
            'reminders_skipped' => $reminders['skipped'],
        ],
    ]);
} catch (Throwable $error) {
    error_log('Mark Overdue Error: ' . $error->getMessage());

    $code = (int) $error->getCode();
    $isClientError = in_array($code, [400, 403, 405], true);

    http_response_code($isClientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $isClientError
            ? $error->getMessage()
            : 'Overdue invoice processing could not be completed right now.',
    ]);
}
