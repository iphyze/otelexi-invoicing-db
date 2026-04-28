<?php
// routes/reports/salesSummary.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /reports/sales-summary
 * Aggregated sales overview for a given date range.
 * Returns totals, breakdowns by status, and a month-by-month trend line.
 * Roles allowed: Admin, Accountant
 *
 * Query params:
 *   ?from=2026-01-01  &to=2026-04-30   (defaults to current month)
 *   &currency=NGN|USD                  (defaults to NGN)
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData         = authenticateUser();
    $loggedInUserRole = $userData['role'];

    if (!in_array($loggedInUserRole, ['admin', 'accountant'])) {
        throw new Exception("Unauthorized: Only Admins or Accountants can access reports.", 403);
    }

    // -------------------------------------------------------
    // 1. Date range (default: current month)
    // -------------------------------------------------------
    $from     = isset($_GET['from']) && DateTime::createFromFormat('Y-m-d', trim($_GET['from']))
                ? trim($_GET['from']) : date('Y-m-01');
    $to       = isset($_GET['to']) && DateTime::createFromFormat('Y-m-d', trim($_GET['to']))
                ? trim($_GET['to']) : date('Y-m-t');
    $currency = isset($_GET['currency']) && in_array(strtoupper(trim($_GET['currency'])), ['NGN','USD'])
                ? strtoupper(trim($_GET['currency'])) : 'NGN';

    if ($from > $to) throw new Exception("'from' date cannot be after 'to' date.", 422);

    // -------------------------------------------------------
    // 2. Top-level invoice totals by status
    // -------------------------------------------------------
    $summaryStmt = $conn->prepare("
        SELECT
            COUNT(*)                                             AS total_invoices,
            COALESCE(SUM(total_amount), 0)                      AS gross_revenue,
            COALESCE(SUM(CASE WHEN status = 'paid'
                         THEN total_amount ELSE 0 END), 0)      AS total_paid,
            COALESCE(SUM(CASE WHEN status IN ('sent','partial','overdue')
                         THEN balance_due ELSE 0 END), 0)       AS total_outstanding,
            COALESCE(SUM(CASE WHEN status = 'partial'
                         THEN amount_paid ELSE 0 END), 0)       AS partial_payments_received,
            COALESCE(SUM(CASE WHEN status = 'cancelled'
                         THEN total_amount ELSE 0 END), 0)      AS cancelled_value,
            COALESCE(SUM(tax_amount), 0)                        AS total_vat_collected,
            COALESCE(SUM(discount_amount), 0)                   AS total_discounts_given,
            COUNT(CASE WHEN status = 'paid'     THEN 1 END)     AS count_paid,
            COUNT(CASE WHEN status = 'partial'  THEN 1 END)     AS count_partial,
            COUNT(CASE WHEN status IN ('sent','overdue') THEN 1 END) AS count_outstanding,
            COUNT(CASE WHEN status = 'overdue'  THEN 1 END)     AS count_overdue,
            COUNT(CASE WHEN status = 'cancelled'THEN 1 END)     AS count_cancelled,
            COUNT(CASE WHEN status = 'draft'    THEN 1 END)     AS count_draft
        FROM invoices
        WHERE issue_date BETWEEN ? AND ?
          AND currency = ?
    ");
    $summaryStmt->bind_param("sss", $from, $to, $currency);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();

    // -------------------------------------------------------
    // 3. Month-by-month trend (revenue + collections)
    // -------------------------------------------------------
    $trendStmt = $conn->prepare("
        SELECT
            DATE_FORMAT(issue_date, '%Y-%m')                        AS month,
            COUNT(*)                                                AS invoice_count,
            COALESCE(SUM(total_amount), 0)                          AS gross_revenue,
            COALESCE(SUM(amount_paid), 0)                           AS amount_collected,
            COALESCE(SUM(CASE WHEN status IN ('sent','partial','overdue')
                         THEN balance_due ELSE 0 END), 0)           AS outstanding
        FROM invoices
        WHERE issue_date BETWEEN ? AND ?
          AND currency = ?
          AND status != 'draft'
        GROUP BY DATE_FORMAT(issue_date, '%Y-%m')
        ORDER BY month ASC
    ");
    $trendStmt->bind_param("sss", $from, $to, $currency);
    $trendStmt->execute();
    $trendRows = $trendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $trendStmt->close();

    $trend = array_map(fn($r) => [
        "month"            => $r['month'],
        "invoice_count"    => (int)$r['invoice_count'],
        "gross_revenue"    => (float)$r['gross_revenue'],
        "amount_collected" => (float)$r['amount_collected'],
        "outstanding"      => (float)$r['outstanding']
    ], $trendRows);

    // -------------------------------------------------------
    // 4. Payments collected in range (actual cash received)
    // -------------------------------------------------------
    $paymentsStmt = $conn->prepare("
        SELECT
            COUNT(*)                    AS total_payments,
            COALESCE(SUM(p.amount), 0)  AS total_collected,
            payment_method,
            COUNT(*)                    AS method_count,
            COALESCE(SUM(p.amount), 0)  AS method_total
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        WHERE p.payment_date BETWEEN ? AND ?
          AND i.currency = ?
        GROUP BY payment_method
        ORDER BY method_total DESC
    ");
    $paymentsStmt->bind_param("sss", $from, $to, $currency);
    $paymentsStmt->execute();
    $paymentRows = $paymentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $paymentsStmt->close();

    $totalCollected      = array_sum(array_column($paymentRows, 'method_total'));
    $totalPaymentCount   = array_sum(array_column($paymentRows, 'method_count'));
    $paymentsByMethod    = array_map(fn($r) => [
        "method"  => $r['payment_method'],
        "count"   => (int)$r['method_count'],
        "total"   => (float)$r['method_total']
    ], $paymentRows);

    // -------------------------------------------------------
    // 5. Compose response
    // -------------------------------------------------------
    $grossRevenue = (float)$summary['gross_revenue'];
    $collectionRate = $grossRevenue > 0
        ? round(($totalCollected / $grossRevenue) * 100, 2)
        : 0;

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Sales summary fetched successfully.",
        "data"    => [
            "period"   => ["from" => $from, "to" => $to, "currency" => $currency],
            "invoices" => [
                "total_count"               => (int)$summary['total_invoices'],
                "gross_revenue"             => $grossRevenue,
                "total_paid"                => (float)$summary['total_paid'],
                "total_outstanding"         => (float)$summary['total_outstanding'],
                "partial_payments_received" => (float)$summary['partial_payments_received'],
                "cancelled_value"           => (float)$summary['cancelled_value'],
                "total_vat_collected"       => (float)$summary['total_vat_collected'],
                "total_discounts_given"     => (float)$summary['total_discounts_given'],
                "by_status" => [
                    "paid"        => (int)$summary['count_paid'],
                    "partial"     => (int)$summary['count_partial'],
                    "outstanding" => (int)$summary['count_outstanding'],
                    "overdue"     => (int)$summary['count_overdue'],
                    "cancelled"   => (int)$summary['count_cancelled'],
                    "draft"       => (int)$summary['count_draft']
                ]
            ],
            "collections" => [
                "total_collected"   => (float)$totalCollected,
                "total_payments"    => (int)$totalPaymentCount,
                "collection_rate"   => $collectionRate,
                "by_method"         => $paymentsByMethod
            ],
            "trend" => $trend
        ]
    ]);

} catch (Exception $e) {
    error_log("Sales Summary Report Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
