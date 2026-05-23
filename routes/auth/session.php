<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed.', 405);
    }

    $user = authenticateUser();

    jsonSuccess([
        'status' => 'success',
        'data'   => ['user' => $user],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status'  => 'failed',
        'message' => 'Unable to retrieve session.',
    ]);
}
