<?php
// POST /admin/security-sessions/revoke - revoke one active session as Super Admin.

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
    enforceSensitiveActionRateLimit($conn, 'admin_session_revoke', (int) $actor['id']);

    $data = json_decode(file_get_contents('php://input'), true);
    $sessionKey = trim((string) ($data['session_key'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $sessionKey)) {
        throw new Exception('A valid session is required.', 422);
    }

    $stmt = $conn->prepare(
        'SELECT s.id, s.user_id, s.revoked_at, u.name, u.email '
        . 'FROM auth_sessions s JOIN users u ON u.id = s.user_id WHERE s.session_key = ? LIMIT 1'
    );
    $stmt->bind_param('s', $sessionKey);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target || $target['revoked_at'] !== null) {
        throw new Exception('That session is no longer active.', 404);
    }

    $currentSessionKey = currentAccessSessionKey();
    if ((int) $target['user_id'] === (int) $actor['id']
        && $currentSessionKey !== null
        && hash_equals($currentSessionKey, $sessionKey)) {
        throw new Exception('Use My Profile to sign out your current device.', 422);
    }

    revokeAuthSessionById($conn, (int) $target['id'], 'admin_revoked');

    $action = 'auth.session_admin_revoked';
    $modelType = 'User';
    $modelId = (int) $target['user_id'];
    $description = sprintf('%s revoked an active session for %s (%s).', $actor['email'], $target['name'], $target['email']);
    $ip = clientIpAddress();
    $actorId = (int) $actor['id'];
    $audit = $conn->prepare(
        'INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    $audit->bind_param('ississ', $actorId, $action, $modelType, $modelId, $description, $ip);
    $audit->execute();
    $audit->close();

    echo json_encode([
        'status' => 'success',
        'message' => 'The selected device has been signed out.',
    ]);
} catch (Throwable $e) {
    error_log('Admin Revoke Security Session Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'The selected session could not be revoked.' : $e->getMessage(),
    ]);
}
