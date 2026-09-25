<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/security.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed.', 405);
    }

    requireCsrfProtection();

    $parsed = parseRefreshCookie($_COOKIE[refreshCookieName()] ?? null);
    if ($parsed !== null) {
        $stmt = $conn->prepare('SELECT session_id FROM auth_refresh_tokens WHERE selector = ? LIMIT 1');
        $stmt->bind_param('s', $parsed['selector']);
        $stmt->execute();
        $tokenRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($tokenRow['session_id'])) {
            revokeAuthSessionById($conn, (int) $tokenRow['session_id'], 'logout');
        } else {
            revokeRefreshTokenBySelector($conn, $parsed['selector']);
        }
    }

    clearAuthCookies();

    jsonSuccess([
        'status'  => 'success',
        'message' => 'You have been signed out.',
    ]);
} catch (Throwable $e) {
    clearAuthCookies();
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status'  => 'failed',
        'message' => 'Unable to complete sign out.',
    ]);
}
