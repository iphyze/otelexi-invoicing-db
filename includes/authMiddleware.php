<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/security.php';

function unauthorized(
    string $message = 'Your session is invalid or has expired.',
    string $reason = 'invalid_session',
    bool $clearAllCookies = false
): void {
    if ($clearAllCookies) {
        clearAuthCookies();
    } else {
        clearAccessCookie();
    }

    http_response_code(401);
    echo json_encode([
        'status'  => 'failed',
        'message' => $message,
        'reason'  => $reason,
    ]);
    exit;
}

function sessionMessageForReason(string $reason): string
{
    return match ($reason) {
        'idle_timeout'     => 'You were signed out after ' . max(1, (int) ceil(sessionIdleTimeoutSeconds() / 60)) . ' minutes of inactivity.',
        'absolute_timeout' => 'Your session has reached its maximum duration. Please sign in again.',
        'deactivated'      => 'Your account is unavailable. Please contact the administrator.',
        'device_mismatch'   => 'This session is no longer valid on this device. Please sign in again.',
        default            => 'Your session is invalid or has expired.',
    };
}

function authenticateUser(bool $touchSession = false): array
{
    global $conn;

    requireCsrfProtection();

    $token = $_COOKIE[accessCookieName()] ?? '';
    if ($token === '') {
        unauthorized('Authentication is required.', 'authentication_required');
    }

    try {
        $decoded = (array) JWT::decode($token, new Key(jwtSecret(), 'HS256'));

        if (($decoded['type'] ?? '') !== 'access'
            || ($decoded['iss'] ?? '') !== jwtIssuer()
            || ($decoded['aud'] ?? '') !== jwtAudience()
            || empty($decoded['id'])) {
            unauthorized();
        }

        $userId = (int) $decoded['id'];
        $stmt = $conn->prepare(
            'SELECT id, name, email, role, is_active, auth_version, last_login, created_at, updated_at FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || (int) $user['is_active'] !== 1) {
            if (!empty($decoded['sid'])) {
                revokeAuthSessionByKey($conn, $userId, (string) $decoded['sid'], 'deactivated');
            }
            unauthorized('Your account is unavailable. Please contact the administrator.', 'deactivated', true);
        }

        if ((int) ($decoded['ver'] ?? 0) !== (int) $user['auth_version']) {
            if (!empty($decoded['sid'])) {
                revokeAuthSessionByKey($conn, $userId, (string) $decoded['sid'], 'security_change');
            }
            unauthorized('Your session has changed. Please sign in again.', 'security_change', true);
        }

        // Access tokens issued before logical sessions were introduced are deliberately
        // pushed through the refresh endpoint, which upgrades them without forcing a login.
        $sessionKey = trim((string) ($decoded['sid'] ?? ''));
        if ($sessionKey === '') {
            unauthorized('Your session needs to be refreshed.', 'session_refresh_required');
        }

        $session = getAuthSessionByKey($conn, $userId, $sessionKey);
        $invalidReason = authSessionInvalidReason($session);
        if ($invalidReason !== null) {
            if ($session && in_array($invalidReason, ['idle_timeout', 'absolute_timeout'], true)) {
                revokeAuthSessionById($conn, (int) $session['id'], $invalidReason);
            }

            unauthorized(sessionMessageForReason($invalidReason), $invalidReason, true);
        }

        $deviceIdentity = ensureDeviceIdentity();
        $session = bindOrValidateSessionDevice($conn, $session, $deviceIdentity);
        if ($session === null) {
            revokeAuthSessionByKey($conn, $userId, $sessionKey, 'device_mismatch');
            unauthorized(sessionMessageForReason('device_mismatch'), 'device_mismatch', true);
        }

        if ($touchSession) {
            touchAuthSessionActivity($conn, $session, true);
        }

        return publicUserData($user);
    } catch (Throwable $e) {
        // If the response has already been terminated by unauthorized(), execution never reaches here.
        unauthorized();
    }
}
