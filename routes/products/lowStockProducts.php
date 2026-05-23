<?php
// routes/products/getLowStockProducts.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /products/low-stock
 * Get all products at or below reorder level.
 * Uses v_low_stock view.
 * Roles allowed: Admin
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    // Only Admin can view low stock alerts
    if (!in_array($loggedInUserRole, ['super_admin', 'admin'], true)) {
        throw new Exception("Unauthorized: Only Super Admins or Admins can view low stock reports.", 403);
    }

    // -------------------------------------------------------
    // 1. Gather & Sanitize Query Parameters
    // -------------------------------------------------------
    $categoryId = isset($_GET['category_id']) && is_numeric($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $status     = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : null;

    // -------------------------------------------------------
    // 2. Build Query
    // -------------------------------------------------------
    // Using the view for consistency with reports
    $sql = "
        SELECT 
            product_id AS id,
            product_name AS name,
            sku,
            category_name,
            stock_quantity,
            reorder_level,
            unit_of_measure,
            stock_status
        FROM v_low_stock
        WHERE 1=1
    ";

    // We need to join with products table for unit_of_measure since view doesn't have it
    // Let's use a direct query instead
    $sql = "
        SELECT 
            p.id AS product_id,
            p.name AS product_name,
            p.sku,
            p.stock_quantity,
            p.reorder_level,
            p.unit_of_measure,
            pc.name AS category_name,
            CASE
                WHEN p.stock_quantity <= 0 THEN 'OUT OF STOCK'
                WHEN p.stock_quantity <= p.reorder_level THEN 'LOW STOCK'
                ELSE 'OK'
            END AS stock_status
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE p.is_active = 1
          AND p.stock_quantity <= p.reorder_level
    ";

    $params = [];
    $types = "";

    // Filter by category
    if ($categoryId) {
        $sql .= " AND p.category_id = ?";
        $params[] = $categoryId;
        $types .= "i";
    }

    // Filter by stock status
    if ($status && in_array($status, ['OUT OF STOCK', 'LOW STOCK'])) {
        if ($status === 'OUT OF STOCK') {
            $sql .= " AND p.stock_quantity <= 0";
        } elseif ($status === 'LOW STOCK') {
            $sql .= " AND p.stock_quantity > 0 AND p.stock_quantity <= p.reorder_level";
        }
    }

    // Sort by stock quantity ascending (most critical first)
    $sql .= " ORDER BY p.stock_quantity ASC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    
    // Format data
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            "id"              => (int)$row['product_id'],
            "name"            => $row['product_name'],
            "sku"             => $row['sku'],
            "category_name"   => $row['category_name'],
            "stock_quantity"  => (float)$row['stock_quantity'],
            "reorder_level"   => (float)$row['reorder_level'],
            "unit_of_measure" => $row['unit_of_measure'],
            "stock_status"    => $row['stock_status']
        ];
    }
    $stmt->close();

    // Summary counts
    $outOfStockCount = count(array_filter($products, fn($p) => $p['stock_status'] === 'OUT OF STOCK'));
    $lowStockCount = count(array_filter($products, fn($p) => $p['stock_status'] === 'LOW STOCK'));

    // -------------------------------------------------------
    // 3. Return Response
    // -------------------------------------------------------
    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Low stock products fetched successfully",
        "data"    => $products,
        "meta"    => [
            "total"           => count($products),
            "out_of_stock"    => $outOfStockCount,
            "low_stock"       => $lowStockCount,
            "category_id"     => $categoryId,
            "status_filter"   => $status
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Low Stock Products Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>