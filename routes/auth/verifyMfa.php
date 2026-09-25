<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/mfa.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed.', 405);
    }

    // This is a pre-authentication endpoint. The short-lived MFA challenge is
    // high-entropy, single-use, device-bound and is only returned to the
    // same-origin login flow, so it acts as the request capability here.
    // Requiring the authenticated-session CSRF cookie at this stage can break
    // legitimate MFA sign-ins before a session exists.
    enforceAuthRateLimit(
        $conn,
        'mfa_verify_ip',
        clientIpAddress(),
        max(5, (int) (config('MFA_IP_MAX_VERIFY_ATTEMPTS', '20') ?? '20')),
        max(300, (int) (config('MFA_RATE_WINDOW_MINUTES', '30') ?? '30') * 60),
        'Too many verification attempts from this network. Please wait and try again.'
    );

    $data = json_decode(file_get_contents('php://input'), true);
    $challengeToken = trim((string) ($data['mfa_challenge'] ?? ''));
    $code = trim((string) ($data['code'] ?? ''));

    if ($challengeToken === '' || $code === '') {
        throw new Exception('Verification challenge and code are required.', 400);
    }

    $deviceIdentity = ensureDeviceIdentity();
    $deviceHash = (string) $deviceIdentity['hash'];

    // Validate the code before the larger sign-in transaction so incorrect
    // attempts are persisted. Successful challenge consumption happens inside
    // the session transaction, which means an unexpected session-creation
    // failure does not permanently burn a valid code.
    $challenge = validateMfaCode($conn, $challengeToken, $code, 'login', $deviceHash);
    $userId = (int) $challenge['user_id'];
    $deviceLimitPayload = null;
    $credentials = null;
    $user = null;

    $conn->begin_transaction();
    try {
        $userStmt = $conn->prepare(
            'SELECT id, name, email, password, role, is_active, auth_version, last_login, created_at, updated_at '
            . 'FROM users WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if (!$user || (int) $user['is_active'] !== 1 || (int) $user['auth_version'] !== (int) $challenge['auth_version']) {
            throw new Exception('Your account security state has changed. Please sign in again.', 401);
        }

        if (!isEmailMfaEnabled($conn, $userId)) {
            throw new Exception('Email verification is no longer enabled for this account. Please sign in again.', 401);
        }

        // Consume only the exact code/challenge that was validated. If a resend
        // happened in between, this safely fails instead of accepting an old code.
        consumeMfaChallenge($conn, $challenge);

        logAuthActivity(
            $conn,
            $userId,
            'auth.mfa_verified',
            $user['name'] . ' completed email verification for sign in'
        );

        pruneExpiredAuthSessions($conn, $userId);
        revokeActiveSessionsForDevice($conn, $userId, $deviceHash);

        $activeSessions = getActiveAuthSessions($conn, $userId);
        $maxSessions = maxConcurrentSessions($conn);

        if (count($activeSessions) >= $maxSessions) {
            logAuthActivity(
                $conn,
                $userId,
                'auth.device_limit_reached',
                $user['name'] . ' reached the active device limit after email verification'
            );

            $deviceLimitPayload = [
                'login_challenge' => buildDeviceLimitChallenge($conn, $user, $deviceHash),
                'max_devices'     => $maxSessions,
                'sessions'        => array_map(
                    static fn(array $session): array => publicAuthSessionData($session),
                    $activeSessions
                ),
            ];
            $conn->commit();
        } else {
            $updateLogin = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $updateLogin->bind_param('i', $userId);
            $updateLogin->execute();
            $updateLogin->close();

            $credentials = createLoginCredentials($conn, $user, $deviceIdentity);
            logAuthActivity(
                $conn,
                $userId,
                'auth.login',
                $user['name'] . ' logged in successfully with email MFA from '
                . ($credentials['auth_session']['device_label'] ?? 'a device')
            );

            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($conn->errno === 0 || $conn->thread_id) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
                // Preserve the original exception.
            }
        }
        throw $e;
    }

    if ($deviceLimitPayload !== null) {
        http_response_code(409);
        echo json_encode([
            'status'  => 'failed',
            'message' => 'Email verification succeeded, but your active device limit has been reached. Sign out one device to continue.',
            'reason'  => 'device_limit_reached',
            'data'    => $deviceLimitPayload,
        ]);
        exit;
    }

    if (!$credentials || !$user) {
        throw new RuntimeException('Unable to create the authenticated session.');
    }

    issueAuthCookies(
        $credentials['access_token'],
        $credentials['refresh_token'],
        $credentials['csrf_token']
    );
    $user['last_login'] = date('Y-m-d H:i:s');

    jsonSuccess([
        'status'  => 'success',
        'message' => 'Verification successful. You are now signed in.',
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

    error_log('MFA Verify Error: ' . $e->getMessage());
    $schemaIssue = $code >= 500 && preg_match(
        '/auth_sessions|auth_refresh_tokens|auth_mfa_challenges|unknown column|doesn\'t exist|base table or view not found/i',
        $e->getMessage()
    );
    $publicMessage = $code >= 500 ? 'Unable to verify this sign-in request.' : $e->getMessage();
    if ($schemaIssue && !isProductionEnvironment()) {
        $publicMessage = 'The local security database schema is incomplete. Apply the latest security migrations and try again.';
    }

    http_response_code($code);
    echo json_encode([
        'status'  => 'failed',
        'message' => $publicMessage,
        'reason'  => $schemaIssue
            ? 'security_schema_incomplete'
            : (in_array($code, [401, 403, 410, 422, 429], true) ? 'mfa_verification_failed' : 'mfa_internal_error'),
    ]);
}
