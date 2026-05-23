<?php
// routes/products/getSingleProduct.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /products/{id}
 * Get single product details with category info.
 * Roles allowed: Admin, Sales, Accounting
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    // Only Admin, Sales, and Accounting can view product details
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales', 'accounting'])) {
        throw new Exception("Unauthorized: You do not have permission to view this product", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Product ID
    // -------------------------------------------------------
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Product ID is required.", 400);
    }
    
    $productId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 2. Fetch Product Details
    // -------------------------------------------------------
    $productQuery = "
        SELECT 
            p.id, 
            p.category_id, 
            p.name, 
            p.sku, 
            p.description, 
            p.unit_price, 
            p.unit_of_measure, 
            p.tax_type, 
            p.tax_rate, 
            p.stock_quantity, 
            p.reorder_level, 
            p.is_active, 
            p.created_at,
            p.updated_at,
            pc.name AS category_name,
            pc.description AS category_description
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE p.id = ?
        LIMIT 1
    ";

    $productStmt = $conn->prepare($productQuery);
    if (!$productStmt) {
        throw new Exception("Database query failed: " . $conn->error, 500);
    }

    $productStmt->bind_param("i", $productId);
    $productStmt->execute();
    $productResult = $productStmt->get_result();

    if ($productResult->num_rows === 0) {
        throw new Exception("Product not found.", 404);
    }

    $product = $productResult->fetch_assoc();
    $productStmt->close();

    // -------------------------------------------------------
    // 3. Determine stock status
    // -------------------------------------------------------
    $stockStatus = 'in_stock';
    if ($product['stock_quantity'] <= 0) {
        $stockStatus = 'out_of_stock';
    } elseif ($product['stock_quantity'] <= $product['reorder_level']) {
        $stockStatus = 'low_stock';
    }

    // -------------------------------------------------------
    // 4. Return Response
    // -------------------------------------------------------
    $formattedProduct = [
        "id"                   => (int)$product['id'],
        "category_id"          => (int)$product['category_id'],
        "category"             => [
            "id"          => (int)$product['category_id'],
            "name"        => $product['category_name'],
            "description" => $product['category_description']
        ],
        "name"                 => $product['name'],
        "sku"                  => $product['sku'],
        "description"          => $product['description'],
        "unit_price"           => (float)$product['unit_price'],
        "unit_of_measure"      => $product['unit_of_measure'],
        "tax_type"             => $product['tax_type'],
        "tax_rate"             => (float)$product['tax_rate'],
        "stock_quantity"       => (float)$product['stock_quantity'],
        "reorder_level"        => (float)$product['reorder_level'],
        "stock_status"         => $stockStatus,
        "is_active"            => (int)$product['is_active'],
        "created_at"           => $product['created_at'],
        "updated_at"           => $product['updated_at']
    ];

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Product fetched successfully",
        "data"    => $formattedProduct
    ]);

} catch (Exception $e) {
    error_log("Get Single Product Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>