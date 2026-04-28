<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin allowed
    if ($loggedInUserRole !== 'admin') {
        throw new Exception("Unauthorized: Only Admins can deactivate users", 403);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['userIds']) || !is_array($data['userIds']) || count($data['userIds']) === 0) {
        throw new Exception("Please select at least one user to deactivate.", 400);
    }

    $userIds = array_map('intval', $data['userIds']);

    // Prevent self-deactivation
    if (in_array($loggedInUserId, $userIds)) {
        throw new Exception("You cannot deactivate your own account.", 400);
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        /**
         * Soft-delete users (set is_active = 0)
         * Only target users that are currently active to avoid redundant logs
         */
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $updateQuery = "UPDATE users SET is_active = 0 WHERE id IN ($placeholders) AND is_active = 1";
        $updateStmt = $conn->prepare($updateQuery);

        if (!$updateStmt) {
            throw new Exception("Database error: Failed to prepare statement", 500);
        }

        $updateStmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);

        if (!$updateStmt->execute()) {
            throw new Exception("Failed to deactivate users: " . $updateStmt->error, 500);
        }

        if ($updateStmt->affected_rows === 0) {
            throw new Exception("Selected users are already deactivated or do not exist.", 404);
        }

        $updateStmt->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "user.deactivated";
        $modelType   = "User";
        $modelId     = null; // Bulk action, so we pass IDs in description
        $description = "{$loggedInUserEmail} deactivated user account(s) with ID(s): " . implode(', ', $userIds);
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log action: " . $logStmt->error, 500);
        }

        $logStmt->close();

        // Commit transaction
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "User account(s) deactivated successfully."
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Deactivate User Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}

?>