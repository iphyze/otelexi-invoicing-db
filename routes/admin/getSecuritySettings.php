<?php
// GET /admin/security-settings - Super Admin security/session policy overview.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can view security settings.');

    ensureSecuritySettingsTable($conn);
    $maxSessions = maxConcurrentSessions($conn);
    $idleSeconds = sessionIdleTimeoutSeconds();
    $cutoff = date('Y-m-d H:i:s', time() - $idleSeconds);

    $statsStmt = $conn->prepare(
        'SELECT COUNT(*) AS active_sessions, COUNT(DISTINCT user_id) AS users_with_sessions '
        . 'FROM auth_sessions WHERE revoked_at IS NULL AND absolute_expires_at > NOW() AND last_activity_at > ?'
    );
    $statsStmt->bind_param('s', $cutoff);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc() ?: [];
    $statsStmt->close();

    $overLimitStmt = $conn->prepare(
        'SELECT COUNT(*) AS users_over_limit FROM ('
        . 'SELECT user_id FROM auth_sessions '
        . 'WHERE revoked_at IS NULL AND absolute_expires_at > NOW() AND last_activity_at > ? '
        . 'GROUP BY user_id HAVING COUNT(*) > ?'
        . ') active_over_limit'
    );
    $overLimitStmt->bind_param('si', $cutoff, $maxSessions);
    $overLimitStmt->execute();
    $overLimit = $overLimitStmt->get_result()->fetch_assoc() ?: [];
    $overLimitStmt->close();

    echo json_encode([
        'status' => 'success',
        'data' => [
            'max_active_sessions' => $maxSessions,
            'allowed_max_active_sessions' => 20,
            'session_policy' => [
                'idle_timeout_seconds' => $idleSeconds,
                'idle_warning_seconds' => sessionIdleWarningSeconds(),
                'absolute_timeout_seconds' => sessionAbsoluteLifetimeSeconds(),
                'activity_write_interval_seconds' => sessionActivityWriteIntervalSeconds(),
            ],
            'summary' => [
                'active_sessions' => (int) ($stats['active_sessions'] ?? 0),
                'users_with_sessions' => (int) ($stats['users_with_sessions'] ?? 0),
                'users_over_limit' => (int) ($overLimit['users_over_limit'] ?? 0),
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Get Security Settings Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Security settings could not be loaded right now.' : $e->getMessage(),
    ]);
}
