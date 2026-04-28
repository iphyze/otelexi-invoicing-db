<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

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

    // Only Admin allowed to permanently delete clients
    if ($loggedInUserRole !== 'admin') {
        throw new Exception("Unauthorized: Only Admins can access this resource", 403);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['clientIds']) || !is_array($data['clientIds']) || count($data['clientIds']) === 0) {
        throw new Exception("Please select at least one client to delete.", 400);
    }

    $clientIds = array_map('intval', $data['clientIds']);

    // Start transaction
    $conn->begin_transaction();

    try {
        /**
         * Hard delete clients
         */
        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $deleteQuery = "DELETE FROM clients WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error: Failed to prepare delete statement", 500);
        }

        $deleteStmt->bind_param(str_repeat('i', count($clientIds)), ...$clientIds);

        if (!$deleteStmt->execute()) {
            // Catch Foreign Key Constraint violations (Error 1451)
            // This triggers if they have Quotations, Proformas, or Invoices (which use RESTRICT)
            if ($conn->errno == 1451) {
                throw new Exception("Cannot delete client(s). They have associated records (e.g., invoices, quotations, or proformas) that must be deleted first.", 409);
            }
            throw new Exception("Failed to delete clients: " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching clients found to delete.", 404);
        }

        $deleteStmt->close();

        /**
         * Log action (Fixed to match 6 columns)
         */
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "client.deleted_permanently";
        $modelType   = "Client";
        $modelId     = null; // Bulk action
        $description = "{$loggedInUserEmail} permanently deleted client record(s) with ID(s): " . implode(', ', $clientIds);
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
            "message" => "Client record(s) permanently deleted."
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete Clients Permanent Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}

?>