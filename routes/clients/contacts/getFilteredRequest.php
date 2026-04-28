<?php
// routes/clients/getFilteredContacts.php
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

/**
 * GET /clients/contacts
 * Get filtered list of all client contacts with pagination.
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

    // Only Admin, Sales, and Accountant can view contacts
    if (!in_array($loggedInUserRole, ['admin', 'sales', 'accountant'])) {
        throw new Exception("Unauthorized: You do not have permission to view client contacts", 403);
    }

    // -------------------------------------------------------
    // 1. Gather & Sanitize Query Parameters
    // -------------------------------------------------------
    $search    = isset($_GET['search']) ? trim($_GET['search']) : null;
    $clientId  = isset($_GET['client_id']) && is_numeric($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $isPrimary = isset($_GET['is_primary']) ? (int)$_GET['client_id'] : null;
    $position  = isset($_GET['position']) ? trim($_GET['position']) : null;

    // Pagination setup
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    // Sorting setup
    $allowedSortFields = ['id', 'name', 'position', 'company_name', 'created_at'];
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
        FROM client_contacts cc
        JOIN clients c ON c.id = cc.client_id
        WHERE 1=1
    ";
    $params = [];
    $types  = "";

    // Filter by specific client
    if ($clientId) {
        $baseQuery .= " AND cc.client_id = ?";
        $params[] = $clientId;
        $types .= "i";
    }

    // Filter by primary status (1 = only primary, 0 = only non-primary, null = all)
    if (isset($_GET['is_primary']) && $_GET['is_primary'] !== '') {
        $primaryVal = (int)$_GET['is_primary'];
        if (in_array($primaryVal, [0, 1])) {
            $baseQuery .= " AND cc.is_primary = ?";
            $params[] = $primaryVal;
            $types .= "i";
        }
    }

    // Filter by position (exact match)
    if ($position) {
        $baseQuery .= " AND cc.position = ?";
        $params[] = $position;
        $types .= "s";
    }

    // Filter by search (contact name, email, phone, company name)
    if ($search) {
        $baseQuery .= " AND (
            cc.name LIKE ? 
            OR cc.email LIKE ? 
            OR cc.phone LIKE ?
            OR c.company_name LIKE ?
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
            cc.id, 
            cc.client_id, 
            cc.name, 
            cc.email, 
            cc.phone, 
            cc.position, 
            cc.is_primary, 
            cc.created_at,
            c.company_name,
            c.city
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
    $contacts = [];
    while ($row = $result->fetch_assoc()) {
        $contacts[] = [
            "id"           => (int)$row['id'],
            "client_id"    => (int)$row['client_id'],
            "name"         => $row['name'],
            "email"        => $row['email'],
            "phone"        => $row['phone'],
            "position"     => $row['position'],
            "is_primary"   => (int)$row['is_primary'],
            "company_name" => $row['company_name'],
            "city"         => $row['city'],
            "created_at"   => $row['created_at']
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
        "message" => "Contacts fetched successfully",
        "data"    => $contacts,
        "meta"    => [
            "total"       => $total,
            "total_pages" => $totalPages,
            "limit"       => $limit,
            "page"        => $page,
            "sortBy"      => $sortBy,
            "sortOrder"   => $sortOrder,
            "search"      => $search,
            "client_id"   => $clientId,
            "is_primary"  => isset($_GET['is_primary']) ? (int)$_GET['is_primary'] : null,
            "position"    => $position
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Filtered Contacts Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>