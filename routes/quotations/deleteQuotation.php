<?php
// routes/quotations/deleteQuotation.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * DELETE /quotations
 * Delete draft quotations (hard delete).
 * Only draft quotations can be deleted.
 * Roles allowed: Admin, Sales (own only)
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin and Sales can delete quotations
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can delete quotations.", 403);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['quotationIds']) || !is_array($data['quotationIds']) || count($data['quotationIds']) === 0) {
        throw new Exception("Please select at least one quotation to delete.", 400);
    }

    $quotationIds = array_map('intval', $data['quotationIds']);

    // Start transaction
    $conn->begin_transaction();

    try {
        // -------------------------------------------------------
        // 1. Fetch Quotation Details & Validate
        // -------------------------------------------------------
        $placeholders = implode(',', array_fill(0, count($quotationIds), '?'));
        
        $fetchQuery = "
            SELECT q.id, q.quotation_number, q.status, q.created_by,
                   c.company_name AS client_name,
                   (SELECT COUNT(*) FROM quotation_items qi WHERE qi.quotation_id = q.id) AS item_count
            FROM quotations q
            JOIN clients c ON c.id = q.client_id
            WHERE q.id IN ($placeholders)
        ";
        
        $fetchStmt = $conn->prepare($fetchQuery);
        if (!$fetchStmt) {
            throw new Exception("Database error: Failed to prepare fetch statement.", 500);
        }

        $fetchStmt->bind_param(str_repeat('i', count($quotationIds)), ...$quotationIds);
        $fetchStmt->execute();
        $quotationsToDelete = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fetchStmt->close();

        if (empty($quotationsToDelete)) {
            throw new Exception("No matching quotations found.", 404);
        }

        // -------------------------------------------------------
        // 2. Validate: Only draft can be deleted
        // -------------------------------------------------------
        $validIds = [];
        $deletedNames = [];
        $invalidStatusIds = [];

        foreach ($quotationsToDelete as $quotation) {
            // Sales can only delete their own
            if ($loggedInUserRole === 'sales' && (int)$quotation['created_by'] !== $loggedInUserId) {
                $invalidStatusIds[] = $quotation['id'];
                continue;
            }

            if ($quotation['status'] !== 'draft') {
                $invalidStatusIds[] = $quotation['id'];
                continue;
            }

            $validIds[] = $quotation['id'];
            $deletedNames[] = "'{$quotation['quotation_number']}' ({$quotation['client_name']})";
        }

        if (empty($validIds)) {
            if (!empty($invalidStatusIds)) {
                throw new Exception("Only draft quotations can be deleted. Selected quotations are not in draft status or you don't have permission.", 409);
            }
            throw new Exception("No valid quotations to delete.", 404);
        }

        // -------------------------------------------------------
        // 3. Delete Quotations (Items will cascade delete)
        // -------------------------------------------------------
        $deletePlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $deleteQuery = "DELETE FROM quotations WHERE id IN ($deletePlaceholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error: Failed to prepare delete statement.", 500);
        }

        $deleteStmt->bind_param(str_repeat('i', count($validIds)), ...$validIds);

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete quotations: " . $deleteStmt->error, 500);
        }

        $deletedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        // -------------------------------------------------------
        // 4. Log Action
        // -------------------------------------------------------
        $nameList = implode(', ', $deletedNames);

        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "quotation.deleted";
        $modelType   = "Quotation";
        $modelId     = null; // Bulk action
        $description = "{$loggedInUserEmail} deleted {$deletedCount} quotation(s): {$nameList}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log quotation deletion: " . $logStmt->error);
        }

        $logStmt->close();

        $conn->commit();

        // -------------------------------------------------------
        // 5. Return Response
        // -------------------------------------------------------
        $response = [
            "status"  => "success",
            "message" => "{$deletedCount} quotation(s) deleted successfully.",
            "meta"    => [
                "deleted_count" => $deletedCount
            ]
        ];

        // Warn about skipped items
        if (!empty($invalidStatusIds)) {
            $response["warnings"] = [
                count($invalidStatusIds) . " quotation(s) were skipped because they are not in draft status or you don't have permission to delete them."
            ];
        }

        http_response_code(200);
        echo json_encode($response);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete Quotations Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>