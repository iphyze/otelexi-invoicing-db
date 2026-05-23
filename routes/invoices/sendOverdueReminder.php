<?php
// routes/invoices/sendOverdueReminder.php
// POST /invoices/{id}/send-reminder
// Manually send a reminder for an already-overdue invoice.

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

    if (!in_array($user['role'] ?? '', ['super_admin', 'admin', 'accounting'], true)) {
        throw new Exception('Only an administrator or accounting can send payment reminders.', 403);
    }

    $invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($invoiceId < 1) {
        throw new Exception('A valid invoice is required.', 400);
    }

    $result = sendInvoiceOverdueReminder($conn, $invoiceId, [
        'id' => (int) $user['id'],
        'label' => (string) $user['email'],
        'trigger' => 'manual',
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
    ], true);

    if ($result['status'] === 'failed') {
        throw new Exception($result['message'], 500);
    }

    if ($result['status'] === 'skipped') {
        throw new Exception($result['message'], 409);
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => $result['message'],
        'data' => $result,
    ]);
} catch (Throwable $error) {
    error_log('Send Overdue Reminder Error: ' . $error->getMessage());

    $code = (int) $error->getCode();
    $isClientError = in_array($code, [400, 403, 405, 409, 422], true);

    http_response_code($isClientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $isClientError
            ? $error->getMessage()
            : 'The overdue reminder could not be sent right now.',
    ]);
}
