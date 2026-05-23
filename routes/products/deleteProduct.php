<?php
// routes/products/deleteProductPermanent.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * DELETE /products/permanent
 * Permanently delete products from the database.
 * 
 * NOTE: Because line item tables use ON DELETE SET NULL, deleting a product
 * will set product_id to NULL on related quotation_items, proforma_items, 
 * and invoice_items. This is safe because descriptions are snapshotted.
 * 
 * Roles allowed: Admin
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

    // Only Admin allowed to permanently delete products
    if ($loggedInUserRole !== 'super_admin') {
        throw new Exception("Unauthorized: Only the Super Admin can access this resource", 403);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['productIds']) || !is_array($data['productIds']) || count($data['productIds']) === 0) {
        throw new Exception("Please select at least one product to delete.", 400);
    }

    $productIds = array_map('intval', $data['productIds']);

    // Start transaction
    $conn->begin_transaction();

    try {
        // -------------------------------------------------------
        // 1. Fetch Product Details for Logging (Before Delete)
        // -------------------------------------------------------
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        
        $fetchQuery = "
            SELECT 
                p.id, 
                p.name, 
                p.sku, 
                p.is_active,
                pc.name AS category_name,
                (SELECT COUNT(*) FROM quotation_items qi WHERE qi.product_id = p.id) AS quotation_ref_count,
                (SELECT COUNT(*) FROM proforma_items pi WHERE pi.product_id = p.id) AS proforma_ref_count,
                (SELECT COUNT(*) FROM invoice_items ii WHERE ii.product_id = p.id) AS invoice_ref_count
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE p.id IN ($placeholders)
        ";
        
        $fetchStmt = $conn->prepare($fetchQuery);
        if (!$fetchStmt) {
            throw new Exception("Database error: Failed to prepare fetch statement", 500);
        }

        $fetchStmt->bind_param(str_repeat('i', count($productIds)), ...$productIds);
        $fetchStmt->execute();
        $productsToDelete = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fetchStmt->close();

        if (empty($productsToDelete)) {
            throw new Exception("No matching products found to delete.", 404);
        }

        // -------------------------------------------------------
        // 2. Build Reference Summary (For Logging & Response)
        // -------------------------------------------------------
        $deletedNames = [];
        $totalQuotationRefs = 0;
        $totalProformaRefs = 0;
        $totalInvoiceRefs = 0;
        $hasActiveInvoiceRefs = false;

        foreach ($productsToDelete as $product) {
            $deletedNames[] = "'{$product['name']}' (SKU: {$product['sku']})";
            $totalQuotationRefs += (int)$product['quotation_ref_count'];
            $totalProformaRefs += (int)$product['proforma_ref_count'];
            $totalInvoiceRefs += (int)$product['invoice_ref_count'];
        }

        $nameList = implode(', ', $deletedNames);

        // -------------------------------------------------------
        // 3. Check for references in ACTIVE invoices (sent, partial, overdue, paid)
        // This is a business warning — deletion is still allowed because
        // descriptions are snapshotted and product_id will just become NULL
        // -------------------------------------------------------
        $activeInvoiceCheck = $conn->prepare("
            SELECT COUNT(*) AS active_count
            FROM invoice_items ii
            JOIN invoices i ON i.id = ii.invoice_id
            WHERE ii.product_id IN ($placeholders)
              AND i.status IN ('sent', 'partial', 'overdue', 'paid')
        ");
        
        $activeInvoiceCheck->bind_param(str_repeat('i', count($productIds)), ...$productIds);
        $activeInvoiceCheck->execute();
        $activeInvoiceCount = (int)$activeInvoiceCheck->get_result()->fetch_assoc()['active_count'];
        $activeInvoiceCheck->close();

        if ($activeInvoiceCount > 0) {
            $hasActiveInvoiceRefs = true;
            // We don't block the deletion, but we log this prominently
            // The frontend can choose to show a confirmation dialog
        }

        // -------------------------------------------------------
        // 4. Hard Delete Products
        // -------------------------------------------------------
        $deleteQuery = "DELETE FROM products WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error: Failed to prepare delete statement", 500);
        }

        $deleteStmt->bind_param(str_repeat('i', count($productIds)), ...$productIds);

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete products: " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No products were deleted.", 404);
        }

        $deletedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        // -------------------------------------------------------
        // 5. Log Action
        // -------------------------------------------------------
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "product.deleted_permanently";
        $modelType   = "Product";
        $modelId     = null; // Bulk action
        
        // Build detailed description
        $refInfo = [];
        if ($totalQuotationRefs > 0) {
            $refInfo[] = "{$totalQuotationRefs} quotation line item(s) unlinked";
        }
        if ($totalProformaRefs > 0) {
            $refInfo[] = "{$totalProformaRefs} proforma line item(s) unlinked";
        }
        if ($totalInvoiceRefs > 0) {
            $refInfo[] = "{$totalInvoiceRefs} invoice line item(s) unlinked";
        }
        
        $refString = !empty($refInfo) ? " Note: " . implode(', ', $refInfo) . "." : "";
        
        $description = "{$loggedInUserEmail} permanently deleted {$deletedCount} product(s): {$nameList}.{$refString}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log product permanent delete: " . $logStmt->error);
        }

        $logStmt->close();

        // Commit transaction
        $conn->commit();

        // -------------------------------------------------------
        // 6. Return Response
        // -------------------------------------------------------
        $responseMessage = "{$deletedCount} product(s) permanently deleted.";
        
        // Include warning about active invoice references
        $warnings = [];
        if ($hasActiveInvoiceRefs) {
            $warnings[] = "{$activeInvoiceCount} line item(s) in active invoices are now unlinked from their products (descriptions are preserved).";
        }
        if ($totalInvoiceRefs > 0 && !$hasActiveInvoiceRefs) {
            $warnings[] = "{$totalInvoiceRefs} historical invoice line item(s) are now unlinked (descriptions are preserved).";
        }

        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => $responseMessage,
            "meta"    => [
                "deleted_count"        => $deletedCount,
                "quotation_refs_cleared" => $totalQuotationRefs,
                "proforma_refs_cleared"  => $totalProformaRefs,
                "invoice_refs_cleared"   => $totalInvoiceRefs,
                "active_invoice_refs"    => $activeInvoiceCount
            ],
            "warnings" => $warnings
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete Products Permanent Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>