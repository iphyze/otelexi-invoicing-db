<?php
// routes/products/createProduct.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /products
 * Create a new product.
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

    // Only Admin can create products
    if (!in_array($loggedInUserRole, ['super_admin', 'admin'], true)) {
        throw new Exception("Unauthorized: Only Super Admins or Admins can create products.", 403);
    }

    // Read JSON payload
    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    // -------------------------------------------------------
    // 1. Validation - Required Fields
    // -------------------------------------------------------
    $requiredFields = ['category_id', 'name', 'sku', 'unit_price', 'unit_of_measure'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && empty(trim($data[$field])))) {
            throw new Exception("The field '{$field}' is required.", 422);
        }
    }

    // Validate category_id is numeric
    if (!is_numeric($data['category_id'])) {
        throw new Exception("The field 'category_id' must be a valid number.", 422);
    }

    // Validate unit_price is numeric and positive
    if (!is_numeric($data['unit_price']) || (float)$data['unit_price'] < 0) {
        throw new Exception("The field 'unit_price' must be a valid positive number.", 422);
    }

    // Validate unit_of_measure
    $validUnits = ['single', 'set', 'carton', 'dozen'];
    if (!in_array(strtolower(trim($data['unit_of_measure'])), $validUnits)) {
        throw new Exception("Invalid unit_of_measure. Must be 'single', 'set', 'carton', or 'dozen'.", 422);
    }

    // Validate tax_type if provided
    $taxType = isset($data['tax_type']) ? strtolower(trim($data['tax_type'])) : 'vat';
    if (!in_array($taxType, ['vat', 'exempt'])) {
        throw new Exception("Invalid tax_type. Must be 'vat' or 'exempt'.", 422);
    }

    // Validate tax_rate if provided
    $taxRate = isset($data['tax_rate']) ? (float)$data['tax_rate'] : 7.50;
    if ($taxRate < 0 || $taxRate > 100) {
        throw new Exception("Invalid tax_rate. Must be between 0 and 100.", 422);
    }

    // If tax_type is exempt, force tax_rate to 0
    if ($taxType === 'exempt') {
        $taxRate = 0.00;
    }

    // Validate stock_quantity if provided
    $stockQuantity = isset($data['stock_quantity']) ? (float)$data['stock_quantity'] : 0.00;
    if ($stockQuantity < 0) {
        throw new Exception("Stock quantity cannot be negative.", 422);
    }

    // Validate reorder_level if provided
    $reorderLevel = isset($data['reorder_level']) ? (float)$data['reorder_level'] : 0.00;
    if ($reorderLevel < 0) {
        throw new Exception("Reorder level cannot be negative.", 422);
    }

    // -------------------------------------------------------
    // 2. Prepare Data
    // -------------------------------------------------------
    $categoryId    = (int)$data['category_id'];
    $name          = trim($data['name']);
    $sku           = trim($data['sku']);
    $description   = isset($data['description']) ? trim($data['description']) : null;
    $unitPrice     = (float)$data['unit_price'];
    $unitOfMeasure = strtolower(trim($data['unit_of_measure']));

    // Validate name length
    if (strlen($name) > 200) {
        throw new Exception("Product name cannot exceed 200 characters.", 422);
    }

    // Validate SKU length
    if (strlen($sku) > 100) {
        throw new Exception("SKU cannot exceed 100 characters.", 422);
    }

    // -------------------------------------------------------
    // 3. Verify Category Exists
    // -------------------------------------------------------
    $categoryCheck = $conn->prepare("SELECT id, name FROM product_categories WHERE id = ? LIMIT 1");
    $categoryCheck->bind_param("i", $categoryId);
    $categoryCheck->execute();
    $categoryResult = $categoryCheck->get_result();

    if ($categoryResult->num_rows === 0) {
        throw new Exception("Selected category does not exist.", 404);
    }

    $category = $categoryResult->fetch_assoc();
    $categoryCheck->close();

    // -------------------------------------------------------
    // 4. FAST-FAIL: Check for duplicate SKU
    // -------------------------------------------------------
    $skuCheck = $conn->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
    $skuCheck->bind_param("s", $sku);
    $skuCheck->execute();
    
    if ($skuCheck->get_result()->num_rows > 0) {
        throw new Exception("A product with this SKU already exists.", 409);
    }
    $skuCheck->close();

    // -------------------------------------------------------
    // 5. Insert New Product
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $query = "
            INSERT INTO products (
                category_id, name, sku, description, unit_price, 
                unit_of_measure, tax_type, tax_rate, stock_quantity, reorder_level
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            throw new Exception("Failed to prepare insert query: " . $conn->error, 500);
        }

        $stmt->bind_param("isssdssddd",
            $categoryId,
            $name,
            $sku,
            $description,
            $unitPrice,
            $unitOfMeasure,
            $taxType,
            $taxRate,
            $stockQuantity,
            $reorderLevel
        );

        if (!$stmt->execute()) {
            // Catch unique constraint violation (safety net)
            if ($stmt->errno === 1062) {
                throw new Exception("A product with this SKU already exists.", 409);
            }
            // Catch foreign key violation
            if ($stmt->errno === 1452) {
                throw new Exception("Invalid category selected.", 422);
            }
            throw new Exception("Failed to save product: " . $stmt->error, 500);
        }

        $newProductId = $stmt->insert_id;
        $stmt->close();

        // -------------------------------------------------------
        // 6. Log Activity
        // -------------------------------------------------------
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "product.created";
        $modelType   = "Product";
        $logDescription = "{$loggedInUserEmail} created product '{$name}' (SKU: {$sku}) in category '{$category['name']}' (ID: {$newProductId})";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $newProductId, $logDescription, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log product creation: " . $logStmt->error);
        }
        $logStmt->close();

        // Commit transaction
        $conn->commit();

        // -------------------------------------------------------
        // 7. Fetch & Return Created Product
        // -------------------------------------------------------
        $fetchStmt = $conn->prepare("
            SELECT p.id, p.category_id, p.name, p.sku, p.description, p.unit_price, 
                   p.unit_of_measure, p.tax_type, p.tax_rate, p.stock_quantity, 
                   p.reorder_level, p.is_active, p.created_at,
                   pc.name AS category_name
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE p.id = ?
        ");
        $fetchStmt->bind_param("i", $newProductId);
        $fetchStmt->execute();
        $newProduct = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        http_response_code(201);
        echo json_encode([
            "status"  => "success",
            "message" => "Product created successfully.",
            "data"    => [
                "id"              => (int)$newProduct['id'],
                "category_id"     => (int)$newProduct['category_id'],
                "category_name"   => $newProduct['category_name'],
                "name"            => $newProduct['name'],
                "sku"             => $newProduct['sku'],
                "description"     => $newProduct['description'],
                "unit_price"      => (float)$newProduct['unit_price'],
                "unit_of_measure" => $newProduct['unit_of_measure'],
                "tax_type"        => $newProduct['tax_type'],
                "tax_rate"        => (float)$newProduct['tax_rate'],
                "stock_quantity"  => (float)$newProduct['stock_quantity'],
                "reorder_level"   => (float)$newProduct['reorder_level'],
                "is_active"       => (int)$newProduct['is_active'],
                "created_at"      => $newProduct['created_at']
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Create Product Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>