<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed.', 405);
    }

    $user = authenticateUser();
    $currentSessionKey = currentAccessSessionKey();
    $sessions = getActiveAuthSessions($conn, (int) $user['id']);

    jsonSuccess([
        'status' => 'success',
        'data'   => [
            'max_devices' => maxConcurrentSessions($conn),
            'sessions'    => array_map(
                static fn(array $session): array => publicAuthSessionData($session, $currentSessionKey),
                $sessions
            ),
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to load active sessions.' : $e->getMessage(),
    ]);
}
