<?php
// routes/inventory/getStockMovements.php
// GET /inventory/movements - inventory movement ledger and summary.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole(
        $user,
        [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING],
        'You do not have permission to view stock movement history.'
    );

    $search = trim((string) ($_GET['search'] ?? ''));
    $productId = (int) ($_GET['product_id'] ?? 0);
    $movementType = trim((string) ($_GET['movement_type'] ?? ''));
    $referenceType = trim((string) ($_GET['reference_type'] ?? ''));
    $dateFrom = trim((string) ($_GET['from'] ?? ''));
    $dateTo = trim((string) ($_GET['to'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $allowedTypes = ['in', 'out', 'adjustment'];
    $where = ['1=1'];
    $params = [];
    $types = '';

    if ($productId > 0) {
        $where[] = 'sm.product_id = ?';
        $params[] = $productId;
        $types .= 'i';
    }
    if (in_array($movementType, $allowedTypes, true)) {
        $where[] = 'sm.movement_type = ?';
        $params[] = $movementType;
        $types .= 's';
    }
    if ($referenceType !== '') {
        $where[] = 'sm.reference_type = ?';
        $params[] = $referenceType;
        $types .= 's';
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(sm.created_at) >= ?';
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(sm.created_at) <= ?';
        $params[] = $dateTo;
        $types .= 's';
    }
    if ($search !== '') {
        $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR sm.movement_number LIKE ? OR sm.notes LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
        $types .= 'ssss';
    }

    $whereSql = implode(' AND ', $where);
    $joinSql = ' FROM stock_movements sm
                 JOIN products p ON p.id = sm.product_id
                 LEFT JOIN users u ON u.id = sm.created_by
                 WHERE ' . $whereSql;

    $count = $conn->prepare('SELECT COUNT(*) AS total' . $joinSql);
    if ($types !== '') {
        $count->bind_param($types, ...$params);
    }
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $summary = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN sm.movement_type = 'in' THEN ABS(sm.quantity) ELSE 0 END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN sm.movement_type = 'out' THEN ABS(sm.quantity) ELSE 0 END), 0) AS total_out,
            COALESCE(SUM(CASE WHEN sm.movement_type = 'adjustment' THEN sm.quantity ELSE 0 END), 0) AS net_adjustment,
            COUNT(DISTINCT sm.product_id) AS products_affected" . $joinSql
    );
    if ($types !== '') {
        $summary->bind_param($types, ...$params);
    }
    $summary->execute();
    $stats = $summary->get_result()->fetch_assoc() ?: [];
    $summary->close();

    $dataParams = $params;
    $dataTypes = $types . 'ii';
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $list = $conn->prepare(
        'SELECT sm.id, sm.movement_number, sm.product_id, p.name AS product_name, p.sku,
                p.unit_of_measure, sm.movement_type, sm.quantity, sm.balance_before, sm.balance_after,
                sm.reference_type, sm.reference_id, sm.reason_code, sm.notes, sm.created_at,
                u.name AS created_by_name, u.email AS created_by_email' .
        $joinSql . ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT ? OFFSET ?'
    );
    $list->bind_param($dataTypes, ...$dataParams);
    $list->execute();
    $result = $list->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int) $row['id'],
            'movement_number' => $row['movement_number'],
            'product_id' => (int) $row['product_id'],
            'product_name' => $row['product_name'],
            'sku' => $row['sku'],
            'unit_of_measure' => $row['unit_of_measure'],
            'movement_type' => $row['movement_type'],
            'quantity' => (float) $row['quantity'],
            'balance_before' => $row['balance_before'] !== null ? (float) $row['balance_before'] : null,
            'balance_after' => $row['balance_after'] !== null ? (float) $row['balance_after'] : null,
            'reference_type' => $row['reference_type'],
            'reference_id' => $row['reference_id'] !== null ? (int) $row['reference_id'] : null,
            'reason_code' => $row['reason_code'],
            'notes' => $row['notes'],
            'created_at' => $row['created_at'],
            'created_by_name' => $row['created_by_name'],
            'created_by_email' => $row['created_by_email'],
        ];
    }
    $list->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'data' => $rows,
        'summary' => [
            'total_in' => (float) ($stats['total_in'] ?? 0),
            'total_out' => (float) ($stats['total_out'] ?? 0),
            'net_adjustment' => (float) ($stats['net_adjustment'] ?? 0),
            'products_affected' => (int) ($stats['products_affected'] ?? 0),
        ],
        'meta' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => max(1, (int) ceil($total / $limit)),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Stock Movement History Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Stock movement history could not be loaded.' : $e->getMessage(),
    ]);
}
