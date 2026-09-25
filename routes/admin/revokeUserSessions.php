<?php
// POST /admin/security-sessions/revoke-user - revoke all active sessions for another user.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $actor = authenticateUser();
    requireRole($actor, [ROLE_SUPER_ADMIN], 'Only the Super Admin can revoke user sessions.');
    enforceSensitiveActionRateLimit($conn, 'admin_user_sessions_revoke', (int) $actor['id']);

    $data = json_decode(file_get_contents('php://input'), true);
    $targetUserId = (int) ($data['user_id'] ?? 0);
    if ($targetUserId <= 0) {
        throw new Exception('A valid user is required.', 422);
    }
    if ($targetUserId === (int) $actor['id']) {
        throw new Exception('Use My Profile to manage your own sessions.', 422);
    }

    $stmt = $conn->prepare('SELECT id, name, email FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $targetUserId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        throw new Exception('User not found.', 404);
    }

    revokeAuthSessionsForUser($conn, $targetUserId, 'admin_revoked');

    $action = 'auth.user_sessions_admin_revoked';
    $modelType = 'User';
    $description = sprintf('%s signed out all active devices for %s (%s).', $actor['email'], $target['name'], $target['email']);
    $ip = clientIpAddress();
    $actorId = (int) $actor['id'];
    $audit = $conn->prepare(
        'INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    $audit->bind_param('ississ', $actorId, $action, $modelType, $targetUserId, $description, $ip);
    $audit->execute();
    $audit->close();

    echo json_encode([
        'status' => 'success',
        'message' => 'All active devices for this user have been signed out.',
    ]);
} catch (Throwable $e) {
    error_log('Admin Revoke User Sessions Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'The user sessions could not be revoked.' : $e->getMessage(),
    ]);
}
