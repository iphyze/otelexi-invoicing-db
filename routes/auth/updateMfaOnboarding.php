<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/mfa.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed.', 405);
    }

    $user = authenticateUser(true);
    $data = json_decode(file_get_contents('php://input'), true);
    $action = strtolower(trim((string) ($data['action'] ?? '')));

    if ($action !== 'dismiss') {
        throw new Exception('Invalid MFA onboarding action.', 400);
    }

    $userId = (int) $user['id'];
    if (isEmailMfaEnabled($conn, $userId)) {
        setMfaOnboardingStatus($conn, $userId, 'completed');
    } else {
        setMfaOnboardingStatus($conn, $userId, 'dismissed');
    }

    logAuthActivity(
        $conn,
        $userId,
        'auth.mfa_onboarding_dismissed',
        $user['name'] . ' chose not to enable email MFA during onboarding'
    );

    jsonSuccess([
        'status'  => 'success',
        'message' => 'MFA setup reminder dismissed. You can enable it later from My Profile.',
        'data'    => mfaStatusData($conn, $user),
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }

    error_log('MFA Onboarding Error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to update MFA onboarding preference.' : $e->getMessage(),
    ]);
}
