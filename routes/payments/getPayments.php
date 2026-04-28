<?php
// routes/payments/getPayments.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /payments
 * List payments with filters and pagination.
 * Admin & Accountant see all; Sales sees only payments on their own invoices.
 * Roles allowed: Admin, Accountant, Sales
 *
 * Query params:
 *   ?invoice_id=7
 *   &client_id=3
 *   &payment_method=bank_transfer|cash|pos|cheque|online
 *   &from=2026-01-01  &to=2026-04-30
 *   &search=TRF/2026   (searches reference or invoice number)
 *   &page=1  &limit=20
 *   &sortBy=payment_date  &sortOrder=DESC
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData         = authenticateUser();
    $loggedInUserId   = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];

    if (!in_array($loggedInUserRole, ['admin', 'accountant', 'sales'])) {
        throw new Exception("Unauthorized: You do not have permission to view payments.", 403);
    }

    // -------------------------------------------------------
    // 1. Query parameters
    // -------------------------------------------------------
    $invoiceId     = isset($_GET['invoice_id']) && is_numeric($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : null;
    $clientId      = isset($_GET['client_id']) && is_numeric($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $paymentMethod = isset($_GET['payment_method']) ? strtolower(trim($_GET['payment_method'])) : null;
    $fromDate      = isset($_GET['from']) ? trim($_GET['from']) : null;
    $toDate        = isset($_GET['to']) ? trim($_GET['to']) : null;
    $search        = isset($_GET['search']) ? trim($_GET['search']) : null;

    $limit  = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    $allowedSortFields = ['id', 'amount', 'payment_date', 'payment_method', 'created_at'];
    $sortBy    = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields) ? $_GET['sortBy'] : 'payment_date';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC' ? 'ASC' : 'DESC';

    $allowedMethods = ['cash', 'bank_transfer', 'pos', 'cheque', 'online'];

    // -------------------------------------------------------
    // 2. Dynamic query
    // -------------------------------------------------------
    $baseQuery = "
        FROM payments p
        JOIN invoices i  ON i.id  = p.invoice_id
        JOIN clients  c  ON c.id  = i.client_id
        LEFT JOIN users u ON u.id = p.recorded_by
        WHERE 1=1
    ";
    $params = [];
    $types  = "";

    // Sales only sees payments tied to their own invoices
    if ($loggedInUserRole === 'sales') {
        $baseQuery .= " AND i.created_by = ?";
        $params[]   = $loggedInUserId;
        $types     .= "i";
    }

    if ($invoiceId) {
        $baseQuery .= " AND p.invoice_id = ?";
        $params[]   = $invoiceId;
        $types     .= "i";
    }

    if ($clientId) {
        $baseQuery .= " AND i.client_id = ?";
        $params[]   = $clientId;
        $types     .= "i";
    }

    if ($paymentMethod && in_array($paymentMethod, $allowedMethods)) {
        $baseQuery .= " AND p.payment_method = ?";
        $params[]   = $paymentMethod;
        $types     .= "s";
    }

    if ($fromDate) {
        $baseQuery .= " AND p.payment_date >= ?";
        $params[]   = $fromDate;
        $types     .= "s";
    }

    if ($toDate) {
        $baseQuery .= " AND p.payment_date <= ?";
        $params[]   = $toDate;
        $types     .= "s";
    }

    if ($search) {
        $baseQuery .= " AND (p.reference LIKE ? OR i.invoice_number LIKE ? OR c.company_name LIKE ?)";
        $like       = "%" . $search . "%";
        $params[]   = $like;
        $params[]   = $like;
        $params[]   = $like;
        $types     .= "sss";
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
            p.id, p.invoice_id, p.recorded_by,
            p.amount, p.payment_date, p.payment_method,
            p.reference, p.notes, p.created_at,
            u.name AS recorded_by_name,
            i.invoice_number, i.total_amount AS invoice_total,
            i.amount_paid AS invoice_amount_paid,
            i.balance_due AS invoice_balance_due,
            i.currency, i.status AS invoice_status,
            i.client_id,
            c.company_name AS client_name
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

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = [
            "id"                   => (int)$row['id'],
            "invoice_id"           => (int)$row['invoice_id'],
            "invoice_number"       => $row['invoice_number'],
            "invoice_status"       => $row['invoice_status'],
            "invoice_total"        => (float)$row['invoice_total'],
            "invoice_amount_paid"  => (float)$row['invoice_amount_paid'],
            "invoice_balance_due"  => (float)$row['invoice_balance_due'],
            "currency"             => $row['currency'],
            "client_id"            => (int)$row['client_id'],
            "client_name"          => $row['client_name'],
            "amount"               => (float)$row['amount'],
            "payment_date"         => $row['payment_date'],
            "payment_method"       => $row['payment_method'],
            "reference"            => $row['reference'],
            "notes"                => $row['notes'],
            "recorded_by"          => (int)$row['recorded_by'],
            "recorded_by_name"     => $row['recorded_by_name'],
            "created_at"           => $row['created_at']
        ];
    }
    $dataStmt->close();

    $totalPages = $total > 0 ? (int)ceil($total / $limit) : 0;

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Payments fetched successfully.",
        "data"    => $payments,
        "meta"    => [
            "total"          => $total,
            "total_pages"    => $totalPages,
            "limit"          => $limit,
            "page"           => $page,
            "sortBy"         => $sortBy,
            "sortOrder"      => $sortOrder,
            "search"         => $search,
            "invoice_id"     => $invoiceId,
            "client_id"      => $clientId,
            "payment_method" => $paymentMethod,
            "from"           => $fromDate,
            "to"             => $toDate
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Payments Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
