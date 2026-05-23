<?php
// routes/dashboard/dashboard.php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

/**
 * Return the current dashboard comparison range.
 * The selected period is compared with the same elapsed number of days in the
 * immediately preceding month, quarter or year.
 */
function dashboardPeriodRange(string $period, DateTimeImmutable $today): array
{
    $year = (int) $today->format('Y');
    $month = (int) $today->format('n');

    switch ($period) {
        case 'quarter':
            $quarterStartMonth = ((int) floor(($month - 1) / 3) * 3) + 1;
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $quarterStartMonth));
            $previousStart = $start->modify('-3 months');
            $label = 'This Quarter';
            break;

        case 'year':
            $start = new DateTimeImmutable(sprintf('%04d-01-01', $year));
            $previousStart = $start->modify('-1 year');
            $label = 'Year to Date';
            break;

        case 'month':
        default:
            $period = 'month';
            $start = new DateTimeImmutable($today->format('Y-m-01'));
            $previousStart = $start->modify('-1 month');
            $label = 'This Month';
            break;
    }

    $elapsedDays = (int) $start->diff($today)->format('%a');
    $previousNaturalEnd = $start->modify('-1 day');
    $previousEnd = $previousStart->modify("+{$elapsedDays} days");

    if ($previousEnd > $previousNaturalEnd) {
        $previousEnd = $previousNaturalEnd;
    }

    return [
        'key' => $period,
        'label' => $label,
        'from' => $start->format('Y-m-d'),
        'to' => $today->format('Y-m-d'),
        'previous_from' => $previousStart->format('Y-m-d'),
        'previous_to' => $previousEnd->format('Y-m-d'),
    ];
}

function dashboardPercentageChange(float $current, float $previous): ?float
{
    if ($previous === 0.0) {
        return $current === 0.0 ? 0.0 : null;
    }

    return round((($current - $previous) / $previous) * 100, 1);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) $userData['id'];
    $loggedInUserRole = (string) $userData['role'];

    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales', 'accounting'], true)) {
        throw new Exception('Unauthorized.', 403);
    }

    $requestedCurrency = strtoupper(trim((string) ($_GET['currency'] ?? 'NGN')));
    $currency = in_array($requestedCurrency, ['NGN', 'USD'], true) ? $requestedCurrency : 'NGN';

    $requestedPeriod = strtolower(trim((string) ($_GET['period'] ?? 'month')));
    $period = in_array($requestedPeriod, ['month', 'quarter', 'year'], true)
        ? $requestedPeriod
        : 'month';

    $todayDate = new DateTimeImmutable('today');
    $today = $todayDate->format('Y-m-d');
    $range = dashboardPeriodRange($period, $todayDate);
    $upcomingDate = $todayDate->modify('+7 days')->format('Y-m-d');

    /*
     * Sales staff only see their own commercial documents and payments.
     * The ID is cast to integer before interpolation, so these fixed scopes are safe.
     */
    $isSales = $loggedInUserRole === 'sales';
    $invoiceScope = $isSales ? " AND i.created_by = {$loggedInUserId}" : '';
    $quotationScope = $isSales ? " AND q.created_by = {$loggedInUserId}" : '';
    $proformaScope = $isSales ? " AND pi.created_by = {$loggedInUserId}" : '';

    // -------------------------------------------------------
    // 1. Invoice totals for selected period and comparison period
    // -------------------------------------------------------
    $invoicePeriodSql = "
        SELECT
            COALESCE(SUM(CASE WHEN i.status NOT IN ('draft', 'cancelled', 'reversed') THEN (i.total_amount - COALESCE(i.credited_amount, 0)) ELSE 0 END), 0) AS invoiced,
            COALESCE(SUM(CASE WHEN i.status NOT IN ('draft', 'cancelled', 'reversed') THEN i.tax_amount ELSE 0 END), 0) AS vat,
            COALESCE(SUM(CASE WHEN i.status NOT IN ('draft', 'cancelled', 'reversed') THEN i.discount_amount ELSE 0 END), 0) AS discounts,
            COUNT(CASE WHEN i.status NOT IN ('draft', 'cancelled', 'reversed') THEN 1 END) AS issued_count,
            COUNT(CASE WHEN i.status = 'paid' THEN 1 END) AS paid_count,
            COUNT(CASE WHEN i.status = 'draft' THEN 1 END) AS draft_count
        FROM invoices i
        WHERE i.issue_date BETWEEN ? AND ?
          AND i.currency = ?
          {$invoiceScope}
    ";

    $periodInvoiceStmt = $conn->prepare($invoicePeriodSql);
    $periodInvoiceStmt->bind_param('sss', $range['from'], $range['to'], $currency);
    $periodInvoiceStmt->execute();
    $periodInvoices = $periodInvoiceStmt->get_result()->fetch_assoc();
    $periodInvoiceStmt->close();

    $previousInvoiceStmt = $conn->prepare($invoicePeriodSql);
    $previousInvoiceStmt->bind_param('sss', $range['previous_from'], $range['previous_to'], $currency);
    $previousInvoiceStmt->execute();
    $previousInvoices = $previousInvoiceStmt->get_result()->fetch_assoc();
    $previousInvoiceStmt->close();

    $paymentPeriodSql = "
        SELECT COUNT(*) AS payment_count, COALESCE(SUM(p.amount), 0) AS collected
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        WHERE p.payment_date BETWEEN ? AND ?
          AND i.currency = ?
          {$invoiceScope}
    ";

    $periodPaymentStmt = $conn->prepare($paymentPeriodSql);
    $periodPaymentStmt->bind_param('sss', $range['from'], $range['to'], $currency);
    $periodPaymentStmt->execute();
    $periodPayments = $periodPaymentStmt->get_result()->fetch_assoc();
    $periodPaymentStmt->close();

    $previousPaymentStmt = $conn->prepare($paymentPeriodSql);
    $previousPaymentStmt->bind_param('sss', $range['previous_from'], $range['previous_to'], $currency);
    $previousPaymentStmt->execute();
    $previousPayments = $previousPaymentStmt->get_result()->fetch_assoc();
    $previousPaymentStmt->close();

    // -------------------------------------------------------
    // 2. Live receivables snapshot (based on due date, not only saved status)
    // -------------------------------------------------------
    $receivableStmt = $conn->prepare("
        SELECT
            COUNT(CASE WHEN i.status IN ('sent', 'partial', 'overdue') AND i.balance_due > 0 THEN 1 END) AS open_count,
            COALESCE(SUM(CASE WHEN i.status IN ('sent', 'partial', 'overdue') AND i.balance_due > 0 THEN i.balance_due ELSE 0 END), 0) AS outstanding,
            COUNT(CASE WHEN i.status IN ('sent', 'partial', 'overdue') AND i.balance_due > 0 AND i.due_date < ? THEN 1 END) AS overdue_count,
            COALESCE(SUM(CASE WHEN i.status IN ('sent', 'partial', 'overdue') AND i.balance_due > 0 AND i.due_date < ? THEN i.balance_due ELSE 0 END), 0) AS overdue_amount
        FROM invoices i
        WHERE i.currency = ?
          {$invoiceScope}
    ");
    $receivableStmt->bind_param('sss', $today, $today, $currency);
    $receivableStmt->execute();
    $receivables = $receivableStmt->get_result()->fetch_assoc();
    $receivableStmt->close();

    // -------------------------------------------------------
    // 3. Document pipeline for the selected period
    // -------------------------------------------------------
    $quotationStmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            COUNT(CASE WHEN q.status = 'sent' THEN 1 END) AS awaiting,
            COUNT(CASE WHEN q.status = 'accepted' THEN 1 END) AS accepted,
            COUNT(CASE WHEN q.status = 'converted' THEN 1 END) AS converted
        FROM quotations q
        WHERE q.issue_date BETWEEN ? AND ?
          AND q.currency = ?
          {$quotationScope}
    ");
    $quotationStmt->bind_param('sss', $range['from'], $range['to'], $currency);
    $quotationStmt->execute();
    $quotationPipeline = $quotationStmt->get_result()->fetch_assoc();
    $quotationStmt->close();

    $proformaStmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            COUNT(CASE WHEN pi.status = 'sent' THEN 1 END) AS awaiting,
            COUNT(CASE WHEN pi.status = 'approved' THEN 1 END) AS approved,
            COUNT(CASE WHEN pi.status = 'converted' THEN 1 END) AS converted
        FROM proforma_invoices pi
        WHERE pi.issue_date BETWEEN ? AND ?
          AND pi.currency = ?
          {$proformaScope}
    ");
    $proformaStmt->bind_param('sss', $range['from'], $range['to'], $currency);
    $proformaStmt->execute();
    $proformaPipeline = $proformaStmt->get_result()->fetch_assoc();
    $proformaStmt->close();

    // -------------------------------------------------------
    // 4. Revenue and actual collection trend
    // -------------------------------------------------------
    $trendMonths = $period === 'year' ? 12 : 6;
    $trendStartDate = (new DateTimeImmutable($todayDate->format('Y-m-01')))
        ->modify('-' . ($trendMonths - 1) . ' months');
    $trendStart = $trendStartDate->format('Y-m-d');

    $invoiceTrendStmt = $conn->prepare("
        SELECT DATE_FORMAT(i.issue_date, '%Y-%m') AS month,
               COALESCE(SUM(i.total_amount - COALESCE(i.credited_amount, 0)), 0) AS invoiced
        FROM invoices i
        WHERE i.issue_date BETWEEN ? AND ?
          AND i.currency = ?
          AND i.status NOT IN ('draft', 'cancelled', 'reversed')
          {$invoiceScope}
        GROUP BY DATE_FORMAT(i.issue_date, '%Y-%m')
    ");
    $invoiceTrendStmt->bind_param('sss', $trendStart, $today, $currency);
    $invoiceTrendStmt->execute();
    $invoiceTrendRows = $invoiceTrendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $invoiceTrendStmt->close();

    $collectionTrendStmt = $conn->prepare("
        SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS month,
               COALESCE(SUM(p.amount), 0) AS collected
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        WHERE p.payment_date BETWEEN ? AND ?
          AND i.currency = ?
          {$invoiceScope}
        GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
    ");
    $collectionTrendStmt->bind_param('sss', $trendStart, $today, $currency);
    $collectionTrendStmt->execute();
    $collectionTrendRows = $collectionTrendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $collectionTrendStmt->close();

    $invoiceTrendMap = [];
    foreach ($invoiceTrendRows as $row) {
        $invoiceTrendMap[$row['month']] = (float) $row['invoiced'];
    }

    $collectionTrendMap = [];
    foreach ($collectionTrendRows as $row) {
        $collectionTrendMap[$row['month']] = (float) $row['collected'];
    }

    $revenueTrend = [];
    for ($index = 0; $index < $trendMonths; $index++) {
        $trendDate = $trendStartDate->modify("+{$index} months");
        $key = $trendDate->format('Y-m');
        $revenueTrend[] = [
            'month' => $key,
            'label' => $trendDate->format('M'),
            'invoiced' => $invoiceTrendMap[$key] ?? 0.00,
            'collected' => $collectionTrendMap[$key] ?? 0.00,
        ];
    }

    // -------------------------------------------------------
    // 5. Effective status distribution for the selected period
    // -------------------------------------------------------
    $statusStmt = $conn->prepare("
        SELECT
            CASE
                WHEN i.status IN ('sent', 'partial') AND i.balance_due > 0 AND i.due_date < ? THEN 'overdue'
                ELSE i.status
            END AS effective_status,
            COUNT(*) AS count,
            COALESCE(SUM(i.total_amount - COALESCE(i.credited_amount, 0)), 0) AS amount
        FROM invoices i
        WHERE i.issue_date BETWEEN ? AND ?
          AND i.currency = ?
          {$invoiceScope}
        GROUP BY effective_status
    ");
    $statusStmt->bind_param('ssss', $today, $range['from'], $range['to'], $currency);
    $statusStmt->execute();
    $statusRows = $statusStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $statusStmt->close();

    $statusBase = [
        'draft' => ['label' => 'Draft', 'count' => 0, 'amount' => 0.00],
        'sent' => ['label' => 'Sent', 'count' => 0, 'amount' => 0.00],
        'partial' => ['label' => 'Partial', 'count' => 0, 'amount' => 0.00],
        'paid' => ['label' => 'Paid', 'count' => 0, 'amount' => 0.00],
        'overdue' => ['label' => 'Overdue', 'count' => 0, 'amount' => 0.00],
        'cancelled' => ['label' => 'Cancelled', 'count' => 0, 'amount' => 0.00],
    ];
    foreach ($statusRows as $row) {
        $key = (string) $row['effective_status'];
        if (isset($statusBase[$key])) {
            $statusBase[$key]['count'] = (int) $row['count'];
            $statusBase[$key]['amount'] = (float) $row['amount'];
        }
    }

    $statusDistribution = [];
    foreach ($statusBase as $key => $item) {
        $statusDistribution[] = [
            'status' => $key,
            'label' => $item['label'],
            'count' => $item['count'],
            'amount' => $item['amount'],
        ];
    }

    // -------------------------------------------------------
    // 6. Invoice aging snapshot
    // -------------------------------------------------------
    $agingStmt = $conn->prepare("
        SELECT
            CASE
                WHEN DATEDIFF(?, i.due_date) <= 0 THEN 'current'
                WHEN DATEDIFF(?, i.due_date) <= 30 THEN '1_30'
                WHEN DATEDIFF(?, i.due_date) <= 60 THEN '31_60'
                WHEN DATEDIFF(?, i.due_date) <= 90 THEN '61_90'
                ELSE 'over_90'
            END AS bracket,
            COUNT(*) AS count,
            COALESCE(SUM(i.balance_due), 0) AS amount
        FROM invoices i
        WHERE i.status IN ('sent', 'partial', 'overdue')
          AND i.balance_due > 0
          AND i.currency = ?
          {$invoiceScope}
        GROUP BY bracket
    ");
    $agingStmt->bind_param('sssss', $today, $today, $today, $today, $currency);
    $agingStmt->execute();
    $agingRows = $agingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $agingStmt->close();

    $agingMap = [];
    foreach ($agingRows as $row) {
        $agingMap[$row['bracket']] = [
            'count' => (int) $row['count'],
            'amount' => (float) $row['amount'],
        ];
    }
    $agingLabels = [
        'current' => 'Current',
        '1_30' => '1–30 days',
        '31_60' => '31–60 days',
        '61_90' => '61–90 days',
        'over_90' => '90+ days',
    ];
    $agingSnapshot = [];
    foreach ($agingLabels as $key => $label) {
        $agingSnapshot[] = [
            'bracket' => $key,
            'label' => $label,
            'count' => $agingMap[$key]['count'] ?? 0,
            'amount' => $agingMap[$key]['amount'] ?? 0.00,
        ];
    }

    // -------------------------------------------------------
    // 7. Payment methods within the selected period
    // -------------------------------------------------------
    $methodStmt = $conn->prepare("
        SELECT p.payment_method, COUNT(*) AS count, COALESCE(SUM(p.amount), 0) AS amount
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        WHERE p.payment_date BETWEEN ? AND ?
          AND i.currency = ?
          {$invoiceScope}
        GROUP BY p.payment_method
        ORDER BY amount DESC
    ");
    $methodStmt->bind_param('sss', $range['from'], $range['to'], $currency);
    $methodStmt->execute();
    $methodRows = $methodStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $methodStmt->close();

    $paymentMethods = array_map(static fn(array $row): array => [
        'method' => $row['payment_method'],
        'count' => (int) $row['count'],
        'amount' => (float) $row['amount'],
    ], $methodRows);

    // -------------------------------------------------------
    // 8. Top products, recent transactions and stock alerts
    // -------------------------------------------------------
    $topProductsStmt = $conn->prepare("
        SELECT p.id, p.name, p.sku,
               COALESCE(SUM(ii.quantity), 0) AS total_quantity,
               COALESCE(SUM(ii.line_total), 0) AS total_revenue
        FROM invoice_items ii
        JOIN invoices i ON i.id = ii.invoice_id
        JOIN products p ON p.id = ii.product_id
        WHERE i.issue_date BETWEEN ? AND ?
          AND i.currency = ?
          AND i.status NOT IN ('draft', 'cancelled', 'reversed')
          {$invoiceScope}
        GROUP BY p.id, p.name, p.sku
        ORDER BY total_revenue DESC
        LIMIT 5
    ");
    $topProductsStmt->bind_param('sss', $range['from'], $range['to'], $currency);
    $topProductsStmt->execute();
    $topProductRows = $topProductsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $topProductsStmt->close();
    $topProducts = array_map(static fn(array $row): array => [
        'product_id' => (int) $row['id'],
        'product_name' => $row['name'],
        'sku' => $row['sku'],
        'total_quantity' => (float) $row['total_quantity'],
        'total_revenue' => (float) $row['total_revenue'],
    ], $topProductRows);

    $recentInvoiceStmt = $conn->prepare("
        SELECT i.id, i.invoice_number, i.issue_date, i.due_date, i.total_amount,
               i.balance_due, i.status, i.currency, c.company_name AS client_name
        FROM invoices i
        JOIN clients c ON c.id = i.client_id
        WHERE i.currency = ?
          {$invoiceScope}
        ORDER BY i.created_at DESC
        LIMIT 6
    ");
    $recentInvoiceStmt->bind_param('s', $currency);
    $recentInvoiceStmt->execute();
    $recentInvoiceRows = $recentInvoiceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentInvoiceStmt->close();
    $recentInvoices = array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'invoice_number' => $row['invoice_number'],
        'client_name' => $row['client_name'],
        'issue_date' => $row['issue_date'],
        'due_date' => $row['due_date'],
        'total_amount' => (float) $row['total_amount'],
        'balance_due' => (float) $row['balance_due'],
        'status' => $row['status'],
        'currency' => $row['currency'],
        'is_overdue' => in_array($row['status'], ['sent', 'partial', 'overdue'], true)
            && (float) $row['balance_due'] > 0
            && $row['due_date'] < date('Y-m-d'),
    ], $recentInvoiceRows);

    $recentPaymentStmt = $conn->prepare("
        SELECT p.id, p.amount, p.payment_date, p.payment_method, p.reference,
               i.id AS invoice_id, i.invoice_number, i.currency,
               c.company_name AS client_name
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        JOIN clients c ON c.id = i.client_id
        WHERE i.currency = ?
          {$invoiceScope}
        ORDER BY p.created_at DESC
        LIMIT 6
    ");
    $recentPaymentStmt->bind_param('s', $currency);
    $recentPaymentStmt->execute();
    $recentPaymentRows = $recentPaymentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentPaymentStmt->close();
    $recentPayments = array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'invoice_id' => (int) $row['invoice_id'],
        'amount' => (float) $row['amount'],
        'payment_date' => $row['payment_date'],
        'payment_method' => $row['payment_method'],
        'reference' => $row['reference'],
        'invoice_number' => $row['invoice_number'],
        'client_name' => $row['client_name'],
        'currency' => $row['currency'],
    ], $recentPaymentRows);

    $lowStockAlerts = [];
    $lowStockCount = 0;
    if (in_array($loggedInUserRole, ['super_admin', 'admin', 'accounting'], true)) {
        $lowStockCountStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM products
            WHERE is_active = 1 AND stock_quantity <= reorder_level
        ");
        $lowStockCountStmt->execute();
        $lowStockCount = (int) $lowStockCountStmt->get_result()->fetch_assoc()['total'];
        $lowStockCountStmt->close();

        $stockStmt = $conn->prepare("
            SELECT id, name, sku, stock_quantity, reorder_level,
                   CASE WHEN stock_quantity <= 0 THEN 'out_of_stock' ELSE 'low_stock' END AS alert_type
            FROM products
            WHERE is_active = 1 AND stock_quantity <= reorder_level
            ORDER BY stock_quantity ASC, name ASC
            LIMIT 5
        ");
        $stockStmt->execute();
        $stockRows = $stockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stockStmt->close();
        $lowStockAlerts = array_map(static fn(array $row): array => [
            'product_id' => (int) $row['id'],
            'product_name' => $row['name'],
            'sku' => $row['sku'],
            'stock_quantity' => (float) $row['stock_quantity'],
            'reorder_level' => (float) $row['reorder_level'],
            'alert_type' => $row['alert_type'],
        ], $stockRows);
    }

    // -------------------------------------------------------
    // 9. Items requiring follow-up
    // -------------------------------------------------------
    $overdueStmt = $conn->prepare("
        SELECT i.id, i.invoice_number, i.due_date, i.balance_due, i.currency,
               DATEDIFF(?, i.due_date) AS days_overdue,
               c.company_name AS client_name
        FROM invoices i
        JOIN clients c ON c.id = i.client_id
        WHERE i.status IN ('sent', 'partial', 'overdue')
          AND i.balance_due > 0
          AND i.due_date < ?
          AND i.currency = ?
          {$invoiceScope}
        ORDER BY i.due_date ASC
        LIMIT 5
    ");
    $overdueStmt->bind_param('sss', $today, $today, $currency);
    $overdueStmt->execute();
    $overdueRows = $overdueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $overdueStmt->close();
    $overdueInvoices = array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'invoice_number' => $row['invoice_number'],
        'client_name' => $row['client_name'],
        'due_date' => $row['due_date'],
        'days_overdue' => (int) $row['days_overdue'],
        'balance_due' => (float) $row['balance_due'],
        'currency' => $row['currency'],
    ], $overdueRows);

    $quoteExpiryStmt = $conn->prepare("
        SELECT q.id, q.quotation_number, q.expiry_date, q.total_amount, q.currency,
               c.company_name AS client_name
        FROM quotations q
        JOIN clients c ON c.id = q.client_id
        WHERE q.status IN ('sent', 'accepted')
          AND q.expiry_date BETWEEN ? AND ?
          AND q.currency = ?
          {$quotationScope}
        ORDER BY q.expiry_date ASC
        LIMIT 5
    ");
    $quoteExpiryStmt->bind_param('sss', $today, $upcomingDate, $currency);
    $quoteExpiryStmt->execute();
    $expiringQuoteRows = $quoteExpiryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $quoteExpiryStmt->close();
    $expiringQuotations = array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'number' => $row['quotation_number'],
        'client_name' => $row['client_name'],
        'expiry_date' => $row['expiry_date'],
        'total_amount' => (float) $row['total_amount'],
        'currency' => $row['currency'],
    ], $expiringQuoteRows);

    $proformaExpiryStmt = $conn->prepare("
        SELECT pi.id, pi.proforma_number, pi.expiry_date, pi.total_amount, pi.currency,
               c.company_name AS client_name
        FROM proforma_invoices pi
        JOIN clients c ON c.id = pi.client_id
        WHERE pi.status IN ('sent', 'approved')
          AND pi.expiry_date BETWEEN ? AND ?
          AND pi.currency = ?
          {$proformaScope}
        ORDER BY pi.expiry_date ASC
        LIMIT 5
    ");
    $proformaExpiryStmt->bind_param('sss', $today, $upcomingDate, $currency);
    $proformaExpiryStmt->execute();
    $expiringProformaRows = $proformaExpiryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $proformaExpiryStmt->close();
    $expiringProformas = array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'number' => $row['proforma_number'],
        'client_name' => $row['client_name'],
        'expiry_date' => $row['expiry_date'],
        'total_amount' => (float) $row['total_amount'],
        'currency' => $row['currency'],
    ], $expiringProformaRows);

    // -------------------------------------------------------
    // 10. Header information
    // -------------------------------------------------------
    $notificationStmt = $conn->prepare('SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = 0');
    $notificationStmt->bind_param('i', $loggedInUserId);
    $notificationStmt->execute();
    $unreadNotifications = (int) $notificationStmt->get_result()->fetch_assoc()['unread_count'];
    $notificationStmt->close();

    $grossInvoiced = (float) $periodInvoices['invoiced'];
    $previousGrossInvoiced = (float) $previousInvoices['invoiced'];
    $collected = (float) $periodPayments['collected'];
    $previousCollected = (float) $previousPayments['collected'];
    $outstanding = (float) $receivables['outstanding'];
    $overdueAmount = (float) $receivables['overdue_amount'];

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Dashboard data fetched successfully.',
        'data' => [
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'currency' => $currency,
                'period' => $range,
                'scope_label' => $isSales ? 'Your sales activity' : 'Company overview',
                'unread_notifications' => $unreadNotifications,
                'can_view_financial_reports' => in_array($loggedInUserRole, ['super_admin', 'admin', 'accounting'], true),
            ],
            'kpis' => [
                'revenue' => [
                    'gross_invoiced' => $grossInvoiced,
                    'previous_gross_invoiced' => $previousGrossInvoiced,
                    'invoiced_change_percent' => dashboardPercentageChange($grossInvoiced, $previousGrossInvoiced),
                    'collected' => $collected,
                    'previous_collected' => $previousCollected,
                    'collected_change_percent' => dashboardPercentageChange($collected, $previousCollected),
                    'collection_rate' => $grossInvoiced > 0 ? round(($collected / $grossInvoiced) * 100, 1) : 0.0,
                    'total_outstanding' => $outstanding,
                    'total_overdue' => $overdueAmount,
                    'overdue_ratio' => $outstanding > 0 ? round(($overdueAmount / $outstanding) * 100, 1) : 0.0,
                ],
                'invoices' => [
                    'issued_count' => (int) $periodInvoices['issued_count'],
                    'paid_count' => (int) $periodInvoices['paid_count'],
                    'draft_count' => (int) $periodInvoices['draft_count'],
                    'open_count' => (int) $receivables['open_count'],
                    'overdue_count' => (int) $receivables['overdue_count'],
                    'payment_count' => (int) $periodPayments['payment_count'],
                    'average_invoice_value' => (int) $periodInvoices['issued_count'] > 0
                        ? round($grossInvoiced / (int) $periodInvoices['issued_count'], 2)
                        : 0.00,
                    'vat_amount' => (float) $periodInvoices['vat'],
                    'discounts_amount' => (float) $periodInvoices['discounts'],
                ],
                'pipeline' => [
                    'quotations' => [
                        'total' => (int) $quotationPipeline['total'],
                        'accepted' => (int) $quotationPipeline['accepted'],
                        'converted' => (int) $quotationPipeline['converted'],
                        'awaiting' => (int) $quotationPipeline['awaiting'],
                    ],
                    'proformas' => [
                        'total' => (int) $proformaPipeline['total'],
                        'approved' => (int) $proformaPipeline['approved'],
                        'converted' => (int) $proformaPipeline['converted'],
                        'awaiting' => (int) $proformaPipeline['awaiting'],
                    ],
                    'invoices' => [
                        'issued' => (int) $periodInvoices['issued_count'],
                        'paid' => (int) $periodInvoices['paid_count'],
                        'open' => (int) $receivables['open_count'],
                    ],
                ],
                'alerts' => [
                    'low_stock_count' => $lowStockCount,
                    'follow_up_count' => count($overdueInvoices) + count($expiringQuotations) + count($expiringProformas),
                ],
            ],
            'charts' => [
                'revenue_trend' => $revenueTrend,
                'status_distribution' => $statusDistribution,
                'invoice_aging' => $agingSnapshot,
                'payment_methods' => $paymentMethods,
            ],
            'lists' => [
                'top_products' => $topProducts,
                'recent_invoices' => $recentInvoices,
                'recent_payments' => $recentPayments,
                'low_stock_alerts' => $lowStockAlerts,
                'attention' => [
                    'overdue_invoices' => $overdueInvoices,
                    'expiring_quotations' => $expiringQuotations,
                    'expiring_proformas' => $expiringProformas,
                ],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $e) {
    error_log('Dashboard Error: ' . $e->getMessage());

    $code = (int) $e->getCode();
    $safeClientError = in_array($code, [400, 403, 405, 422], true);

    http_response_code($safeClientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $safeClientError
            ? $e->getMessage()
            : 'We could not load the dashboard right now. Please try again shortly.',
    ]);
}
