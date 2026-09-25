<?php
// routes/admin/getMailDiagnostics.php
// Returns password-free SMTP configuration details for the Super Admin.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/mailer.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can access mail diagnostics.');

    $summary = mailConfigurationSummary();

    echo json_encode([
        'status' => 'success',
        'message' => 'Mail configuration loaded successfully.',
        'data' => $summary,
    ]);
} catch (Throwable $e) {
    error_log('Mail Diagnostics Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Mail diagnostics could not be loaded right now.' : $e->getMessage(),
    ]);
}
