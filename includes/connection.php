<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/security.php';

loadEnvironment();

try {
    $host = requiredConfig('DB_HOST');
    $port = (int) (config('DB_PORT', '3306') ?? '3306');
    $database = requiredConfig('DB_NAME');
    $username = requiredConfig('DB_USER');
    $password = config('DB_PASSWORD', '') ?? '';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($host, $username, $password, $database, $port);
    $conn->set_charset('utf8mb4');

    // Keep database NOW()/timestamp comparisons aligned with PHP's configured app timezone.
    $timezoneName = config('APP_TIMEZONE', 'Africa/Lagos') ?? 'Africa/Lagos';
    $timezone = new DateTimeZone($timezoneName);
    $timezoneOffset = (new DateTimeImmutable('now', $timezone))->format('P');
    $conn->query("SET time_zone = '" . $conn->real_escape_string($timezoneOffset) . "'");
} catch (Throwable $e) {
    error_log('Database Connection Error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'failed',
        'message' => 'Internal server error.',
    ]);
    exit;
}
