<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed.', 405);
    }

    $accessToken = trim((string) ($_COOKIE[accessCookieName()] ?? ''));
    $refreshToken = trim((string) ($_COOKIE[refreshCookieName()] ?? ''));

    // A genuine first-time/fully signed-out visitor is not an error condition.
    // Returning 200 here prevents a harmless bootstrap check from polluting the
    // browser console with 401 responses and, more importantly, makes the guest
    // state explicit to the frontend.
    if ($accessToken === '' && $refreshToken === '') {
        jsonSuccess([
            'status' => 'success',
            'data' => [
                'authenticated' => false,
                'user' => null,
                'session_policy' => sessionPolicyData($conn),
            ],
        ]);
        exit;
    }

    // If the short-lived access cookie has expired but the refresh cookie still
    // exists, deliberately ask the shared Axios interceptor to refresh. This
    // keeps persistent sign-in working while still keeping a clean guest load.
    if ($accessToken === '' && $refreshToken !== '') {
        http_response_code(401);
        echo json_encode([
            'status' => 'failed',
            'message' => 'Your session needs to be refreshed.',
            'reason' => 'session_refresh_required',
        ]);
        exit;
    }

    $user = authenticateUser();

    jsonSuccess([
        'status' => 'success',
        'data'   => [
            'authenticated' => true,
            'user'           => $user,
            'session_policy' => sessionPolicyData($conn),
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status'  => 'failed',
        'message' => 'Unable to retrieve session.',
    ]);
}
