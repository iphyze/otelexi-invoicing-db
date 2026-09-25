<?php
// routes/admin/testMailProvider.php
// Tests one configured mail provider without sending an email.

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
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can test mail providers.');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid or missing JSON payload.', 400);
    }

    $provider = normalizeMailProvider((string) ($data['provider'] ?? ''), false);
    // Testing is allowed before a provider is enabled so configuration can be
    // verified safely before it becomes available for production sends.
    $result = testMailProviderConnection($provider, false);

    echo json_encode([
        'status' => 'success',
        'message' => $result['success']
            ? ucfirst($provider) . ' connection test passed.'
            : ucfirst($provider) . ' connection test completed with an error.',
        'data' => $result,
    ]);
} catch (Throwable $e) {
    error_log('Mail Provider Test Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'The provider connection test could not be completed right now.' : $e->getMessage(),
    ]);
}
