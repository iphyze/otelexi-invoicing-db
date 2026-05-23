<?php
// routes/proformas/approveProforma.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /proforma/{id}/approve
 * Mark a sent proforma invoice as 'approved' (client has approved it).
 * Only 'sent' proformas can be approved.
 * Once approved it can be converted to a final invoice.
 * Roles allowed: Admin, Sales (own only)
 *
 * Query param: ?id=5
 * No request body needed.
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    $userData          = authenticateUser();
    $loggedInUserId    = (int)$userData['id'];
    $loggedInUserRole  = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can approve proforma invoices.", 403);
    }

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Proforma ID is required.", 400);
    }
    $proformaId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 1. Verify proforma exists and is sent
    // -------------------------------------------------------
    $checkStmt = $conn->prepare("
        SELECT p.id, p.proforma_number, p.status, p.created_by,
               p.expiry_date, p.total_amount, p.currency,
               c.company_name AS client_name
        FROM proforma_invoices p
        JOIN clients c ON c.id = p.client_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $checkStmt->bind_param("i", $proformaId);
    $checkStmt->execute();
    $proforma = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$proforma) {
        throw new Exception("Proforma invoice not found.", 404);
    }
    if ($proforma['status'] !== 'sent') {
        throw new Exception("Only sent proforma invoices can be approved. Current status: {$proforma['status']}.", 409);
    }
    // Enforce expiry check
    if ($proforma['expiry_date'] < date('Y-m-d')) {
        throw new Exception("This proforma has expired (expired on {$proforma['expiry_date']}). Please issue a new proforma.", 409);
    }
    if ($loggedInUserRole === 'sales' && (int)$proforma['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only approve your own proforma invoices.", 403);
    }

    // -------------------------------------------------------
    // 2. Update status
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $updateStmt = $conn->prepare("UPDATE proforma_invoices SET status = 'approved' WHERE id = ? AND status = 'sent'");
        $updateStmt->bind_param("i", $proformaId);
        if (!$updateStmt->execute()) throw new Exception("Failed to update proforma status: " . $updateStmt->error, 500);
        if ($updateStmt->affected_rows === 0) throw new Exception("Proforma status was not updated. It may have been modified by another user.", 409);
        $updateStmt->close();

        $logStmt     = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "proforma.approved";
        $modelType   = "ProformaInvoice";
        $description = "{$loggedInUserEmail} marked proforma {$proforma['proforma_number']} as approved for '{$proforma['client_name']}'. Amount: {$proforma['currency']} {$proforma['total_amount']}.";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $proformaId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Proforma invoice approved. You can now convert it to a final invoice.",
            "data"    => [
                "id"              => $proformaId,
                "proforma_number" => $proforma['proforma_number'],
                "client_name"     => $proforma['client_name'],
                "previous_status" => "sent",
                "new_status"      => "approved",
                "total_amount"    => (float)$proforma['total_amount'],
                "currency"        => $proforma['currency'],
                "next_step"       => "/api/v1/proforma/{$proformaId}/convert-invoice"
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Approve Proforma Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
