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
    $currentSessionKey = currentAccessSessionKey();
    if ($currentSessionKey === null) {
        throw new Exception('Current session could not be identified.', 401);
    }

    $sessions = getActiveAuthSessions($conn, (int) $user['id']);
    $revokedCount = 0;

    foreach ($sessions as $session) {
        if (hash_equals((string) $session['session_key'], $currentSessionKey)) {
            continue;
        }

        revokeAuthSessionById($conn, (int) $session['id'], 'user_revoked_other');
        $revokedCount++;
    }

    if ($revokedCount > 0) {
        logAuthActivity(
            $conn,
            (int) $user['id'],
            'auth.other_sessions_revoked',
            $user['name'] . " signed out {$revokedCount} other active device session(s)"
        );
    }

    jsonSuccess([
        'status'  => 'success',
        'message' => $revokedCount > 0 ? 'Other devices have been signed out.' : 'There are no other active devices.',
        'data'    => ['revoked_count' => $revokedCount],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to sign out other devices.' : $e->getMessage(),
    ]);
}
