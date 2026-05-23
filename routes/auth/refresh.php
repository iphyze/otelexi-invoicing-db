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
    if ($parsed === null) {
        clearAuthCookies();
        throw new Exception('Session expired. Please sign in again.', 401);
    }

    $stmt = $conn->prepare(
        'SELECT rt.id AS refresh_id, rt.user_id, rt.token_hash, rt.csrf_hash, rt.expires_at, rt.revoked_at, '
        . 'u.id, u.name, u.email, u.role, u.is_active, u.auth_version, u.last_login, u.created_at, u.updated_at '
        . 'FROM auth_refresh_tokens rt JOIN users u ON u.id = rt.user_id WHERE rt.selector = ? LIMIT 1'
    );
    $stmt->bind_param('s', $parsed['selector']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $csrfToken = $_COOKIE[csrfCookieName()] ?? '';
    $isValid = $row
        && $row['revoked_at'] === null
        && strtotime($row['expires_at']) > time()
        && (int) $row['is_active'] === 1
        && hash_equals($row['token_hash'], hash('sha256', $parsed['validator']))
        && hash_equals($row['csrf_hash'], hash('sha256', $csrfToken));

    if (!$isValid) {
        if ($row) {
            revokeRefreshTokenBySelector($conn, $parsed['selector']);
        }
        clearAuthCookies();
        throw new Exception('Session expired. Please sign in again.', 401);
    }

    $conn->begin_transaction();
    try {
        revokeRefreshTokenBySelector($conn, $parsed['selector']);
        $newCsrfToken = randomUrlSafeToken(32);
        $newRefreshToken = createRefreshSession($conn, (int) $row['user_id'], $newCsrfToken);
        $accessToken = buildAccessToken($row);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    issueAuthCookies($accessToken, $newRefreshToken, $newCsrfToken);

    jsonSuccess([
        'status'  => 'success',
        'message' => 'Session refreshed.',
        'data'    => [
            'user'       => publicUserData($row),
            'csrf_token' => $newCsrfToken,
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    error_log('Session Refresh Error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to refresh the session.' : $e->getMessage(),
    ]);
}
