<?php
// routes/quotations/getQuotations.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /quotations
 * Get filtered list of quotations with pagination.
 * Sales sees own only; Admin sees all.
 * Roles allowed: Admin, Sales
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];

    // Only Admin and Sales can view quotations
    if (!in_array($loggedInUserRole, ['admin', 'sales'])) {
        throw new Exception("Unauthorized: You do not have permission to view quotations", 403);
    }

    // -------------------------------------------------------
    // 1. Gather & Sanitize Query Parameters
    // -------------------------------------------------------
    $search    = isset($_GET['search']) ? trim($_GET['search']) : null;
    $status    = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : null;
    $clientId  = isset($_GET['client_id']) && is_numeric($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $fromDate  = isset($_GET['from']) ? trim($_GET['from']) : null;
    $toDate    = isset($_GET['to']) ? trim($_GET['to']) : null;

    // Pagination setup
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    // Sorting setup
    $allowedSortFields = ['id', 'quotation_number', 'issue_date', 'expiry_date', 'total_amount', 'status', 'created_at'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : 'created_at';

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC'
        ? 'ASC'
        : 'DESC';

    // Valid statuses
    $validStatuses = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted'];

    // -------------------------------------------------------
    // 2. Dynamic Query Building
    // -------------------------------------------------------
    $baseQuery = "
        FROM quotations q
        JOIN clients c ON c.id = q.client_id
        LEFT JOIN users u ON u.id = q.created_by
        WHERE 1=1
    ";
    $params = [];
    $types  = "";

    // Sales can only see their own quotations
    if ($loggedInUserRole === 'sales') {
        $baseQuery .= " AND q.created_by = ?";
        $params[] = $loggedInUserId;
        $types .= "i";
    }

    // Filter by status
    if ($status && in_array($status, $validStatuses)) {
        $baseQuery .= " AND q.status = ?";
        $params[] = $status;
        $types .= "s";
    }

    // Filter by client
    if ($clientId) {
        $baseQuery .= " AND q.client_id = ?";
        $params[] = $clientId;
        $types .= "i";
    }

    // Filter by date range (issue_date)
    if ($fromDate) {
        $baseQuery .= " AND q.issue_date >= ?";
        $params[] = $fromDate;
        $types .= "s";
    }
    if ($toDate) {
        $baseQuery .= " AND q.issue_date <= ?";
        $params[] = $toDate;
        $types .= "s";
    }

    // Filter by search (quotation number, client name)
    if ($search) {
        $baseQuery .= " AND (
            q.quotation_number LIKE ? 
            OR c.company_name LIKE ?
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
    // 4. Fetch paginated data
    // -------------------------------------------------------
    $dataQuery = "
        SELECT 
            q.id, 
            q.quotation_number, 
            q.client_id,
            c.company_name AS client_name,
            q.created_by,
            u.name AS created_by_name,
            q.issue_date, 
            q.expiry_date, 
            q.currency, 
            q.exchange_rate,
            q.subtotal, 
            q.discount_type,
            q.discount_value,
            q.discount_amount,
            q.taxable_amount,
            q.tax_amount, 
            q.total_amount, 
            q.status, 
            q.created_at,
            q.updated_at,
            (SELECT COUNT(*) FROM quotation_items qi WHERE qi.quotation_id = q.id) AS item_count
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
    $quotations = [];
    while ($row = $result->fetch_assoc()) {
        // Check if expired
        $isExpired = false;
        if ($row['status'] === 'sent' && strtotime($row['expiry_date']) < strtotime(date('Y-m-d'))) {
            $isExpired = true;
        }

        $quotations[] = [
            "id"               => (int)$row['id'],
            "quotation_number" => $row['quotation_number'],
            "client_id"        => (int)$row['client_id'],
            "client_name"      => $row['client_name'],
            "created_by"       => (int)$row['created_by'],
            "created_by_name"  => $row['created_by_name'],
            "issue_date"       => $row['issue_date'],
            "expiry_date"      => $row['expiry_date'],
            "is_expired"       => $isExpired,
            "currency"         => $row['currency'],
            "exchange_rate"    => (float)$row['exchange_rate'],
            "subtotal"         => (float)$row['subtotal'],
            "discount_type"    => $row['discount_type'],
            "discount_value"   => (float)$row['discount_value'],
            "discount_amount"  => (float)$row['discount_amount'],
            "taxable_amount"   => (float)$row['taxable_amount'],
            "tax_amount"       => (float)$row['tax_amount'],
            "total_amount"     => (float)$row['total_amount'],
            "status"           => $row['status'],
            "item_count"       => (int)$row['item_count'],
            "created_at"       => $row['created_at'],
            "updated_at"       => $row['updated_at']
        ];
    }
    $dataStmt->close();

    // Calculate total pages
    $totalPages = $total > 0 ? (int)ceil($total / $limit) : 0;

    // -------------------------------------------------------
    // 5. Return Response
    // -------------------------------------------------------
    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Quotations fetched successfully",
        "data"    => $quotations,
        "meta"    => [
            "total"      => $total,
            "total_pages"=> $totalPages,
            "limit"      => $limit,
            "page"       => $page,
            "sortBy"     => $sortBy,
            "sortOrder"  => $sortOrder,
            "search"     => $search,
            "status"     => $status,
            "client_id"  => $clientId,
            "from"       => $fromDate,
            "to"         => $toDate
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Quotations List Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>