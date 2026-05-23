<?php
// routes/auth/resetPassword.php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

use Dotenv\Dotenv;

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
    $dotenv->load();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Method Not Allowed", 405);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (empty($data['token'])) {
        throw new Exception("Reset token is required.", 400);
    }
    if (empty($data['password'])) {
        throw new Exception("New password is required.", 400);
    }
    if (strlen($data['password']) < 8) {
        throw new Exception("Password must be at least 8 characters long.", 422);
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $data['password'])) {
        throw new Exception("Password must contain at least one special character (e.g. @, _, /, #).", 422);
    }
    if (empty($data['password_confirmation']) || $data['password'] !== $data['password_confirmation']) {
        throw new Exception("Passwords do not match.", 422);
    }

    $rawToken    = trim($data['token']);
    $hashedToken = hash('sha256', $rawToken);
    $newPassword = $data['password'];

    // ── Validate token ────────────────────────────────────────────
    $tokenStmt = $conn->prepare("
        SELECT prt.id, prt.user_id, prt.expires_at, prt.used_at,
               u.name, u.email
        FROM password_reset_tokens prt
        JOIN users u ON u.id = prt.user_id
        WHERE prt.token = ?
        LIMIT 1
    ");
    $tokenStmt->bind_param("s", $hashedToken);
    $tokenStmt->execute();
    $tokenRow = $tokenStmt->get_result()->fetch_assoc();
    $tokenStmt->close();

    if (!$tokenRow) {
        throw new Exception("Invalid or expired reset link. Please request a new one.", 400);
    }
    if ($tokenRow['used_at'] !== null) {
        throw new Exception("This reset link has already been used. Please request a new one.", 400);
    }
    if (strtotime($tokenRow['expires_at']) < time()) {
        throw new Exception("This reset link has expired. Please request a new one.", 400);
    }

    $userId = (int)$tokenRow['user_id'];

    // ── Update password ───────────────────────────────────────────
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

    $updatePwd = $conn->prepare("UPDATE users SET password = ?, auth_version = auth_version + 1, updated_at = NOW() WHERE id = ?");
    $updatePwd->bind_param("si", $hashedPassword, $userId);
    if (!$updatePwd->execute()) {
        throw new Exception("Failed to update password. Please try again.", 500);
    }
    $updatePwd->close();

    // Invalidate all existing browser sessions after a password reset.
    revokeRefreshTokensForUser($conn, $userId);
    clearAuthCookies();

    // ── Mark token as used ────────────────────────────────────────
    $markUsed = $conn->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?");
    $markUsed->bind_param("i", $tokenRow['id']);
    $markUsed->execute();
    $markUsed->close();

    // ── Send confirmation email ───────────────────────────────────
    $settingsRes = $conn->query("SELECT company_name FROM company_settings LIMIT 1");
    $settings    = $settingsRes ? $settingsRes->fetch_assoc() : [];
    $companyName = $settings['company_name'] ?? 'Otelex';

    try {
        sendMail(
            to:      $tokenRow['email'],
            toName:  $tokenRow['name'],
            subject: "Your {$companyName} Password Has Been Changed",
            body:    emailPasswordChanged($tokenRow['name'], $companyName)
        );
    } catch (\Exception $mailErr) {
        // Non-fatal — password was reset, just log the email failure
        error_log("Password changed confirmation email failed: " . $mailErr->getMessage());
    }

    // ── Log ───────────────────────────────────────────────────────
    $logStmt = $conn->prepare(
        "INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $action      = "auth.password_reset_completed";
    $modelType   = "User";
    $description = "Password successfully reset for {$tokenRow['email']}";
    $ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $logStmt->bind_param("ississ", $userId, $action, $modelType, $userId, $description, $ip);
    $logStmt->execute();
    $logStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Password reset successfully. You can now log in with your new password."
    ]);

} catch (Exception $e) {
    error_log("Reset Password Error: " . $e->getMessage());
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>
