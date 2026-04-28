<?php
// routes/products/updateProduct.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * PUT /products/{id}
 * Update a specific product.
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

    // Only Admin can update products
    if ($loggedInUserRole !== 'admin') {
        throw new Exception("Unauthorized: Only Admins can update products.", 403);
    }

    // Read JSON payload
    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    // -------------------------------------------------------
    // 1. Validate Product ID
    // -------------------------------------------------------
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Product ID is required.", 400);
    }
    $productId = (int)$_GET['id'];
    unset($_GET['id']); // Remove ID from data array

    // -------------------------------------------------------
    // 2. Verify Product Exists & Get Current Data
    // -------------------------------------------------------
    $productCheck = $conn->prepare("
        SELECT p.id, p.name, p.sku, p.category_id, p.tax_type, p.tax_rate,
               pc.name AS category_name
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE p.id = ? 
        LIMIT 1
    ");
    $productCheck->bind_param("i", $productId);
    $productCheck->execute();
    $productResult = $productCheck->get_result();

    if ($productResult->num_rows === 0) {
        throw new Exception("Product not found.", 404);
    }

    $existingProduct = $productResult->fetch_assoc();
    $oldName = $existingProduct['name'];
    $oldSku = $existingProduct['sku'];
    $oldCategoryId = (int)$existingProduct['category_id'];
    $oldCategoryName = $existingProduct['category_name'];
    $oldTaxType = $existingProduct['tax_type'];
    $oldTaxRate = (float)$existingProduct['tax_rate'];
    $productCheck->close();

    // -------------------------------------------------------
    // 3. Whitelist & Validate Fields
    // -------------------------------------------------------
    $allowedFields = [
        'category_id', 'name', 'sku', 'description', 'unit_price', 
        'unit_of_measure', 'tax_type', 'tax_rate', 'stock_quantity', 
        'reorder_level', 'is_active'
    ];
    
    $requiredIfProvided = ['name', 'sku', 'unit_price', 'unit_of_measure'];

    $updateFields = [];
    $params = [];
    $types = "";
    $newTaxType = $oldTaxType;
    $newTaxRate = $oldTaxRate;
    $newCategoryName = $oldCategoryName;

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

        // Specific validations
        if ($key === 'name' && strlen($value) > 200) {
            throw new Exception("Product name cannot exceed 200 characters.", 422);
        }

        if ($key === 'sku' && strlen($value) > 100) {
            throw new Exception("SKU cannot exceed 100 characters.", 422);
        }

        if ($key === 'category_id') {
            if (!is_numeric($value)) {
                throw new Exception("The field 'category_id' must be a valid number.", 422);
            }
            $value = (int)$value;
        }

        if ($key === 'unit_price') {
            if (!is_numeric($value) || (float)$value < 0) {
                throw new Exception("The field 'unit_price' must be a valid positive number.", 422);
            }
            $value = (float)$value;
        }

        if ($key === 'unit_of_measure') {
            $validUnits = ['single', 'set', 'carton', 'dozen'];
            if (!in_array(strtolower($value), $validUnits)) {
                throw new Exception("Invalid unit_of_measure. Must be 'single', 'set', 'carton', or 'dozen'.", 422);
            }
            $value = strtolower($value);
        }

        if ($key === 'tax_type') {
            if (!in_array(strtolower($value), ['vat', 'exempt'])) {
                throw new Exception("Invalid tax_type. Must be 'vat' or 'exempt'.", 422);
            }
            $newTaxType = strtolower($value);
            $value = $newTaxType;

            // If changing to exempt, we'll force tax_rate to 0 below
        }

        if ($key === 'tax_rate') {
            if (!is_numeric($value) || (float)$value < 0 || (float)$value > 100) {
                throw new Exception("Invalid tax_rate. Must be between 0 and 100.", 422);
            }
            $newTaxRate = (float)$value;
            $value = $newTaxRate;
        }

        if ($key === 'stock_quantity' || $key === 'reorder_level') {
            if (!is_numeric($value) || (float)$value < 0) {
                throw new Exception("The field '{$key}' cannot be negative.", 422);
            }
            $value = (float)$value;
        }

        if ($key === 'is_active') {
            $value = (int)$value;
            if (!in_array($value, [0, 1])) {
                throw new Exception("The field 'is_active' must be 0 or 1.", 422);
            }
        }

        $updateFields[] = "`{$key}` = ?";
        $params[] = $value;
        $types .= is_int($value) || is_float($value) ? "d" : "s";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    // -------------------------------------------------------
    // 4. If tax_type is exempt, ensure tax_rate is 0
    // -------------------------------------------------------
    if ($newTaxType === 'exempt') {
        $taxRateKey = array_search('tax_rate', array_keys($data));
        if ($taxRateKey === false) {
            // tax_rate wasn't in the payload, but tax_type changed to exempt
            // We need to add tax_rate = 0 to the update
            $updateFields[] = "`tax_rate` = ?";
            $params[] = 0.00;
            $types .= "d";
            $newTaxRate = 0.00;
        } elseif ($newTaxRate > 0) {
            throw new Exception("Tax rate must be 0 for exempt products.", 422);
        }
    }

    // -------------------------------------------------------
    // 5. DUPLICATE CHECKS (Excluding current product ID)
    // -------------------------------------------------------
    
    // Check SKU uniqueness
    if (in_array('sku', array_keys($data))) {
        $skuCheck = $conn->prepare("SELECT id FROM products WHERE sku = ? AND id != ? LIMIT 1");
        $skuCheck->bind_param("si", $data['sku'], $productId);
        $skuCheck->execute();
        
        if ($skuCheck->get_result()->num_rows > 0) {
            throw new Exception("Another product with this SKU already exists.", 409);
        }
        $skuCheck->close();
    }

    // Verify new category exists (if changing)
        if (in_array('category_id', array_keys($data))) {
        $catCheck = $conn->prepare("SELECT id, name FROM product_categories WHERE id = ? LIMIT 1");
        $catCheck->bind_param("i", $data['category_id']);
        $catCheck->execute();
        
        $catResult = $catCheck->get_result(); // Fetch result ONCE
        
        if ($catResult->num_rows === 0) {
            $catCheck->close();
            throw new Exception("Selected category does not exist.", 404);
        }
        
        // Now safely use the stored result
        $newCategoryName = $catResult->fetch_assoc()['name'];
        $catCheck->close();
    }

    // -------------------------------------------------------
    // 6. Execute Update
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $sql = "UPDATE products SET " . implode(", ", $updateFields) . " WHERE id = ?";
        
        $updateStmt = $conn->prepare($sql);
        if (!$updateStmt) {
            error_log("DB Prepare Error (Update Product): " . $conn->error);
            throw new Exception("Failed to prepare product update.", 500);
        }

        // Append the Product ID to parameters for WHERE clause
        $params[] = $productId;
        $types .= "i";

        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            if ($updateStmt->errno === 1062) {
                throw new Exception("A product with this SKU already exists.", 409);
            }
            if ($updateStmt->errno === 1452) {
                throw new Exception("Invalid category selected.", 422);
            }
            error_log("DB Execute Error (Update Product): " . $updateStmt->error);
            throw new Exception("Failed to update product in the database.", 500);
        }

        if ($updateStmt->affected_rows === 0) {
            throw new Exception("No changes were made. The product data might be identical to what was submitted.", 200);
        }
        
        $updateStmt->close();

        // -------------------------------------------------------
        // 7. Log Activity
        // -------------------------------------------------------
        $changes = [];
        
        if (in_array('name', array_keys($data)) && $data['name'] !== $oldName) {
            $changes[] = "name: '{$oldName}' → '{$data['name']}'";
        }
        if (in_array('sku', array_keys($data)) && $data['sku'] !== $oldSku) {
            $changes[] = "SKU: '{$oldSku}' → '{$data['sku']}'";
        }
        if (in_array('category_id', array_keys($data))) {
            $changes[] = "category: '{$oldCategoryName}' → '{$newCategoryName}'";
        }
        if (in_array('unit_price', array_keys($data))) {
            $changes[] = "unit_price updated";
        }
        if (in_array('unit_of_measure', array_keys($data))) {
            $changes[] = "unit_of_measure updated";
        }
        if (in_array('tax_type', array_keys($data)) || in_array('tax_rate', array_keys($data))) {
            $changes[] = "tax: {$oldTaxType} ({$oldTaxRate}%) → {$newTaxType} ({$newTaxRate}%)";
        }
        if (in_array('stock_quantity', array_keys($data))) {
            $changes[] = "stock_quantity updated";
        }
        if (in_array('reorder_level', array_keys($data))) {
            $changes[] = "reorder_level updated";
        }
        if (in_array('is_active', array_keys($data))) {
            $statusWord = (int)$data['is_active'] === 1 ? 'activated' : 'deactivated';
            $changes[] = $statusWord;
        }

        $changeSummary = implode(', ', $changes);
        $newName = $data['name'] ?? $oldName;

        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "product.updated";
        $modelType   = "Product";
        $logDescription = "{$loggedInUserEmail} updated product '{$newName}' (ID: {$productId}). Changes: {$changeSummary}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $productId, $logDescription, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log product update: " . $logStmt->error);
        }
        $logStmt->close();

        // Commit transaction
        $conn->commit();

        // -------------------------------------------------------
        // 8. Fetch & Return Updated Product
        // -------------------------------------------------------
        $fetchStmt = $conn->prepare("
            SELECT p.id, p.category_id, p.name, p.sku, p.description, p.unit_price, 
                   p.unit_of_measure, p.tax_type, p.tax_rate, p.stock_quantity, 
                   p.reorder_level, p.is_active, p.created_at, p.updated_at,
                   pc.name AS category_name
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE p.id = ?
        ");
        $fetchStmt->bind_param("i", $productId);
        $fetchStmt->execute();
        $updatedProduct = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        // Determine stock status
        $stockStatus = 'in_stock';
        if ($updatedProduct['stock_quantity'] <= 0) {
            $stockStatus = 'out_of_stock';
        } elseif ($updatedProduct['stock_quantity'] <= $updatedProduct['reorder_level']) {
            $stockStatus = 'low_stock';
        }

        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Product updated successfully.",
            "data"    => [
                "id"              => (int)$updatedProduct['id'],
                "category_id"     => (int)$updatedProduct['category_id'],
                "category_name"   => $updatedProduct['category_name'],
                "name"            => $updatedProduct['name'],
                "sku"             => $updatedProduct['sku'],
                "description"     => $updatedProduct['description'],
                "unit_price"      => (float)$updatedProduct['unit_price'],
                "unit_of_measure" => $updatedProduct['unit_of_measure'],
                "tax_type"        => $updatedProduct['tax_type'],
                "tax_rate"        => (float)$updatedProduct['tax_rate'],
                "stock_quantity"  => (float)$updatedProduct['stock_quantity'],
                "reorder_level"   => (float)$updatedProduct['reorder_level'],
                "stock_status"    => $stockStatus,
                "is_active"       => (int)$updatedProduct['is_active'],
                "created_at"      => $updatedProduct['created_at'],
                "updated_at"      => $updatedProduct['updated_at']
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Update Product Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>