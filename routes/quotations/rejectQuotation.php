<?php
// routes/quotations/rejectQuotation.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /quotations/{id}/reject
 * Mark quotation as 'rejected' (changes status from sent to rejected).
 * Optional: Store rejection reason in the log.
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

    // Only Admin and Sales can reject quotations
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can reject quotations.", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Quotation ID
    // -------------------------------------------------------
    $quotationId = null;
    
    $reason = null;
    if (!$quotationId) {
        $data = json_decode(file_get_contents("php://input"), true);
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            $quotationId = (int)$_GET['id'];
        }
        if (isset($data['reason']) && !empty(trim($data['reason']))) {
            $reason = trim($data['reason']);
        }
    } else {
        // ID from URL, check body for reason
        $data = json_decode(file_get_contents("php://input"), true);
        if ($data && isset($data['reason']) && !empty(trim($data['reason']))) {
            $reason = trim($data['reason']);
        }
    }

    if (!$quotationId) {
        throw new Exception("A valid Quotation ID is required.", 400);
    }

    // -------------------------------------------------------
    // 2. Verify Quotation Exists & Is Sent
    // -------------------------------------------------------
    $quotationCheck = $conn->prepare("
        SELECT q.id, q.quotation_number, q.status, q.created_by, q.issue_date, q.expiry_date,
               q.total_amount, q.currency,
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

    // Only sent can be rejected
    if ($quotation['status'] !== 'sent') {
        throw new Exception("Only sent quotations can be rejected. Current status: {$quotation['status']}.", 409);
    }

    // Sales can only reject their own
    if ($loggedInUserRole === 'sales' && (int)$quotation['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only reject your own quotations.", 403);
    }

    // -------------------------------------------------------
    // 3. Execute Status Change
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $updateStmt = $conn->prepare("
            UPDATE quotations 
            SET status = 'rejected' 
            WHERE id = ? AND status = 'sent'
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
        // 4. Log Activity (include reason if provided)
        // -------------------------------------------------------
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "quotation.rejected";
        $modelType   = "Quotation";
        
        $reasonText = $reason ? " Reason: {$reason}" : "";
        $description = "{$loggedInUserEmail} rejected quotation {$quotation['quotation_number']} for '{$quotation['client_name']}'.{$reasonText}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $quotationId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log quotation rejection: " . $logStmt->error);
        }
        $logStmt->close();

        $conn->commit();

        // -------------------------------------------------------
        // 5. Return Response
        // -------------------------------------------------------
        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Quotation rejected successfully.",
            "data"    => [
                "id"               => $quotationId,
                "quotation_number" => $quotation['quotation_number'],
                "previous_status"  => "sent",
                "new_status"       => "rejected",
                "client_name"      => $quotation['client_name'],
                "reason_recorded"  => $reason ? true : false
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Reject Quotation Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>