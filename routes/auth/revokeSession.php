<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed.', 405);
    }

    $user = authenticateUser();
    $data = json_decode(file_get_contents('php://input'), true);
    $sessionKey = trim((string) ($data['session_key'] ?? ''));

    if (!preg_match('/^[a-f0-9]{64}$/', $sessionKey)) {
        throw new Exception('A valid session is required.', 400);
    }

    $target = getAuthSessionByKey($conn, (int) $user['id'], $sessionKey);
    if (!$target || authSessionInvalidReason($target) !== null) {
        throw new Exception('That session is no longer active.', 404);
    }

    $currentSessionKey = currentAccessSessionKey();
    $revokedCurrentSession = $currentSessionKey !== null && hash_equals($currentSessionKey, $sessionKey);

    revokeAuthSessionById($conn, (int) $target['id'], 'user_revoked');
    logAuthActivity(
        $conn,
        (int) $user['id'],
        'auth.session_revoked',
        $user['name'] . ' signed out ' . ($revokedCurrentSession ? 'the current device' : 'another active device')
    );

    if ($revokedCurrentSession) {
        clearAuthCookies();
    }

    jsonSuccess([
        'status'  => 'success',
        'message' => $revokedCurrentSession ? 'This device has been signed out.' : 'Device signed out successfully.',
        'data'    => [
            'current_session_revoked' => $revokedCurrentSession,
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to sign out the selected device.' : $e->getMessage(),
    ]);
}
