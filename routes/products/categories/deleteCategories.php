<?php
// routes/categories/deleteCategory.php
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

/**
 * DELETE /categories/{id}
 * Delete a product category (only if no products linked).
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

    // Only Admin can delete categories
    if ($loggedInUserRole !== 'super_admin') {
        throw new Exception("Unauthorized: Only the Super Admin can delete product categories.", 403);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['categoryIds']) || !is_array($data['categoryIds']) || count($data['categoryIds']) === 0) {
        throw new Exception("Please select at least one category to delete.", 400);
    }

    $categoryIds = array_map('intval', $data['categoryIds']);

    // Start transaction
    $conn->begin_transaction();

    try {
        // -------------------------------------------------------
        // 1. Fetch Category Details for Logging (Before Delete)
        // -------------------------------------------------------
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        
        $fetchQuery = "
            SELECT pc.id, pc.name, 
                   (SELECT COUNT(*) FROM products p WHERE p.category_id = pc.id) AS product_count
            FROM product_categories pc
            WHERE pc.id IN ($placeholders)
        ";
        
        $fetchStmt = $conn->prepare($fetchQuery);
        if (!$fetchStmt) {
            throw new Exception("Database error: Failed to prepare fetch statement.", 500);
        }

        $fetchStmt->bind_param(str_repeat('i', count($categoryIds)), ...$categoryIds);
        $fetchStmt->execute();
        $categoriesToDelete = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fetchStmt->close();

        if (empty($categoriesToDelete)) {
            throw new Exception("No matching categories found to delete.", 404);
        }

        // -------------------------------------------------------
        // 2. Check for linked products (pre-delete validation)
        // -------------------------------------------------------
        foreach ($categoriesToDelete as $category) {
            if ((int)$category['product_count'] > 0) {
                throw new Exception(
                    "Cannot delete category '{$category['name']}'. It has {$category['product_count']} product(s) linked to it. Please reassign or delete the products first.", 
                    409
                );
            }
        }

        // -------------------------------------------------------
        // 3. Delete Categories
        // -------------------------------------------------------
        $deleteQuery = "DELETE FROM product_categories WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error: Failed to prepare delete statement.", 500);
        }

        $deleteStmt->bind_param(str_repeat('i', count($categoryIds)), ...$categoryIds);

        if (!$deleteStmt->execute()) {
            // Catch Foreign Key Constraint violations (Error 1451)
            if ($conn->errno == 1451) {
                throw new Exception("Cannot delete category. It has linked products that must be removed first.", 409);
            }
            throw new Exception("Failed to delete categories: " . $deleteStmt->error, 500);
        }

        $deletedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        // -------------------------------------------------------
        // 4. Build Log Description
        // -------------------------------------------------------
        $deletedNames = [];
        foreach ($categoriesToDelete as $category) {
            $deletedNames[] = "'{$category['name']}'";
        }
        $nameList = implode(', ', $deletedNames);

        // -------------------------------------------------------
        // 5. Log Action
        // -------------------------------------------------------
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "category.deleted";
        $modelType   = "ProductCategory";
        $modelId     = null; // Bulk action
        $description = "{$loggedInUserEmail} deleted {$deletedCount} product categor(ies): {$nameList}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log category deletion: " . $logStmt->error);
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
            "message" => "{$deletedCount} categor(ies) permanently deleted.",
            "meta"    => [
                "deleted_count" => $deletedCount
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete Categories Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>