<?php
// routes/proformas/expireProformas.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /proformas/expire
 * Mark all 'sent' proforma invoices past their expiry_date as 'expired'.
 * Intended to be called by a daily cron job OR manually by an Admin.
 * Roles allowed: Admin only
 *
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

    if ($loggedInUserRole !== 'admin') {
        throw new Exception("Unauthorized: Only Admins can run proforma expiration.", 403);
    }

    $today = date('Y-m-d');

    // -------------------------------------------------------
    // 1. Find all sent proformas past their expiry date
    // -------------------------------------------------------
    $fetchStmt = $conn->prepare("
        SELECT p.id, p.proforma_number, p.expiry_date,
               c.company_name AS client_name
        FROM proforma_invoices p
        JOIN clients c ON c.id = p.client_id
        WHERE p.status = 'sent' AND p.expiry_date < ?
    ");
    $fetchStmt->bind_param("s", $today);
    $fetchStmt->execute();
    $expiredProformas = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fetchStmt->close();

    if (empty($expiredProformas)) {
        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "No proforma invoices to expire.",
            "data"    => ["expired_count" => 0, "run_date" => $today]
        ]);
        exit;
    }

    // -------------------------------------------------------
    // 2. Bulk update to 'expired'
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $updateStmt = $conn->prepare("
            UPDATE proforma_invoices
            SET status = 'expired'
            WHERE status = 'sent' AND expiry_date < ?
        ");
        $updateStmt->bind_param("s", $today);
        if (!$updateStmt->execute()) throw new Exception("Failed to expire proformas: " . $updateStmt->error, 500);
        $expiredCount = $updateStmt->affected_rows;
        $updateStmt->close();

        // Activity log
        $expiredNumbers = array_column($expiredProformas, 'proforma_number');
        $numbersList    = implode(', ', $expiredNumbers);

        $logStmt     = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "proforma.expired_bulk";
        $modelType   = "ProformaInvoice";
        $modelId     = null;
        $description = "{$loggedInUserEmail} ran bulk expiration. {$expiredCount} proforma(s) expired: {$numbersList}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "{$expiredCount} proforma invoice(s) marked as expired.",
            "data"    => [
                "expired_count"    => $expiredCount,
                "run_date"         => $today,
                "expired_proformas"=> $expiredProformas
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Expire Proformas Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
