<?php
// routes/dashboard/dashboard.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /dashboard
 * Returns everything the dashboard needs in a single request:
 *   - KPI cards (revenue, outstanding, overdue, paid invoices)
 *   - 6-month revenue + collection trend
 *   - Invoice status distribution (for pie/donut chart)
 *   - Invoice aging snapshot
 *   - Low stock alerts
 *   - Top 5 products this month
 *   - Recent activity (last 10 invoices, payments)
 *   - Unread notification count
 *   - Role-specific panels (Sales sees own; Accountant sees financials; Admin sees all)
 *
 * Roles allowed: Admin, Sales, Accountant
 *
 * Query params:
 *   ?currency=NGN|USD    (default: NGN)
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData         = authenticateUser();
    $loggedInUserId   = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];

    if (!in_array($loggedInUserRole, ['admin', 'sales', 'accountant'])) {
        throw new Exception("Unauthorized.", 403);
    }

    $currency = isset($_GET['currency']) && in_array(strtoupper(trim($_GET['currency'])), ['NGN', 'USD'])
        ? strtoupper(trim($_GET['currency'])) : 'NGN';

    $today        = date('Y-m-d');
    $monthStart   = date('Y-m-01');
    $monthEnd     = date('Y-m-t');
    $yearStart    = date('Y-01-01');

    // Scope: Sales sees their own records only
    $invoiceScope    = $loggedInUserRole === 'sales' ? "AND i.created_by = {$loggedInUserId}" : "";
    $quotationScope  = $loggedInUserRole === 'sales' ? "AND created_by = {$loggedInUserId}" : "";

    // -------------------------------------------------------
    // 1. KPI CARDS — current month
    // -------------------------------------------------------
    $kpiStmt = $conn->prepare("
        SELECT
            -- Revenue (sent + partial + paid + overdue, not draft/cancelled)
            COALESCE(SUM(CASE WHEN status NOT IN ('draft','cancelled')
                         THEN total_amount ELSE 0 END), 0)              AS gross_revenue_mtd,

            -- Cash actually collected this month via payments table
            COALESCE((
                SELECT SUM(p.amount)
                FROM payments p
                JOIN invoices pi ON pi.id = p.invoice_id
                WHERE p.payment_date BETWEEN ? AND ?
                  AND pi.currency = ?
                  {$invoiceScope}
            ), 0)                                                        AS collected_mtd,

            -- Outstanding (balance_due across all open invoices, not just this month)
            COALESCE(SUM(CASE WHEN status IN ('sent','partial','overdue')
                         THEN balance_due ELSE 0 END), 0)               AS total_outstanding,

            -- Overdue amount
            COALESCE(SUM(CASE WHEN status = 'overdue'
                         THEN balance_due ELSE 0 END), 0)               AS total_overdue,

            -- Invoice counts
            COUNT(CASE WHEN status NOT IN ('draft','cancelled')
                        AND issue_date BETWEEN ? AND ?
                        THEN 1 END)                                      AS invoices_mtd,
            COUNT(CASE WHEN status = 'paid'
                        AND issue_date BETWEEN ? AND ?
                        THEN 1 END)                                      AS paid_mtd,
            COUNT(CASE WHEN status = 'overdue'                  THEN 1 END) AS overdue_count,
            COUNT(CASE WHEN status IN ('sent','partial','overdue') THEN 1 END) AS open_count,
            COUNT(CASE WHEN status = 'draft'                    THEN 1 END) AS draft_count
        FROM invoices i
        WHERE currency = ?
        {$invoiceScope}
    ");
    // Params: monthStart, monthEnd, currency (for subquery),
    //         monthStart, monthEnd (invoices_mtd),
    //         monthStart, monthEnd (paid_mtd),
    //         currency (outer WHERE)
    $kpiStmt->bind_param(
        "ssssssss",
        $monthStart,
        $monthEnd,
        $currency,
        $monthStart,
        $monthEnd,
        $monthStart,
        $monthEnd,
        $currency
    );
    $kpiStmt->execute();
    $kpi = $kpiStmt->get_result()->fetch_assoc();
    $kpiStmt->close();

    // Quotation KPIs
    $quoteKpiStmt = $conn->prepare("
        SELECT
            COUNT(*)                                                    AS total_mtd,
            COUNT(CASE WHEN status = 'accepted'  THEN 1 END)           AS accepted_mtd,
            COUNT(CASE WHEN status = 'converted' THEN 1 END)           AS converted_mtd,
            COUNT(CASE WHEN status = 'pending'   THEN 1 END)           AS pending_mtd
        FROM quotations
        WHERE DATE(created_at) BETWEEN ? AND ?
        {$quotationScope}
    ");
    $quoteKpiStmt->bind_param("ss", $monthStart, $monthEnd);
    $quoteKpiStmt->execute();
    $quoteKpi = $quoteKpiStmt->get_result()->fetch_assoc();
    $quoteKpiStmt->close();

    // -------------------------------------------------------
    // 2. 6-MONTH REVENUE + COLLECTION TREND
    // -------------------------------------------------------
    $sixMonthsAgo = date('Y-m-01', strtotime('-5 months'));

    $trendStmt = $conn->prepare("
        SELECT
            DATE_FORMAT(i.issue_date, '%Y-%m')  AS month,
            COALESCE(SUM(i.total_amount), 0)    AS invoiced,
            COALESCE(SUM(i.amount_paid), 0)     AS collected
        FROM invoices i
        WHERE i.issue_date >= ?
          AND i.currency = ?
          AND i.status NOT IN ('draft','cancelled')
          {$invoiceScope}
        GROUP BY DATE_FORMAT(i.issue_date, '%Y-%m')
        ORDER BY month ASC
    ");
    $trendStmt->bind_param("ss", $sixMonthsAgo, $currency);
    $trendStmt->execute();
    $trendRows = $trendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $trendStmt->close();

    $revenueTrend = array_map(fn($r) => [
        "month"     => $r['month'],
        "invoiced"  => (float)$r['invoiced'],
        "collected" => (float)$r['collected']
    ], $trendRows);

    // -------------------------------------------------------
    // 3. INVOICE STATUS DISTRIBUTION (pie chart)
    // -------------------------------------------------------
    $distStmt = $conn->prepare("
        SELECT status, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS value
        FROM invoices i
        WHERE issue_date BETWEEN ? AND ?
          AND currency = ?
          {$invoiceScope}
        GROUP BY status
    ");
    $distStmt->bind_param("sss", $yearStart, $today, $currency);
    $distStmt->execute();
    $distRows = $distStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $distStmt->close();

    $statusDistribution = array_map(fn($r) => [
        "status" => $r['status'],
        "count"  => (int)$r['count'],
        "value"  => (float)$r['value']
    ], $distRows);

    // -------------------------------------------------------
    // 4. INVOICE AGING SNAPSHOT (counts + amounts by bracket)
    // -------------------------------------------------------
    $agingStmt = $conn->prepare("
        SELECT
            CASE
                WHEN DATEDIFF(?, due_date) <= 0  THEN 'current'
                WHEN DATEDIFF(?, due_date) <= 30 THEN '1_30'
                WHEN DATEDIFF(?, due_date) <= 60 THEN '31_60'
                WHEN DATEDIFF(?, due_date) <= 90 THEN '61_90'
                ELSE 'over_90'
            END                                  AS bracket,
            COUNT(*)                             AS count,
            COALESCE(SUM(balance_due), 0)        AS amount
        FROM invoices i
        WHERE status IN ('sent','partial','overdue')
          AND currency = ?
          {$invoiceScope}
        GROUP BY bracket
    ");
    $agingStmt->bind_param("sssss", $today, $today, $today, $today, $currency);
    $agingStmt->execute();
    $agingRows = $agingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $agingStmt->close();

    $agingLabels = [
        'current' => 'Current',
        '1_30'    => '1–30 days',
        '31_60'   => '31–60 days',
        '61_90'   => '61–90 days',
        'over_90' => '90+ days'
    ];
    $agingMap = [];
    foreach ($agingRows as $r) {
        $agingMap[$r['bracket']] = ['count' => (int)$r['count'], 'amount' => (float)$r['amount']];
    }
    $agingSnapshot = [];
    foreach ($agingLabels as $key => $label) {
        $agingSnapshot[] = [
            "bracket" => $key,
            "label"   => $label,
            "count"   => $agingMap[$key]['count'] ?? 0,
            "amount"  => $agingMap[$key]['amount'] ?? 0.00
        ];
    }

    // -------------------------------------------------------
    // 5. LOW STOCK ALERTS (Admin + Accountant only)
    // -------------------------------------------------------
    $lowStockAlerts = [];
    if (in_array($loggedInUserRole, ['admin', 'accountant'])) {
        $stockStmt = $conn->prepare("
            SELECT id, name, sku, stock_quantity, reorder_level,
                   CASE WHEN stock_quantity <= 0 THEN 'out_of_stock' ELSE 'low_stock' END AS alert_type
            FROM products
            WHERE 1=1
              AND is_active = 1
              AND stock_quantity <= reorder_level
            ORDER BY stock_quantity ASC
            LIMIT 10
        ");
        $stockStmt->execute();
        $stockRows = $stockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stockStmt->close();

        $lowStockAlerts = array_map(fn($r) => [
            "product_id"     => (int)$r['id'],
            "product_name"   => $r['name'],
            "sku"            => $r['sku'],
            "stock_quantity" => (float)$r['stock_quantity'],
            "reorder_level"  => (float)$r['reorder_level'],
            "alert_type"     => $r['alert_type']
        ], $stockRows);
    }

    // -------------------------------------------------------
    // 6. TOP 5 PRODUCTS THIS MONTH
    // -------------------------------------------------------
    $topProductsStmt = $conn->prepare("
        SELECT
            p.id, p.name, p.sku,
            COALESCE(SUM(ii.quantity), 0)     AS total_quantity,
            COALESCE(SUM(ii.line_total), 0)   AS total_revenue
        FROM invoice_items ii
        JOIN invoices i  ON i.id  = ii.invoice_id
        JOIN products p  ON p.id  = ii.product_id
        WHERE i.issue_date BETWEEN ? AND ?
          AND i.currency = ?
          AND i.status NOT IN ('draft','cancelled')
          AND ii.product_id IS NOT NULL
          {$invoiceScope}
        GROUP BY p.id, p.name, p.sku
        ORDER BY total_revenue DESC
        LIMIT 5
    ");
    $topProductsStmt->bind_param("sss", $monthStart, $monthEnd, $currency);
    $topProductsStmt->execute();
    $topProductRows = $topProductsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $topProductsStmt->close();

    $topProducts = array_map(fn($r) => [
        "product_id"     => (int)$r['id'],
        "product_name"   => $r['name'],
        "sku"            => $r['sku'],
        "total_quantity" => (float)$r['total_quantity'],
        "total_revenue"  => (float)$r['total_revenue']
    ], $topProductRows);

    // -------------------------------------------------------
    // 7. RECENT INVOICES (last 8)
    // -------------------------------------------------------
    $recentInvoicesStmt = $conn->prepare("
        SELECT i.id, i.invoice_number, i.issue_date, i.due_date,
               i.total_amount, i.balance_due, i.status, i.currency,
               c.company_name AS client_name
        FROM invoices i
        JOIN clients c ON c.id = i.client_id
        WHERE i.currency = ?
          {$invoiceScope}
        ORDER BY i.created_at DESC
        LIMIT 8
    ");
    $recentInvoicesStmt->bind_param("s", $currency);
    $recentInvoicesStmt->execute();
    $recentInvoiceRows = $recentInvoicesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentInvoicesStmt->close();

    $recentInvoices = array_map(fn($r) => [
        "id"             => (int)$r['id'],
        "invoice_number" => $r['invoice_number'],
        "client_name"    => $r['client_name'],
        "issue_date"     => $r['issue_date'],
        "due_date"       => $r['due_date'],
        "total_amount"   => (float)$r['total_amount'],
        "balance_due"    => (float)$r['balance_due'],
        "status"         => $r['status'],
        "currency"       => $r['currency'],
        "is_overdue"     => in_array($r['status'], ['sent', 'partial']) && $r['due_date'] < $today
    ], $recentInvoiceRows);

    // -------------------------------------------------------
    // 8. RECENT PAYMENTS (last 8)
    // -------------------------------------------------------
    $recentPaymentsStmt = $conn->prepare("
        SELECT p.id, p.amount, p.payment_date, p.payment_method, p.reference,
               i.invoice_number, i.currency,
               c.company_name AS client_name
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        JOIN clients  c ON c.id = i.client_id
        WHERE i.currency = ?
          {$invoiceScope}
        ORDER BY p.created_at DESC
        LIMIT 8
    ");
    $recentPaymentsStmt->bind_param("s", $currency);
    $recentPaymentsStmt->execute();
    $recentPaymentRows = $recentPaymentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentPaymentsStmt->close();

    $recentPayments = array_map(fn($r) => [
        "id"             => (int)$r['id'],
        "amount"         => (float)$r['amount'],
        "payment_date"   => $r['payment_date'],
        "payment_method" => $r['payment_method'],
        "reference"      => $r['reference'],
        "invoice_number" => $r['invoice_number'],
        "client_name"    => $r['client_name'],
        "currency"       => $r['currency']
    ], $recentPaymentRows);

    // -------------------------------------------------------
    // 9. UNREAD NOTIFICATION COUNT
    // -------------------------------------------------------
    $notifStmt = $conn->prepare("
        SELECT COUNT(*) AS unread_count
        FROM notifications
        WHERE user_id = ? AND is_read = 0
    ");
    $notifStmt->bind_param("i", $loggedInUserId);
    $notifStmt->execute();
    $unreadNotifications = (int)$notifStmt->get_result()->fetch_assoc()['unread_count'];
    $notifStmt->close();

    // -------------------------------------------------------
    // 10. Compose and return
    // -------------------------------------------------------
    $grossRevenueMtd = (float)$kpi['gross_revenue_mtd'];
    $collectedMtd    = (float)$kpi['collected_mtd'];
    $collectionRate  = $grossRevenueMtd > 0
        ? round(($collectedMtd / $grossRevenueMtd) * 100, 2) : 0;

    http_response_code(200);
    echo json_encode([
        "status"   => "success",
        "message"  => "Dashboard data fetched successfully.",
        "data"     => [
            "meta" => [
                "generated_at"         => date('Y-m-d H:i:s'),
                "currency"             => $currency,
                "period_month"         => date('Y-m'),
                "unread_notifications" => $unreadNotifications
            ],

            "kpis" => [
                "revenue" => [
                    "gross_invoiced_mtd"  => $grossRevenueMtd,
                    "collected_mtd"       => $collectedMtd,
                    "collection_rate"     => $collectionRate,
                    "total_outstanding"   => (float)$kpi['total_outstanding'],
                    "total_overdue"       => (float)$kpi['total_overdue']
                ],
                "invoices" => [
                    "total_mtd"           => (int)$kpi['invoices_mtd'],
                    "paid_mtd"            => (int)$kpi['paid_mtd'],
                    "open_count"          => (int)$kpi['open_count'],
                    "overdue_count"       => (int)$kpi['overdue_count'],
                    "draft_count"         => (int)$kpi['draft_count']
                ],
                "quotations" => [
                    "total_mtd"           => (int)$quoteKpi['total_mtd'],
                    "accepted_mtd"        => (int)$quoteKpi['accepted_mtd'],
                    "converted_mtd"       => (int)$quoteKpi['converted_mtd'],
                    "pending_mtd"         => (int)$quoteKpi['pending_mtd']
                ]
            ],

            "charts" => [
                "revenue_trend"        => $revenueTrend,
                "status_distribution"  => $statusDistribution,
                "invoice_aging"        => $agingSnapshot
            ],

            "lists" => [
                "top_products"    => $topProducts,
                "recent_invoices" => $recentInvoices,
                "recent_payments" => $recentPayments,
                "low_stock_alerts" => $lowStockAlerts
            ]
        ]
    ]);
} catch (Exception $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
