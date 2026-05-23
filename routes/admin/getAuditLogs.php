<?php
// routes/admin/getAuditLogs.php
// GET /admin/audit-logs - super admin audit explorer.

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
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can view the audit log.');

    $search = trim((string) ($_GET['search'] ?? ''));
    $action = trim((string) ($_GET['action'] ?? ''));
    $modelType = trim((string) ($_GET['model_type'] ?? ''));
    $userId = (int) ($_GET['user_id'] ?? 0);
    $dateFrom = trim((string) ($_GET['from'] ?? ''));
    $dateTo = trim((string) ($_GET['to'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = ['1=1'];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(al.description LIKE ? OR al.action LIKE ? OR al.model_type LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
        $types .= 'sssss';
    }
    if ($action !== '') {
        $where[] = 'al.action LIKE ?';
        $params[] = $action . '%';
        $types .= 's';
    }
    if ($modelType !== '') {
        $where[] = 'al.model_type = ?';
        $params[] = $modelType;
        $types .= 's';
    }
    if ($userId > 0) {
        $where[] = 'al.user_id = ?';
        $params[] = $userId;
        $types .= 'i';
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(al.created_at) >= ?';
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(al.created_at) <= ?';
        $params[] = $dateTo;
        $types .= 's';
    }

    $join = ' FROM activity_log al LEFT JOIN users u ON u.id = al.user_id WHERE ' . implode(' AND ', $where);

    $count = $conn->prepare('SELECT COUNT(*) AS total' . $join);
    if ($types !== '') {
        $count->bind_param($types, ...$params);
    }
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $stats = $conn->query(
        "SELECT
            COUNT(*) AS total_events,
            SUM(CASE WHEN action LIKE 'auth.%' THEN 1 ELSE 0 END) AS auth_events,
            SUM(CASE WHEN action LIKE 'inventory.%' THEN 1 ELSE 0 END) AS inventory_events,
            SUM(CASE WHEN action LIKE 'credit_note.%' OR action LIKE 'refund.%' OR action LIKE 'invoice.reversed%' THEN 1 ELSE 0 END) AS controlled_finance_events,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS last_24_hours
         FROM activity_log"
    )->fetch_assoc();

    $facetUsers = [];
    $usersResult = $conn->query('SELECT id, name, email FROM users ORDER BY name ASC');
    while ($row = $usersResult->fetch_assoc()) {
        $facetUsers[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email']];
    }

    $facetModels = [];
    $modelResult = $conn->query('SELECT DISTINCT model_type FROM activity_log WHERE model_type IS NOT NULL ORDER BY model_type ASC');
    while ($row = $modelResult->fetch_assoc()) {
        $facetModels[] = $row['model_type'];
    }

    $dataParams = $params;
    $dataTypes = $types . 'ii';
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $list = $conn->prepare(
        'SELECT al.id, al.user_id, al.action, al.model_type, al.model_id, al.description,
                al.properties, al.ip_address, al.created_at, u.name AS user_name, u.email AS user_email
         ' . $join . ' ORDER BY al.created_at DESC, al.id DESC LIMIT ? OFFSET ?'
    );
    $list->bind_param($dataTypes, ...$dataParams);
    $list->execute();
    $result = $list->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int) $row['id'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'user_name' => $row['user_name'] ?: 'System',
            'user_email' => $row['user_email'],
            'action' => $row['action'],
            'model_type' => $row['model_type'],
            'model_id' => $row['model_id'] !== null ? (int) $row['model_id'] : null,
            'description' => $row['description'],
            'properties' => $row['properties'] ? json_decode($row['properties'], true) : null,
            'ip_address' => $row['ip_address'],
            'created_at' => $row['created_at'],
        ];
    }
    $list->close();

    echo json_encode([
        'status' => 'success',
        'data' => $rows,
        'summary' => [
            'total_events' => (int) ($stats['total_events'] ?? 0),
            'auth_events' => (int) ($stats['auth_events'] ?? 0),
            'inventory_events' => (int) ($stats['inventory_events'] ?? 0),
            'controlled_finance_events' => (int) ($stats['controlled_finance_events'] ?? 0),
            'last_24_hours' => (int) ($stats['last_24_hours'] ?? 0),
        ],
        'facets' => ['users' => $facetUsers, 'models' => $facetModels],
        'meta' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => max(1, (int) ceil($total / $limit)),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Get Audit Logs Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Audit events could not be loaded right now.' : $e->getMessage(),
    ]);
}
