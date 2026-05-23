<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/security.php';

function unauthorized(string $message = 'Your session is invalid or has expired.'): void
{
    clearAccessCookie();
    http_response_code(401);
    echo json_encode([
        'status'  => 'failed',
        'message' => $message,
    ]);
    exit;
}

function authenticateUser(): array
{
    global $conn;

    requireCsrfProtection();

    $token = $_COOKIE[accessCookieName()] ?? '';
    if ($token === '') {
        unauthorized('Authentication is required.');
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
            unauthorized('Your account is unavailable. Please contact the administrator.');
        }

        if ((int) ($decoded['ver'] ?? 0) !== (int) $user['auth_version']) {
            unauthorized('Your session has changed. Please sign in again.');
        }

        return publicUserData($user);
    } catch (Throwable $e) {
        unauthorized();
    }
}
