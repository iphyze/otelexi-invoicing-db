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

    /**
     * Fetch current user record (for password verification)
     */
    $userStmt = $conn->prepare("
        SELECT id, email, password 
        FROM users 
        WHERE id = ?
        LIMIT 1
    ");
    $userStmt->bind_param("i", $loggedInUserId);
    $userStmt->execute();
    $currentUser = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if (!$currentUser) {
        throw new Exception("User record not found", 404);
    }

    /**
     * Build update fields dynamically
     */
    $updateFields = [];
    $params = [];
    $types = "";

    // Name
    // if (isset($data['name']) && trim($data['name']) !== '') {
    //     $name = trim($data['name']);
    //     if (!v::stringType()->length(2, 150)->validate($name)) {
    //         throw new Exception("Name must be between 2 and 150 characters", 400);
    //     }
    //     $updateFields[] = "name = ?";
    //     $params[] = $name;
    //     $types .= "s";
    // }

    // Email
    // if (isset($data['email']) && trim($data['email']) !== '') {
    //     $email = strtolower(trim($data['email']));
    //     if (!v::email()->validate($email)) {
    //         throw new Exception("Invalid email format", 400);
    //     }

    //     // Prevent duplicate email (exclude self)
    //     $dupStmt = $conn->prepare("
    //         SELECT id FROM users 
    //         WHERE email = ? AND id != ?
    //         LIMIT 1
    //     ");
    //     $dupStmt->bind_param("si", $email, $loggedInUserId);
    //     $dupStmt->execute();
    //     if ($dupStmt->get_result()->num_rows > 0) {
    //         throw new Exception("Email already in use by another user", 400);
    //     }
    //     $dupStmt->close();

    //     $updateFields[] = "email = ?";
    //     $params[] = $email;
    //     $types .= "s";
    // }

    /**
     * Password update (requires current password)
     */
    $wantsPasswordChange = !empty($data['password']) || !empty($data['currentPassword']);

    if ($wantsPasswordChange) {
        if (empty($data['currentPassword']) || empty($data['password'])) {
            throw new Exception("Both current password and new password are required", 400);
        }

        // Verify current password
        if (!password_verify($data['currentPassword'], $currentUser['password'])) {
            throw new Exception("Current password is incorrect", 401);
        }

        // Validate new password rules
        if (!v::stringType()->length(8, null)->validate($data['password'])) {
            throw new Exception("New password must be at least 8 characters long", 400);
        }

        if (!preg_match('/[^a-zA-Z0-9]/', $data['password'])) {
            throw new Exception("New password must contain at least one special character (e.g. @, _, /, #)", 400);
        }

        $updateFields[] = "password = ?";
        $params[] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        $types .= "s";
        $updateFields[] = "auth_version = auth_version + 1";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update", 400);
    }

    /**
     * Execute update
     */
    $sql = "
        UPDATE users 
        SET " . implode(", ", $updateFields) . "
        WHERE id = ?
    ";
    $params[] = $loggedInUserId;
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

    if ($wantsPasswordChange) {
        // A password change invalidates every refresh session, including this browser.
        revokeRefreshTokensForUser($conn, $loggedInUserId);
        clearAuthCookies();
    }

    /**
     * Log action
     */
    $logStmt = $conn->prepare("
        INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $action      = "profile.updated";
    $modelType   = "User";
    $description = "{$loggedInUserEmail} updated their profile";
    $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $loggedInUserId, $description, $ipAddress);
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
    $fetchStmt->bind_param("i", $loggedInUserId);
    $fetchStmt->execute();
    $updatedData = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Profile updated successfully",
        "data"    => [
            "id"         => (int)$updatedData['id'],
            "name"       => $updatedData['name'],
            "email"      => $updatedData['email'],
            "role"       => $updatedData['role'],
            "is_active"  => (int)$updatedData['is_active'],
            "last_login" => $updatedData['last_login'],
            "created_at" => $updatedData['created_at'],
            "updated_at" => $updatedData['updated_at'],
            "requires_reauthentication" => $wantsPasswordChange
        ]
    ]);

} catch (Exception $e) {
    error_log("Update Profile Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}

?>