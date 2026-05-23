<?php
// routes/proformas/deleteProforma.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * DELETE /proformas/delete
 * Hard-delete one or more DRAFT proforma invoices.
 * Only draft proformas can be deleted.
 * Roles allowed: Admin, Sales (own only)
 *
 * Sample payload:
 * {
 *   "proformaIds": [3, 5, 8]
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

    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can delete proforma invoices.", 403);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['proformaIds']) || !is_array($data['proformaIds']) || count($data['proformaIds']) === 0) {
        throw new Exception("Please select at least one proforma to delete.", 400);
    }

    $proformaIds = array_map('intval', $data['proformaIds']);

    $conn->begin_transaction();

    try {
        // -------------------------------------------------------
        // 1. Fetch target records
        // -------------------------------------------------------
        $placeholders = implode(',', array_fill(0, count($proformaIds), '?'));

        $fetchStmt = $conn->prepare("
            SELECT p.id, p.proforma_number, p.status, p.created_by,
                   c.company_name AS client_name
            FROM proforma_invoices p
            JOIN clients c ON c.id = p.client_id
            WHERE p.id IN ($placeholders)
        ");
        $fetchStmt->bind_param(str_repeat('i', count($proformaIds)), ...$proformaIds);
        $fetchStmt->execute();
        $rows = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fetchStmt->close();

        if (empty($rows)) {
            throw new Exception("No matching proforma invoices found.", 404);
        }

        // -------------------------------------------------------
        // 2. Validate: only draft, only own (for sales)
        // -------------------------------------------------------
        $validIds      = [];
        $deletedNames  = [];
        $skippedIds    = [];

        foreach ($rows as $row) {
            if ($loggedInUserRole === 'sales' && (int)$row['created_by'] !== $loggedInUserId) {
                $skippedIds[] = $row['id'];
                continue;
            }
            if ($row['status'] !== 'draft') {
                $skippedIds[] = $row['id'];
                continue;
            }
            $validIds[]     = $row['id'];
            $deletedNames[] = "'{$row['proforma_number']}' ({$row['client_name']})";
        }

        if (empty($validIds)) {
            throw new Exception("Only draft proforma invoices can be deleted. Selected records are not in draft status or you don't have permission.", 409);
        }

        // -------------------------------------------------------
        // 3. Delete (items cascade via FK)
        // -------------------------------------------------------
        $delPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $deleteStmt      = $conn->prepare("DELETE FROM proforma_invoices WHERE id IN ($delPlaceholders)");
        if (!$deleteStmt) throw new Exception("Failed to prepare delete: " . $conn->error, 500);

        $deleteStmt->bind_param(str_repeat('i', count($validIds)), ...$validIds);
        if (!$deleteStmt->execute()) throw new Exception("Failed to delete proformas: " . $deleteStmt->error, 500);

        $deletedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        // Activity log
        $logStmt     = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "proforma.deleted";
        $modelType   = "ProformaInvoice";
        $modelId     = null;
        $nameList    = implode(', ', $deletedNames);
        $description = "{$loggedInUserEmail} deleted {$deletedCount} proforma invoice(s): {$nameList}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        $response = [
            "status"  => "success",
            "message" => "{$deletedCount} proforma invoice(s) deleted successfully.",
            "meta"    => ["deleted_count" => $deletedCount]
        ];

        if (!empty($skippedIds)) {
            $response["warnings"] = [count($skippedIds) . " record(s) were skipped (not draft or no permission)."];
        }

        http_response_code(200);
        echo json_encode($response);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete Proforma Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
