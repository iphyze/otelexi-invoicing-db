<?php
// routes/clients/getClients.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /clients
 * Get filtered list of clients with pagination.
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

    // Only Admin, Sales, and Accountant can view clients
    if (!in_array($loggedInUserRole, ['admin', 'sales', 'accountant'])) {
        throw new Exception("Unauthorized: You do not have permission to view clients", 403);
    }

    // -------------------------------------------------------
    // 1. Gather & Sanitize Query Parameters
    // -------------------------------------------------------
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;
    $status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'active';
    $currency = isset($_GET['currency']) ? strtoupper(trim($_GET['currency'])) : null;

    // Pagination setup
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    // Sorting setup
    $allowedSortFields = ['id', 'company_name', 'city', 'state', 'created_at'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : 'created_at';

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC'
        ? 'ASC'
        : 'DESC';

    // -------------------------------------------------------
    // 2. Dynamic Query Building
    // -------------------------------------------------------
    $baseQuery = "FROM clients c WHERE 1=1";
    $params = [];
    $types  = "";

    // Filter by status (default to active)
    if ($status === 'inactive') {
        $baseQuery .= " AND c.is_active = 0";
    } else {
        $baseQuery .= " AND c.is_active = 1";
    }

    // Filter by search (company name, email, phone)
    if ($search) {
        $baseQuery .= " AND (
            c.company_name LIKE ? 
            OR c.email LIKE ? 
            OR c.phone LIKE ?
        )";
        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $types .= "sss";
    }

    // Filter by currency
    if ($currency && in_array($currency, ['NGN', 'USD'])) {
        $baseQuery .= " AND c.currency = ?";
        $params[] = $currency;
        $types .= "s";
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
            c.id, c.company_name, c.city, c.state, c.country, 
            c.email, c.phone, c.tax_id, c.currency, c.payment_terms, 
            c.is_active, c.created_at
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
    $clients = [];
    while ($row = $result->fetch_assoc()) {
        $clients[] = [
            "id"            => (int)$row['id'],
            "company_name"  => $row['company_name'],
            "city"          => $row['city'],
            "state"         => $row['state'],
            "country"       => $row['country'],
            "email"         => $row['email'],
            "phone"         => $row['phone'],
            "tax_id"        => $row['tax_id'],
            "currency"      => $row['currency'],
            "payment_terms" => $row['payment_terms'],
            "is_active"     => (int)$row['is_active'],
            "created_at"    => $row['created_at']
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
        "message" => "Clients fetched successfully",
        "data"    => $clients,
        "meta"    => [
            "total"      => $total,
            "total_pages"=> $totalPages,
            "limit"      => $limit,
            "page"       => $page,
            "sortBy"     => $sortBy,
            "sortOrder"  => $sortOrder,
            "search"     => $search,
            "status"     => $status,
            "currency"   => $currency
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Clients List Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>