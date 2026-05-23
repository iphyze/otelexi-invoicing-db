<?php
// routes/reports/topProducts.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /reports/top-products
 * Ranks products by quantity sold and revenue generated from finalized invoices.
 * Only counts invoice_items linked to a product_id on non-draft, non-cancelled invoices.
 * Roles allowed: Admin, Accounting
 *
 * Query params:
 *   ?from=2026-01-01  &to=2026-04-30   (defaults to current month)
 *   &currency=NGN|USD
 *   &category_id=2                     (filter by product category)
 *   &limit=10                          (top N products, default 10, max 50)
 *   &sortBy=revenue|quantity           (default: revenue)
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
    $limit    = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
    $sortBy   = isset($_GET['sortBy']) && $_GET['sortBy'] === 'quantity' ? 'total_quantity' : 'total_revenue';

    $categoryId = isset($_GET['category_id']) && is_numeric($_GET['category_id'])
                  ? (int)$_GET['category_id'] : null;

    if ($from > $to) throw new Exception("'from' date cannot be after 'to' date.", 422);

    // -------------------------------------------------------
    // 2. Top products query
    // -------------------------------------------------------
    $baseWhere = "
        WHERE i.issue_date BETWEEN ? AND ?
          AND i.currency = ?
          AND i.status NOT IN ('draft', 'cancelled')
          AND ii.product_id IS NOT NULL
    ";
    $params = [$from, $to, $currency];
    $types  = "sss";

    if ($categoryId) {
        $baseWhere .= " AND p.category_id = ?";
        $params[]   = $categoryId;
        $types     .= "i";
    }

    $params[] = $limit;
    $types   .= "i";

    $topStmt = $conn->prepare("
        SELECT
            p.id                                   AS product_id,
            p.name                                 AS product_name,
            p.sku,
            p.unit_of_measure,
            pc.name                                AS category_name,
            p.stock_quantity                       AS current_stock,
            COUNT(DISTINCT ii.invoice_id)          AS invoice_count,
            COALESCE(SUM(ii.quantity), 0)          AS total_quantity,
            COALESCE(SUM(ii.line_total), 0)        AS total_revenue,
            COALESCE(SUM(ii.tax_amount), 0)        AS total_vat,
            COALESCE(SUM(ii.discount_amount), 0)   AS total_discounts,
            COALESCE(AVG(ii.unit_price), 0)        AS avg_unit_price
        FROM invoice_items ii
        JOIN invoices i        ON i.id  = ii.invoice_id
        JOIN products p        ON p.id  = ii.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        {$baseWhere}
        GROUP BY p.id, p.name, p.sku, p.unit_of_measure, pc.name, p.stock_quantity
        ORDER BY {$sortBy} DESC
        LIMIT ?
    ");
    $topStmt->bind_param($types, ...$params);
    $topStmt->execute();
    $rows = $topStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $topStmt->close();

    $products = array_map(fn($r) => [
        "product_id"      => (int)$r['product_id'],
        "product_name"    => $r['product_name'],
        "sku"             => $r['sku'],
        "unit_of_measure" => $r['unit_of_measure'],
        "category"        => $r['category_name'],
        "current_stock"   => (float)$r['current_stock'],
        "invoice_count"   => (int)$r['invoice_count'],
        "total_quantity"  => (float)$r['total_quantity'],
        "total_revenue"   => (float)$r['total_revenue'],
        "total_vat"       => (float)$r['total_vat'],
        "total_discounts" => (float)$r['total_discounts'],
        "avg_unit_price"  => round((float)$r['avg_unit_price'], 2)
    ], $rows);

    // -------------------------------------------------------
    // 3. Category breakdown
    // -------------------------------------------------------
    $catParams = [$from, $to, $currency];
    $catTypes  = "sss";
    $catWhere  = "WHERE i.issue_date BETWEEN ? AND ?
                    AND i.currency = ?
                    AND i.status NOT IN ('draft', 'cancelled')
                    AND ii.product_id IS NOT NULL";

    if ($categoryId) {
        $catWhere   .= " AND p.category_id = ?";
        $catParams[] = $categoryId;
        $catTypes   .= "i";
    }

    $catStmt = $conn->prepare("
        SELECT
            pc.id                                AS category_id,
            pc.name                              AS category_name,
            COALESCE(SUM(ii.quantity), 0)        AS total_quantity,
            COALESCE(SUM(ii.line_total), 0)      AS total_revenue,
            COUNT(DISTINCT ii.product_id)        AS product_count
        FROM invoice_items ii
        JOIN invoices i             ON i.id  = ii.invoice_id
        JOIN products p             ON p.id  = ii.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        {$catWhere}
        GROUP BY pc.id, pc.name
        ORDER BY total_revenue DESC
    ");
    $catStmt->bind_param($catTypes, ...$catParams);
    $catStmt->execute();
    $catRows = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $catStmt->close();

    $categories = array_map(fn($r) => [
        "category_id"    => $r['category_id'] ? (int)$r['category_id'] : null,
        "category_name"  => $r['category_name'] ?? 'Uncategorised',
        "total_quantity" => (float)$r['total_quantity'],
        "total_revenue"  => (float)$r['total_revenue'],
        "product_count"  => (int)$r['product_count']
    ], $catRows);

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Top products report fetched successfully.",
        "data"    => [
            "period"      => ["from" => $from, "to" => $to, "currency" => $currency],
            "sort_by"     => $sortBy === 'total_quantity' ? 'quantity' : 'revenue',
            "top_limit"   => $limit,
            "products"    => $products,
            "by_category" => $categories
        ]
    ]);

} catch (Exception $e) {
    error_log("Top Products Report Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
