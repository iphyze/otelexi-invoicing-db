<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/security.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed.', 405);
    }

    // Pre-auth challenge endpoints are protected by short-lived, single-use,
    // device-bound capability tokens and global origin checks rather than an
    // authenticated-session CSRF cookie that does not yet exist.
    enforceAuthRateLimit(
        $conn,
        'device_limit_resolution_ip',
        clientIpAddress(),
        max(5, (int) (config('AUTH_DEVICE_LIMIT_MAX_ATTEMPTS', '20') ?? '20')),
        max(300, (int) (config('LOGIN_RATE_WINDOW_MINUTES', '15') ?? '15') * 60),
        'Too many device verification attempts. Please wait and try again.'
    );

    $data = json_decode(file_get_contents('php://input'), true);
    $challengeToken = trim((string) ($data['login_challenge'] ?? ''));
    $sessionKey = trim((string) ($data['session_key'] ?? ''));

    if ($challengeToken === '' || !preg_match('/^[a-f0-9]{64}$/', $sessionKey)) {
        throw new Exception('Select an active device to sign out.', 400);
    }

    try {
        $challenge = decodeDeviceLimitChallenge($challengeToken);
    } catch (Throwable $e) {
        throw new Exception('This sign-in request has expired. Please enter your password again.', 401);
    }

    $userId = (int) $challenge['id'];
    $deviceIdentity = ensureDeviceIdentity();

    $conn->begin_transaction();
    try {
        // Serialize concurrent login/device-limit operations for this user.
        $userStmt = $conn->prepare(
            'SELECT id, name, email, role, is_active, auth_version, last_login, created_at, updated_at '
            . 'FROM users WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if (!$user || (int) $user['is_active'] !== 1 || (int) $user['auth_version'] !== (int) $challenge['ver']) {
            throw new Exception('Your account security state has changed. Please sign in again.', 401);
        }

        $storedChallenge = getValidDeviceLimitChallenge($conn, $challenge);
        if (!$storedChallenge
            || !hash_equals((string) $challenge['dev'], (string) $deviceIdentity['hash'])) {
            throw new Exception('This sign-in request has expired or is not valid on this device. Please sign in again.', 401);
        }

        pruneExpiredAuthSessions($conn, $userId);
        revokeActiveSessionsForDevice($conn, $userId, (string) $deviceIdentity['hash'], 'replaced_login');

        $target = getAuthSessionByKey($conn, $userId, $sessionKey);
        if ($target && authSessionInvalidReason($target) === null) {
            revokeAuthSessionById($conn, (int) $target['id'], 'device_limit_replaced');
            logAuthActivity(
                $conn,
                $userId,
                'auth.session_revoked_for_login',
                $user['name'] . ' signed out an existing device to continue a new sign-in'
            );
        }

        $activeSessions = getActiveAuthSessions($conn, $userId);
        $maxSessions = maxConcurrentSessions($conn);
        markLoginChallengeUsed($conn, (int) $storedChallenge['id']);

        if (count($activeSessions) >= $maxSessions) {
            $newChallenge = buildDeviceLimitChallenge($conn, $user, (string) $deviceIdentity['hash']);
            $conn->commit();
            http_response_code(409);
            echo json_encode([
                'status'  => 'failed',
                'message' => "You still have {$maxSessions} active devices. Sign out another device to continue.",
                'reason'  => 'device_limit_reached',
                'data'    => [
                    'login_challenge' => $newChallenge,
                    'max_devices'     => $maxSessions,
                    'sessions'        => array_map(
                        static fn(array $session): array => publicAuthSessionData($session),
                        $activeSessions
                    ),
                ],
            ]);
            exit;
        }

        $updateLogin = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $updateLogin->bind_param('i', $userId);
        $updateLogin->execute();
        $updateLogin->close();

        $credentials = createLoginCredentials($conn, $user, $deviceIdentity);
        logAuthActivity(
            $conn,
            $userId,
            'auth.login',
            $user['name'] . ' logged in successfully after replacing an active device'
        );

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    issueAuthCookies(
        $credentials['access_token'],
        $credentials['refresh_token'],
        $credentials['csrf_token']
    );
    $user['last_login'] = date('Y-m-d H:i:s');

    jsonSuccess([
        'status'  => 'success',
        'message' => 'Device signed out and login completed.',
        'data'    => [
            'user'           => publicUserData($user),
            'csrf_token'     => $credentials['csrf_token'],
            'session_policy' => sessionPolicyData($conn),
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }

    error_log('Resolve Device Limit Error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to complete this sign-in request.' : $e->getMessage(),
        'reason'  => $code === 401 ? 'login_challenge_expired' : null,
    ]);
}
