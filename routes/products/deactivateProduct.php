<?php
// routes/products/deleteProduct.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * DELETE /products
 * Soft-delete products (sets is_active = 0).
 * Roles allowed: Admin
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin can delete products
    if ($loggedInUserRole !== 'admin') {
        throw new Exception("Unauthorized: Only Admins can delete products.", 403);
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
            SELECT p.id, p.name, p.sku, p.is_active,
                   (SELECT COUNT(*) FROM quotation_items qi WHERE qi.product_id = p.id) AS quotation_count,
                   (SELECT COUNT(*) FROM proforma_items pi WHERE pi.product_id = p.id) AS proforma_count,
                   (SELECT COUNT(*) FROM invoice_items ii WHERE ii.product_id = p.id) AS invoice_count
            FROM products p
            WHERE p.id IN ($placeholders)
        ";
        
        $fetchStmt = $conn->prepare($fetchQuery);
        if (!$fetchStmt) {
            throw new Exception("Database error: Failed to prepare fetch statement.", 500);
        }

        $fetchStmt->bind_param(str_repeat('i', count($productIds)), ...$productIds);
        $fetchStmt->execute();
        $productsToDelete = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fetchStmt->close();

        if (empty($productsToDelete)) {
            throw new Exception("No matching products found.", 404);
        }

        // -------------------------------------------------------
        // 2. Check if all products are already inactive
        // -------------------------------------------------------
        $alreadyInactive = true;
        foreach ($productsToDelete as $product) {
            if ((int)$product['is_active'] === 1) {
                $alreadyInactive = false;
                break;
            }
        }

        if ($alreadyInactive) {
            throw new Exception("Selected products are already deactivated.", 404);
        }

        // -------------------------------------------------------
        // 3. Soft-delete products (set is_active = 0)
        // Only target products that are currently active
        // -------------------------------------------------------
        $updateQuery = "UPDATE products SET is_active = 0 WHERE id IN ($placeholders) AND is_active = 1";
        $updateStmt = $conn->prepare($updateQuery);

        if (!$updateStmt) {
            throw new Exception("Database error: Failed to prepare statement.", 500);
        }

        $updateStmt->bind_param(str_repeat('i', count($productIds)), ...$productIds);

        if (!$updateStmt->execute()) {
            throw new Exception("Failed to deactivate products: " . $updateStmt->error, 500);
        }

        $deactivatedCount = $updateStmt->affected_rows;
        $updateStmt->close();

        if ($deactivatedCount === 0) {
            throw new Exception("No products were deactivated.", 404);
        }

        // -------------------------------------------------------
        // 4. Build Log Description
        // -------------------------------------------------------
        $deletedNames = [];
        foreach ($productsToDelete as $product) {
            if ((int)$product['is_active'] === 1) {
                $deletedNames[] = "'{$product['name']}' (SKU: {$product['sku']})";
            }
        }
        $nameList = implode(', ', $deletedNames);

        // -------------------------------------------------------
        // 5. Log Action
        // -------------------------------------------------------
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "product.deactivated";
        $modelType   = "Product";
        $modelId     = null; // Bulk action
        $description = "{$loggedInUserEmail} deactivated {$deactivatedCount} product(s): {$nameList}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log product deactivation: " . $logStmt->error);
        }

        $logStmt->close();

        // Commit transaction
        $conn->commit();

        // -------------------------------------------------------
        // 6. Return Response
        // -------------------------------------------------------
        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "{$deactivatedCount} product(s) deactivated successfully.",
            "meta"    => [
                "deactivated_count" => $deactivatedCount
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete Products Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>