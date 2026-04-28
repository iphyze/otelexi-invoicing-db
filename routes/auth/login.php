<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';

use Firebase\JWT\JWT;
use Respect\Validation\Validator as v;
use Dotenv\Dotenv;

header('Content-Type: application/json');

try {
    // Load environment variables
    $dotenv = Dotenv::createImmutable('./');
    $dotenv->load();

    // Ensure the request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    // Get the JSON input
    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->email) || !isset($data->password)) {
        throw new Exception("Email and Password are required", 400);
    }

    $email = trim($data->email);
    $password = trim($data->password);

    // Define validators
    $emailValidator = v::email()->notEmpty();

    // Validate email
    if (!$emailValidator->validate($email)) {
        throw new Exception("Invalid email format", 400);
    }

    // Validate password length and special character
    if (!v::stringType()->length(8, null)->validate($password)) {
        throw new Exception("Password must be at least 8 characters long", 400);
    }

    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        throw new Exception("Password must contain at least one special character (e.g. @, _, /, #)", 400);
    }

    // Query database — use prepared statement directly, no mysqli_real_escape_string needed
    $sql = "SELECT * FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . $conn->error, 500);
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        throw new Exception("Database execution error: " . $stmt->error, 500);
    }

    if ($result->num_rows === 0) {
        throw new Exception("Invalid email or password", 401);
    }

    $user = $result->fetch_assoc();

    // Check if account is active
    if ((int)$user['is_active'] !== 1) {
        throw new Exception("Account has been deactivated. Contact the administrator.", 403);
    }

    // Verify password
    if (!password_verify($password, $user['password'])) {
        throw new Exception("Invalid email or password", 401);
    }

    // Generate JWT
    $secretKey = $_ENV["JWT_SECRET"] ?: "otelex_secret_key";
    $expiresIn = (int)($_ENV["JWT_EXPIRES_IN"] ?: 432000);

    $tokenPayload = [
        "id"    => (int)$user['id'],
        "email" => $user['email'],
        "role"  => $user['role'],
        "exp"   => time() + $expiresIn
    ];

    $token = JWT::encode($tokenPayload, $secretKey, 'HS256');

    // Update last_login
    $updateLogin = "UPDATE users SET last_login = NOW() WHERE id = ?";
    $stmt = $conn->prepare($updateLogin);
    if ($stmt) {
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
    }

    // Log to activity_log
    $logSql = "INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($logSql);
    if ($stmt) {
        $action      = "auth.login";
        $modelType   = "User";
        $modelId     = (int)$user['id'];
        $description = $user['name'] . " logged in successfully";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $stmt->bind_param("ississ", $user['id'], $action, $modelType, $modelId, $description, $ipAddress);
        $stmt->execute();
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Login successful",
        "data"    => [
            "id"         => (int)$user['id'],
            "name"       => $user['name'],
            "email"      => $user['email'],
            "role"       => $user['role'],
            "token"      => $token,
            "expires_in" => $expiresIn
        ]
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}

?>