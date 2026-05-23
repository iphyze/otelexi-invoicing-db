<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin allowed
    if ($loggedInUserRole !== 'super_admin') {
        throw new Exception("Unauthorized: Only the Super Admin can access this resource", 403);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['userIds']) || !is_array($data['userIds']) || count($data['userIds']) === 0) {
        throw new Exception("Please select at least one user to delete.", 400);
    }

    $userIds = array_map('intval', $data['userIds']);

    // Prevent self-deletion
    if (in_array($loggedInUserId, $userIds)) {
        throw new Exception("You cannot delete your own account.", 400);
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        /**
         * Hard delete users
         */
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $deleteQuery = "DELETE FROM users WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error: Failed to prepare delete statement", 500);
        }

        $deleteStmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);

        if (!$deleteStmt->execute()) {
            // Catch Foreign Key Constraint violations (Error 1451)
            if ($conn->errno == 1451) {
                throw new Exception("Cannot delete user(s). They have associated records (e.g., invoices or quotations) that must be deleted first.", 409);
            }
            throw new Exception("Failed to delete users: " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching users found to delete.", 404);
        }

        $deleteStmt->close();

        /**
         * Log action (Fixed to match 6 columns)
         */
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "user.deleted";
        $modelType   = "User";
        $modelId     = null; // Bulk action
        $description = "{$loggedInUserEmail} permanently deleted user account(s) with ID(s): " . implode(', ', $userIds);
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log delete action: " . $logStmt->error, 500);
        }

        $logStmt->close();

        // Commit transaction
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "User account(s) permanently deleted."
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete User Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}

?>