<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../includes/mfa.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed.', 405);
    }

    $actor = authenticateUser(true);
    requireRole($actor, [ROLE_SUPER_ADMIN], 'Only the Super Admin can reset another user\'s MFA.');

    enforceSensitiveActionRateLimit($conn, 'admin_mfa_recovery', (int) $actor['id']);

    $data = json_decode(file_get_contents('php://input'), true);
    $targetUserId = (int) ($data['user_id'] ?? 0);
    if ($targetUserId <= 0) {
        throw new Exception('A valid user is required.', 422);
    }
    if ($targetUserId === (int) $actor['id']) {
        throw new Exception('You cannot use administrative recovery to disable MFA on your own account.', 409);
    }

    ensureMfaTables($conn);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'SELECT id, name, email, role, is_active, auth_version FROM users WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->bind_param('i', $targetUserId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target) {
            throw new Exception('User not found.', 404);
        }

        setEmailMfaEnabled($conn, $targetUserId, false);
        invalidatePendingAuthChallenges($conn, $targetUserId);

        $version = $conn->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?');
        $version->bind_param('i', $targetUserId);
        $version->execute();
        $version->close();

        revokeRefreshTokensForUser($conn, $targetUserId);

        logAuthActivity(
            $conn,
            (int) $actor['id'],
            'auth.mfa_admin_reset',
            $actor['name'] . ' reset email MFA for ' . $target['email']
        );

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    try {
        $safeName = htmlspecialchars((string) $target['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '<div style="font-family:Arial,sans-serif;color:#1f2937;line-height:1.6">'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Your Otelex email multi-factor authentication was reset by a Super Admin.</p>'
            . '<p>All active sessions were signed out. You can sign in with your password and enable email MFA again from My Profile.</p>'
            . '<p>If you did not expect this change, contact your administrator immediately.</p>'
            . '</div>';
        sendMail(
            (string) $target['email'],
            (string) $target['name'],
            'Your Otelex email MFA was reset',
            $body,
            'Your Otelex email multi-factor authentication was reset by a Super Admin. All active sessions were signed out.'
        );
    } catch (Throwable $mailError) {
        error_log('Admin MFA Reset Notification Error: ' . $mailError->getMessage());
    }

    jsonSuccess([
        'status'  => 'success',
        'message' => 'Email MFA has been reset. The user has been signed out and can enable MFA again after signing in.',
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    error_log('Admin MFA Reset Error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to reset email MFA at this time.' : $e->getMessage(),
    ]);
}
