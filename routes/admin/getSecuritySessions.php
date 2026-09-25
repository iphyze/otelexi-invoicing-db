<?php
// GET /admin/security-sessions - Super Admin active-session oversight.

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
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can view active user sessions.');

    $search = trim((string) ($_GET['search'] ?? ''));
    $role = trim((string) ($_GET['role'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $idleCutoff = date('Y-m-d H:i:s', time() - sessionIdleTimeoutSeconds());

    if ($role !== '' && !in_array($role, APP_ROLES, true)) {
        throw new Exception('Invalid role filter.', 422);
    }

    $where = [
        's.revoked_at IS NULL',
        's.absolute_expires_at > NOW()',
        's.last_activity_at > ?',
    ];
    $params = [$idleCutoff];
    $types = 's';

    if ($search !== '') {
        $where[] = '(u.name LIKE ? OR u.email LIKE ? OR s.device_label LIKE ? OR s.ip_address LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
        $types .= 'ssss';
    }

    if ($role !== '') {
        $where[] = 'u.role = ?';
        $params[] = $role;
        $types .= 's';
    }

    $from = ' FROM auth_sessions s JOIN users u ON u.id = s.user_id WHERE ' . implode(' AND ', $where);

    $countStmt = $conn->prepare('SELECT COUNT(*) AS total' . $from);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $dataParams = $params;
    $dataTypes = $types . 'ii';
    $dataParams[] = $limit;
    $dataParams[] = $offset;

    $listStmt = $conn->prepare(
        'SELECT s.session_key, s.user_id, s.device_label, s.ip_address, s.user_agent, '
        . 's.last_activity_at, s.absolute_expires_at, s.created_at, '
        . 'u.name AS user_name, u.email AS user_email, u.role AS user_role '
        . $from . ' ORDER BY s.last_activity_at DESC, s.id DESC LIMIT ? OFFSET ?'
    );
    $listStmt->bind_param($dataTypes, ...$dataParams);
    $listStmt->execute();
    $result = $listStmt->get_result();
    $rows = [];
    $currentSessionKey = currentAccessSessionKey();

    while ($row = $result->fetch_assoc()) {
        $device = describeUserAgent((string) ($row['user_agent'] ?? ''));
        $rows[] = [
            'session_key' => $row['session_key'],
            'user_id' => (int) $row['user_id'],
            'user_name' => $row['user_name'],
            'user_email' => $row['user_email'],
            'user_role' => $row['user_role'],
            'device_name' => trim((string) ($row['device_label'] ?? '')) ?: $device['label'],
            'browser' => $device['browser'],
            'platform' => $device['platform'],
            'device_type' => $device['device_type'],
            'ip_address' => $row['ip_address'],
            'last_activity_at' => $row['last_activity_at'],
            'signed_in_at' => $row['created_at'],
            'expires_at' => $row['absolute_expires_at'],
            'current' => (int) $row['user_id'] === (int) $user['id']
                && $currentSessionKey !== null
                && hash_equals((string) $row['session_key'], $currentSessionKey),
        ];
    }
    $listStmt->close();

    $summaryStmt = $conn->prepare(
        'SELECT COUNT(*) AS active_sessions, COUNT(DISTINCT user_id) AS users_with_sessions '
        . 'FROM auth_sessions WHERE revoked_at IS NULL AND absolute_expires_at > NOW() AND last_activity_at > ?'
    );
    $summaryStmt->bind_param('s', $idleCutoff);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    echo json_encode([
        'status' => 'success',
        'data' => $rows,
        'summary' => [
            'active_sessions' => (int) ($summary['active_sessions'] ?? 0),
            'users_with_sessions' => (int) ($summary['users_with_sessions'] ?? 0),
            'max_active_sessions' => maxConcurrentSessions($conn),
        ],
        'meta' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => max(1, (int) ceil($total / $limit)),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Get Security Sessions Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Active sessions could not be loaded right now.' : $e->getMessage(),
    ]);
}
