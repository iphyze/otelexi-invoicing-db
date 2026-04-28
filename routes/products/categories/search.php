<?php
// routes/categories/getCategoryDropdown.php
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

/**
 * GET /categories/dropdown
 * Get categories list for dropdown (used when creating products).
 * Roles allowed: Admin, Sales, Accountant
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();

    // Get search query (optional)
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    // Base query
    $sql = "
        SELECT 
            pc.id, 
            pc.name,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = pc.id AND p.is_active = 1) AS product_count
        FROM product_categories pc
        WHERE 1=1
    ";

    $params = [];
    $types = "";

    // Search filter
    if (!empty($search)) {
        $sql .= " AND pc.name LIKE ?";
        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        $types .= "s";
    }

    // Sort by name and limit for dropdown performance
    $sql .= " ORDER BY pc.name ASC LIMIT 100";

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
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = [
            "id"            => (int)$row['id'],
            "name"          => $row['name'],
            "product_count" => (int)$row['product_count']
        ];
    }
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "data"   => $categories
    ]);

} catch (Exception $e) {
    error_log("Category Dropdown Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>