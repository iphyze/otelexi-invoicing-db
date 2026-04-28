<?php
// routes/invoices/getInvoices.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /invoices
 * List invoices with filters and pagination.
 * Sales sees own only; Admin and Accountant see all.
 * Roles allowed: Admin, Sales, Accountant
 *
 * Query params:
 *   ?status=draft|sent|partial|paid|overdue|cancelled
 *   &client_id=3
 *   &from=2026-01-01  &to=2026-04-30
 *   &search=INV/2026
 *   &page=1  &limit=20
 *   &sortBy=created_at  &sortOrder=DESC
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData         = authenticateUser();
    $loggedInUserId   = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];

    // Accountant can view all; Sales sees own only
    if (!in_array($loggedInUserRole, ['admin', 'sales', 'accountant'])) {
        throw new Exception("Unauthorized: You do not have permission to view invoices.", 403);
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

    $allowedSortFields = ['id', 'invoice_number', 'issue_date', 'due_date', 'total_amount', 'balance_due', 'status', 'created_at'];
    $sortBy    = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields) ? $_GET['sortBy'] : 'created_at';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC' ? 'ASC' : 'DESC';

    $validStatuses = ['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled'];

    // -------------------------------------------------------
    // 2. Dynamic query
    // -------------------------------------------------------
    $baseQuery = "
        FROM invoices i
        JOIN clients c ON c.id = i.client_id
        LEFT JOIN users u ON u.id = i.created_by
        LEFT JOIN users ua ON ua.id = i.approved_by
        WHERE 1=1
    ";
    $params = [];
    $types  = "";

    // Sales sees only their own
    if ($loggedInUserRole === 'sales') {
        $baseQuery .= " AND i.created_by = ?";
        $params[]   = $loggedInUserId;
        $types     .= "i";
    }

    if ($status && in_array($status, $validStatuses)) {
        $baseQuery .= " AND i.status = ?";
        $params[]   = $status;
        $types     .= "s";
    }

    if ($clientId) {
        $baseQuery .= " AND i.client_id = ?";
        $params[]   = $clientId;
        $types     .= "i";
    }

    if ($fromDate) {
        $baseQuery .= " AND i.issue_date >= ?";
        $params[]   = $fromDate;
        $types     .= "s";
    }

    if ($toDate) {
        $baseQuery .= " AND i.issue_date <= ?";
        $params[]   = $toDate;
        $types     .= "s";
    }

    if ($search) {
        $baseQuery .= " AND (i.invoice_number LIKE ? OR c.company_name LIKE ?)";
        $like       = "%" . $search . "%";
        $params[]   = $like;
        $params[]   = $like;
        $types     .= "ss";
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
            i.id, i.invoice_number,
            i.proforma_id, i.quotation_id,
            i.client_id, c.company_name AS client_name,
            i.created_by, u.name AS created_by_name,
            i.approved_by, ua.name AS approved_by_name,
            i.issue_date, i.due_date,
            i.currency, i.exchange_rate,
            i.subtotal, i.discount_type, i.discount_value, i.discount_amount,
            i.taxable_amount, i.tax_amount, i.total_amount,
            i.amount_paid, i.balance_due,
            i.payment_terms, i.status, i.stock_deducted,
            i.reminder_count, i.last_reminder_at, i.next_reminder_at,
            i.created_at, i.updated_at,
            (SELECT COUNT(*) FROM invoice_items ii WHERE ii.invoice_id = i.id) AS item_count,
            (SELECT COUNT(*) FROM payments p WHERE p.invoice_id = i.id) AS payment_count
        $baseQuery
        ORDER BY i.{$sortBy} {$sortOrder}
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

    $invoices = [];
    $today    = date('Y-m-d');

    while ($row = $result->fetch_assoc()) {
        $invoices[] = [
            "id"               => (int)$row['id'],
            "invoice_number"   => $row['invoice_number'],
            "proforma_id"      => $row['proforma_id'] ? (int)$row['proforma_id'] : null,
            "quotation_id"     => $row['quotation_id'] ? (int)$row['quotation_id'] : null,
            "client_id"        => (int)$row['client_id'],
            "client_name"      => $row['client_name'],
            "created_by"       => (int)$row['created_by'],
            "created_by_name"  => $row['created_by_name'],
            "approved_by"      => $row['approved_by'] ? (int)$row['approved_by'] : null,
            "approved_by_name" => $row['approved_by_name'],
            "issue_date"       => $row['issue_date'],
            "due_date"         => $row['due_date'],
            "is_overdue"       => in_array($row['status'], ['sent','partial']) && $row['due_date'] < $today,
            "days_overdue"     => (in_array($row['status'], ['sent','partial','overdue']) && $row['due_date'] < $today)
                                    ? (int)(new DateTime($today))->diff(new DateTime($row['due_date']))->days
                                    : 0,
            "currency"         => $row['currency'],
            "exchange_rate"    => (float)$row['exchange_rate'],
            "subtotal"         => (float)$row['subtotal'],
            "discount_type"    => $row['discount_type'],
            "discount_value"   => (float)$row['discount_value'],
            "discount_amount"  => (float)$row['discount_amount'],
            "taxable_amount"   => (float)$row['taxable_amount'],
            "tax_amount"       => (float)$row['tax_amount'],
            "total_amount"     => (float)$row['total_amount'],
            "amount_paid"      => (float)$row['amount_paid'],
            "balance_due"      => (float)$row['balance_due'],
            "payment_terms"    => $row['payment_terms'],
            "status"           => $row['status'],
            "stock_deducted"   => (bool)$row['stock_deducted'],
            "reminder_count"   => (int)$row['reminder_count'],
            "last_reminder_at" => $row['last_reminder_at'],
            "next_reminder_at" => $row['next_reminder_at'],
            "item_count"       => (int)$row['item_count'],
            "payment_count"    => (int)$row['payment_count'],
            "created_at"       => $row['created_at'],
            "updated_at"       => $row['updated_at']
        ];
    }
    $dataStmt->close();

    $totalPages = $total > 0 ? (int)ceil($total / $limit) : 0;

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Invoices fetched successfully.",
        "data"    => $invoices,
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
    error_log("Get Invoices Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
