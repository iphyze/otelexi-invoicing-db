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
    enforceSensitiveActionRateLimit($conn, 'mfa_settings', (int) $user['id']);
    $data = json_decode(file_get_contents('php://input'), true);
    $action = strtolower(trim((string) ($data['action'] ?? '')));
    $currentPassword = (string) ($data['current_password'] ?? '');

    if (!in_array($action, ['enable', 'disable'], true) || $currentPassword === '') {
        throw new Exception('Current password and a valid MFA action are required.', 400);
    }

    $stmt = $conn->prepare('SELECT id, name, email, password, role, is_active, auth_version FROM users WHERE id = ? LIMIT 1');
    $userId = (int) $user['id'];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record || (int) $record['is_active'] !== 1 || !password_verify($currentPassword, (string) $record['password'])) {
        logAuthActivity($conn, $userId, 'auth.mfa_settings_password_failed', $user['name'] . ' entered an incorrect password while changing email MFA');
        throw new Exception('Current password is incorrect.', 422);
    }

    $enabled = isEmailMfaEnabled($conn, $userId);
    if ($action === 'enable' && $enabled) {
        throw new Exception('Email verification is already enabled.', 409);
    }
    if ($action === 'disable' && !$enabled) {
        throw new Exception('Email verification is already disabled.', 409);
    }

    $deviceIdentity = ensureDeviceIdentity();
    $purpose = $action === 'enable' ? 'enable_email_mfa' : 'disable_email_mfa';
    $mfa = createEmailMfaChallenge($conn, $record, $purpose, (string) $deviceIdentity['hash']);

    logAuthActivity(
        $conn,
        $userId,
        'auth.mfa_settings_challenge_sent',
        $user['name'] . ' requested to ' . $action . ' email MFA'
    );

    jsonSuccess([
        'status'  => 'success',
        'message' => 'Enter the verification code sent to your email address.',
        'data'    => [
            'action'        => $action,
            'mfa_challenge' => $mfa['challenge'],
            'masked_email'  => $mfa['masked_email'],
            'expires_in'    => $mfa['expires_in'],
            'resend_after'  => $mfa['resend_after'],
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    error_log('MFA Setup Start Error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to start this security change.' : $e->getMessage(),
    ]);
}
