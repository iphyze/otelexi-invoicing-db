<?php
// routes/categories/updateCategory.php
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

/**
 * PUT /categories/{id}
 * Update a specific product category.
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

    // Only Admin can update categories
    if (!in_array($loggedInUserRole, ['super_admin', 'admin'], true)) {
        throw new Exception("Unauthorized: Only Super Admins or Admins can update product categories.", 403);
    }

    // Read JSON payload
    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    // -------------------------------------------------------
    // 1. Validate Category ID
    // -------------------------------------------------------
    // if (!isset($data['id']) || !is_numeric($data['id'])) {
    //     throw new Exception("A valid Category ID is required.", 400);
    // }
    // $categoryId = (int)$data['id'];
    // unset($data['id']); // Remove ID from data array

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Category ID is required.", 400);
    }
    
    $categoryId = (int)$_GET['id'];
    unset($_GET['id']);

    // -------------------------------------------------------
    // 2. Verify Category Exists
    // -------------------------------------------------------
    $categoryCheck = $conn->prepare("SELECT id, name FROM product_categories WHERE id = ? LIMIT 1");
    $categoryCheck->bind_param("i", $categoryId);
    $categoryCheck->execute();
    $categoryResult = $categoryCheck->get_result();

    if ($categoryResult->num_rows === 0) {
        throw new Exception("Category not found.", 404);
    }

    $existingCategory = $categoryResult->fetch_assoc();
    $oldName = $existingCategory['name'];
    $categoryCheck->close();

    // -------------------------------------------------------
    // 3. Whitelist & Validate Fields
    // -------------------------------------------------------
    $allowedFields = ['name', 'description'];
    $requiredIfProvided = ['name'];

    $updateFields = [];
    $params = [];
    $types = "";

    foreach ($data as $key => $value) {
        if (!in_array($key, $allowedFields)) {
            continue; // Ignore unknown fields
        }

        // Handle string trimming
        if (is_string($value)) {
            $value = trim($value);
        }

        // Prevent emptying required fields
        if (in_array($key, $requiredIfProvided) && $value === '') {
            throw new Exception("The field '{$key}' cannot be empty.", 422);
        }

        // Validate name length
        if ($key === 'name' && strlen($value) > 100) {
            throw new Exception("Category name cannot exceed 100 characters.", 422);
        }

        $updateFields[] = "`{$key}` = ?";
        $params[] = $value;
        $types .= "s";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    // -------------------------------------------------------
    // 4. Check for duplicate name (excluding current category)
    // -------------------------------------------------------
    if (in_array('name', array_keys($data))) {
        $nameCheck = $conn->prepare("SELECT id FROM product_categories WHERE name = ? AND id != ? LIMIT 1");
        $nameCheck->bind_param("si", $data['name'], $categoryId);
        $nameCheck->execute();
        
        if ($nameCheck->get_result()->num_rows > 0) {
            throw new Exception("Another category with this name already exists.", 409);
        }
        $nameCheck->close();
    }

    // -------------------------------------------------------
    // 5. Execute Update
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $sql = "UPDATE product_categories SET " . implode(", ", $updateFields) . " WHERE id = ?";
        
        $updateStmt = $conn->prepare($sql);
        if (!$updateStmt) {
            error_log("DB Prepare Error (Update Category): " . $conn->error);
            throw new Exception("Failed to prepare category update.", 500);
        }

        // Append the Category ID to parameters for WHERE clause
        $params[] = $categoryId;
        $types .= "i";

        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            if ($updateStmt->errno === 1062) {
                throw new Exception("A category with this name already exists.", 409);
            }
            error_log("DB Execute Error (Update Category): " . $updateStmt->error);
            throw new Exception("Failed to update category in the database.", 500);
        }

        if ($updateStmt->affected_rows === 0) {
            throw new Exception("No changes were made. The category data might be identical to what was submitted.", 200);
        }
        
        $updateStmt->close();

        // -------------------------------------------------------
        // 6. Log Activity
        // -------------------------------------------------------
        $changes = [];
        if (in_array('name', array_keys($data)) && $data['name'] !== $oldName) {
            $changes[] = "name: '{$oldName}' → '{$data['name']}'";
        }
        if (in_array('description', array_keys($data))) {
            $changes[] = "description updated";
        }

        $changeSummary = implode(', ', $changes);
        $newName = $data['name'] ?? $oldName;

        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "category.updated";
        $modelType   = "ProductCategory";
        $logDescription = "{$loggedInUserEmail} updated category '{$newName}' (ID: {$categoryId}). Changes: {$changeSummary}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $categoryId, $logDescription, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log category update: " . $logStmt->error);
        }
        $logStmt->close();

        // Commit transaction
        $conn->commit();

        // -------------------------------------------------------
        // 7. Fetch & Return Updated Category
        // -------------------------------------------------------
        $fetchStmt = $conn->prepare("
            SELECT pc.id, pc.name, pc.description, pc.created_at,
                   (SELECT COUNT(*) FROM products p WHERE p.category_id = pc.id AND p.is_active = 1) AS active_product_count,
                   (SELECT COUNT(*) FROM products p WHERE p.category_id = pc.id) AS total_product_count
            FROM product_categories pc
            WHERE pc.id = ?
        ");
        $fetchStmt->bind_param("i", $categoryId);
        $fetchStmt->execute();
        $updatedCategory = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Category updated successfully.",
            "data"    => [
                "id"                  => (int)$updatedCategory['id'],
                "name"                => $updatedCategory['name'],
                "description"         => $updatedCategory['description'],
                "active_product_count"=> (int)$updatedCategory['active_product_count'],
                "total_product_count" => (int)$updatedCategory['total_product_count'],
                "created_at"          => $updatedCategory['created_at']
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Update Category Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>