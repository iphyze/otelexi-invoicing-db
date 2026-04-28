<?php
// routes/quotations/acceptQuotation.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /quotations/{id}/accept
 * Mark quotation as 'accepted' (changes status from sent to accepted).
 * Optionally converts to proforma or invoice (handled by separate endpoints).
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

    // Only Admin and Sales can accept quotations
    if (!in_array($loggedInUserRole, ['admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can accept quotations.", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Quotation ID
    // -------------------------------------------------------
    $quotationId = null;
    
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

    // Only sent can be accepted
    if ($quotation['status'] !== 'sent') {
        throw new Exception("Only sent quotations can be accepted. Current status: {$quotation['status']}.", 409);
    }

    // Check if expired
    if (strtotime($quotation['expiry_date']) < strtotime(date('Y-m-d'))) {
        throw new Exception("This quotation has expired (expired on {$quotation['expiry_date']}). Please create a new quotation.", 409);
    }

    // Sales can only accept their own
    if ($loggedInUserRole === 'sales' && (int)$quotation['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only accept your own quotations.", 403);
    }

    // -------------------------------------------------------
    // 3. Execute Status Change
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $updateStmt = $conn->prepare("
            UPDATE quotations 
            SET status = 'accepted' 
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
        // 4. Log Activity
        // -------------------------------------------------------
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "quotation.accepted";
        $modelType   = "Quotation";
        $description = "{$loggedInUserEmail} accepted quotation {$quotation['quotation_number']} for '{$quotation['client_name']}'. Amount: {$quotation['currency']} {$quotation['total_amount']}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $quotationId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log quotation acceptance: " . $logStmt->error);
        }
        $logStmt->close();

        $conn->commit();

        // -------------------------------------------------------
        // 5. Return Response
        // -------------------------------------------------------
        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Quotation accepted successfully. You can now convert it to a proforma invoice or directly to an invoice.",
            "data"    => [
                "id"               => $quotationId,
                "quotation_number" => $quotation['quotation_number'],
                "previous_status"  => "sent",
                "new_status"       => "accepted",
                "client_name"      => $quotation['client_name'],
                "total_amount"     => (float)$quotation['total_amount'],
                "currency"         => $quotation['currency'],
                "next_steps"       => [
                    "convert_to_proforma" => "/api/v1/quotations/{$quotationId}/convert-proforma",
                    "convert_to_invoice"  => "/api/v1/quotations/{$quotationId}/convert-invoice"
                ]
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Accept Quotation Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>