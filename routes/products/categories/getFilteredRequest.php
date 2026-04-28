<?php
// routes/categories/getCategories.php
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

/**
 * GET /categories
 * Get filtered list of product categories with pagination.
 * Roles allowed: Admin, Sales, Accountant
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    // Only Admin, Sales, and Accountant can view categories
    if (!in_array($loggedInUserRole, ['admin', 'sales', 'accountant'])) {
        throw new Exception("Unauthorized: You do not have permission to view categories", 403);
    }

    // -------------------------------------------------------
    // 1. Gather & Sanitize Query Parameters
    // -------------------------------------------------------
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    // Pagination setup
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    // Sorting setup
    $allowedSortFields = ['id', 'name', 'created_at'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : 'name';

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'DESC'
        ? 'DESC'
        : 'ASC';

    // -------------------------------------------------------
    // 2. Dynamic Query Building
    // -------------------------------------------------------
    $baseQuery = "FROM product_categories pc WHERE 1=1";
    $params = [];
    $types  = "";

    // Filter by search (category name, description)
    if ($search) {
        $baseQuery .= " AND (
            name LIKE ? 
            OR description LIKE ?
        )";
        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $types .= "ss";
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
    // 4. Fetch paginated data with product count
    // -------------------------------------------------------
    $dataQuery = "
        SELECT 
            pc.id, 
            pc.name, 
            pc.description, 
            pc.created_at,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = pc.id AND p.is_active = 1) AS active_product_count,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = pc.id) AS total_product_count
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
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = [
            "id"                  => (int)$row['id'],
            "name"                => $row['name'],
            "description"         => $row['description'],
            "active_product_count"=> (int)$row['active_product_count'],
            "total_product_count" => (int)$row['total_product_count'],
            "created_at"          => $row['created_at']
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
        "message" => "Categories fetched successfully",
        "data"    => $categories,
        "meta"    => [
            "total"      => $total,
            "total_pages"=> $totalPages,
            "limit"      => $limit,
            "page"       => $page,
            "sortBy"     => $sortBy,
            "sortOrder"  => $sortOrder,
            "search"     => $search
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Categories List Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>