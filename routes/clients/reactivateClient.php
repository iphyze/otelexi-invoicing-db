<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

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
        throw new Exception("Unauthorized: Only Admins can reactivate clients", 403);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['clientIds']) || !is_array($data['clientIds']) || count($data['clientIds']) === 0) {
        throw new Exception("Please select at least one client to reactivate.", 400);
    }

    $clientIds = array_map('intval', $data['clientIds']);

    // Start transaction
    $conn->begin_transaction();

    try {
        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));

        /**
         * BUSINESS LOGIC: Check for active financial data in the ENTIRE batch
         */

        // Check for active non-expired quotations
        $quoteCheck = $conn->prepare("
            SELECT client_id FROM quotations 
            WHERE client_id IN ($placeholders) AND status IN ('draft', 'sent', 'accepted') 
            LIMIT 1
        ");
        $quoteCheck->bind_param(str_repeat('i', count($clientIds)), ...$clientIds);
        $quoteCheck->execute();
        
        if ($quoteCheck->get_result()->num_rows > 0) {
            $quoteCheck->close();
            throw new Exception(
                "Cannot reactivate. One or more selected clients have active Quotations. Please reject or convert them first.", 
                409
            );
        }
        $quoteCheck->close();


        /**
         * Soft-delete clients (set is_active = 0)
         * Only target clients that are currently active to avoid redundant logs
         */
        $updateQuery = "UPDATE clients SET is_active = 1 WHERE id IN ($placeholders) AND is_active = 0";
        $updateStmt = $conn->prepare($updateQuery);

        if (!$updateStmt) {
            throw new Exception("Database error: Failed to prepare statement", 500);
        }

        $updateStmt->bind_param(str_repeat('i', count($clientIds)), ...$clientIds);

        if (!$updateStmt->execute()) {
            throw new Exception("Failed to reactivate clients: " . $updateStmt->error, 500);
        }

        if ($updateStmt->affected_rows === 0) {
            throw new Exception("Selected clients are already reactivated or do not exist.", 404);
        }

        $updateStmt->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "client.reactivated";
        $modelType   = "Client";
        $modelId     = null; // Bulk action, so we pass IDs in description
        $description = "{$loggedInUserEmail} reactivated client account(s) with ID(s): " . implode(', ', $clientIds);
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
            "message" => "Client account(s) reactivated successfully."
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("reactivate Client Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>