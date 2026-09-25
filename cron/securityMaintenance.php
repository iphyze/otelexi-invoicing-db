<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/security.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$statements = [
    'auth_login_attempts' => "DELETE FROM auth_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)",
    'auth_rate_limit_events' => "DELETE FROM auth_rate_limit_events WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)",
    'auth_mfa_challenges' => "DELETE FROM auth_mfa_challenges WHERE (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 2 DAY)) OR expires_at < DATE_SUB(NOW(), INTERVAL 2 DAY)",
    'auth_login_challenges' => "DELETE FROM auth_login_challenges WHERE (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 2 DAY)) OR expires_at < DATE_SUB(NOW(), INTERVAL 2 DAY)",
    'password_reset_tokens' => "DELETE FROM password_reset_tokens WHERE (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) OR expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
    'auth_refresh_tokens' => "DELETE FROM auth_refresh_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY) OR (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL 7 DAY))",
    'auth_sessions' => "DELETE FROM auth_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)) OR absolute_expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
];

$results = [];
foreach ($statements as $name => $sql) {
    try {
        if (!$conn->query($sql)) {
            throw new RuntimeException($conn->error);
        }
        $results[$name] = $conn->affected_rows;
    } catch (Throwable $e) {
        // Staged deployments may run this before every migration is present.
        $results[$name] = 'skipped: ' . $e->getMessage();
    }
}

echo '[' . date('Y-m-d H:i:s') . '] security maintenance ' . json_encode($results, JSON_UNESCAPED_SLASHES) . PHP_EOL;
