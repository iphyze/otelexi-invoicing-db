<?php
// routes/customerPortal/getCustomerPortalLinks.php
// Lists customer portal links for staff management.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/customerPortal.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING, ROLE_SALES], 'You do not have permission to view customer portal links.');

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(50, max(5, (int) ($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = trim((string) ($_GET['search'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));

    $allowedStatuses = ['active', 'expired', 'revoked'];
    if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
        throw new Exception('Invalid customer portal status filter.', 422);
    }

    // Keep stale active links tidy before listing.
    $conn->query("UPDATE customer_portal_links SET status = 'expired', updated_at = NOW() WHERE status = 'active' AND expires_at < NOW()");

    $where = [];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(cpl.reference LIKE ? OR i.invoice_number LIKE ? OR c.company_name LIKE ? OR c.email LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
        $types .= 'ssss';
    }

    if ($status !== '') {
        $where[] = 'cpl.status = ?';
        $params[] = $status;
        $types .= 's';
    }

    if ($from !== '') {
        $where[] = 'DATE(cpl.created_at) >= ?';
        $params[] = $from;
        $types .= 's';
    }

    if ($to !== '') {
        $where[] = 'DATE(cpl.created_at) <= ?';
        $params[] = $to;
        $types .= 's';
    }

    if (($user['role'] ?? '') === ROLE_SALES) {
        $where[] = 'i.created_by = ?';
        $params[] = (int) $user['id'];
        $types .= 'i';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(*) AS total
                 FROM customer_portal_links cpl
                 JOIN invoices i ON i.id = cpl.invoice_id
                 JOIN clients c ON c.id = cpl.client_id
                 {$whereSql}";
    $countStmt = $conn->prepare($countSql);
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = "SELECT cpl.*, i.invoice_number, i.status AS invoice_status, i.total_amount AS invoice_total,
                   i.balance_due, i.currency, c.company_name AS client_name, c.email AS client_email,
                   u.name AS created_by_name
            FROM customer_portal_links cpl
            JOIN invoices i ON i.id = cpl.invoice_id
            JOIN clients c ON c.id = cpl.client_id
            LEFT JOIN users u ON u.id = cpl.created_by
            {$whereSql}
            ORDER BY cpl.created_at DESC, cpl.id DESC
            LIMIT ? OFFSET ?";

    $listStmt = $conn->prepare($sql);
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$limit, $offset]);
    $listStmt->bind_param($listTypes, ...$listParams);
    $listStmt->execute();
    $result = $listStmt->get_result();

    $links = [];
    while ($row = $result->fetch_assoc()) {
        $links[] = customerPortalLinkResponse($row);
    }
    $listStmt->close();

    echo json_encode([
        'status' => 'success',
        'message' => 'Customer portal links loaded successfully.',
        'data' => $links,
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $limit)),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Get Customer Portal Links Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $e->getMessage() : 'Customer portal links could not be loaded right now.',
    ]);
}
