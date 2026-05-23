<?php
// routes/automation/runDocumentMaintenance.php
// POST /automation/document-maintenance/run
// Admin-only local/manual trigger for invoice overdue checks, reminders and document expiry.

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
        throw new Exception('Only an administrator can run document checks.', 403);
    }

    $result = runDocumentMaintenance($conn, [
        'id' => (int) $user['id'],
        'label' => (string) $user['email'],
        'trigger' => 'manual',
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
    ]);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Document checks completed successfully.',
        'data' => $result,
    ]);
} catch (Throwable $error) {
    error_log('Run Document Maintenance Error: ' . $error->getMessage());

    $code = (int) $error->getCode();
    $isClientError = in_array($code, [400, 403, 405, 409, 422], true);

    http_response_code($isClientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $isClientError
            ? $error->getMessage()
            : 'Document checks could not be completed right now.',
    ]);
}
