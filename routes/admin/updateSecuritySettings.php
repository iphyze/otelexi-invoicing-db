<?php
// PUT /admin/security-settings - update administrator-managed security settings.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can modify security settings.');
    enforceSensitiveActionRateLimit($conn, 'admin_security_settings', (int) $user['id']);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !array_key_exists('max_active_sessions', $data)) {
        throw new Exception('Maximum active devices is required.', 422);
    }

    $raw = $data['max_active_sessions'];
    if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
        throw new Exception('Maximum active devices must be a whole number.', 422);
    }

    $maxSessions = (int) $raw;
    if ($maxSessions < 1 || $maxSessions > 20) {
        throw new Exception('Maximum active devices must be between 1 and 20.', 422);
    }

    $previous = maxConcurrentSessions($conn);
    $saved = saveMaxConcurrentSessions($conn, $maxSessions, (int) $user['id']);

    try {
        $action = 'security.settings_updated';
        $modelType = 'SecuritySettings';
        $description = sprintf(
            '%s changed the maximum active device limit from %d to %d.',
            $user['email'],
            $previous,
            $saved
        );
        $properties = json_encode([
            'previous_max_active_sessions' => $previous,
            'max_active_sessions' => $saved,
        ], JSON_UNESCAPED_SLASHES);
        $ip = clientIpAddress();
        $userId = (int) $user['id'];
        $stmt = $conn->prepare(
            'INSERT INTO activity_log (user_id, action, model_type, description, properties, ip_address) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssss', $userId, $action, $modelType, $description, $properties, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $auditError) {
        error_log('Security Settings Audit Error: ' . $auditError->getMessage());
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Security settings updated successfully.',
        'data' => [
            'max_active_sessions' => $saved,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Update Security Settings Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Security settings could not be updated right now.' : $e->getMessage(),
    ]);
}
