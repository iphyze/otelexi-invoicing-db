<?php
// routes/quotations/reopenQuotation.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /quotations/{id}/reopen
 * Revert a 'rejected' quotation back to 'draft' for editing.
 * Only rejected quotations can be reopened.
 * Roles allowed: Admin, Sales (own only)
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin and Sales can reopen quotations
    if (!in_array($loggedInUserRole, ['admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can reopen quotations.", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Quotation ID
    // -------------------------------------------------------
    $quotationId = null;
    
    if (!$quotationId) {
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            $quotationId = (int)$_GET['id'];
        }
    }

    if (!$quotationId) {
        throw new Exception("A valid Quotation ID is required.", 400);
    }

    // -------------------------------------------------------
    // 2. Verify Quotation Exists & Is Rejected
    // -------------------------------------------------------
    $quotationCheck = $conn->prepare("
        SELECT q.id, q.quotation_number, q.status, q.created_by,
               c.company_name AS client_name
        FROM quotations q
        JOIN clients c ON c.id = q.client_id
        WHERE q.id = ? 
        LIMIT 1
    ");
    $quotationCheck->bind_param("i", $quotationId);
    $quotationCheck->execute();
    $quotationResult = $quotationCheck->get_result();

    if ($quotationResult->num_rows === 0) {
        throw new Exception("Quotation not found.", 404);
    }

    $quotation = $quotationResult->fetch_assoc();
    $quotationCheck->close();

    // Only rejected can be reopened
    if ($quotation['status'] !== 'rejected') {
        throw new Exception("Only rejected quotations can be reopened. Current status: {$quotation['status']}.", 409);
    }

    // Sales can only reopen their own
    if ($loggedInUserRole === 'sales' && (int)$quotation['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only reopen your own quotations.", 403);
    }

    // -------------------------------------------------------
    // 3. Execute Status Change
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $updateStmt = $conn->prepare("
            UPDATE quotations 
            SET status = 'draft' 
            WHERE id = ? AND status = 'rejected'
        ");
        $updateStmt->bind_param("i", $quotationId);

        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update quotation status: " . $updateStmt->error, 500);
        }

        if ($updateStmt->affected_rows === 0) {
            throw new Exception("Quotation status was not updated. It may have been modified by another user.", 409);
        }
        $updateStmt->close();

        // -------------------------------------------------------
        // 4. Log Activity
        // -------------------------------------------------------
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "quotation.reopened";
        $modelType   = "Quotation";
        $description = "{$loggedInUserEmail} reopened quotation {$quotation['quotation_number']} for '{$quotation['client_name']}' back to draft for editing.";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $quotationId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log quotation reopen: " . $logStmt->error);
        }
        $logStmt->close();

        $conn->commit();

        // -------------------------------------------------------
        // 5. Return Response
        // -------------------------------------------------------
        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Quotation reopened successfully. You can now edit it.",
            "data"    => [
                "id"               => $quotationId,
                "quotation_number" => $quotation['quotation_number'],
                "previous_status"  => "rejected",
                "new_status"       => "draft",
                "client_name"      => $quotation['client_name']
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Reopen Quotation Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>