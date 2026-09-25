<?php
// routes/admin/getMailSettings.php
// Returns non-sensitive provider/routing settings for the Super Admin.

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
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can view mail settings.');

    echo json_encode([
        'status' => 'success',
        'message' => 'Mail settings loaded successfully.',
        'data' => mailProvidersSummary(),
    ]);
} catch (Throwable $e) {
    error_log('Get Mail Settings Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Mail settings could not be loaded right now.' : $e->getMessage(),
    ]);
}
