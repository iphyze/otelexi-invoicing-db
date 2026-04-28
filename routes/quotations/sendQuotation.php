<?php
// routes/quotations/sendQuotation.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /quotations/{id}/send
 * Mark quotation as 'sent' (changes status from draft to sent).
 * Expiry date is already set on creation (issue_date + 14 days).
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

    // Only Admin and Sales can send quotations
    if (!in_array($loggedInUserRole, ['admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can send quotations.", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Quotation ID
    // -------------------------------------------------------
    // ID can come from URL path or JSON body
    $quotationId = null;
    
    // Fallback to JSON body
    // if (!$quotationId) {
    //     $data = json_decode(file_get_contents("php://input"), true);
    //     if (isset($data['id']) && is_numeric($data['id'])) {
    //         $quotationId = (int)$data['id'];
    //     }
    // }

    if (!$quotationId) {
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            $quotationId = (int)$_GET['id'];
        }
    }

    if (!$quotationId) {
        throw new Exception("A valid Quotation ID is required.", 400);
    }

    // -------------------------------------------------------
    // 2. Verify Quotation Exists & Is Draft
    // -------------------------------------------------------
    $quotationCheck = $conn->prepare("
        SELECT q.id, q.quotation_number, q.status, q.created_by, q.issue_date, q.expiry_date,
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

    // Only draft can be sent
    if ($quotation['status'] !== 'draft') {
        throw new Exception("Only draft quotations can be sent. Current status: {$quotation['status']}.", 409);
    }

    // Sales can only send their own
    if ($loggedInUserRole === 'sales' && (int)$quotation['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only send your own quotations.", 403);
    }

    // -------------------------------------------------------
    // 3. Execute Status Change
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $updateStmt = $conn->prepare("
            UPDATE quotations 
            SET status = 'sent' 
            WHERE id = ? AND status = 'draft'
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

        $action      = "quotation.sent";
        $modelType   = "Quotation";
        $description = "{$loggedInUserEmail} sent quotation {$quotation['quotation_number']} to '{$quotation['client_name']}'. Valid until {$quotation['expiry_date']}.";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $quotationId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log quotation send: " . $logStmt->error);
        }
        $logStmt->close();

        $conn->commit();

        // -------------------------------------------------------
        // 5. Return Response
        // -------------------------------------------------------
        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Quotation sent successfully.",
            "data"    => [
                "id"               => $quotationId,
                "quotation_number" => $quotation['quotation_number'],
                "previous_status"  => "draft",
                "new_status"       => "sent",
                "issue_date"       => $quotation['issue_date'],
                "expiry_date"      => $quotation['expiry_date']
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Send Quotation Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>