<?php
// routes/reports/stockLevels.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /reports/stock-levels
 * Current stock positions for all tracked products.
 * Includes low-stock alerts and a movement summary for the period.
 * Roles allowed: Admin, Accounting
 *
 * Query params:
 *   ?from=2026-01-01  &to=2026-04-30   (movement history range, defaults to current month)
 *   &category_id=2
 *   &filter=all|low_stock|out_of_stock  (default: all)
 *   &page=1  &limit=20
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
    $from       = isset($_GET['from']) && DateTime::createFromFormat('Y-m-d', trim($_GET['from']))
                  ? trim($_GET['from']) : date('Y-m-01');
    $to         = isset($_GET['to']) && DateTime::createFromFormat('Y-m-d', trim($_GET['to']))
                  ? trim($_GET['to']) : date('Y-m-t');
    $categoryId = isset($_GET['category_id']) && is_numeric($_GET['category_id'])
                  ? (int)$_GET['category_id'] : null;
    $filter     = isset($_GET['filter']) ? strtolower(trim($_GET['filter'])) : 'all';
    $limit      = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset     = ($page - 1) * $limit;

    if ($from > $to) throw new Exception("'from' date cannot be after 'to' date.", 422);

    // -------------------------------------------------------
    // 2. Build product stock query
    // -------------------------------------------------------
    $whereClause = "WHERE p.is_active = 1";
    $params      = [];
    $types       = "";

    if ($categoryId) {
        $whereClause .= " AND p.category_id = ?";
        $params[]     = $categoryId;
        $types       .= "i";
    }

    if ($filter === 'low_stock') {
        $whereClause .= " AND p.stock_quantity > 0 AND p.stock_quantity <= p.reorder_level";
    } elseif ($filter === 'out_of_stock') {
        $whereClause .= " AND p.stock_quantity <= 0";
    }

    // Count
    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        {$whereClause}
    ");
    if (!empty($params)) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Paginated stock data + movement sums for the period
    $pageParams   = array_merge($params, [$from, $to, $from, $to, $limit, $offset]);
    $pageTypes    = $types . "ssssii";

    $stockStmt = $conn->prepare("
        SELECT
            p.id, p.name, p.sku, p.unit_of_measure,
            pc.name                                        AS category_name,
            p.stock_quantity                               AS current_stock,
            p.reorder_level,
            p.unit_price,
            CASE
                WHEN p.stock_quantity <= 0            THEN 'out_of_stock'
                WHEN p.stock_quantity <= p.reorder_level THEN 'low_stock'
                ELSE 'ok'
            END                                            AS stock_status,
            COALESCE(
                (SELECT SUM(sm.quantity)
                 FROM stock_movements sm
                 WHERE sm.product_id = p.id
                   AND sm.movement_type = 'out'
                   AND DATE(sm.created_at) BETWEEN ? AND ?), 0
            )                                              AS units_sold_in_period,
            COALESCE(
                (SELECT SUM(sm.quantity)
                 FROM stock_movements sm
                 WHERE sm.product_id = p.id
                   AND sm.movement_type = 'in'
                   AND DATE(sm.created_at) BETWEEN ? AND ?), 0
            )                                              AS units_received_in_period,
            COALESCE(p.stock_quantity * p.unit_price, 0) AS stock_value
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        {$whereClause}
        ORDER BY p.stock_quantity ASC, p.name ASC
        LIMIT ? OFFSET ?
    ");
    $stockStmt->bind_param($pageTypes, ...$pageParams);
    $stockStmt->execute();
    $rows = $stockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stockStmt->close();

    $products = array_map(fn($r) => [
        "product_id"             => (int)$r['id'],
        "product_name"           => $r['name'],
        "sku"                    => $r['sku'],
        "unit_of_measure"        => $r['unit_of_measure'],
        "category"               => $r['category_name'],
        "current_stock"          => (float)$r['current_stock'],
        "reorder_level"          => (float)$r['reorder_level'],
        "stock_status"           => $r['stock_status'],
        "unit_price"          => (float)$r['unit_price'],
        "stock_value"            => round((float)$r['stock_value'], 2),
        "units_sold_in_period"   => (float)$r['units_sold_in_period'],
        "units_received_in_period"=> (float)$r['units_received_in_period']
    ], $rows);

    // -------------------------------------------------------
    // 3. Aggregate summary across ALL tracked products
    //    (not just the current page)
    // -------------------------------------------------------
    $aggrStmt = $conn->prepare("
        SELECT
            COUNT(*)                                              AS total_skus,
            COUNT(CASE WHEN stock_quantity <= 0 THEN 1 END)      AS out_of_stock_count,
            COUNT(CASE WHEN stock_quantity > 0
                        AND stock_quantity <= reorder_level
                        THEN 1 END)                               AS low_stock_count,
            COALESCE(SUM(stock_quantity * unit_price), 0)     AS total_stock_value
        FROM products
        WHERE is_active = 1
    ");
    $aggrStmt->execute();
    $aggr = $aggrStmt->get_result()->fetch_assoc();
    $aggrStmt->close();

    $totalPages = $total > 0 ? (int)ceil($total / $limit) : 0;

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Stock levels report fetched successfully.",
        "data"    => [
            "period"   => ["from" => $from, "to" => $to],
            "summary"  => [
                "total_tracked_skus"  => (int)$aggr['total_skus'],
                "out_of_stock_count"  => (int)$aggr['out_of_stock_count'],
                "low_stock_count"     => (int)$aggr['low_stock_count'],
                "total_stock_value"   => round((float)$aggr['total_stock_value'], 2)
            ],
            "products" => $products,
            "meta"     => [
                "total"       => $total,
                "total_pages" => $totalPages,
                "page"        => $page,
                "limit"       => $limit,
                "filter"      => $filter,
                "category_id" => $categoryId
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("Stock Levels Report Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>