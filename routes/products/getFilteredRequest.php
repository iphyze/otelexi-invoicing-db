<?php
// routes/products/getProducts.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /products
 * Get filtered list of products with pagination.
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

    // Only Admin, Sales, and Accounting can view products
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales', 'accounting'])) {
        throw new Exception("Unauthorized: You do not have permission to view products", 403);
    }

    // -------------------------------------------------------
    // 1. Gather & Sanitize Query Parameters
    // -------------------------------------------------------
    $search      = isset($_GET['search']) ? trim($_GET['search']) : null;
    $categoryId  = isset($_GET['category_id']) && is_numeric($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $taxType     = isset($_GET['tax_type']) ? strtolower(trim($_GET['tax_type'])) : null;
    $status      = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'active';
    $lowStock    = isset($_GET['low_stock']) ? (int)$_GET['low_stock'] : 0;
    $unitOfMeasure = isset($_GET['unit_of_measure']) ? strtolower(trim($_GET['unit_of_measure'])) : null;

    // Pagination setup
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    // Sorting setup
    $allowedSortFields = ['id', 'name', 'sku', 'unit_price', 'stock_quantity', 'created_at'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : 'created_at';

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC'
        ? 'ASC'
        : 'DESC';

    // -------------------------------------------------------
    // 2. Dynamic Query Building
    // -------------------------------------------------------
    $baseQuery = "
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE 1=1
    ";
    $params = [];
    $types  = "";

    // Filter by status (default to active)
    if ($status === 'inactive') {
        $baseQuery .= " AND p.is_active = 0";
    } else {
        $baseQuery .= " AND p.is_active = 1";
    }

    // Filter by category
    if ($categoryId) {
        $baseQuery .= " AND p.category_id = ?";
        $params[] = $categoryId;
        $types .= "i";
    }

    // Filter by tax type
    if ($taxType && in_array($taxType, ['vat', 'exempt'])) {
        $baseQuery .= " AND p.tax_type = ?";
        $params[] = $taxType;
        $types .= "s";
    }

    // Filter by unit of measure
    if ($unitOfMeasure && in_array($unitOfMeasure, ['single', 'set', 'carton', 'dozen'])) {
        $baseQuery .= " AND p.unit_of_measure = ?";
        $params[] = $unitOfMeasure;
        $types .= "s";
    }

    // Filter by low stock (at or below reorder level)
    if ($lowStock === 1) {
        $baseQuery .= " AND p.stock_quantity <= p.reorder_level";
    }

    // Filter by search (product name, SKU, description, category name)
    if ($search) {
        $baseQuery .= " AND (
            p.name LIKE ? 
            OR p.sku LIKE ? 
            OR p.description LIKE ?
            OR pc.name LIKE ?
        )";
        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $types .= "ssss";
    }

    // -------------------------------------------------------
    // 3. Count total records
    // -------------------------------------------------------
    $countQuery = "SELECT COUNT(*) AS total $baseQuery";
    $countStmt = $conn->prepare($countQuery);
    
    if (!$countStmt) {
        throw new Exception("Failed to prepare count query: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // -------------------------------------------------------
    // 4. Fetch paginated data
    // -------------------------------------------------------
    $dataQuery = "
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
            pc.name AS category_name
        $baseQuery
        ORDER BY $sortBy $sortOrder
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Append limit & offset to params and types
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $result = $dataStmt->get_result();
    
    // Cast types for the frontend
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $stockStatus = 'in_stock';
        if ($row['stock_quantity'] <= 0) {
            $stockStatus = 'out_of_stock';
        } elseif ($row['stock_quantity'] <= $row['reorder_level']) {
            $stockStatus = 'low_stock';
        }

        $products[] = [
            "id"               => (int)$row['id'],
            "category_id"      => (int)$row['category_id'],
            "category_name"    => $row['category_name'],
            "name"             => $row['name'],
            "sku"              => $row['sku'],
            "description"      => $row['description'],
            "unit_price"       => (float)$row['unit_price'],
            "unit_of_measure"  => $row['unit_of_measure'],
            "tax_type"         => $row['tax_type'],
            "tax_rate"         => (float)$row['tax_rate'],
            "stock_quantity"   => (float)$row['stock_quantity'],
            "reorder_level"    => (float)$row['reorder_level'],
            "stock_status"     => $stockStatus,
            "is_active"        => (int)$row['is_active'],
            "created_at"       => $row['created_at']
        ];
    }
    $dataStmt->close();

    // Calculate total pages for frontend pagination controls
    $totalPages = $total > 0 ? (int)ceil($total / $limit) : 0;

    // -------------------------------------------------------
    // 5. Return Response
    // -------------------------------------------------------
    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Products fetched successfully",
        "data"    => $products,
        "meta"    => [
            "total"           => $total,
            "total_pages"     => $totalPages,
            "limit"           => $limit,
            "page"            => $page,
            "sortBy"          => $sortBy,
            "sortOrder"       => $sortOrder,
            "search"          => $search,
            "category_id"     => $categoryId,
            "tax_type"        => $taxType,
            "status"          => $status,
            "low_stock"       => $lowStock,
            "unit_of_measure" => $unitOfMeasure
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Products List Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>