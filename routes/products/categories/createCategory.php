<?php
// routes/categories/createCategory.php
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

/**
 * POST /categories
 * Create a new product category.
 * Roles allowed: Admin
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

    // Only Admin can create categories
    if (!in_array($loggedInUserRole, ['super_admin', 'admin'], true)) {
        throw new Exception("Unauthorized: Only Super Admins or Admins can create product categories.", 403);
    }

    // Read JSON payload
    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    // -------------------------------------------------------
    // 1. Validation
    // -------------------------------------------------------
    if (!isset($data['name']) || empty(trim($data['name']))) {
        throw new Exception("The field 'name' is required.", 422);
    }

    $name = trim($data['name']);
    $description = isset($data['description']) ? trim($data['description']) : null;

    // Validate name length
    if (strlen($name) > 100) {
        throw new Exception("Category name cannot exceed 100 characters.", 422);
    }

    // -------------------------------------------------------
    // 2. FAST-FAIL: Check for duplicate name
    // -------------------------------------------------------
    $nameCheck = $conn->prepare("SELECT id FROM product_categories WHERE name = ? LIMIT 1");
    $nameCheck->bind_param("s", $name);
    $nameCheck->execute();
    
    if ($nameCheck->get_result()->num_rows > 0) {
        throw new Exception("A category with this name already exists.", 409);
    }
    $nameCheck->close();

    // -------------------------------------------------------
    // 3. Insert New Category
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $query = "INSERT INTO product_categories (name, description) VALUES (?, ?)";
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            throw new Exception("Failed to prepare insert query: " . $conn->error, 500);
        }

        $stmt->bind_param("ss", $name, $description);

        if (!$stmt->execute()) {
            // Catch unique constraint violation (safety net)
            if ($stmt->errno === 1062) {
                throw new Exception("A category with this name already exists.", 409);
            }
            throw new Exception("Failed to save category: " . $stmt->error, 500);
        }

        $newCategoryId = $stmt->insert_id;
        $stmt->close();

        // -------------------------------------------------------
        // 4. Log Activity
        // -------------------------------------------------------
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "category.created";
        $modelType   = "ProductCategory";
        $logDescription = "{$loggedInUserEmail} created product category '{$name}' (ID: {$newCategoryId})";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $newCategoryId, $logDescription, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log category creation: " . $logStmt->error);
        }
        $logStmt->close();

        // Commit transaction
        $conn->commit();

        // -------------------------------------------------------
        // 5. Fetch & Return Created Category
        // -------------------------------------------------------
        $fetchStmt = $conn->prepare("
            SELECT id, name, description, created_at 
            FROM product_categories 
            WHERE id = ?
        ");
        $fetchStmt->bind_param("i", $newCategoryId);
        $fetchStmt->execute();
        $newCategory = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        http_response_code(201);
        echo json_encode([
            "status"  => "success",
            "message" => "Category created successfully.",
            "data"    => [
                "id"          => (int)$newCategory['id'],
                "name"        => $newCategory['name'],
                "description" => $newCategory['description'],
                "created_at"  => $newCategory['created_at']
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Create Category Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>