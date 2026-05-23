<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    // Only Admin allowed
    if ($loggedInUserRole !== 'super_admin') {
        throw new Exception("Unauthorized: Only the Super Admin can access this resource", 403);
    }

    // Pagination setup (optional, with defaults)
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    // Search filter (optional)
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    // Role filter (optional)
    $roleFilter = isset($_GET['role']) ? trim($_GET['role']) : null;
    $allowedRoles = ['super_admin', 'admin', 'sales', 'accounting'];

    if ($roleFilter && !in_array($roleFilter, $allowedRoles)) {
        throw new Exception("Invalid role filter. Allowed: super_admin, admin, sales, accounting", 400);
    }

    // Sorting setup
    $allowedSortFields = ['id', 'name', 'email', 'role', 'created_at'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : 'id';

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC'
        ? 'ASC'
        : 'DESC';

    // Base query
    $baseQuery = "FROM users WHERE 1=1";
    $params = [];
    $types  = "";

    // Search filter
    if ($search) {
        $baseQuery .= " AND (
            name LIKE ? 
            OR email LIKE ? 
            OR role LIKE ?
        )";
        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $types .= "sss";
    }

    // Role filter
    if ($roleFilter) {
        $baseQuery .= " AND role = ?";
        $params[] = $roleFilter;
        $types .= "s";
    }

    /**
     * Count total records
     */
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

    /**
     * Fetch paginated data
     */
    $dataQuery = "
        SELECT id, name, email, role, is_active, last_login, created_at, updated_at
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
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            "id"         => (int)$row['id'],
            "name"       => $row['name'],
            "email"      => $row['email'],
            "role"       => $row['role'],
            "is_active"  => (int)$row['is_active'],
            "last_login" => $row['last_login'],
            "created_at" => $row['created_at'],
            "updated_at" => $row['updated_at']
        ];
    }
    $dataStmt->close();

    // Calculate total pages for frontend pagination controls
    $totalPages = $total > 0 ? (int)ceil($total / $limit) : 0;

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Users fetched successfully",
        "data"    => $users,
        "meta"    => [
            "total"       => $total,
            "total_pages" => $totalPages,
            "limit"       => $limit,
            "page"        => $page,
            "sortBy"      => $sortBy,
            "sortOrder"   => $sortOrder,
            "search"      => $search,
            "role"        => $roleFilter
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Users List Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}

?>