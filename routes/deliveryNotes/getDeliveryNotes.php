<?php
// routes/deliveryNotes/getDeliveryNotes.php
// GET /delivery-notes

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    $userId = (int) $user['id'];
    $role = (string) $user['role'];

    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_SALES, ROLE_ACCOUNTING], 'Unauthorized: You do not have permission to view delivery notes.');

    $search = trim((string) ($_GET['search'] ?? ''));
    $status = strtolower(trim((string) ($_GET['status'] ?? '')));
    $clientId = isset($_GET['client_id']) && is_numeric($_GET['client_id']) ? (int) $_GET['client_id'] : null;
    $invoiceId = isset($_GET['invoice_id']) && is_numeric($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : null;
    $fromDate = trim((string) ($_GET['from'] ?? ''));
    $toDate = trim((string) ($_GET['to'] ?? ''));

    $limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    $allowedSortFields = ['id', 'delivery_note_number', 'delivery_date', 'dispatch_date', 'delivered_at', 'status', 'created_at'];
    $sortBy = in_array((string) ($_GET['sortBy'] ?? ''), $allowedSortFields, true) ? (string) $_GET['sortBy'] : 'created_at';
    $sortOrder = strtoupper((string) ($_GET['sortOrder'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    $validStatuses = ['draft', 'dispatched', 'delivered', 'cancelled'];

    $baseQuery = '
        FROM delivery_notes dn
        JOIN invoices i ON i.id = dn.invoice_id
        JOIN clients c ON c.id = dn.client_id
        LEFT JOIN users u ON u.id = dn.created_by
        WHERE 1=1
    ';
    $params = [];
    $types = '';

    if ($role === ROLE_SALES) {
        $baseQuery .= ' AND (dn.created_by = ? OR i.created_by = ?)';
        $params[] = $userId;
        $params[] = $userId;
        $types .= 'ii';
    }

    if ($status !== '' && in_array($status, $validStatuses, true)) {
        $baseQuery .= ' AND dn.status = ?';
        $params[] = $status;
        $types .= 's';
    }

    if ($clientId) {
        $baseQuery .= ' AND dn.client_id = ?';
        $params[] = $clientId;
        $types .= 'i';
    }

    if ($invoiceId) {
        $baseQuery .= ' AND dn.invoice_id = ?';
        $params[] = $invoiceId;
        $types .= 'i';
    }

    if ($fromDate !== '') {
        $baseQuery .= ' AND dn.delivery_date >= ?';
        $params[] = $fromDate;
        $types .= 's';
    }

    if ($toDate !== '') {
        $baseQuery .= ' AND dn.delivery_date <= ?';
        $params[] = $toDate;
        $types .= 's';
    }

    if ($search !== '') {
        $baseQuery .= ' AND (dn.delivery_note_number LIKE ? OR i.invoice_number LIKE ? OR c.company_name LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    $countStmt = $conn->prepare('SELECT COUNT(*) AS total ' . $baseQuery);
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $dataQuery = "
        SELECT dn.id, dn.delivery_note_number, dn.invoice_id, i.invoice_number,
               dn.client_id, c.company_name AS client_name, c.email AS client_email,
               dn.delivery_date, dn.dispatch_date, dn.delivered_at,
               dn.delivery_address, dn.contact_person, dn.contact_phone,
               dn.driver_name, dn.vehicle_number, dn.receiver_name,
               dn.status, dn.notes, dn.created_by, u.name AS created_by_name,
               dn.created_at, dn.updated_at,
               COALESCE((SELECT COUNT(*) FROM delivery_note_items dni WHERE dni.delivery_note_id = dn.id), 0) AS item_count,
               COALESCE((SELECT SUM(dni.quantity) FROM delivery_note_items dni WHERE dni.delivery_note_id = dn.id), 0) AS total_quantity
        {$baseQuery}
        ORDER BY dn.{$sortBy} {$sortOrder}
        LIMIT ? OFFSET ?
    ";

    $dataTypes = $types . 'ii';
    $dataParams = [...$params, $limit, $offset];
    $dataStmt = $conn->prepare($dataQuery);
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $result = $dataStmt->get_result();

    $deliveryNotes = [];
    while ($row = $result->fetch_assoc()) {
        $deliveryNotes[] = [
            'id' => (int) $row['id'],
            'delivery_note_number' => $row['delivery_note_number'],
            'invoice_id' => (int) $row['invoice_id'],
            'invoice_number' => $row['invoice_number'],
            'client_id' => (int) $row['client_id'],
            'client_name' => $row['client_name'],
            'client_email' => $row['client_email'],
            'delivery_date' => $row['delivery_date'],
            'dispatch_date' => $row['dispatch_date'],
            'delivered_at' => $row['delivered_at'],
            'delivery_address' => $row['delivery_address'],
            'contact_person' => $row['contact_person'],
            'contact_phone' => $row['contact_phone'],
            'driver_name' => $row['driver_name'],
            'vehicle_number' => $row['vehicle_number'],
            'receiver_name' => $row['receiver_name'],
            'status' => $row['status'],
            'notes' => $row['notes'],
            'created_by' => (int) $row['created_by'],
            'created_by_name' => $row['created_by_name'],
            'item_count' => (int) $row['item_count'],
            'total_quantity' => (float) $row['total_quantity'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
    $dataStmt->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Delivery notes fetched successfully.',
        'data' => $deliveryNotes,
        'meta' => [
            'total' => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
            'limit' => $limit,
            'page' => $page,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'search' => $search,
            'status' => $status,
            'client_id' => $clientId,
            'invoice_id' => $invoiceId,
            'from' => $fromDate,
            'to' => $toDate,
        ],
    ]);
} catch (Throwable $error) {
    error_log('Get Delivery Notes Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    $clientError = in_array($code, [400, 403, 405], true);

    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $error->getMessage() : 'Delivery notes could not be loaded right now.',
    ]);
}
