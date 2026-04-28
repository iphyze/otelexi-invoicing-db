<?php
// routes/reports/vatReport.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /reports/vat
 * VAT collected report for a given date range.
 * Breaks down taxable vs exempt sales, VAT at each rate, and monthly trend.
 * Per spec: standard VAT is 7.5% (Nigeria), some items may be exempt.
 * Roles allowed: Admin, Accountant
 *
 * Query params:
 *   ?from=2026-01-01  &to=2026-04-30   (defaults to current month)
 *   &currency=NGN|USD
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
    // 1. Parameters
    // -------------------------------------------------------
    $from     = isset($_GET['from']) && DateTime::createFromFormat('Y-m-d', trim($_GET['from']))
                ? trim($_GET['from']) : date('Y-m-01');
    $to       = isset($_GET['to']) && DateTime::createFromFormat('Y-m-d', trim($_GET['to']))
                ? trim($_GET['to']) : date('Y-m-t');
    $currency = isset($_GET['currency']) && in_array(strtoupper(trim($_GET['currency'])), ['NGN','USD'])
                ? strtoupper(trim($_GET['currency'])) : 'NGN';

    if ($from > $to) throw new Exception("'from' date cannot be after 'to' date.", 422);

    // -------------------------------------------------------
    // 2. Invoice-level VAT summary
    //    Only count non-draft, non-cancelled invoices
    // -------------------------------------------------------
    $summaryStmt = $conn->prepare("
        SELECT
            COUNT(DISTINCT i.id)                                  AS total_invoices,
            COALESCE(SUM(i.taxable_amount), 0)                    AS total_taxable_amount,
            COALESCE(SUM(i.tax_amount), 0)                        AS total_vat_collected,
            COALESCE(SUM(i.total_amount), 0)                      AS total_invoice_value,
            COALESCE(SUM(i.total_amount - i.tax_amount), 0)       AS total_net_amount
        FROM invoices i
        WHERE i.issue_date BETWEEN ? AND ?
          AND i.currency = ?
          AND i.status NOT IN ('draft', 'cancelled')
    ");
    $summaryStmt->bind_param("sss", $from, $to, $currency);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();

    // -------------------------------------------------------
    // 3. VAT breakdown by tax rate (line-item level)
    // -------------------------------------------------------
    $rateStmt = $conn->prepare("
        SELECT
            ii.tax_rate,
            COUNT(DISTINCT ii.invoice_id)          AS invoice_count,
            COALESCE(SUM(ii.line_total), 0)        AS net_sales,
            COALESCE(SUM(ii.tax_amount), 0)        AS vat_collected,
            COALESCE(SUM(ii.quantity), 0)          AS total_units,
            CASE WHEN ii.tax_rate = 0 THEN 'exempt' ELSE 'taxable' END AS tax_status
        FROM invoice_items ii
        JOIN invoices i ON i.id = ii.invoice_id
        WHERE i.issue_date BETWEEN ? AND ?
          AND i.currency = ?
          AND i.status NOT IN ('draft', 'cancelled')
        GROUP BY ii.tax_rate
        ORDER BY ii.tax_rate DESC
    ");
    $rateStmt->bind_param("sss", $from, $to, $currency);
    $rateStmt->execute();
    $rateRows = $rateStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rateStmt->close();

    $byRate = array_map(fn($r) => [
        "tax_rate"      => (float)$r['tax_rate'],
        "tax_status"    => $r['tax_status'],
        "invoice_count" => (int)$r['invoice_count'],
        "net_sales"     => (float)$r['net_sales'],
        "vat_collected" => (float)$r['vat_collected'],
        "total_units"   => (float)$r['total_units']
    ], $rateRows);

    // Taxable vs exempt totals derived from rate breakdown
    $taxableNetSales  = array_sum(array_column(
        array_filter($byRate, fn($r) => $r['tax_status'] === 'taxable'), 'net_sales'
    ));
    $exemptNetSales   = array_sum(array_column(
        array_filter($byRate, fn($r) => $r['tax_status'] === 'exempt'), 'net_sales'
    ));
    $totalNetSales    = $taxableNetSales + $exemptNetSales;
    $taxablePercent   = $totalNetSales > 0
        ? round(($taxableNetSales / $totalNetSales) * 100, 2) : 0;

    // -------------------------------------------------------
    // 4. Monthly trend
    // -------------------------------------------------------
    $trendStmt = $conn->prepare("
        SELECT
            DATE_FORMAT(i.issue_date, '%Y-%m') AS month,
            COALESCE(SUM(i.taxable_amount), 0) AS taxable_amount,
            COALESCE(SUM(i.tax_amount), 0)     AS vat_collected,
            COUNT(DISTINCT i.id)               AS invoice_count
        FROM invoices i
        WHERE i.issue_date BETWEEN ? AND ?
          AND i.currency = ?
          AND i.status NOT IN ('draft', 'cancelled')
        GROUP BY DATE_FORMAT(i.issue_date, '%Y-%m')
        ORDER BY month ASC
    ");
    $trendStmt->bind_param("sss", $from, $to, $currency);
    $trendStmt->execute();
    $trendRows = $trendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $trendStmt->close();

    $trend = array_map(fn($r) => [
        "month"          => $r['month'],
        "taxable_amount" => (float)$r['taxable_amount'],
        "vat_collected"  => (float)$r['vat_collected'],
        "invoice_count"  => (int)$r['invoice_count']
    ], $trendRows);

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "VAT report fetched successfully.",
        "data"    => [
            "period"  => ["from" => $from, "to" => $to, "currency" => $currency],
            "summary" => [
                "total_invoices"       => (int)$summary['total_invoices'],
                "total_invoice_value"  => (float)$summary['total_invoice_value'],
                "total_net_amount"     => (float)$summary['total_net_amount'],
                "total_taxable_amount" => (float)$summary['total_taxable_amount'],
                "total_vat_collected"  => (float)$summary['total_vat_collected'],
                "taxable_net_sales"    => $taxableNetSales,
                "exempt_net_sales"     => $exemptNetSales,
                "taxable_percentage"   => $taxablePercent
            ],
            "by_tax_rate" => $byRate,
            "trend"       => $trend
        ]
    ]);

} catch (Exception $e) {
    error_log("VAT Report Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
