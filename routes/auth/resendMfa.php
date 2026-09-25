<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/mfa.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed.', 405);
    }

    // Pre-auth challenge endpoints are protected by short-lived, single-use,
    // device-bound capability tokens and global origin checks rather than an
    // authenticated-session CSRF cookie that does not yet exist.
    enforceAuthRateLimit(
        $conn,
        'mfa_resend_ip',
        clientIpAddress(),
        max(3, (int) (config('MFA_IP_MAX_RESENDS', '10') ?? '10')),
        max(300, (int) (config('MFA_RATE_WINDOW_MINUTES', '30') ?? '30') * 60),
        'Too many verification-code requests from this network. Please wait and try again.'
    );
    $data = json_decode(file_get_contents('php://input'), true);
    $challengeToken = trim((string) ($data['mfa_challenge'] ?? ''));
    if ($challengeToken === '') {
        throw new Exception('Verification challenge is required.', 400);
    }

    $deviceIdentity = ensureDeviceIdentity();
    $result = resendMfaCode($conn, $challengeToken, (string) $deviceIdentity['hash']);

    jsonSuccess([
        'status'  => 'success',
        'message' => 'A new verification code has been sent.',
        'data'    => $result,
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    error_log('MFA Resend Error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to resend the verification code.' : $e->getMessage(),
    ]);
}
