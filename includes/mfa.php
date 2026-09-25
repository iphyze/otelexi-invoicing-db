<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../utils/mailer.php';

/**
 * Email MFA helpers.
 *
 * MFA codes are never stored in plaintext. The database stores an HMAC of the
 * code using the application JWT secret plus the opaque challenge token.
 */

function mfaCodeExpirySeconds(): int
{
    return min(1800, max(300, (int) (config('MFA_CODE_EXPIRES_SECONDS', '600') ?? '600')));
}

function mfaMaxAttempts(): int
{
    return min(10, max(3, (int) (config('MFA_MAX_ATTEMPTS', '5') ?? '5')));
}

function mfaResendCooldownSeconds(): int
{
    return min(300, max(30, (int) (config('MFA_RESEND_COOLDOWN_SECONDS', '60') ?? '60')));
}

function mfaMaxEmailsPerWindow(): int
{
    return min(20, max(3, (int) (config('MFA_MAX_EMAILS_PER_WINDOW', '5') ?? '5')));
}

function mfaRateWindowMinutes(): int
{
    return min(120, max(10, (int) (config('MFA_RATE_WINDOW_MINUTES', '30') ?? '30')));
}

function ensureMfaTables(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS user_security_settings (
            user_id INT(11) NOT NULL,
            email_mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
            email_mfa_enabled_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_user_security_settings_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS auth_mfa_challenges (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT(11) NOT NULL,
            purpose VARCHAR(32) NOT NULL,
            challenge_hash CHAR(64) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            device_hash CHAR(64) DEFAULT NULL,
            auth_version INT(10) UNSIGNED NOT NULL DEFAULT 1,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            send_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
            last_sent_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_auth_mfa_challenge_hash (challenge_hash),
            KEY idx_auth_mfa_user_purpose (user_id, purpose, used_at, expires_at),
            KEY idx_auth_mfa_cleanup (expires_at, used_at),
            CONSTRAINT fk_auth_mfa_challenges_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS user_mfa_onboarding (
            user_id INT(11) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            prompted_at DATETIME DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_user_mfa_onboarding_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function isEmailMfaEnabled(mysqli $conn, int $userId): bool
{
    try {
        $stmt = $conn->prepare('SELECT email_mfa_enabled FROM user_security_settings WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['email_mfa_enabled'] ?? 0) === 1;
    } catch (Throwable $e) {
        // Before the migration is applied MFA safely remains disabled.
        return false;
    }
}

function setEmailMfaEnabled(mysqli $conn, int $userId, bool $enabled): void
{
    $enabledInt = $enabled ? 1 : 0;
    $enabledAt = $enabled ? date('Y-m-d H:i:s') : null;

    $stmt = $conn->prepare(
        'INSERT INTO user_security_settings (user_id, email_mfa_enabled, email_mfa_enabled_at) '
        . 'VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE email_mfa_enabled = VALUES(email_mfa_enabled), '
        . 'email_mfa_enabled_at = VALUES(email_mfa_enabled_at)'
    );
    $stmt->bind_param('iis', $userId, $enabledInt, $enabledAt);
    $stmt->execute();
    $stmt->close();

    if ($enabled) {
        setMfaOnboardingStatus($conn, $userId, 'completed');
    }
}

function mfaOnboardingStatus(mysqli $conn, int $userId): string
{
    try {
        $stmt = $conn->prepare('SELECT status FROM user_mfa_onboarding WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $status = strtolower(trim((string) ($row['status'] ?? 'pending')));
        return in_array($status, ['pending', 'dismissed', 'completed'], true) ? $status : 'pending';
    } catch (Throwable $e) {
        error_log('MFA onboarding status error: ' . $e->getMessage());
        return 'pending';
    }
}

function setMfaOnboardingStatus(mysqli $conn, int $userId, string $status): void
{
    if (!in_array($status, ['pending', 'dismissed', 'completed'], true)) {
        throw new InvalidArgumentException('Invalid MFA onboarding status.');
    }

    $promptedAt = $status === 'pending' ? null : date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        'INSERT INTO user_mfa_onboarding (user_id, status, prompted_at) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE status = VALUES(status), prompted_at = COALESCE(prompted_at, VALUES(prompted_at))'
    );
    $stmt->bind_param('iss', $userId, $status, $promptedAt);
    $stmt->execute();
    $stmt->close();
}

function shouldPromptForMfaSetup(mysqli $conn, int $userId): bool
{
    return !isEmailMfaEnabled($conn, $userId) && mfaOnboardingStatus($conn, $userId) === 'pending';
}

function maskEmailAddress(string $email): string
{
    $email = strtolower(trim($email));
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($local === '' || $domain === '') {
        return 'your email address';
    }

    if (strlen($local) <= 2) {
        $maskedLocal = substr($local, 0, 1) . '***';
    } else {
        $maskedLocal = substr($local, 0, 1) . str_repeat('*', min(5, max(3, strlen($local) - 2))) . substr($local, -1);
    }

    return $maskedLocal . '@' . $domain;
}

function mfaPurposeLabel(string $purpose): string
{
    return match ($purpose) {
        'enable_email_mfa'  => 'enable email verification',
        'disable_email_mfa' => 'disable email verification',
        default             => 'complete your sign in',
    };
}

function mfaCodeHash(string $challengeToken, string $code): string
{
    return hash_hmac('sha256', $challengeToken . ':' . $code, jwtSecret());
}

function mfaEmailBody(array $user, string $code, string $purpose): array
{
    $name = htmlspecialchars((string) ($user['name'] ?? 'User'), ENT_QUOTES, 'UTF-8');
    $purposeText = htmlspecialchars(mfaPurposeLabel($purpose), ENT_QUOTES, 'UTF-8');
    $minutes = max(1, (int) ceil(mfaCodeExpirySeconds() / 60));

    $subject = $purpose === 'login'
        ? 'Your Otelex sign-in verification code'
        : 'Confirm your Otelex security change';

    $html = '<div style="font-family:Arial,sans-serif;color:#1f2937;line-height:1.6">'
        . '<p>Hello ' . $name . ',</p>'
        . '<p>Use the verification code below to ' . $purposeText . ':</p>'
        . '<div style="font-size:30px;font-weight:700;letter-spacing:8px;margin:22px 0;color:#1a56db">' . $code . '</div>'
        . '<p>This code expires in ' . $minutes . ' minutes and can only be used once.</p>'
        . '<p>If you did not request this, do not share the code with anyone and contact your administrator if necessary.</p>'
        . '<p>Otelex Hospitality Supplies Ltd</p>'
        . '</div>';

    $plain = "Hello " . ($user['name'] ?? 'User') . ",\n\n"
        . "Your Otelex verification code is: {$code}\n\n"
        . "It expires in {$minutes} minutes and can only be used once.\n"
        . "If you did not request this, do not share the code with anyone.";

    return [$subject, $html, $plain];
}

function mfaSendRateCount(mysqli $conn, int $userId): int
{
    $minutes = mfaRateWindowMinutes();
    $sql = 'SELECT COALESCE(SUM(send_count), 0) AS total_sends FROM auth_mfa_challenges '
        . 'WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE)';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total_sends'] ?? 0);
    $stmt->close();
    return $count;
}

function assertMfaSendRateAllowed(mysqli $conn, int $userId): void
{
    if (mfaSendRateCount($conn, $userId) >= mfaMaxEmailsPerWindow()) {
        throw new RuntimeException(
            'Too many verification emails have been requested. Please wait before trying again.',
            429
        );
    }
}

function createEmailMfaChallenge(mysqli $conn, array $user, string $purpose, ?string $deviceHash = null): array
{
    if (!in_array($purpose, ['login', 'enable_email_mfa', 'disable_email_mfa'], true)) {
        throw new InvalidArgumentException('Invalid MFA challenge purpose.');
    }

    ensureMfaTables($conn);
    $cleanup = $conn->prepare(
        'DELETE FROM auth_mfa_challenges WHERE '
        . '(expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)) '
        . 'OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 1 DAY))'
    );
    $cleanup->execute();
    $cleanup->close();

    $userId = (int) $user['id'];
    assertMfaSendRateAllowed($conn, $userId);

    $email = strtolower(trim((string) ($user['email'] ?? '')));
    if (!isDeliverableEmail($email)) {
        throw new RuntimeException('Your account does not have a deliverable email address.', 422);
    }

    $deviceHash = $deviceHash !== null && $deviceHash !== '' ? $deviceHash : null;
    $challengeToken = randomUrlSafeToken(32);
    $challengeHash = hash('sha256', $challengeToken);
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = mfaCodeHash($challengeToken, $code);
    $expiresAt = date('Y-m-d H:i:s', time() + mfaCodeExpirySeconds());
    $lastSentAt = date('Y-m-d H:i:s');
    $authVersion = (int) ($user['auth_version'] ?? 1);

    // Only the newest active challenge for this purpose/device should remain usable.
    if ($deviceHash !== null) {
        $invalidate = $conn->prepare(
            'UPDATE auth_mfa_challenges SET used_at = COALESCE(used_at, NOW()) '
            . 'WHERE user_id = ? AND purpose = ? AND device_hash = ? AND used_at IS NULL'
        );
        $invalidate->bind_param('iss', $userId, $purpose, $deviceHash);
    } else {
        $invalidate = $conn->prepare(
            'UPDATE auth_mfa_challenges SET used_at = COALESCE(used_at, NOW()) '
            . 'WHERE user_id = ? AND purpose = ? AND device_hash IS NULL AND used_at IS NULL'
        );
        $invalidate->bind_param('is', $userId, $purpose);
    }
    $invalidate->execute();
    $invalidate->close();

    $stmt = $conn->prepare(
        'INSERT INTO auth_mfa_challenges '
        . '(user_id, purpose, challenge_hash, code_hash, device_hash, auth_version, attempts, send_count, last_sent_at, expires_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?, ?)'
    );
    $stmt->bind_param(
        'issssiss',
        $userId,
        $purpose,
        $challengeHash,
        $codeHash,
        $deviceHash,
        $authVersion,
        $lastSentAt,
        $expiresAt
    );
    $stmt->execute();
    $challengeId = (int) $stmt->insert_id;
    $stmt->close();

    try {
        [$subject, $html, $plain] = mfaEmailBody($user, $code, $purpose);
        sendMail($email, (string) ($user['name'] ?? ''), $subject, $html, $plain, [], 'system');
    } catch (Throwable $e) {
        $disable = $conn->prepare('UPDATE auth_mfa_challenges SET used_at = NOW() WHERE id = ?');
        $disable->bind_param('i', $challengeId);
        $disable->execute();
        $disable->close();
        error_log('MFA email delivery error: ' . $e->getMessage());
        throw new RuntimeException('We could not send the verification email. Please try again shortly.', 503);
    }

    return [
        'challenge'        => $challengeToken,
        'masked_email'     => maskEmailAddress($email),
        'expires_in'       => mfaCodeExpirySeconds(),
        'resend_after'     => mfaResendCooldownSeconds(),
        'max_attempts'     => mfaMaxAttempts(),
    ];
}


function getMfaChallenge(mysqli $conn, string $challengeToken): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{40,}$/', $challengeToken)) {
        return null;
    }

    $hash = hash('sha256', $challengeToken);
    $stmt = $conn->prepare(
        'SELECT id, user_id, purpose, challenge_hash, code_hash, device_hash, auth_version, attempts, '
        . 'send_count, last_sent_at, expires_at, used_at, created_at '
        . 'FROM auth_mfa_challenges WHERE challenge_hash = ? LIMIT 1'
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getMfaChallengeForUpdate(mysqli $conn, string $challengeToken): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{40,}$/', $challengeToken)) {
        return null;
    }

    $hash = hash('sha256', $challengeToken);
    $stmt = $conn->prepare(
        'SELECT id, user_id, purpose, challenge_hash, code_hash, device_hash, auth_version, attempts, '
        . 'send_count, last_sent_at, expires_at, used_at, created_at '
        . 'FROM auth_mfa_challenges WHERE challenge_hash = ? LIMIT 1'
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function validateMfaChallengeContext(array $challenge, string $purpose, ?string $deviceHash = null): void
{
    if (($challenge['purpose'] ?? '') !== $purpose || $challenge['used_at'] !== null) {
        throw new RuntimeException('This verification request is no longer valid. Please start again.', 410);
    }

    if (strtotime((string) $challenge['expires_at']) <= time()) {
        throw new RuntimeException('This verification code has expired. Please request a new one.', 410);
    }

    if ((int) ($challenge['attempts'] ?? 0) >= mfaMaxAttempts()) {
        throw new RuntimeException('Too many incorrect verification attempts. Please start again.', 429);
    }

    $storedDevice = trim((string) ($challenge['device_hash'] ?? ''));
    if ($storedDevice !== '') {
        if ($deviceHash === null || $deviceHash === '' || !hash_equals($storedDevice, $deviceHash)) {
            throw new RuntimeException('This verification request is not valid on this device.', 403);
        }
    }
}

function validateMfaCode(
    mysqli $conn,
    string $challengeToken,
    string $code,
    string $purpose,
    ?string $deviceHash = null
): array {
    if (!preg_match('/^\d{6}$/', $code)) {
        throw new RuntimeException('Enter the 6-digit verification code.', 422);
    }

    $challenge = getMfaChallenge($conn, $challengeToken);
    if (!$challenge) {
        throw new RuntimeException('This verification request is no longer valid. Please start again.', 410);
    }

    validateMfaChallengeContext($challenge, $purpose, $deviceHash);
    $expected = mfaCodeHash($challengeToken, $code);

    if (!hash_equals((string) $challenge['code_hash'], $expected)) {
        $challengeId = (int) $challenge['id'];
        $maxAttempts = mfaMaxAttempts();
        $stmt = $conn->prepare(
            'UPDATE auth_mfa_challenges SET attempts = attempts + 1, '
            . 'used_at = CASE WHEN attempts + 1 >= ? THEN NOW() ELSE used_at END '
            . 'WHERE id = ? AND used_at IS NULL'
        );
        $stmt->bind_param('ii', $maxAttempts, $challengeId);
        $stmt->execute();
        $updated = $stmt->affected_rows === 1;
        $stmt->close();

        if ($updated) {
            logAuthActivity(
                $conn,
                (int) $challenge['user_id'],
                'auth.mfa_failed',
                'An incorrect email verification code was entered'
            );
        }

        if ((int) $challenge['attempts'] + 1 >= $maxAttempts) {
            throw new RuntimeException('Too many incorrect verification attempts. Please start again.', 429);
        }

        throw new RuntimeException('The verification code is incorrect.', 422);
    }

    return $challenge;
}

function consumeMfaChallenge(mysqli $conn, array $challenge): void
{
    $challengeId = (int) ($challenge['id'] ?? 0);
    $codeHash = (string) ($challenge['code_hash'] ?? '');
    if ($challengeId <= 0 || $codeHash === '') {
        throw new RuntimeException('This verification request is no longer valid. Please start again.', 410);
    }

    $stmt = $conn->prepare(
        'UPDATE auth_mfa_challenges SET used_at = NOW() '
        . 'WHERE id = ? AND code_hash = ? AND used_at IS NULL AND expires_at > NOW()'
    );
    $stmt->bind_param('is', $challengeId, $codeHash);
    $stmt->execute();
    $consumed = $stmt->affected_rows === 1;
    $stmt->close();

    if (!$consumed) {
        throw new RuntimeException('This verification request was already used or has expired. Please start again.', 410);
    }
}

/**
 * Backwards-compatible helper for callers that do not need to coordinate MFA
 * consumption with a larger database transaction.
 */
function verifyMfaCode(
    mysqli $conn,
    string $challengeToken,
    string $code,
    string $purpose,
    ?string $deviceHash = null
): array {
    $challenge = validateMfaCode($conn, $challengeToken, $code, $purpose, $deviceHash);
    consumeMfaChallenge($conn, $challenge);
    return $challenge;
}

function resendMfaCode(mysqli $conn, string $challengeToken, string $deviceHash): array
{
    ensureMfaTables($conn);
    $challenge = getMfaChallenge($conn, $challengeToken);
    if (!$challenge) {
        throw new RuntimeException('This verification request is no longer valid. Please start again.', 410);
    }

    validateMfaChallengeContext($challenge, (string) $challenge['purpose'], $deviceHash);
    $elapsed = time() - strtotime((string) $challenge['last_sent_at']);
    $cooldown = mfaResendCooldownSeconds();
    if ($elapsed < $cooldown) {
        throw new RuntimeException('Please wait ' . max(1, $cooldown - $elapsed) . ' seconds before requesting another code.', 429);
    }

    assertMfaSendRateAllowed($conn, (int) $challenge['user_id']);

    $stmt = $conn->prepare('SELECT id, name, email, role, is_active, auth_version FROM users WHERE id = ? LIMIT 1');
    $userId = (int) $challenge['user_id'];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int) $user['is_active'] !== 1 || (int) $user['auth_version'] !== (int) $challenge['auth_version']) {
        throw new RuntimeException('Your account security state has changed. Please start again.', 409);
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = mfaCodeHash($challengeToken, $code);
    $expiresAt = date('Y-m-d H:i:s', time() + mfaCodeExpirySeconds());
    $sentAt = date('Y-m-d H:i:s');
    $cooldownSql = mfaResendCooldownSeconds();

    // Reserve the resend atomically so simultaneous clicks cannot bypass cooldown.
    $update = $conn->prepare(
        'UPDATE auth_mfa_challenges SET code_hash = ?, attempts = 0, send_count = send_count + 1, '
        . 'last_sent_at = ?, expires_at = ? WHERE id = ? AND used_at IS NULL AND expires_at > NOW() '
        . 'AND last_sent_at <= DATE_SUB(NOW(), INTERVAL ' . $cooldownSql . ' SECOND)'
    );
    $challengeId = (int) $challenge['id'];
    $update->bind_param('sssi', $codeHash, $sentAt, $expiresAt, $challengeId);
    $update->execute();
    $reserved = $update->affected_rows === 1;
    $update->close();

    if (!$reserved) {
        throw new RuntimeException('Please wait before requesting another verification code.', 429);
    }

    try {
        [$subject, $html, $plain] = mfaEmailBody($user, $code, (string) $challenge['purpose']);
        sendMail((string) $user['email'], (string) $user['name'], $subject, $html, $plain, [], 'system');
    } catch (Throwable $e) {
        $disable = $conn->prepare('UPDATE auth_mfa_challenges SET used_at = NOW() WHERE id = ?');
        $disable->bind_param('i', $challengeId);
        $disable->execute();
        $disable->close();
        error_log('MFA resend delivery error: ' . $e->getMessage());
        throw new RuntimeException('We could not resend the verification email. Please start again shortly.', 503);
    }

    logAuthActivity(
        $conn,
        (int) $user['id'],
        'auth.mfa_code_resent',
        'A new email verification code was requested'
    );

    return [
        'masked_email' => maskEmailAddress((string) $user['email']),
        'expires_in'   => mfaCodeExpirySeconds(),
        'resend_after' => mfaResendCooldownSeconds(),
    ];
}

function mfaStatusData(mysqli $conn, array $user): array
{
    $userId = (int) $user['id'];
    $enabled = isEmailMfaEnabled($conn, $userId);
    $onboardingStatus = $enabled ? 'completed' : mfaOnboardingStatus($conn, $userId);

    return [
        'email_enabled'        => $enabled,
        'masked_email'         => maskEmailAddress((string) $user['email']),
        'method'               => 'email',
        'onboarding_status'    => $onboardingStatus,
        'setup_prompt_required'=> !$enabled && $onboardingStatus === 'pending',
    ];
}
