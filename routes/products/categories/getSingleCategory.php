<?php
// routes/categories/getSingleCategory.php
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

/**
 * GET /categories/{id}
 * Get single category details with product count.
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

    // Only Admin, Sales, and Accountant can view category details
    if (!in_array($loggedInUserRole, ['admin', 'sales', 'accountant'])) {
        throw new Exception("Unauthorized: You do not have permission to view this category", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Category ID
    // -------------------------------------------------------
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Category ID is required.", 400);
    }
    
    $categoryId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 2. Fetch Category Details
    // -------------------------------------------------------
    $categoryQuery = "
        SELECT 
            pc.id, 
            pc.name, 
            pc.description, 
            pc.created_at,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = pc.id AND p.is_active = 1) AS active_product_count,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = pc.id) AS total_product_count
        FROM product_categories pc
        WHERE pc.id = ?
        LIMIT 1
    ";

    $categoryStmt = $conn->prepare($categoryQuery);
    if (!$categoryStmt) {
        throw new Exception("Database query failed: " . $conn->error, 500);
    }

    $categoryStmt->bind_param("i", $categoryId);
    $categoryStmt->execute();
    $categoryResult = $categoryStmt->get_result();

    if ($categoryResult->num_rows === 0) {
        throw new Exception("Category not found.", 404);
    }

    $category = $categoryResult->fetch_assoc();
    $categoryStmt->close();

    // -------------------------------------------------------
    // 3. Return Response
    // -------------------------------------------------------
    $formattedCategory = [
        "id"                  => (int)$category['id'],
        "name"                => $category['name'],
        "description"         => $category['description'],
        "active_product_count"=> (int)$category['active_product_count'],
        "total_product_count" => (int)$category['total_product_count'],
        "created_at"          => $category['created_at']
    ];

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Category fetched successfully",
        "data"    => $formattedCategory
    ]);

} catch (Exception $e) {
    error_log("Get Single Category Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>