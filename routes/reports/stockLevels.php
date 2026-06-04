<?php
// routes/reports/stockLevels.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /reports/stock-levels
 * Current stock positions for all tracked products.
 * Includes low-stock alerts and movement totals for the selected period.
 * Roles allowed: super_admin, admin, accounting
 *
 * Query params:
 *   ?from=2026-01-01&to=2026-04-30
 *   &category_id=2
 *   &filter=all|ok|low_stock|out_of_stock
 *   &search=blender
 *   &page=1&limit=20
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

function bindStockReportParams(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || empty($params)) {
        return;
    }

    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }

    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'] ?? '';

    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'accounting'], true)) {
        throw new Exception("Unauthorized: Only Admins or Accounting users can access stock reports.", 403);
    }

    // -------------------------------------------------------
    // 1. Parameters
    // -------------------------------------------------------
    $from = isset($_GET['from']) && DateTime::createFromFormat('Y-m-d', trim((string) $_GET['from']))
        ? trim((string) $_GET['from'])
        : date('Y-m-01');

    $to = isset($_GET['to']) && DateTime::createFromFormat('Y-m-d', trim((string) $_GET['to']))
        ? trim((string) $_GET['to'])
        : date('Y-m-t');

    if ($from > $to) {
        throw new Exception("'from' date cannot be after 'to' date.", 422);
    }

    $categoryId = isset($_GET['category_id']) && is_numeric($_GET['category_id'])
        ? (int) $_GET['category_id']
        : null;

    $allowedFilters = ['all', 'ok', 'low_stock', 'out_of_stock'];
    $filter = isset($_GET['filter']) ? strtolower(trim((string) $_GET['filter'])) : 'all';
    if (!in_array($filter, $allowedFilters, true)) {
        $filter = 'all';
    }

    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    $limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    // -------------------------------------------------------
    // 2. Shared scope filters
    //    The summary uses category/search scope, while the table
    //    also applies the selected stock-status filter.
    // -------------------------------------------------------
    $scopeWhere = "WHERE p.is_active = 1";
    $scopeParams = [];
    $scopeTypes = '';

    if ($categoryId) {
        $scopeWhere .= " AND p.category_id = ?";
        $scopeParams[] = $categoryId;
        $scopeTypes .= 'i';
    }

    if ($search !== '') {
        $scopeWhere .= " AND (p.name LIKE ? OR p.sku LIKE ? OR pc.name LIKE ?)";
        $likeSearch = '%' . $search . '%';
        $scopeParams[] = $likeSearch;
        $scopeParams[] = $likeSearch;
        $scopeParams[] = $likeSearch;
        $scopeTypes .= 'sss';
    }

    $tableWhere = $scopeWhere;
    $tableParams = $scopeParams;
    $tableTypes = $scopeTypes;

    if ($filter === 'ok') {
        $tableWhere .= " AND p.stock_quantity > p.reorder_level";
    } elseif ($filter === 'low_stock') {
        $tableWhere .= " AND p.stock_quantity > 0 AND p.stock_quantity <= p.reorder_level";
    } elseif ($filter === 'out_of_stock') {
        $tableWhere .= " AND p.stock_quantity <= 0";
    }

    // -------------------------------------------------------
    // 3. Count filtered products
    // -------------------------------------------------------
    $countSql = "
        SELECT COUNT(*) AS total
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        {$tableWhere}
    ";

    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        throw new Exception("Failed to prepare stock count query: " . $conn->error, 500);
    }
    bindStockReportParams($countStmt, $tableTypes, $tableParams);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    // -------------------------------------------------------
    // 4. Paginated stock data + movement totals for the period
    // -------------------------------------------------------
    $dataSql = "
        SELECT
            p.id,
            p.name,
            p.sku,
            p.unit_of_measure,
            pc.name AS category_name,
            p.stock_quantity AS current_stock,
            p.reorder_level,
            p.unit_price,
            CASE
                WHEN p.stock_quantity <= 0 THEN 'out_of_stock'
                WHEN p.stock_quantity <= p.reorder_level THEN 'low_stock'
                ELSE 'ok'
            END AS stock_status,
            COALESCE(mv.units_sold_in_period, 0) AS units_sold_in_period,
            COALESCE(mv.units_received_in_period, 0) AS units_received_in_period,
            COALESCE(p.stock_quantity * p.unit_price, 0) AS stock_value
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        LEFT JOIN (
            SELECT
                product_id,
                SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE 0 END) AS units_sold_in_period,
                SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE 0 END) AS units_received_in_period
            FROM stock_movements
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY product_id
        ) mv ON mv.product_id = p.id
        {$tableWhere}
        ORDER BY
            CASE
                WHEN p.stock_quantity <= 0 THEN 0
                WHEN p.stock_quantity <= p.reorder_level THEN 1
                ELSE 2
            END ASC,
            p.stock_quantity ASC,
            p.name ASC
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataSql);
    if (!$dataStmt) {
        throw new Exception("Failed to prepare stock data query: " . $conn->error, 500);
    }

    $dataParams = array_merge([$from, $to], $tableParams, [$limit, $offset]);
    $dataTypes = 'ss' . $tableTypes . 'ii';
    bindStockReportParams($dataStmt, $dataTypes, $dataParams);
    $dataStmt->execute();
    $rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    $products = array_map(static fn($r) => [
        'product_id' => (int) $r['id'],
        'product_name' => $r['name'],
        'sku' => $r['sku'],
        'unit_of_measure' => $r['unit_of_measure'],
        'category' => $r['category_name'],
        'current_stock' => (float) $r['current_stock'],
        'reorder_level' => (float) $r['reorder_level'],
        'stock_status' => $r['stock_status'],
        'unit_price' => (float) $r['unit_price'],
        'stock_value' => round((float) $r['stock_value'], 2),
        'units_sold_in_period' => (float) $r['units_sold_in_period'],
        'units_received_in_period' => (float) $r['units_received_in_period'],
    ], $rows);

    // -------------------------------------------------------
    // 5. Scoped summary across selected category/search scope
    // -------------------------------------------------------
    $summarySql = "
        SELECT
            COUNT(*) AS total_skus,
            COUNT(CASE WHEN p.stock_quantity <= 0 THEN 1 END) AS out_of_stock_count,
            COUNT(CASE WHEN p.stock_quantity > 0 AND p.stock_quantity <= p.reorder_level THEN 1 END) AS low_stock_count,
            COUNT(CASE WHEN p.stock_quantity > p.reorder_level THEN 1 END) AS ok_count,
            COALESCE(SUM(p.stock_quantity), 0) AS total_stock_units,
            COALESCE(SUM(p.stock_quantity * p.unit_price), 0) AS total_stock_value,
            COALESCE(SUM(mv.units_sold_in_period), 0) AS units_sold_in_period,
            COALESCE(SUM(mv.units_received_in_period), 0) AS units_received_in_period
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        LEFT JOIN (
            SELECT
                product_id,
                SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE 0 END) AS units_sold_in_period,
                SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE 0 END) AS units_received_in_period
            FROM stock_movements
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY product_id
        ) mv ON mv.product_id = p.id
        {$scopeWhere}
    ";

    $summaryStmt = $conn->prepare($summarySql);
    if (!$summaryStmt) {
        throw new Exception("Failed to prepare stock summary query: " . $conn->error, 500);
    }

    $summaryParams = array_merge([$from, $to], $scopeParams);
    $summaryTypes = 'ss' . $scopeTypes;
    bindStockReportParams($summaryStmt, $summaryTypes, $summaryParams);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Stock levels report fetched successfully.',
        'data' => [
            'period' => ['from' => $from, 'to' => $to],
            'summary' => [
                'total_tracked_skus' => (int) ($summary['total_skus'] ?? 0),
                'ok_count' => (int) ($summary['ok_count'] ?? 0),
                'out_of_stock_count' => (int) ($summary['out_of_stock_count'] ?? 0),
                'low_stock_count' => (int) ($summary['low_stock_count'] ?? 0),
                'total_stock_units' => round((float) ($summary['total_stock_units'] ?? 0), 2),
                'total_stock_value' => round((float) ($summary['total_stock_value'] ?? 0), 2),
                'units_sold_in_period' => round((float) ($summary['units_sold_in_period'] ?? 0), 2),
                'units_received_in_period' => round((float) ($summary['units_received_in_period'] ?? 0), 2),
            ],
            'products' => $products,
            'meta' => [
                'total' => $total,
                'total_pages' => $totalPages,
                'page' => $page,
                'limit' => $limit,
                'filter' => $filter,
                'category_id' => $categoryId,
                'search' => $search,
            ],
        ],
    ]);
} catch (Exception $e) {
    error_log('Stock Levels Report Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    http_response_code(($code >= 400 && $code < 600) ? $code : 500);
    echo json_encode(['status' => 'failed', 'message' => $e->getMessage()]);
}
?>
