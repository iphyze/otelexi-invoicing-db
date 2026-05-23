<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Firebase\JWT\JWT;

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

function apiBasePath(): string
{
    return rtrim(config('API_BASE_PATH', '/otelex-server/api') ?? '/otelex-server/api', '/');
}

function cookieSecure(): bool
{
    return configBool('COOKIE_SECURE', config('APP_ENV', 'production') === 'production');
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
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

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
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
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

function buildAccessToken(array $user): string
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
        'type'  => 'access',
        'iat'   => $now,
        'nbf'   => $now,
        'exp'   => $now + $ttl,
    ], jwtSecret(), 'HS256');
}

function createRefreshSession(mysqli $conn, int $userId, string $csrfToken): string
{
    $selector = bin2hex(random_bytes(16));
    $validator = randomUrlSafeToken(32);
    $validatorHash = hash('sha256', $validator);
    $csrfHash = hash('sha256', $csrfToken);
    $expiresAt = date('Y-m-d H:i:s', time() + (int) (config('JWT_REFRESH_EXPIRES_IN', '2592000') ?? '2592000'));
    $ip = clientIpAddress();
    $agent = userAgent();

    $stmt = $conn->prepare(
        'INSERT INTO auth_refresh_tokens (user_id, selector, token_hash, csrf_hash, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssss', $userId, $selector, $validatorHash, $csrfHash, $expiresAt, $ip, $agent);
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
