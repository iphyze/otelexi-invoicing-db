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
    $currentSessionKey = currentAccessSessionKey();
    if ($currentSessionKey === null) {
        throw new Exception('Current session could not be identified.', 401);
    }

    enforceSensitiveActionRateLimit($conn, 'mfa_settings_verify', (int) $user['id']);
    $data = json_decode(file_get_contents('php://input'), true);
    $action = strtolower(trim((string) ($data['action'] ?? '')));
    $challengeToken = trim((string) ($data['mfa_challenge'] ?? ''));
    $code = trim((string) ($data['code'] ?? ''));

    if (!in_array($action, ['enable', 'disable'], true) || $challengeToken === '' || $code === '') {
        throw new Exception('Verification code and challenge are required.', 400);
    }

    $purpose = $action === 'enable' ? 'enable_email_mfa' : 'disable_email_mfa';
    $deviceIdentity = ensureDeviceIdentity();

    $challenge = validateMfaCode(
        $conn,
        $challengeToken,
        $code,
        $purpose,
        (string) $deviceIdentity['hash']
    );

    if ((int) $challenge['user_id'] !== (int) $user['id']) {
        throw new Exception('This verification request does not belong to your account.', 403);
    }

    $conn->begin_transaction();
    try {
        $userStmt = $conn->prepare('SELECT auth_version FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $userId = (int) $user['id'];
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $record = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if (!$record || (int) $record['auth_version'] !== (int) $challenge['auth_version']) {
            throw new Exception('Your account security state has changed. Please start again.', 409);
        }

        consumeMfaChallenge($conn, $challenge);

        $enabled = $action === 'enable';
        setEmailMfaEnabled($conn, $userId, $enabled);

        // When MFA is first enabled, terminate other signed-in devices so every
        // subsequent session must pass the newly enabled second factor.
        if ($enabled) {
            foreach (getActiveAuthSessions($conn, $userId) as $session) {
                if (!hash_equals((string) $session['session_key'], $currentSessionKey)) {
                    revokeAuthSessionById($conn, (int) $session['id'], 'mfa_enabled');
                }
            }
        }

        logAuthActivity(
            $conn,
            $userId,
            $enabled ? 'auth.mfa_enabled' : 'auth.mfa_disabled',
            $user['name'] . ($enabled ? ' enabled' : ' disabled') . ' email multi-factor authentication'
        );

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    jsonSuccess([
        'status'  => 'success',
        'message' => $action === 'enable'
            ? 'Email multi-factor authentication is now enabled.'
            : 'Email multi-factor authentication is now disabled.',
        'data'    => [
            'email_enabled' => $action === 'enable',
            'masked_email'  => maskEmailAddress((string) $user['email']),
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    error_log('MFA Setup Verify Error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to complete this security change.' : $e->getMessage(),
    ]);
}
