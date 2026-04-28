<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserName = $userData['email'];

    // Only Admin allowed
    if ($userData['role'] !== 'admin') {
        throw new Exception("Unauthorized: Only Admins can create users", 403);
    }

    // Decode JSON body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // Required fields
    $requiredFields = ['name', 'email', 'password', 'role'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // Clean inputs
    $name     = trim($data['name']);
    $email    = strtolower(trim($data['email']));
    $password = trim($data['password']);
    $role     = trim($data['role']);
    
    // Determine active status (defaults to 1 if not provided)
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

    // Validation
    if (!v::stringType()->length(2, 150)->validate($name)) {
        throw new Exception("Name must be between 2 and 150 characters", 400);
    }

    if (!v::email()->validate($email)) {
        throw new Exception("Invalid email format", 400);
    }

    if (!v::stringType()->length(8, null)->validate($password)) {
        throw new Exception("Password must be at least 8 characters long", 400);
    }

    // Demand at least one special character
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        throw new Exception("Password must contain at least one special character (e.g. @, _, /, #)", 400);
    }

    $allowedRoles = ['admin', 'sales', 'accountant'];
    if (!in_array($role, $allowedRoles)) {
        throw new Exception("Invalid role. Allowed: admin, sales, accountant", 400);
    }

    // Validate is_active strictly
    if (!in_array($isActive, [0, 1])) {
        throw new Exception("Invalid is_active value. Must be 0 (inactive) or 1 (active)", 400);
    }

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Check for duplicate email
    $dupStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $dupStmt->bind_param("s", $email);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("User with this email already exists", 400);
    }
    $dupStmt->close();

    // Insert user
    $insertStmt = $conn->prepare("INSERT INTO users (name, email, password, role, is_active) VALUES (?, ?, ?, ?, ?)");
    $insertStmt->bind_param("ssssi", $name, $email, $hashedPassword, $role, $isActive);

    if (!$insertStmt->execute()) {
        throw new Exception("Database insert failed: " . $insertStmt->error, 500);
    }

    $insertedId = $insertStmt->insert_id;
    $insertStmt->close();

    // Log action
    $logStmt = $conn->prepare("
        INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $action      = "user.created";
    $modelType   = "User";
    $statusText  = $isActive === 1 ? 'active' : 'inactive';
    $description = "{$loggedInUserName} created a new user ({$email}) with role {$role} ({$statusText})";
    $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $insertedId, $description, $ipAddress);
    $logStmt->execute();
    $logStmt->close();

    http_response_code(201);
    echo json_encode([
        "status"  => "success",
        "message" => "User created successfully",
        "data"    => [
            "id"        => (int)$insertedId,
            "name"      => $name,
            "email"     => $email,
            "role"      => $role,
            "is_active" => (int)$isActive
        ]
    ]);

} catch (Exception $e) {
    error_log("Create User Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}

?>