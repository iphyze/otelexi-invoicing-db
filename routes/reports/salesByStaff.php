<?php
// routes/reports/salesByStaff.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /reports/sales-by-staff
 * Breaks down invoiced revenue, collection performance, and document
 * conversion rates per Sales staff member for a given period.
 * Roles allowed: Admin, Accounting
 *
 * Query params:
 *   ?from=2026-01-01  &to=2026-04-30   (defaults to current month)
 *   &currency=NGN|USD
 *   &user_id=4                         (drill down to a single staff member)
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData         = authenticateUser();
    $loggedInUserRole = $userData['role'];

    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'accounting'])) {
        throw new Exception("Unauthorized: Only Admins or Accounting users can access reports.", 403);
    }

    // -------------------------------------------------------
    // 1. Parameters
    // -------------------------------------------------------
    $from     = isset($_GET['from']) && DateTime::createFromFormat('Y-m-d', trim($_GET['from']))
                ? trim($_GET['from']) : date('Y-m-01');
    $to       = isset($_GET['to']) && DateTime::createFromFormat('Y-m-d', trim($_GET['to']))
                ? trim($_GET['to']) : date('Y-m-t');
    $currency = isset($_GET['currency']) && in_array(strtoupper(trim($_GET['currency'])), ['NGN','USD'])
                ? strtoupper(trim($_GET['currency'])) : 'NGN';
    $userId   = isset($_GET['user_id']) && is_numeric($_GET['user_id'])
                ? (int)$_GET['user_id'] : null;

    if ($from > $to) throw new Exception("'from' date cannot be after 'to' date.", 422);

    // -------------------------------------------------------
    // 2. Per-staff invoice performance
    // -------------------------------------------------------
    $staffWhere = "WHERE i.issue_date BETWEEN ? AND ?
                     AND i.currency = ?
                     AND i.status NOT IN ('draft', 'cancelled')";
    $params = [$from, $to, $currency];
    $types  = "sss";

    if ($userId) {
        $staffWhere .= " AND u.id = ?";
        $params[]    = $userId;
        $types      .= "i";
    }

    $staffStmt = $conn->prepare("
        SELECT
            u.id                                                  AS user_id,
            u.name                                                AS staff_name,
            u.email                                               AS staff_email,
            COUNT(DISTINCT i.id)                                  AS total_invoices,
            COALESCE(SUM(i.total_amount), 0)                      AS gross_invoiced,
            COALESCE(SUM(i.amount_paid), 0)                       AS total_collected,
            COALESCE(SUM(i.balance_due), 0)                       AS total_outstanding,
            COALESCE(SUM(i.discount_amount), 0)                   AS total_discounts,
            COUNT(CASE WHEN i.status = 'paid'     THEN 1 END)     AS count_paid,
            COUNT(CASE WHEN i.status = 'partial'  THEN 1 END)     AS count_partial,
            COUNT(CASE WHEN i.status = 'overdue'  THEN 1 END)     AS count_overdue
        FROM invoices i
        JOIN users u ON u.id = i.created_by
        {$staffWhere}
        GROUP BY u.id, u.name, u.email
        ORDER BY gross_invoiced DESC
    ");
    $staffStmt->bind_param($types, ...$params);
    $staffStmt->execute();
    $staffRows = $staffStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $staffStmt->close();

    // -------------------------------------------------------
    // 3. Per-staff quotation counts (for conversion rate)
    // -------------------------------------------------------
    $quoteParams = [$from, $to];
    $quoteTypes  = "ss";
    $quoteWhere  = "WHERE q.created_at BETWEEN ? AND ?";

    if ($userId) {
        $quoteWhere   .= " AND q.created_by = ?";
        $quoteParams[] = $userId;
        $quoteTypes   .= "i";
    }

    $quoteStmt = $conn->prepare("
        SELECT
            q.created_by                                          AS user_id,
            COUNT(*)                                              AS total_quotations,
            COUNT(CASE WHEN q.status = 'accepted'   THEN 1 END)  AS accepted,
            COUNT(CASE WHEN q.status = 'converted'  THEN 1 END)  AS converted,
            COUNT(CASE WHEN q.status = 'rejected'   THEN 1 END)  AS rejected,
            COUNT(CASE WHEN q.status = 'expired'    THEN 1 END)  AS expired
        FROM quotations q
        {$quoteWhere}
        GROUP BY q.created_by
    ");
    $quoteStmt->bind_param($quoteTypes, ...$quoteParams);
    $quoteStmt->execute();
    $quoteRows = $quoteStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $quoteStmt->close();

    // Index quote stats by user_id for merging
    $quoteByUser = [];
    foreach ($quoteRows as $row) {
        $quoteByUser[(int)$row['user_id']] = $row;
    }

    // -------------------------------------------------------
    // 4. Merge and compute derived metrics
    // -------------------------------------------------------
    $staff = [];
    foreach ($staffRows as $row) {
        $uid          = (int)$row['user_id'];
        $grossInvoiced = (float)$row['gross_invoiced'];
        $collected     = (float)$row['total_collected'];

        $qData            = $quoteByUser[$uid] ?? null;
        $totalQuotes      = $qData ? (int)$qData['total_quotations'] : 0;
        $convertedQuotes  = $qData ? (int)$qData['converted'] : 0;
        $conversionRate   = $totalQuotes > 0
            ? round(($convertedQuotes / $totalQuotes) * 100, 2) : 0;
        $collectionRate   = $grossInvoiced > 0
            ? round(($collected / $grossInvoiced) * 100, 2) : 0;

        $staff[] = [
            "user_id"          => $uid,
            "staff_name"       => $row['staff_name'],
            "staff_email"      => $row['staff_email'],
            "invoices" => [
                "total"        => (int)$row['total_invoices'],
                "paid"         => (int)$row['count_paid'],
                "partial"      => (int)$row['count_partial'],
                "overdue"      => (int)$row['count_overdue']
            ],
            "financials" => [
                "gross_invoiced"    => $grossInvoiced,
                "total_collected"   => $collected,
                "total_outstanding" => (float)$row['total_outstanding'],
                "total_discounts"   => (float)$row['total_discounts'],
                "collection_rate"   => $collectionRate
            ],
            "quotations" => [
                "total"            => $totalQuotes,
                "accepted"         => $qData ? (int)$qData['accepted'] : 0,
                "converted"        => $convertedQuotes,
                "rejected"         => $qData ? (int)$qData['rejected'] : 0,
                "expired"          => $qData ? (int)$qData['expired'] : 0,
                "conversion_rate"  => $conversionRate
            ]
        ];
    }

    // -------------------------------------------------------
    // 5. Team totals
    // -------------------------------------------------------
    $teamGross     = array_sum(array_column(array_column($staff, 'financials'), 'gross_invoiced'));
    $teamCollected = array_sum(array_column(array_column($staff, 'financials'), 'total_collected'));
    $teamRate      = $teamGross > 0 ? round(($teamCollected / $teamGross) * 100, 2) : 0;

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Sales by staff report fetched successfully.",
        "data"    => [
            "period"     => ["from" => $from, "to" => $to, "currency" => $currency],
            "team_totals"=> [
                "gross_invoiced"  => $teamGross,
                "total_collected" => $teamCollected,
                "collection_rate" => $teamRate,
                "staff_count"     => count($staff)
            ],
            "staff"  => $staff
        ]
    ]);

} catch (Exception $e) {
    error_log("Sales By Staff Report Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
