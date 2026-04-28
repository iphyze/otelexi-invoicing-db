<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserEmail = $userData['email'];

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    // Require target user ID (can come from URL param or body)
    $targetUserId = isset($data['id']) ? (int)$data['id'] : 0;
    if ($targetUserId <= 0) {
        throw new Exception("Field 'id' is required.", 400);
    }

    /**
     * Authorization rule:
     * - Admin can update anyone
     * - Others can only update their own account
     */
    if ($userData['role'] !== 'admin' && $targetUserId !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only update your own account", 403);
    }

    /**
     * Check if target user exists
     */
    $checkStmt = $conn->prepare("SELECT id, email FROM users WHERE id = ?");
    $checkStmt->bind_param("i", $targetUserId);
    $checkStmt->execute();
    $existingUser = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existingUser) {
        throw new Exception("User not found.", 404);
    }

    /**
     * Build dynamic update fields
     */
    $updateFields = [];
    $params = [];
    $types = "";

    // Name
    if (isset($data['name']) && trim($data['name']) !== '') {
        $name = trim($data['name']);
        if (!v::stringType()->length(2, 150)->validate($name)) {
            throw new Exception("Name must be between 2 and 150 characters", 400);
        }
        $updateFields[] = "name = ?";
        $params[] = $name;
        $types .= "s";
    }

    // Email
    if (isset($data['email']) && trim($data['email']) !== '') {
        $email = strtolower(trim($data['email']));
        if (!v::email()->validate($email)) {
            throw new Exception("Invalid email format", 400);
        }

        // Prevent duplicate email (exclude current user)
        $dupStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $dupStmt->bind_param("si", $email, $targetUserId);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            throw new Exception("Email already in use by another user", 400);
        }
        $dupStmt->close();

        $updateFields[] = "email = ?";
        $params[] = $email;
        $types .= "s";
    }

    // Password (optional)
    if (isset($data['password']) && trim($data['password']) !== '') {
        $password = trim($data['password']);
        
        if (!v::stringType()->length(8, null)->validate($password)) {
            throw new Exception("Password must be at least 8 characters long", 400);
        }

        if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            throw new Exception("Password must contain at least one special character (e.g. @, _, /, #)", 400);
        }

        $updateFields[] = "password = ?";
        $params[] = password_hash($password, PASSWORD_DEFAULT);
        $types .= "s";
    }

    // Role (Admin only)
    if (isset($data['role'])) {
        if ($userData['role'] !== 'admin') {
            throw new Exception("Unauthorized: Only Admins can update user roles", 403);
        }

        $allowedRoles = ['admin', 'sales', 'accountant'];
        if (!in_array($data['role'], $allowedRoles)) {
            throw new Exception("Invalid role. Allowed: admin, sales, accountant", 400);
        }

        $updateFields[] = "role = ?";
        $params[] = $data['role'];
        $types .= "s";
    }

    // is_active status (Admin only)
    if (isset($data['is_active'])) {
        if ($userData['role'] !== 'admin') {
            throw new Exception("Unauthorized: Only Admins can change account status", 403);
        }

        $updateFields[] = "is_active = ?";
        $params[] = (int)$data['is_active'];
        $types .= "i";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update", 400);
    }

    /**
     * Execute update
     */
    $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $params[] = $targetUserId;
    $types .= "i";

    $updateStmt = $conn->prepare($sql);
    if (!$updateStmt) {
        throw new Exception("Failed to prepare update query: " . $conn->error, 500);
    }

    $updateStmt->bind_param($types, ...$params);
    if (!$updateStmt->execute()) {
        throw new Exception("Update failed: " . $updateStmt->error, 500);
    }
    $updateStmt->close();

    /**
     * Log action
     */
    $logStmt = $conn->prepare("
        INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $action      = "user.updated";
    $modelType   = "User";
    $description = "{$loggedInUserEmail} updated user account (ID {$targetUserId})";
    $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $targetUserId, $description, $ipAddress);
    $logStmt->execute();
    $logStmt->close();

    /**
     * Fetch and return updated record
     */
    $fetchStmt = $conn->prepare("
        SELECT id, name, email, role, is_active, last_login, created_at, updated_at
        FROM users 
        WHERE id = ?
    ");
    $fetchStmt->bind_param("i", $targetUserId);
    $fetchStmt->execute();
    $updatedData = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "User updated successfully",
        "data"    => $updatedData
    ]);

} catch (Exception $e) {
    error_log("Update User Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}

?>