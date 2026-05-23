<?php
// routes/invoices/deleteInvoice.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * DELETE /invoices/delete
 * Hard-delete one or more DRAFT invoices.
 * Only draft invoices can be deleted. Admin only.
 *
 * Sample payload:
 * {
 *   "invoiceIds": [4, 7, 12]
 * }
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Route not found", 400);
    }

    $userData          = authenticateUser();
    $loggedInUserId    = (int)$userData['id'];
    $loggedInUserRole  = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Admin only — invoices are financial records, Sales cannot delete
    if ($loggedInUserRole !== 'super_admin') {
        throw new Exception("Unauthorized: Only the Super Admin can delete invoices.", 403);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['invoiceIds']) || !is_array($data['invoiceIds']) || count($data['invoiceIds']) === 0) {
        throw new Exception("Please select at least one invoice to delete.", 400);
    }

    $invoiceIds = array_map('intval', $data['invoiceIds']);

    $conn->begin_transaction();

    try {
        // -------------------------------------------------------
        // 1. Fetch targets
        // -------------------------------------------------------
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));

        $fetchStmt = $conn->prepare("
            SELECT i.id, i.invoice_number, i.status, i.stock_deducted,
                   c.company_name AS client_name
            FROM invoices i
            JOIN clients c ON c.id = i.client_id
            WHERE i.id IN ($placeholders)
        ");
        $fetchStmt->bind_param(str_repeat('i', count($invoiceIds)), ...$invoiceIds);
        $fetchStmt->execute();
        $rows = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fetchStmt->close();

        if (empty($rows)) throw new Exception("No matching invoices found.", 404);

        // -------------------------------------------------------
        // 2. Validate: only draft, stock must not have been deducted
        // -------------------------------------------------------
        $validIds     = [];
        $deletedNames = [];
        $skippedIds   = [];

        foreach ($rows as $row) {
            if ($row['status'] !== 'draft') {
                $skippedIds[] = $row['id'];
                continue;
            }
            if ((int)$row['stock_deducted'] === 1) {
                // Shouldn't happen (draft + stock_deducted=1 is an inconsistent state)
                // but guard it anyway
                $skippedIds[] = $row['id'];
                continue;
            }
            $validIds[]     = $row['id'];
            $deletedNames[] = "'{$row['invoice_number']}' ({$row['client_name']})";
        }

        if (empty($validIds)) {
            throw new Exception("Only draft invoices with no stock deductions can be deleted. All selected records failed this check.", 409);
        }

        // -------------------------------------------------------
        // 3. Delete (invoice_items cascade via FK)
        // -------------------------------------------------------
        $delPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $deleteStmt      = $conn->prepare("DELETE FROM invoices WHERE id IN ($delPlaceholders)");
        if (!$deleteStmt) throw new Exception("Failed to prepare delete: " . $conn->error, 500);

        $deleteStmt->bind_param(str_repeat('i', count($validIds)), ...$validIds);
        if (!$deleteStmt->execute()) throw new Exception("Failed to delete invoices: " . $deleteStmt->error, 500);

        $deletedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        // Activity log
        $logStmt     = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "invoice.deleted";
        $modelType   = "Invoice";
        $modelId     = null;
        $nameList    = implode(', ', $deletedNames);
        $description = "{$loggedInUserEmail} deleted {$deletedCount} invoice(s): {$nameList}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        $response = [
            "status"  => "success",
            "message" => "{$deletedCount} invoice(s) deleted successfully.",
            "meta"    => ["deleted_count" => $deletedCount]
        ];

        if (!empty($skippedIds)) {
            $response["warnings"] = [
                count($skippedIds) . " record(s) were skipped (not in draft status or stock already deducted)."
            ];
        }

        http_response_code(200);
        echo json_encode($response);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete Invoice Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
