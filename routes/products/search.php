<?php
// routes/products/getProductDropdown.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /products/dropdown
 * Get products list for dropdown (used when creating quotations/invoices).
 * Roles allowed: Admin, Sales, Accountant
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();

    // Get query parameters
    $search     = isset($_GET['search']) ? trim($_GET['search']) : '';
    $categoryId = isset($_GET['category_id']) && is_numeric($_GET['category_id']) ? (int)$_GET['category_id'] : null;

    // Base query - only active products
    $sql = "
        SELECT 
            p.id, 
            p.name, 
            p.sku,
            p.unit_price,
            p.unit_of_measure,
            p.tax_type,
            p.tax_rate,
            p.stock_quantity,
            p.reorder_level,
            pc.name AS category_name
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE p.is_active = 1
    ";

    $params = [];
    $types = "";

    // Filter by category
    if ($categoryId) {
        $sql .= " AND p.category_id = ?";
        $params[] = $categoryId;
        $types .= "i";
    }

    // Search filter (product name, SKU)
    if (!empty($search)) {
        $sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $types .= "ss";
    }

    // Sort by name and limit for dropdown performance
    $sql .= " ORDER BY p.name ASC LIMIT 100";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    
    // Format data for frontend dropdowns
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $stockStatus = 'in_stock';
        if ($row['stock_quantity'] <= 0) {
            $stockStatus = 'out_of_stock';
        } elseif ($row['stock_quantity'] <= $row['reorder_level']) {
            $stockStatus = 'low_stock';
        }

        $products[] = [
            "id"              => (int)$row['id'],
            "name"            => $row['name'],
            "sku"             => $row['sku'],
            "category_name"   => $row['category_name'],
            "unit_price"      => (float)$row['unit_price'],
            "unit_of_measure" => $row['unit_of_measure'],
            "tax_type"        => $row['tax_type'],
            "tax_rate"        => (float)$row['tax_rate'],
            "stock_quantity"  => (float)$row['stock_quantity'],
            "stock_status"    => $stockStatus
        ];
    }
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "data"   => $products
    ]);

} catch (Exception $e) {
    error_log("Product Dropdown Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>