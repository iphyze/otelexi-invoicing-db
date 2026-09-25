<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/mfa.php';

use Respect\Validation\Validator as v;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed.', 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['email'], $data['password'])) {
        throw new Exception('Email and password are required.', 400);
    }

    $email = strtolower(trim((string) $data['email']));
    $password = (string) $data['password'];

    if (!v::email()->validate($email) || $password === '') {
        throw new Exception('Invalid email or password.', 401);
    }

    $identifierHash = hash('sha256', $email);
    $ipAddress = clientIpAddress();
    $windowMinutes = max(1, (int) (config('LOGIN_RATE_WINDOW_MINUTES', '15') ?? '15'));
    $maxAttempts = max(1, (int) (config('LOGIN_MAX_ATTEMPTS', '5') ?? '5'));
    $accountMaxAttempts = max($maxAttempts, (int) (config('LOGIN_ACCOUNT_MAX_ATTEMPTS', '10') ?? '10'));
    $ipMaxAttempts = max($accountMaxAttempts, (int) (config('LOGIN_IP_MAX_ATTEMPTS', '30') ?? '30'));
    $cutoff = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

    $cleanup = $conn->prepare('DELETE FROM auth_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)');
    $cleanup->execute();
    $cleanup->close();

    $attemptStmt = $conn->prepare(
        'SELECT '
        . 'SUM(CASE WHEN identifier_hash = ? AND ip_address = ? THEN 1 ELSE 0 END) AS pair_failures, '
        . 'SUM(CASE WHEN identifier_hash = ? THEN 1 ELSE 0 END) AS account_failures, '
        . 'SUM(CASE WHEN ip_address = ? THEN 1 ELSE 0 END) AS ip_failures '
        . 'FROM auth_login_attempts WHERE success = 0 AND attempted_at >= ?'
    );
    $attemptStmt->bind_param('sssss', $identifierHash, $ipAddress, $identifierHash, $ipAddress, $cutoff);
    $attemptStmt->execute();
    $attempts = $attemptStmt->get_result()->fetch_assoc() ?: [];
    $attemptStmt->close();

    $pairFailures = (int) ($attempts['pair_failures'] ?? 0);
    $accountFailures = (int) ($attempts['account_failures'] ?? 0);
    $ipFailures = (int) ($attempts['ip_failures'] ?? 0);

    if ($pairFailures >= $maxAttempts || $accountFailures >= $accountMaxAttempts || $ipFailures >= $ipMaxAttempts) {
        header('Retry-After: ' . ($windowMinutes * 60));
        throw new Exception('Too many failed sign-in attempts. Please try again later.', 429);
    }

    $stmt = $conn->prepare(
        'SELECT id, name, email, password, role, is_active, auth_version, last_login, created_at, updated_at '
        . 'FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Always perform a password hash verification to reduce account-existence timing differences.
    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $passwordHash = $user ? (string) $user['password'] : $dummyHash;
    $passwordValid = password_verify($password, $passwordHash);
    $authenticated = $user && (int) $user['is_active'] === 1 && $passwordValid;
    if (!$authenticated) {
        $failed = $conn->prepare(
            'INSERT INTO auth_login_attempts (identifier_hash, ip_address, success) VALUES (?, ?, 0)'
        );
        $failed->bind_param('ss', $identifierHash, $ipAddress);
        $failed->execute();
        $failed->close();

        throw new Exception('Invalid email or password.', 401);
    }

    // A legitimate successful login clears stale account-specific failures across networks.
    $clearAttempts = $conn->prepare('DELETE FROM auth_login_attempts WHERE identifier_hash = ?');
    $clearAttempts->bind_param('s', $identifierHash);
    $clearAttempts->execute();
    $clearAttempts->close();

    if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
        $rehash = password_hash($password, PASSWORD_DEFAULT);
        $rehashStmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $rehashStmt->bind_param('si', $rehash, $user['id']);
        $rehashStmt->execute();
        $rehashStmt->close();
        $user['password'] = $rehash;
    }

    $deviceIdentity = ensureDeviceIdentity();

    // Password authentication is only the first factor when email MFA is enabled.
    // No authenticated cookies/session are issued until the one-time code succeeds.
    if (isEmailMfaEnabled($conn, (int) $user['id'])) {
        $mfa = createEmailMfaChallenge(
            $conn,
            $user,
            'login',
            (string) $deviceIdentity['hash']
        );

        logAuthActivity(
            $conn,
            (int) $user['id'],
            'auth.mfa_challenge_sent',
            $user['name'] . ' was sent an email verification code for sign in'
        );

        http_response_code(202);
        echo json_encode([
            'status'  => 'pending',
            'message' => 'Enter the verification code sent to your email address.',
            'reason'  => 'mfa_required',
            'data'    => [
                'mfa_challenge' => $mfa['challenge'],
                'masked_email'  => $mfa['masked_email'],
                'expires_in'    => $mfa['expires_in'],
                'resend_after'  => $mfa['resend_after'],
                'max_attempts'  => $mfa['max_attempts'],
            ],
        ]);
        exit;
    }
    $deviceLimitPayload = null;

    $conn->begin_transaction();
    try {
        // Serialize login/device-limit operations for this account so simultaneous
        // requests cannot exceed the configured active-device limit.
        $lockStmt = $conn->prepare('SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $lockStmt->bind_param('i', $user['id']);
        $lockStmt->execute();
        $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();

        // A fresh login from the same browser replaces that browser's older session
        // rather than consuming another device slot.
        pruneExpiredAuthSessions($conn, (int) $user['id']);
        revokeActiveSessionsForDevice($conn, (int) $user['id'], (string) $deviceIdentity['hash']);

        $activeSessions = getActiveAuthSessions($conn, (int) $user['id']);
        $maxSessions = maxConcurrentSessions($conn);

        if (count($activeSessions) >= $maxSessions) {
            logAuthActivity(
                $conn,
                (int) $user['id'],
                'auth.device_limit_reached',
                $user['name'] . ' reached the active device limit while signing in'
            );

            $deviceLimitPayload = [
                'login_challenge' => buildDeviceLimitChallenge($conn, $user, (string) $deviceIdentity['hash']),
                'max_devices'     => $maxSessions,
                'sessions'        => array_map(
                    static fn(array $session): array => publicAuthSessionData($session),
                    $activeSessions
                ),
            ];
            $conn->commit();
        } else {
            $updateLogin = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $updateLogin->bind_param('i', $user['id']);
            $updateLogin->execute();
            $updateLogin->close();

            $credentials = createLoginCredentials($conn, $user, $deviceIdentity);

            logAuthActivity(
                $conn,
                (int) $user['id'],
                'auth.login',
                $user['name'] . ' logged in successfully from ' . ($credentials['auth_session']['device_label'] ?? 'a device')
            );

            $conn->commit();
        }
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    if ($deviceLimitPayload !== null) {
        http_response_code(409);
        echo json_encode([
            'status'  => 'failed',
            'message' => 'You already have ' . $deviceLimitPayload['max_devices'] . ' active devices. Sign out one device to continue.',
            'reason'  => 'device_limit_reached',
            'data'    => $deviceLimitPayload,
        ]);
        exit;
    }

    issueAuthCookies(
        $credentials['access_token'],
        $credentials['refresh_token'],
        $credentials['csrf_token']
    );
    $user['last_login'] = date('Y-m-d H:i:s');

    jsonSuccess([
        'status'  => 'success',
        'message' => 'Login successful.',
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

    error_log('Login Error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to sign in at this time.' : $e->getMessage(),
    ]);
}
