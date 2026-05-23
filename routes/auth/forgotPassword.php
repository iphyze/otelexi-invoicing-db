<?php
// routes/auth/forgotPassword.php

declare(strict_types=1);


use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

/**
 * Always use the same accepted response once an email address has been validated.
 * This prevents exposing whether the supplied email belongs to an active account.
 */
function respondPasswordResetAccepted(): void
{
    http_response_code(200);
    echo json_encode([
        'status'  => 'success',
        'message' => 'If that email address is registered, you will receive a reset link shortly.',
    ]);
    exit;
}

try {

    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../../includes/connection.php';
    require_once __DIR__ . '/../../utils/mailer.php';
    require_once __DIR__ . '/../../utils/emailTemplates.php';

    Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('Invalid request format.', 400);
    }

    $email = strtolower(trim((string) ($data['email'] ?? '')));

    if ($email === '') {
        throw new Exception('Email address is required.', 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address.', 400);
    }

    // Look up the account. The same response is returned if it is absent or inactive.
    $stmt = $conn->prepare(
        'SELECT id, name, email, is_active FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int) $user['is_active'] !== 1) {
        respondPasswordResetAccepted();
    }

    $userId = (int) $user['id'];

    // Limit repeat reset requests for an active account while keeping the response generic.
    $maxRequests = max(1, (int) ($_ENV['PASSWORD_RESET_MAX_REQUESTS'] ?? 3));
    $rateWindowMinutes = max(
        1,
        (int) ($_ENV['PASSWORD_RESET_RATE_WINDOW_MINUTES'] ?? 30)
    );

    $rateStmt = $conn->prepare(
        'SELECT COUNT(*) AS request_count
         FROM password_reset_tokens
         WHERE user_id = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $rateStmt->bind_param('ii', $userId, $rateWindowMinutes);
    $rateStmt->execute();
    $requestCount = (int) (
        $rateStmt->get_result()->fetch_assoc()['request_count'] ?? 0
    );
    $rateStmt->close();

    if ($requestCount >= $maxRequests) {
        error_log("Password reset rate limit reached for user ID {$userId}.");
        respondPasswordResetAccepted();
    }

    $expiryMinutes = min(
        1440,
        max(5, (int) ($_ENV['PASSWORD_RESET_EXPIRES_MINUTES'] ?? 30))
    );

    $rawToken = bin2hex(random_bytes(32));
    $hashedToken = hash('sha256', $rawToken);
    $expiresAt = date(
        'Y-m-d H:i:s',
        strtotime("+{$expiryMinutes} minutes")
    );

    // Replace any previously unused link with one new reset link atomically.
    $conn->begin_transaction();

    try {
        $invalidate = $conn->prepare(
            'UPDATE password_reset_tokens
             SET used_at = NOW()
             WHERE user_id = ?
               AND used_at IS NULL'
        );
        $invalidate->bind_param('i', $userId);
        $invalidate->execute();
        $invalidate->close();

        $insertToken = $conn->prepare(
            'INSERT INTO password_reset_tokens (user_id, token, expires_at)
             VALUES (?, ?, ?)'
        );
        $insertToken->bind_param('iss', $userId, $hashedToken, $expiresAt);
        $insertToken->execute();
        $insertToken->close();

        $conn->commit();
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }

    // Build reset URL.
    $frontendUrl = rtrim(
        (string) ($_ENV['FRONTEND_URL'] ?? 'http://localhost:5173'),
        '/'
    );
    $resetUrl = "{$frontendUrl}/reset-password?token={$rawToken}";

    // Fetch company name for email branding.
    $settingsRes = $conn->query(
        'SELECT company_name FROM company_settings LIMIT 1'
    );
    $settings = $settingsRes ? $settingsRes->fetch_assoc() : [];

    $companyName = trim(
        (string) ($settings['company_name'] ?? 'Otelex')
    ) ?: 'Otelex';

    $companyName = str_replace(["\r", "\n"], ' ', $companyName);

    $htmlBody = emailForgotPassword(
        htmlspecialchars(
            (string) $user['name'],
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ),
        htmlspecialchars(
            $resetUrl,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ),
        htmlspecialchars(
            $companyName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        )
    );

    // Send password reset email.
    sendMail(
        to: (string) $user['email'],
        toName: (string) $user['name'],
        subject: "Reset Your {$companyName} Password",
        body: $htmlBody
    );

    /*
     * The reset email has already been sent.
     * An activity-log issue must not make the frontend report failure
     * after the user has already received a valid link.
     */
    try {
        $logStmt = $conn->prepare(
            'INSERT INTO activity_log
                (user_id, action, model_type, model_id, description, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $action = 'auth.password_reset_requested';
        $modelType = 'User';
        $description = "Password reset requested for {$user['email']}";
        $ip = substr(
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            0,
            45
        );

        $logStmt->bind_param(
            'ississ',
            $userId,
            $action,
            $modelType,
            $userId,
            $description,
            $ip
        );

        $logStmt->execute();
        $logStmt->close();
    } catch (Throwable $logError) {
        error_log(
            'Password Reset Activity Log Error: ' . $logError->getMessage()
        );
    }

    respondPasswordResetAccepted();

} catch (Throwable $e) {
    error_log('Forgot Password Error: ' . $e->getMessage());

    $code = (int) $e->getCode();
    $isClientError = in_array($code, [400, 405, 422], true);
    $responseCode = $isClientError ? $code : 500;

    http_response_code($responseCode);
    echo json_encode([
        'status'  => 'failed',
        'message' => $isClientError
            ? $e->getMessage()
            : 'We could not process your password reset request right now. Please try again shortly.',
    ]);
}