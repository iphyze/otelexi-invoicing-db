<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Shared authentication and request-security helpers.
 *
 * Access JWTs and refresh tokens are delivered through HttpOnly cookies only.
 * A separate CSRF cookie/token pair is required for state-changing requests.
 */

function loadEnvironment(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $root = dirname(__DIR__);
    if (file_exists($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }

    $timezone = $_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'Africa/Lagos';
    if (is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)) {
        date_default_timezone_set($timezone);
    }

    $loaded = true;
}

function config(string $key, ?string $default = null): ?string
{
    loadEnvironment();
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function requiredConfig(string $key): string
{
    $value = config($key);
    if ($value === null || trim($value) === '') {
        throw new RuntimeException("Required environment variable {$key} is not configured.");
    }

    return trim($value);
}

function configBool(string $key, bool $default = false): bool
{
    $value = config($key);
    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function appEnvironment(): string
{
    return strtolower(config('APP_ENV', 'production') ?? 'production');
}

function isProductionEnvironment(): bool
{
    return appEnvironment() === 'production';
}

function ipMatchesTrustedProxy(string $ip, string $rule): bool
{
    $ip = trim($ip);
    $rule = trim($rule);
    if ($ip === '' || $rule === '') {
        return false;
    }

    if (!str_contains($rule, '/')) {
        return hash_equals(strtolower($rule), strtolower($ip));
    }

    [$network, $prefixRaw] = array_pad(explode('/', $rule, 2), 2, '');
    $ipPacked = @inet_pton($ip);
    $networkPacked = @inet_pton(trim($network));
    if ($ipPacked === false || $networkPacked === false || strlen($ipPacked) !== strlen($networkPacked)) {
        return false;
    }

    $maxBits = strlen($ipPacked) * 8;
    if (!ctype_digit($prefixRaw)) {
        return false;
    }
    $prefix = (int) $prefixRaw;
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
        return false;
    }
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ipPacked[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
}

function requestComesFromTrustedProxy(): bool
{
    if (!configBool('TRUST_PROXY_HEADERS', false)) {
        return false;
    }

    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $configured = array_filter(array_map('trim', explode(',', config('TRUSTED_PROXY_IPS', '') ?? '')));
    if ($remoteAddress === '' || $configured === []) {
        return false;
    }

    foreach ($configured as $rule) {
        if (ipMatchesTrustedProxy($remoteAddress, $rule)) {
            return true;
        }
    }

    return false;
}

function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    if (requestComesFromTrustedProxy()) {
        $forwardedProto = strtolower(trim((string) (requestHeader('X-Forwarded-Proto') ?? '')));
        return $forwardedProto === 'https';
    }

    return false;
}

function apiBasePath(): string
{
    return rtrim(config('API_BASE_PATH', '/otelex-server/api') ?? '/otelex-server/api', '/');
}

function cookieSecure(): bool
{
    // Authentication cookies must never be downgraded in production.
    if (isProductionEnvironment()) {
        return true;
    }

    return configBool('COOKIE_SECURE', false);
}

function cookieSameSite(): string
{
    $configured = ucfirst(strtolower(config('COOKIE_SAMESITE', 'Strict') ?? 'Strict'));
    return in_array($configured, ['Strict', 'Lax', 'None'], true) ? $configured : 'Strict';
}

function cookieDomain(): ?string
{
    return config('COOKIE_DOMAIN');
}

function accessCookieName(): string
{
    return config('AUTH_ACCESS_COOKIE', 'otelex_access') ?? 'otelex_access';
}

function refreshCookieName(): string
{
    return config('AUTH_REFRESH_COOKIE', 'otelex_refresh') ?? 'otelex_refresh';
}

function csrfCookieName(): string
{
    return config('AUTH_CSRF_COOKIE', 'otelex_csrf') ?? 'otelex_csrf';
}

function deviceCookieName(): string
{
    return config('AUTH_DEVICE_COOKIE', 'otelex_device') ?? 'otelex_device';
}

function deviceCookieLifetimeSeconds(): int
{
    return max(86400, (int) (config('AUTH_DEVICE_COOKIE_EXPIRES_IN', '31536000') ?? '31536000'));
}

function ensureDeviceIdentity(): array
{
    $cookieName = deviceCookieName();
    $deviceToken = trim((string) ($_COOKIE[$cookieName] ?? ''));

    if (!preg_match('/^[A-Za-z0-9_-]{40,}$/', $deviceToken)) {
        $deviceToken = randomUrlSafeToken(32);
        setAppCookie(
            $cookieName,
            $deviceToken,
            time() + deviceCookieLifetimeSeconds(),
            apiBasePath(),
            true
        );
        $_COOKIE[$cookieName] = $deviceToken;
    }

    return [
        'token' => $deviceToken,
        'hash'  => hash('sha256', $deviceToken),
    ];
}

function setAppCookie(string $name, string $value, int $expiresAt, string $path, bool $httpOnly): void
{
    $options = [
        'expires'  => $expiresAt,
        'path'     => $path,
        'secure'   => cookieSecure(),
        'httponly' => $httpOnly,
        'samesite' => cookieSameSite(),
    ];

    $domain = cookieDomain();
    if ($domain !== null && $domain !== '') {
        $options['domain'] = $domain;
    }

    setcookie($name, $value, $options);
}

function issueAuthCookies(string $accessToken, string $refreshToken, string $csrfToken): void
{
    $now = time();
    $accessTtl = (int) (config('JWT_ACCESS_EXPIRES_IN', '900') ?? '900');
    $refreshTtl = (int) (config('JWT_REFRESH_EXPIRES_IN', '2592000') ?? '2592000');
    $basePath = apiBasePath();

    setAppCookie(accessCookieName(), $accessToken, $now + $accessTtl, $basePath, true);
    setAppCookie(refreshCookieName(), $refreshToken, $now + $refreshTtl, $basePath . '/auth', true);
    setAppCookie(csrfCookieName(), $csrfToken, $now + $refreshTtl, $basePath, false);
}

function clearAccessCookie(): void
{
    setAppCookie(accessCookieName(), '', time() - 3600, apiBasePath(), true);
}

function clearAuthCookies(): void
{
    $expired = time() - 3600;
    $basePath = apiBasePath();

    clearAccessCookie();
    setAppCookie(refreshCookieName(), '', $expired, $basePath . '/auth', true);
    setAppCookie(csrfCookieName(), '', $expired, $basePath, false);
}

function randomUrlSafeToken(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function requestHeader(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$key] ?? null;
    return is_string($value) && $value !== '' ? $value : null;
}

function requireCsrfProtection(): void
{
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $cookieToken = $_COOKIE[csrfCookieName()] ?? '';
    $headerToken = requestHeader('X-CSRF-Token') ?? '';

    if ($cookieToken === '' || $headerToken === '' || !hash_equals($cookieToken, $headerToken)) {
        http_response_code(419);
        echo json_encode([
            'status'  => 'failed',
            'message' => 'Security validation failed. Please refresh the page and try again.',
        ]);
        exit;
    }
}

function allowedOrigins(): array
{
    $raw = config('ALLOWED_ORIGINS', '') ?? '';
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function applyApiSecurityHeaders(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-site');
    header('X-Permitted-Cross-Domain-Policies: none');

    if (isProductionEnvironment() && isHttpsRequest()) {
        $hstsSeconds = max(300, (int) (config('SECURITY_HSTS_MAX_AGE', '31536000') ?? '31536000'));
        $hsts = 'max-age=' . $hstsSeconds;
        if (configBool('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false)) {
            $hsts .= '; includeSubDomains';
        }
        header('Strict-Transport-Security: ' . $hsts);
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        if (!in_array($origin, allowedOrigins(), true)) {
            http_response_code(403);
            echo json_encode([
                'status'  => 'failed',
                'message' => 'Origin is not permitted.',
            ]);
            exit;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
    header('Access-Control-Max-Age: 600');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function clientIpAddress(): string
{
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));

    if (requestComesFromTrustedProxy()) {
        $forwarded = requestHeader('X-Forwarded-For');
        if ($forwarded !== null) {
            $chain = array_values(array_filter(array_map('trim', explode(',', $forwarded)), static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
            $chain[] = $remoteAddress;
            $trustedRules = array_filter(array_map('trim', explode(',', config('TRUSTED_PROXY_IPS', '') ?? '')));

            // Walk from the trusted edge inward. The first address that is not a
            // configured proxy is the client, which avoids trusting a spoofed
            // left-most X-Forwarded-For value.
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $candidate = $chain[$i];
                $isTrusted = false;
                foreach ($trustedRules as $rule) {
                    if (ipMatchesTrustedProxy($candidate, $rule)) {
                        $isTrusted = true;
                        break;
                    }
                }
                if (!$isTrusted) {
                    return substr($candidate, 0, 45);
                }
            }
        }
    }

    return substr($remoteAddress !== '' ? $remoteAddress : '0.0.0.0', 0, 45);
}

function ensureAuthRateLimitTable(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    // Security tables are provisioned by migrations so the production
    // application database user does not require CREATE TABLE privileges.
    try {
        $conn->query('SELECT 1 FROM auth_rate_limit_events LIMIT 1');
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Authentication security storage is unavailable. Apply the latest database migrations.',
            503
        );
    }

    $ensured = true;
}

function enforceAuthRateLimit(
    mysqli $conn,
    string $action,
    string $key,
    int $maxAttempts,
    int $windowSeconds,
    string $message = 'Too many requests. Please wait and try again.'
): void {
    ensureAuthRateLimitTable($conn);

    $action = substr(preg_replace('/[^a-z0-9._-]/i', '_', $action) ?: 'auth', 0, 64);
    $keyHash = hash('sha256', $key);
    $maxAttempts = max(1, $maxAttempts);
    $windowSeconds = max(30, $windowSeconds);
    $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);

    static $cleaned = false;
    if (!$cleaned) {
        $conn->query("DELETE FROM auth_rate_limit_events WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
        $cleaned = true;
    }

    // Record the attempt before counting it. This narrows the race window between
    // concurrent requests and means attempt N+1 is the first request rejected.
    $insert = $conn->prepare('INSERT INTO auth_rate_limit_events (action, key_hash) VALUES (?, ?)');
    $insert->bind_param('ss', $action, $keyHash);
    $insert->execute();
    $insert->close();

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM auth_rate_limit_events '
        . 'WHERE action = ? AND key_hash = ? AND attempted_at >= ?'
    );
    $stmt->bind_param('sss', $action, $keyHash, $cutoff);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    if ($count > $maxAttempts) {
        header('Retry-After: ' . $windowSeconds);
        throw new RuntimeException($message, 429);
    }
}

function enforceSensitiveActionRateLimit(mysqli $conn, string $action, int $userId): void
{
    $windowSeconds = max(300, (int) (config('AUTH_SENSITIVE_ACTION_WINDOW_MINUTES', '15') ?? '15') * 60);
    $userLimit = max(3, (int) (config('AUTH_SENSITIVE_ACTION_MAX_ATTEMPTS', '10') ?? '10'));
    $ipLimit = max($userLimit, (int) (config('AUTH_SENSITIVE_IP_MAX_ATTEMPTS', '20') ?? '20'));

    enforceAuthRateLimit(
        $conn,
        $action . '_user',
        (string) $userId,
        $userLimit,
        $windowSeconds,
        'Too many sensitive security actions. Please wait and try again.'
    );
    enforceAuthRateLimit(
        $conn,
        $action . '_ip',
        clientIpAddress(),
        $ipLimit,
        $windowSeconds,
        'Too many sensitive security actions from this network. Please wait and try again.'
    );
}

function passwordMinLength(): int
{
    return min(64, max(10, (int) (config('AUTH_PASSWORD_MIN_LENGTH', '12') ?? '12')));
}

function validatePasswordStrength(string $password): ?string
{
    $length = strlen($password);
    $minimum = passwordMinLength();

    if ($length < $minimum) {
        return "Password must be at least {$minimum} characters long.";
    }
    if ($length > 128) {
        return 'Password must not exceed 128 characters.';
    }

    $normalized = strtolower(trim($password));
    $common = [
        'password', 'password123', '12345678', '123456789', 'qwerty123',
        'admin123', 'welcome123', 'otelex123', 'letmein123',
    ];
    if (in_array($normalized, $common, true) || preg_match('/^(.)\\1{9,}$/', $password)) {
        return 'Choose a stronger password that is not commonly used or repetitive.';
    }

    $classes = 0;
    $classes += preg_match('/[a-z]/', $password) ? 1 : 0;
    $classes += preg_match('/[A-Z]/', $password) ? 1 : 0;
    $classes += preg_match('/\\d/', $password) ? 1 : 0;
    $classes += preg_match('/[^a-zA-Z0-9]/', $password) ? 1 : 0;

    if ($length < 16 && $classes < 3) {
        return 'Use at least three of: uppercase letters, lowercase letters, numbers, and symbols, or use a passphrase of 16+ characters.';
    }
    return null;
}

function assertPasswordStrength(string $password, int $statusCode = 422): void
{
    $error = validatePasswordStrength($password);
    if ($error !== null) {
        throw new RuntimeException($error, $statusCode);
    }
}

function activeSuperAdminCount(mysqli $conn, bool $lockRows = false): int
{
    if ($lockRows) {
        $result = $conn->query("SELECT id FROM users WHERE role = 'super_admin' AND is_active = 1 FOR UPDATE");
        return $result ? $result->num_rows : 0;
    }

    $result = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'super_admin' AND is_active = 1");
    return (int) (($result ? $result->fetch_assoc() : [])['total'] ?? 0);
}

function assertSuperAdminContinuity(
    mysqli $conn,
    array $targetUser,
    ?string $newRole = null,
    ?int $newActive = null,
    bool $deleting = false
): void {
    $isActiveSuperAdmin = ($targetUser['role'] ?? '') === 'super_admin'
        && (int) ($targetUser['is_active'] ?? 0) === 1;
    if (!$isActiveSuperAdmin) {
        return;
    }

    $wouldRemovePrivilege = $deleting
        || ($newRole !== null && $newRole !== 'super_admin')
        || ($newActive !== null && $newActive !== 1);

    if ($wouldRemovePrivilege && activeSuperAdminCount($conn, true) <= 1) {
        throw new RuntimeException(
            'This action would remove the last active Super Admin account. Create or activate another Super Admin first.',
            409
        );
    }
}

function assertBulkSuperAdminContinuity(mysqli $conn, array $userIds): void
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
    if ($userIds === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM users WHERE id IN ($placeholders) AND role = 'super_admin' AND is_active = 1"
    );
    $stmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);
    $stmt->execute();
    $selected = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    if ($selected > 0 && (activeSuperAdminCount($conn, true) - $selected) < 1) {
        throw new RuntimeException(
            'This action would remove the last active Super Admin account. Create or activate another Super Admin first.',
            409
        );
    }
}

function invalidatePendingAuthChallenges(mysqli $conn, int $userId): void
{
    try {
        $stmt = $conn->prepare(
            'UPDATE auth_mfa_challenges SET used_at = COALESCE(used_at, NOW()) WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // MFA migration may not exist yet during a staged deployment.
    }

    try {
        $stmt = $conn->prepare(
            'UPDATE auth_login_challenges SET used_at = COALESCE(used_at, NOW()) WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Device-limit migration may not exist yet during a staged deployment.
    }
}

function userAgent(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
}

function jwtIssuer(): string
{
    return config('JWT_ISSUER', config('APP_URL', 'otelex-api')) ?? 'otelex-api';
}

function jwtAudience(): string
{
    return config('JWT_AUDIENCE', 'otelex-web') ?? 'otelex-web';
}

function jwtSecret(): string
{
    $secret = requiredConfig('JWT_SECRET');
    if (strlen($secret) < 64) {
        throw new RuntimeException('JWT_SECRET must be at least 64 characters long.');
    }

    return $secret;
}

function sessionIdleTimeoutSeconds(): int
{
    return max(60, (int) (config('AUTH_IDLE_TIMEOUT_SECONDS', '600') ?? '600'));
}

function sessionIdleWarningSeconds(): int
{
    $idle = sessionIdleTimeoutSeconds();
    $warning = max(15, (int) (config('AUTH_IDLE_WARNING_SECONDS', '60') ?? '60'));

    return min($warning, max(15, $idle - 15));
}

function sessionAbsoluteLifetimeSeconds(): int
{
    return max(sessionIdleTimeoutSeconds(), (int) (config('AUTH_ABSOLUTE_SESSION_SECONDS', '43200') ?? '43200'));
}

function sessionActivityWriteIntervalSeconds(): int
{
    return max(10, (int) (config('AUTH_ACTIVITY_WRITE_INTERVAL_SECONDS', '60') ?? '60'));
}

function ensureSecuritySettingsTable(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS security_settings (
            id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            max_active_sessions TINYINT UNSIGNED NOT NULL DEFAULT 2,
            updated_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_security_settings_updated_by (updated_by),
            CONSTRAINT fk_security_settings_updated_by
                FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function maxConcurrentSessions(?mysqli $conn = null): int
{
    $fallback = min(20, max(1, (int) (config('AUTH_MAX_ACTIVE_SESSIONS', '2') ?? '2')));

    if ($conn === null) {
        return $fallback;
    }

    try {
        $result = $conn->query('SELECT max_active_sessions FROM security_settings WHERE id = 1 LIMIT 1');
        $row = $result ? $result->fetch_assoc() : null;
        if ($row && isset($row['max_active_sessions'])) {
            return min(20, max(1, (int) $row['max_active_sessions']));
        }
    } catch (Throwable $e) {
        // Before the migration is applied, authentication safely falls back to .env.
    }

    return $fallback;
}

function saveMaxConcurrentSessions(mysqli $conn, int $maxSessions, int $updatedBy): int
{
    ensureSecuritySettingsTable($conn);
    $maxSessions = min(20, max(1, $maxSessions));

    $stmt = $conn->prepare(
        'INSERT INTO security_settings (id, max_active_sessions, updated_by) VALUES (1, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE max_active_sessions = VALUES(max_active_sessions), updated_by = VALUES(updated_by)'
    );
    $stmt->bind_param('ii', $maxSessions, $updatedBy);
    $stmt->execute();
    $stmt->close();

    return $maxSessions;
}

function deviceLimitChallengeTtlSeconds(): int
{
    return min(900, max(60, (int) (config('AUTH_DEVICE_LIMIT_CHALLENGE_SECONDS', '300') ?? '300')));
}

function sessionPolicyData(?mysqli $conn = null): array
{
    return [
        'idle_timeout_seconds'      => sessionIdleTimeoutSeconds(),
        'idle_warning_seconds'      => sessionIdleWarningSeconds(),
        'absolute_timeout_seconds'  => sessionAbsoluteLifetimeSeconds(),
        'heartbeat_interval_seconds'=> max(30, sessionActivityWriteIntervalSeconds()),
        'max_active_sessions'         => maxConcurrentSessions($conn),
    ];
}

function describeUserAgent(?string $agent = null): array
{
    $agent = $agent ?? userAgent();
    $browser = 'Browser';
    $platform = 'Unknown device';
    $deviceType = 'desktop';

    if (stripos($agent, 'Edg/') !== false) {
        $browser = 'Microsoft Edge';
    } elseif (stripos($agent, 'OPR/') !== false || stripos($agent, 'Opera') !== false) {
        $browser = 'Opera';
    } elseif (stripos($agent, 'Chrome/') !== false || stripos($agent, 'CriOS/') !== false) {
        $browser = 'Google Chrome';
    } elseif (stripos($agent, 'Firefox/') !== false || stripos($agent, 'FxiOS/') !== false) {
        $browser = 'Mozilla Firefox';
    } elseif (stripos($agent, 'Safari/') !== false) {
        $browser = 'Safari';
    }

    if (stripos($agent, 'iPad') !== false) {
        $platform = 'iPad';
        $deviceType = 'tablet';
    } elseif (stripos($agent, 'iPhone') !== false) {
        $platform = 'iPhone';
        $deviceType = 'mobile';
    } elseif (stripos($agent, 'Android') !== false) {
        $platform = stripos($agent, 'Mobile') !== false ? 'Android phone' : 'Android tablet';
        $deviceType = stripos($agent, 'Mobile') !== false ? 'mobile' : 'tablet';
    } elseif (stripos($agent, 'Windows') !== false) {
        $platform = 'Windows';
    } elseif (stripos($agent, 'Macintosh') !== false || stripos($agent, 'Mac OS X') !== false) {
        $platform = 'macOS';
    } elseif (stripos($agent, 'Linux') !== false) {
        $platform = 'Linux';
    }

    return [
        'browser'     => $browser,
        'platform'    => $platform,
        'device_type' => $deviceType,
        'label'       => $browser . ' on ' . $platform,
    ];
}

function createAuthSession(mysqli $conn, int $userId, ?array $deviceIdentity = null): array
{
    $deviceIdentity = $deviceIdentity ?? ensureDeviceIdentity();
    $sessionKey = bin2hex(random_bytes(32));
    $deviceHash = (string) ($deviceIdentity['hash'] ?? '');
    $ip = clientIpAddress();
    $agent = userAgent();
    $device = describeUserAgent($agent);
    $deviceLabel = substr($device['label'], 0, 120);
    $lastActivityAt = date('Y-m-d H:i:s');
    $absoluteExpiresAt = date('Y-m-d H:i:s', time() + sessionAbsoluteLifetimeSeconds());

    $stmt = $conn->prepare(
        'INSERT INTO auth_sessions '
        . '(session_key, user_id, device_hash, device_label, ip_address, user_agent, last_activity_at, absolute_expires_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'sissssss',
        $sessionKey,
        $userId,
        $deviceHash,
        $deviceLabel,
        $ip,
        $agent,
        $lastActivityAt,
        $absoluteExpiresAt
    );
    $stmt->execute();
    $sessionId = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id'                  => $sessionId,
        'session_key'         => $sessionKey,
        'user_id'             => $userId,
        'device_hash'         => $deviceHash,
        'device_label'        => $deviceLabel,
        'ip_address'          => $ip,
        'user_agent'          => $agent,
        'last_activity_at'    => $lastActivityAt,
        'absolute_expires_at' => $absoluteExpiresAt,
        'revoked_at'          => null,
        'revocation_reason'   => null,
    ];
}

function getAuthSessionByKey(mysqli $conn, int $userId, string $sessionKey): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, session_key, user_id, device_hash, device_label, ip_address, user_agent, '
        . 'last_activity_at, absolute_expires_at, revoked_at, revocation_reason, created_at, updated_at '
        . 'FROM auth_sessions WHERE session_key = ? AND user_id = ? LIMIT 1'
    );
    $stmt->bind_param('si', $sessionKey, $userId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $session;
}

function authSessionInvalidReason(?array $session): ?string
{
    if (!$session) {
        return 'invalid_session';
    }

    if ($session['revoked_at'] !== null) {
        $reason = (string) ($session['revocation_reason'] ?? '');
        return in_array($reason, ['idle_timeout', 'absolute_timeout', 'deactivated', 'security_change'], true)
            ? $reason
            : 'revoked';
    }

    $now = time();
    $absoluteExpiry = strtotime((string) $session['absolute_expires_at']);
    if ($absoluteExpiry === false || $absoluteExpiry <= $now) {
        return 'absolute_timeout';
    }

    $lastActivity = strtotime((string) $session['last_activity_at']);
    if ($lastActivity === false || ($lastActivity + sessionIdleTimeoutSeconds()) <= $now) {
        return 'idle_timeout';
    }

    return null;
}

function revokeAuthSessionById(mysqli $conn, int $sessionId, string $reason = 'revoked'): void
{
    $reason = substr(trim($reason) ?: 'revoked', 0, 50);

    $stmt = $conn->prepare(
        'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, NOW()), '
        . 'revocation_reason = COALESCE(revocation_reason, ?) WHERE id = ?'
    );
    $stmt->bind_param('si', $reason, $sessionId);
    $stmt->execute();
    $stmt->close();

    $tokens = $conn->prepare(
        'UPDATE auth_refresh_tokens SET revoked_at = COALESCE(revoked_at, NOW()) '
        . 'WHERE session_id = ? AND revoked_at IS NULL'
    );
    $tokens->bind_param('i', $sessionId);
    $tokens->execute();
    $tokens->close();
}

function revokeAuthSessionByKey(mysqli $conn, int $userId, string $sessionKey, string $reason = 'revoked'): void
{
    $session = getAuthSessionByKey($conn, $userId, $sessionKey);
    if ($session) {
        revokeAuthSessionById($conn, (int) $session['id'], $reason);
    }
}

function revokeAuthSessionsForUser(mysqli $conn, int $userId, string $reason = 'security_change'): void
{
    $reason = substr(trim($reason) ?: 'security_change', 0, 50);
    $stmt = $conn->prepare(
        'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, NOW()), '
        . 'revocation_reason = COALESCE(revocation_reason, ?) '
        . 'WHERE user_id = ? AND revoked_at IS NULL'
    );
    $stmt->bind_param('si', $reason, $userId);
    $stmt->execute();
    $stmt->close();

    $tokens = $conn->prepare(
        'UPDATE auth_refresh_tokens rt '
        . 'JOIN auth_sessions s ON s.id = rt.session_id '
        . 'SET rt.revoked_at = COALESCE(rt.revoked_at, NOW()) '
        . 'WHERE s.user_id = ? AND rt.revoked_at IS NULL'
    );
    $tokens->bind_param('i', $userId);
    $tokens->execute();
    $tokens->close();
}

function bindOrValidateSessionDevice(mysqli $conn, array $session, array $deviceIdentity): ?array
{
    $deviceHash = (string) ($deviceIdentity['hash'] ?? '');
    $storedHash = trim((string) ($session['device_hash'] ?? ''));

    if ($deviceHash === '') {
        return null;
    }

    if ($storedHash !== '') {
        return hash_equals($storedHash, $deviceHash) ? $session : null;
    }

    $device = describeUserAgent();
    $deviceLabel = substr($device['label'], 0, 120);
    $agent = userAgent();
    $sessionId = (int) $session['id'];
    $stmt = $conn->prepare(
        'UPDATE auth_sessions SET device_hash = ?, device_label = ?, user_agent = ? '
        . 'WHERE id = ? AND device_hash IS NULL AND revoked_at IS NULL'
    );
    $stmt->bind_param('sssi', $deviceHash, $deviceLabel, $agent, $sessionId);
    $stmt->execute();
    $stmt->close();

    $session['device_hash'] = $deviceHash;
    $session['device_label'] = $deviceLabel;
    $session['user_agent'] = $agent;
    return $session;
}

function touchAuthSessionActivity(mysqli $conn, array $session, bool $force = false): void
{
    $lastActivity = strtotime((string) ($session['last_activity_at'] ?? '')) ?: 0;
    if (!$force && (time() - $lastActivity) < sessionActivityWriteIntervalSeconds()) {
        return;
    }

    $ip = clientIpAddress();
    $sessionId = (int) $session['id'];
    $stmt = $conn->prepare(
        'UPDATE auth_sessions SET last_activity_at = NOW(), ip_address = ? '
        . 'WHERE id = ? AND revoked_at IS NULL'
    );
    $stmt->bind_param('si', $ip, $sessionId);
    $stmt->execute();
    $stmt->close();
}

function pruneExpiredAuthSessions(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare(
        'SELECT id, last_activity_at, absolute_expires_at, revoked_at, revocation_reason '
        . 'FROM auth_sessions WHERE user_id = ? AND revoked_at IS NULL'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $sessions = [];
    while ($row = $result->fetch_assoc()) {
        $sessions[] = $row;
    }
    $stmt->close();

    foreach ($sessions as $session) {
        $reason = authSessionInvalidReason($session);
        if ($reason !== null) {
            revokeAuthSessionById($conn, (int) $session['id'], $reason);
        }
    }
}

function getActiveAuthSessions(mysqli $conn, int $userId): array
{
    pruneExpiredAuthSessions($conn, $userId);

    $stmt = $conn->prepare(
        'SELECT id, session_key, user_id, device_hash, device_label, ip_address, user_agent, '
        . 'last_activity_at, absolute_expires_at, created_at, updated_at '
        . 'FROM auth_sessions WHERE user_id = ? AND revoked_at IS NULL '
        . 'ORDER BY last_activity_at DESC, id DESC'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $sessions = [];
    while ($row = $result->fetch_assoc()) {
        $sessions[] = $row;
    }
    $stmt->close();

    return $sessions;
}

function revokeActiveSessionsForDevice(mysqli $conn, int $userId, string $deviceHash, string $reason = 'replaced_login'): void
{
    if ($deviceHash === '') {
        return;
    }

    $stmt = $conn->prepare(
        'SELECT id FROM auth_sessions WHERE user_id = ? AND device_hash = ? AND revoked_at IS NULL'
    );
    $stmt->bind_param('is', $userId, $deviceHash);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
    $stmt->close();

    foreach ($ids as $sessionId) {
        revokeAuthSessionById($conn, $sessionId, $reason);
    }
}

function publicAuthSessionData(array $session, ?string $currentSessionKey = null): array
{
    $device = describeUserAgent((string) ($session['user_agent'] ?? ''));
    return [
        'session_key'      => (string) $session['session_key'],
        'device_name'      => trim((string) ($session['device_label'] ?? '')) ?: $device['label'],
        'browser'          => $device['browser'],
        'platform'         => $device['platform'],
        'device_type'      => $device['device_type'],
        'ip_address'       => (string) ($session['ip_address'] ?? ''),
        'last_activity_at' => $session['last_activity_at'] ?? null,
        'signed_in_at'     => $session['created_at'] ?? null,
        'expires_at'       => $session['absolute_expires_at'] ?? null,
        'current'          => $currentSessionKey !== null && hash_equals((string) $session['session_key'], $currentSessionKey),
    ];
}

function buildDeviceLimitChallenge(mysqli $conn, array $user, string $deviceHash): string
{
    $now = time();
    $jti = randomUrlSafeToken(24);
    $jtiHash = hash('sha256', $jti);
    $expiresAt = date('Y-m-d H:i:s', $now + deviceLimitChallengeTtlSeconds());
    $purpose = 'device_limit';
    $userId = (int) $user['id'];

    $cleanup = $conn->prepare(
        'DELETE FROM auth_login_challenges WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY) OR used_at IS NOT NULL'
    );
    $cleanup->execute();
    $cleanup->close();

    $stmt = $conn->prepare(
        'INSERT INTO auth_login_challenges (user_id, purpose, challenge_hash, device_hash, expires_at) '
        . 'VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issss', $userId, $purpose, $jtiHash, $deviceHash, $expiresAt);
    $stmt->execute();
    $stmt->close();

    return JWT::encode([
        'iss'  => jwtIssuer(),
        'aud'  => jwtAudience(),
        'sub'  => (string) $userId,
        'id'   => $userId,
        'ver'  => (int) ($user['auth_version'] ?? 1),
        'dev'  => $deviceHash,
        'type' => 'device_limit',
        'jti'  => $jti,
        'iat'  => $now,
        'nbf'  => $now,
        'exp'  => $now + deviceLimitChallengeTtlSeconds(),
    ], jwtSecret(), 'HS256');
}

function decodeDeviceLimitChallenge(string $token): array
{
    $decoded = (array) JWT::decode($token, new Key(jwtSecret(), 'HS256'));
    if (($decoded['type'] ?? '') !== 'device_limit'
        || ($decoded['iss'] ?? '') !== jwtIssuer()
        || ($decoded['aud'] ?? '') !== jwtAudience()
        || empty($decoded['id'])
        || empty($decoded['dev'])
        || empty($decoded['jti'])) {
        throw new RuntimeException('Invalid device verification challenge.');
    }

    return $decoded;
}

function getValidDeviceLimitChallenge(mysqli $conn, array $decoded): ?array
{
    $userId = (int) ($decoded['id'] ?? 0);
    $purpose = 'device_limit';
    $challengeHash = hash('sha256', (string) ($decoded['jti'] ?? ''));
    $deviceHash = (string) ($decoded['dev'] ?? '');

    $stmt = $conn->prepare(
        'SELECT id, user_id, purpose, device_hash, expires_at, used_at '
        . 'FROM auth_login_challenges '
        . 'WHERE user_id = ? AND purpose = ? AND challenge_hash = ? AND device_hash = ? '
        . 'AND used_at IS NULL AND expires_at > NOW() LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('isss', $userId, $purpose, $challengeHash, $deviceHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function markLoginChallengeUsed(mysqli $conn, int $challengeId): void
{
    $stmt = $conn->prepare('UPDATE auth_login_challenges SET used_at = COALESCE(used_at, NOW()) WHERE id = ?');
    $stmt->bind_param('i', $challengeId);
    $stmt->execute();
    $stmt->close();
}

function currentAccessSessionKey(): ?string
{
    $token = $_COOKIE[accessCookieName()] ?? '';
    if ($token === '') {
        return null;
    }

    try {
        $decoded = (array) JWT::decode($token, new Key(jwtSecret(), 'HS256'));
        $key = trim((string) ($decoded['sid'] ?? ''));
        return $key !== '' ? $key : null;
    } catch (Throwable $e) {
        return null;
    }
}

function createLoginCredentials(mysqli $conn, array $user, array $deviceIdentity): array
{
    $authSession = createAuthSession($conn, (int) $user['id'], $deviceIdentity);
    $csrfToken = randomUrlSafeToken(32);
    $refreshToken = createRefreshSession($conn, (int) $user['id'], $csrfToken, (int) $authSession['id']);
    $accessToken = buildAccessToken($user, (string) $authSession['session_key']);

    return [
        'auth_session'  => $authSession,
        'csrf_token'    => $csrfToken,
        'refresh_token' => $refreshToken,
        'access_token'  => $accessToken,
    ];
}

function logAuthActivity(mysqli $conn, int $userId, string $action, string $description): void
{
    $modelType = 'User';
    $modelId = $userId;
    $ipAddress = clientIpAddress();
    $stmt = $conn->prepare(
        'INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ississ', $userId, $action, $modelType, $modelId, $description, $ipAddress);
    $stmt->execute();
    $stmt->close();
}

function buildAccessToken(array $user, string $sessionKey): string
{
    $now = time();
    $ttl = (int) (config('JWT_ACCESS_EXPIRES_IN', '900') ?? '900');

    return JWT::encode([
        'iss'   => jwtIssuer(),
        'aud'   => jwtAudience(),
        'sub'   => (string) $user['id'],
        'id'    => (int) $user['id'],
        'email' => $user['email'],
        'role'  => $user['role'],
        'ver'   => (int) ($user['auth_version'] ?? 1),
        'sid'   => $sessionKey,
        'type'  => 'access',
        'iat'   => $now,
        'nbf'   => $now,
        'exp'   => $now + $ttl,
    ], jwtSecret(), 'HS256');
}

function createRefreshSession(mysqli $conn, int $userId, string $csrfToken, int $sessionId): string
{
    $selector = bin2hex(random_bytes(16));
    $validator = randomUrlSafeToken(32);
    $validatorHash = hash('sha256', $validator);
    $csrfHash = hash('sha256', $csrfToken);
    $expiresAt = date('Y-m-d H:i:s', time() + (int) (config('JWT_REFRESH_EXPIRES_IN', '2592000') ?? '2592000'));
    $ip = clientIpAddress();
    $agent = userAgent();

    $stmt = $conn->prepare(
        'INSERT INTO auth_refresh_tokens (user_id, session_id, selector, token_hash, csrf_hash, expires_at, ip_address, user_agent) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iissssss', $userId, $sessionId, $selector, $validatorHash, $csrfHash, $expiresAt, $ip, $agent);
    $stmt->execute();
    $stmt->close();

    return $selector . '.' . $validator;
}

function parseRefreshCookie(?string $cookieValue): ?array
{
    if ($cookieValue === null || !preg_match('/^([a-f0-9]{32})\.([A-Za-z0-9_-]{40,})$/', $cookieValue, $matches)) {
        return null;
    }

    return ['selector' => $matches[1], 'validator' => $matches[2]];
}

function revokeRefreshTokenBySelector(mysqli $conn, string $selector): void
{
    $stmt = $conn->prepare('UPDATE auth_refresh_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE selector = ?');
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $stmt->close();
}

function revokeRefreshTokensForUser(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare('UPDATE auth_refresh_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE user_id = ? AND revoked_at IS NULL');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    revokeAuthSessionsForUser($conn, $userId, 'security_change');
}

function jsonSuccess(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
}

function publicUserData(array $user): array
{
    return [
        'id'         => (int) $user['id'],
        'name'       => $user['name'],
        'email'      => $user['email'],
        'role'       => $user['role'],
        'is_active'  => (int) ($user['is_active'] ?? 1),
        'last_login' => $user['last_login'] ?? null,
        'created_at' => $user['created_at'] ?? null,
        'updated_at' => $user['updated_at'] ?? null,
    ];
}
