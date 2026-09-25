<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/roles.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json');

$transactionStarted = false;

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

    // Administrative user editing is privileged. Personal password changes use /users/update,
    // which verifies the user's current password.
    requireRole($userData, [ROLE_SUPER_ADMIN], 'Only the Super Admin can modify user accounts.');
    enforceSensitiveActionRateLimit($conn, 'admin_user_edit', $loggedInUserId);

    // Keep privileged account changes atomic and lock the target while checking
    // Super Admin continuity.
    $conn->begin_transaction();
    $transactionStarted = true;

    /**
     * Check if target user exists
     */
    $checkStmt = $conn->prepare("SELECT id, email, role, is_active FROM users WHERE id = ? FOR UPDATE");
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
    $invalidatesSessions = false;

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
        $invalidatesSessions = true;
    }

    // Password (optional)
    if (isset($data['password']) && trim($data['password']) !== '') {
        $password = trim($data['password']);
        
        assertPasswordStrength($password, 400);

        $updateFields[] = "password = ?";
        $params[] = password_hash($password, PASSWORD_DEFAULT);
        $types .= "s";
        $invalidatesSessions = true;
    }

    // Role (Admin only)
    if (isset($data['role'])) {
        $allowedRoles = ['super_admin', 'admin', 'sales', 'accounting'];
        if (!in_array($data['role'], $allowedRoles, true)) {
            throw new Exception("Invalid role. Allowed: super_admin, admin, sales, accounting", 400);
        }
        if ($targetUserId === $loggedInUserId && $data['role'] !== ROLE_SUPER_ADMIN) {
            throw new Exception('You cannot remove your own Super Admin role.', 409);
        }
        assertSuperAdminContinuity($conn, $existingUser, (string) $data['role'], null, false);

        $updateFields[] = "role = ?";
        $params[] = $data['role'];
        $types .= "s";
        $invalidatesSessions = true;
    }

    // is_active status (Admin only)
    if (isset($data['is_active'])) {
        $newActive = (int) $data['is_active'];
        if (!in_array($newActive, [0, 1], true)) {
            throw new Exception('Account status must be active or inactive.', 400);
        }
        if ($targetUserId === $loggedInUserId && $newActive === 0) {
            throw new Exception('You cannot deactivate your own account.', 409);
        }
        assertSuperAdminContinuity($conn, $existingUser, null, $newActive, false);

        $updateFields[] = "is_active = ?";
        $params[] = $newActive;
        $types .= "i";
        $invalidatesSessions = true;
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update", 400);
    }

    if ($invalidatesSessions) {
        $updateFields[] = "auth_version = auth_version + 1";
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

    if ($invalidatesSessions) {
        revokeRefreshTokensForUser($conn, $targetUserId);
        invalidatePendingAuthChallenges($conn, $targetUserId);
    }

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

    $conn->commit();
    $transactionStarted = false;

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "User updated successfully",
        "data"    => $updatedData
    ]);

} catch (Exception $e) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    error_log("Update User Error: " . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        "status"  => "failed",
        "message" => $code === 500 ? 'Unable to update the user at this time.' : $e->getMessage()
    ]);
}

?>