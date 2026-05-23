<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/security.php';

use Respect\Validation\Validator as v;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed.', 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['email'], $data['password'])) {
        throw new Exception('Email and password are required.', 400);
    }

    $email = strtolower(trim((string) $data['email']));
    $password = (string) $data['password'];

    if (!v::email()->validate($email) || $password === '') {
        throw new Exception('Invalid email or password.', 401);
    }

    $identifierHash = hash('sha256', $email);
    $ipAddress = clientIpAddress();
    $windowMinutes = max(1, (int) (config('LOGIN_RATE_WINDOW_MINUTES', '15') ?? '15'));
    $maxAttempts = max(1, (int) (config('LOGIN_MAX_ATTEMPTS', '5') ?? '5'));
    $cutoff = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

    $cleanup = $conn->prepare('DELETE FROM auth_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $cleanup->execute();
    $cleanup->close();

    $attemptStmt = $conn->prepare(
        'SELECT COUNT(*) AS failed_attempts FROM auth_login_attempts WHERE identifier_hash = ? AND ip_address = ? AND success = 0 AND attempted_at >= ?'
    );
    $attemptStmt->bind_param('sss', $identifierHash, $ipAddress, $cutoff);
    $attemptStmt->execute();
    $failedAttempts = (int) ($attemptStmt->get_result()->fetch_assoc()['failed_attempts'] ?? 0);
    $attemptStmt->close();

    if ($failedAttempts >= $maxAttempts) {
        throw new Exception('Too many failed sign-in attempts. Please try again later.', 429);
    }

    $stmt = $conn->prepare(
        'SELECT id, name, email, password, role, is_active, auth_version, last_login, created_at, updated_at FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $authenticated = $user && (int) $user['is_active'] === 1 && password_verify($password, $user['password']);
    if (!$authenticated) {
        $failed = $conn->prepare(
            'INSERT INTO auth_login_attempts (identifier_hash, ip_address, success) VALUES (?, ?, 0)'
        );
        $failed->bind_param('ss', $identifierHash, $ipAddress);
        $failed->execute();
        $failed->close();

        throw new Exception('Invalid email or password.', 401);
    }

    $clearAttempts = $conn->prepare('DELETE FROM auth_login_attempts WHERE identifier_hash = ? AND ip_address = ?');
    $clearAttempts->bind_param('ss', $identifierHash, $ipAddress);
    $clearAttempts->execute();
    $clearAttempts->close();

    $conn->begin_transaction();
    try {
        $updateLogin = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $updateLogin->bind_param('i', $user['id']);
        $updateLogin->execute();
        $updateLogin->close();

        $csrfToken = randomUrlSafeToken(32);
        $refreshToken = createRefreshSession($conn, (int) $user['id'], $csrfToken);
        $accessToken = buildAccessToken($user);

        $log = $conn->prepare(
            'INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $action = 'auth.login';
        $modelType = 'User';
        $modelId = (int) $user['id'];
        $description = $user['name'] . ' logged in successfully';
        $log->bind_param('ississ', $user['id'], $action, $modelType, $modelId, $description, $ipAddress);
        $log->execute();
        $log->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    issueAuthCookies($accessToken, $refreshToken, $csrfToken);
    $user['last_login'] = date('Y-m-d H:i:s');

    jsonSuccess([
        'status'  => 'success',
        'message' => 'Login successful.',
        'data'    => [
            'user'       => publicUserData($user),
            'csrf_token' => $csrfToken,
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }

    error_log('Login Error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to sign in at this time.' : $e->getMessage(),
    ]);
}
