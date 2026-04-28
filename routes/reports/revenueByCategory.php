<?php
// routes/reports/revenueByCategory.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /reports/revenue-by-category
 * Breaks down invoice revenue by product category for a given period.
 * Includes month-by-month trend per category.
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
    // 2. Revenue by category
    // -------------------------------------------------------
    $catStmt = $conn->prepare("
        SELECT
            pc.id                                      AS category_id,
            pc.name                                    AS category_name,
            COUNT(DISTINCT ii.invoice_id)              AS invoice_count,
            COUNT(DISTINCT ii.product_id)              AS product_count,
            COALESCE(SUM(ii.quantity), 0)              AS total_quantity,
            COALESCE(SUM(ii.line_total), 0)            AS total_revenue,
            COALESCE(SUM(ii.tax_amount), 0)            AS total_vat,
            COALESCE(SUM(ii.discount_amount), 0)       AS total_discounts
        FROM invoice_items ii
        JOIN invoices i             ON i.id  = ii.invoice_id
        JOIN products p             ON p.id  = ii.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE i.issue_date BETWEEN ? AND ?
          AND i.currency = ?
          AND i.status NOT IN ('draft', 'cancelled')
          AND ii.product_id IS NOT NULL
        GROUP BY pc.id, pc.name
        ORDER BY total_revenue DESC
    ");
    $catStmt->bind_param("sss", $from, $to, $currency);
    $catStmt->execute();
    $catRows = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $catStmt->close();

    $totalRevenue = array_sum(array_column($catRows, 'total_revenue'));

    $categories = array_map(function($r) use ($totalRevenue) {
        $rev = (float)$r['total_revenue'];
        return [
            "category_id"     => $r['category_id'] ? (int)$r['category_id'] : null,
            "category_name"   => $r['category_name'] ?? 'Uncategorised',
            "invoice_count"   => (int)$r['invoice_count'],
            "product_count"   => (int)$r['product_count'],
            "total_quantity"  => (float)$r['total_quantity'],
            "total_revenue"   => $rev,
            "total_vat"       => (float)$r['total_vat'],
            "total_discounts" => (float)$r['total_discounts'],
            "revenue_share"   => $totalRevenue > 0 ? round(($rev / $totalRevenue) * 100, 2) : 0
        ];
    }, $catRows);

    // -------------------------------------------------------
    // 3. Month-by-month trend per category
    // -------------------------------------------------------
    $trendStmt = $conn->prepare("
        SELECT
            DATE_FORMAT(i.issue_date, '%Y-%m')  AS month,
            COALESCE(pc.name, 'Uncategorised')  AS category_name,
            COALESCE(SUM(ii.line_total), 0)     AS revenue
        FROM invoice_items ii
        JOIN invoices i             ON i.id  = ii.invoice_id
        JOIN products p             ON p.id  = ii.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE i.issue_date BETWEEN ? AND ?
          AND i.currency = ?
          AND i.status NOT IN ('draft', 'cancelled')
          AND ii.product_id IS NOT NULL
        GROUP BY month, category_name
        ORDER BY month ASC, revenue DESC
    ");
    $trendStmt->bind_param("sss", $from, $to, $currency);
    $trendStmt->execute();
    $trendRows = $trendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $trendStmt->close();

    // Pivot trend into { month: { category: revenue } } structure
    $trendPivot = [];
    foreach ($trendRows as $row) {
        $month    = $row['month'];
        $category = $row['category_name'];
        if (!isset($trendPivot[$month])) {
            $trendPivot[$month] = ["month" => $month];
        }
        $trendPivot[$month][$category] = (float)$row['revenue'];
    }
    $trend = array_values($trendPivot);

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Revenue by category report fetched successfully.",
        "data"    => [
            "period"        => ["from" => $from, "to" => $to, "currency" => $currency],
            "total_revenue" => round($totalRevenue, 2),
            "categories"    => $categories,
            "trend"         => $trend
        ]
    ]);

} catch (Exception $e) {
    error_log("Revenue By Category Report Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
