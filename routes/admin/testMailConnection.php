<?php
// routes/admin/testMailConnection.php
// Tests SMTP connection/authentication without sending an email.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/mailer.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can test the mail connection.');

    $result = testMailTransportConnection();

    echo json_encode([
        'status' => 'success',
        'message' => $result['success']
            ? 'SMTP connection test passed.'
            : 'SMTP connection test completed with an error.',
        'data' => $result,
    ]);
} catch (Throwable $e) {
    error_log('Mail Connection Test Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'The SMTP connection test could not be completed right now.' : $e->getMessage(),
    ]);
}
