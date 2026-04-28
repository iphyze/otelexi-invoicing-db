<?php
// routes/quotations/expireQuotations.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /quotations/expire
 * Mark all expired 'sent' quotations as 'expired'.
 * Can be called by cron job or manually by Admin.
 * Roles allowed: Admin only
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

    // Only Admin can run expiration
    if ($loggedInUserRole !== 'admin') {
        throw new Exception("Unauthorized: Only Admins can run quotation expiration.", 403);
    }

    $today = date('Y-m-d');

    // -------------------------------------------------------
    // 1. Find all sent quotations past expiry date
    // -------------------------------------------------------
    $fetchStmt = $conn->prepare("
        SELECT id, quotation_number, client_id, expiry_date
        FROM quotations
        WHERE status = 'sent' AND expiry_date < ?
    ");
    $fetchStmt->bind_param("s", $today);
    $fetchStmt->execute();
    $expiredQuotations = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fetchStmt->close();

    if (empty($expiredQuotations)) {
        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "No quotations to expire.",
            "data"    => [
                "expired_count" => 0
            ]
        ]);
        exit;
    }

    // -------------------------------------------------------
    // 2. Bulk update to expired
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $updateStmt = $conn->prepare("
            UPDATE quotations 
            SET status = 'expired' 
            WHERE status = 'sent' AND expiry_date < ?
        ");
        $updateStmt->bind_param("s", $today);

        if (!$updateStmt->execute()) {
            throw new Exception("Failed to expire quotations: " . $updateStmt->error, 500);
        }

        $expiredCount = $updateStmt->affected_rows;
        $updateStmt->close();

        // -------------------------------------------------------
        // 3. Log Activity (single log for bulk operation)
        // -------------------------------------------------------
        $expiredNumbers = [];
        foreach ($expiredQuotations as $q) {
            $expiredNumbers[] = $q['quotation_number'];
        }
        $numbersList = implode(', ', $expiredNumbers);

        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "quotation.expired_bulk";
        $modelType   = "Quotation";
        $modelId     = null;
        $description = "{$loggedInUserEmail} ran bulk expiration. {$expiredCount} quotation(s) expired: {$numbersList}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log bulk expiration: " . $logStmt->error);
        }
        $logStmt->close();

        $conn->commit();

        // -------------------------------------------------------
        // 4. Return Response
        // -------------------------------------------------------
        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "{$expiredCount} quotation(s) expired successfully.",
            "data"    => [
                "expired_count" => $expiredCount,
                "expired_quotations" => $expiredQuotations,
                "run_date"      => $today
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Expire Quotations Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>