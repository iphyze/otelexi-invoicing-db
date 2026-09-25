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
        'SELECT rt.id AS refresh_id, rt.user_id, rt.session_id, rt.token_hash, rt.csrf_hash, rt.expires_at, rt.revoked_at, '
        . 'u.id, u.name, u.email, u.role, u.is_active, u.auth_version, u.last_login, u.created_at, u.updated_at, '
        . 's.session_key, s.device_hash, s.device_label, s.ip_address AS session_ip_address, s.user_agent AS session_user_agent, '
        . 's.last_activity_at, s.absolute_expires_at, s.revoked_at AS session_revoked_at, '
        . 's.revocation_reason AS session_revocation_reason '
        . 'FROM auth_refresh_tokens rt '
        . 'JOIN users u ON u.id = rt.user_id '
        . 'LEFT JOIN auth_sessions s ON s.id = rt.session_id '
        . 'WHERE rt.selector = ? LIMIT 1'
    );
    $stmt->bind_param('s', $parsed['selector']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $csrfToken = $_COOKIE[csrfCookieName()] ?? '';
    $isBaseValid = $row
        && $row['revoked_at'] === null
        && strtotime((string) $row['expires_at']) > time()
        && (int) $row['is_active'] === 1
        && hash_equals((string) $row['token_hash'], hash('sha256', $parsed['validator']))
        && hash_equals((string) $row['csrf_hash'], hash('sha256', $csrfToken));

    if (!$isBaseValid) {
        if ($row && $row['revoked_at'] === null) {
            revokeRefreshTokenBySelector($conn, $parsed['selector']);
        }
        clearAuthCookies();
        throw new Exception('Session expired. Please sign in again.', 401);
    }

    $authSession = null;
    if (!empty($row['session_id'])) {
        $authSession = [
            'id'                  => (int) $row['session_id'],
            'session_key'         => $row['session_key'],
            'user_id'             => (int) $row['user_id'],
            'device_hash'         => $row['device_hash'],
            'device_label'        => $row['device_label'],
            'ip_address'          => $row['session_ip_address'],
            'user_agent'          => $row['session_user_agent'],
            'last_activity_at'    => $row['last_activity_at'],
            'absolute_expires_at' => $row['absolute_expires_at'],
            'revoked_at'          => $row['session_revoked_at'],
            'revocation_reason'   => $row['session_revocation_reason'],
        ];

        $invalidReason = authSessionInvalidReason($authSession);
        if ($invalidReason !== null) {
            if (in_array($invalidReason, ['idle_timeout', 'absolute_timeout'], true)) {
                revokeAuthSessionById($conn, (int) $authSession['id'], $invalidReason);
            }
            clearAuthCookies();

            $message = $invalidReason === 'idle_timeout'
                ? 'You were signed out after ' . max(1, (int) ceil(sessionIdleTimeoutSeconds() / 60)) . ' minutes of inactivity.'
                : ($invalidReason === 'absolute_timeout'
                    ? 'Your session has reached its maximum duration. Please sign in again.'
                    : 'Session expired. Please sign in again.');

            http_response_code(401);
            echo json_encode([
                'status'  => 'failed',
                'message' => $message,
                'reason'  => $invalidReason,
            ]);
            exit;
        }

        $deviceIdentity = ensureDeviceIdentity();
        $authSession = bindOrValidateSessionDevice($conn, $authSession, $deviceIdentity);
        if ($authSession === null) {
            revokeAuthSessionById($conn, (int) $row['session_id'], 'device_mismatch');
            clearAuthCookies();
            http_response_code(401);
            echo json_encode([
                'status'  => 'failed',
                'message' => 'This session is no longer valid on this device. Please sign in again.',
                'reason'  => 'device_mismatch',
            ]);
            exit;
        }
    } else {
        $deviceIdentity = ensureDeviceIdentity();
    }

    $conn->begin_transaction();
    try {
        // Upgrade refresh tokens created before logical sessions existed.
        if ($authSession === null) {
            $authSession = createAuthSession($conn, (int) $row['user_id'], $deviceIdentity);
        }

        revokeRefreshTokenBySelector($conn, $parsed['selector']);
        $newCsrfToken = randomUrlSafeToken(32);
        $newRefreshToken = createRefreshSession(
            $conn,
            (int) $row['user_id'],
            $newCsrfToken,
            (int) $authSession['id']
        );
        $accessToken = buildAccessToken($row, (string) $authSession['session_key']);
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
            'user'           => publicUserData($row),
            'csrf_token'     => $newCsrfToken,
            'session_policy' => sessionPolicyData($conn),
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
        'reason'  => $code === 401 ? 'session_expired' : null,
    ]);
}
