<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/security.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed.', 405);
    }

    $csrfToken = $_COOKIE[csrfCookieName()] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]{40,}$/', $csrfToken)) {
        $csrfToken = randomUrlSafeToken(32);
        $ttl = (int) (config('JWT_REFRESH_EXPIRES_IN', '2592000') ?? '2592000');
        setAppCookie(csrfCookieName(), $csrfToken, time() + $ttl, apiBasePath(), false);
    }

    jsonSuccess([
        'status' => 'success',
        'data'   => ['csrf_token' => $csrfToken],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status'  => 'failed',
        'message' => 'Unable to initialise security token.',
    ]);
}
