<?php
// routes/proformas/getProformas.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /proformas
 * List proforma invoices with filters and pagination.
 * Sales sees own only; Admin sees all.
 * Roles allowed: Admin, Sales
 *
 * Query params:
 *   ?status=draft|sent|approved|rejected|converted|expired
 *   &client_id=3
 *   &from=2026-01-01
 *   &to=2026-04-30
 *   &search=PRO/2026
 *   &page=1&limit=20
 *   &sortBy=created_at&sortOrder=DESC
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData         = authenticateUser();
    $loggedInUserId   = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];

    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: You do not have permission to view proforma invoices.", 403);
    }

    // -------------------------------------------------------
    // 1. Query parameters
    // -------------------------------------------------------
    $search   = isset($_GET['search']) ? trim($_GET['search']) : null;
    $status   = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : null;
    $clientId = isset($_GET['client_id']) && is_numeric($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $fromDate = isset($_GET['from']) ? trim($_GET['from']) : null;
    $toDate   = isset($_GET['to']) ? trim($_GET['to']) : null;

    $limit  = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    $allowedSortFields = ['id', 'proforma_number', 'issue_date', 'expiry_date', 'total_amount', 'status', 'created_at'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy'] : 'created_at';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC' ? 'ASC' : 'DESC';

    $validStatuses = ['draft', 'sent', 'approved', 'rejected', 'converted', 'expired'];

    // -------------------------------------------------------
    // 2. Dynamic query
    // -------------------------------------------------------
    $baseQuery = "
        FROM proforma_invoices p
        JOIN clients c ON c.id = p.client_id
        LEFT JOIN users u ON u.id = p.created_by
        LEFT JOIN quotations q ON q.id = p.quotation_id
        WHERE 1=1
    ";
    $params = [];
    $types  = "";

    if ($loggedInUserRole === 'sales') {
        $baseQuery .= " AND p.created_by = ?";
        $params[] = $loggedInUserId;
        $types   .= "i";
    }

    if ($status && in_array($status, $validStatuses)) {
        $baseQuery .= " AND p.status = ?";
        $params[] = $status;
        $types   .= "s";
    }

    if ($clientId) {
        $baseQuery .= " AND p.client_id = ?";
        $params[] = $clientId;
        $types   .= "i";
    }

    if ($fromDate) {
        $baseQuery .= " AND p.issue_date >= ?";
        $params[] = $fromDate;
        $types   .= "s";
    }

    if ($toDate) {
        $baseQuery .= " AND p.issue_date <= ?";
        $params[] = $toDate;
        $types   .= "s";
    }

    if ($search) {
        $baseQuery .= " AND (p.proforma_number LIKE ? OR c.company_name LIKE ?)";
        $like      = "%" . $search . "%";
        $params[]  = $like;
        $params[]  = $like;
        $types    .= "ss";
    }

    // -------------------------------------------------------
    // 3. Count
    // -------------------------------------------------------
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total $baseQuery");
    if (!$countStmt) throw new Exception("Failed to prepare count query: " . $conn->error, 500);

    if (!empty($params)) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // -------------------------------------------------------
    // 4. Paginated data
    // -------------------------------------------------------
    $dataQuery = "
        SELECT
            p.id, p.proforma_number, p.quotation_id,
            q.quotation_number,
            p.client_id, c.company_name AS client_name,
            p.created_by, u.name AS created_by_name,
            p.issue_date, p.expiry_date,
            p.currency, p.exchange_rate,
            p.subtotal, p.discount_type, p.discount_value, p.discount_amount,
            p.taxable_amount, p.tax_amount, p.total_amount,
            p.status, p.created_at, p.updated_at,
            (SELECT COUNT(*) FROM proforma_items pi WHERE pi.proforma_id = p.id) AS item_count
        $baseQuery
        ORDER BY p.{$sortBy} {$sortOrder}
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) throw new Exception("Failed to prepare data query: " . $conn->error, 500);

    $types   .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $result = $dataStmt->get_result();

    $proformas = [];
    $today     = date('Y-m-d');

    while ($row = $result->fetch_assoc()) {
        $isExpired = ($row['status'] === 'sent' && $row['expiry_date'] < $today);

        $proformas[] = [
            "id"               => (int)$row['id'],
            "proforma_number"  => $row['proforma_number'],
            "quotation_id"     => $row['quotation_id'] ? (int)$row['quotation_id'] : null,
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

    $totalPages = $total > 0 ? (int)ceil($total / $limit) : 0;

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Proforma invoices fetched successfully.",
        "data"    => $proformas,
        "meta"    => [
            "total"       => $total,
            "total_pages" => $totalPages,
            "limit"       => $limit,
            "page"        => $page,
            "sortBy"      => $sortBy,
            "sortOrder"   => $sortOrder,
            "search"      => $search,
            "status"      => $status,
            "client_id"   => $clientId,
            "from"        => $fromDate,
            "to"          => $toDate
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Proformas Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
