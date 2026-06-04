<?php
// routes/paymentLinks/getPaymentLinks.php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/paymentLinks.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING, ROLE_SALES], 'You do not have permission to view payment links.');

    $invoiceId = isset($_GET['invoice_id']) && is_numeric($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : null;
    $clientId = isset($_GET['client_id']) && is_numeric($_GET['client_id']) ? (int) $_GET['client_id'] : null;
    $provider = isset($_GET['provider']) ? strtolower(trim((string) $_GET['provider'])) : null;
    $status = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : null;
    $from = trim((string) ($_GET['from'] ?? '')) ?: null;
    $to = trim((string) ($_GET['to'] ?? '')) ?: null;
    $search = trim((string) ($_GET['search'] ?? '')) ?: null;
    $limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    $allowedProviders = ['manual', 'paystack'];
    $allowedStatuses = ['pending', 'processing', 'paid', 'failed', 'expired', 'cancelled'];
    if ($provider && !in_array($provider, $allowedProviders, true)) {
        $provider = null;
    }
    if ($status && !in_array($status, $allowedStatuses, true)) {
        $status = null;
    }

    $base = "
        FROM payment_links pl
        JOIN invoices i ON i.id = pl.invoice_id
        JOIN clients c ON c.id = pl.client_id
        LEFT JOIN users u ON u.id = pl.created_by
        LEFT JOIN payment_receipts r ON r.id = pl.receipt_id
        WHERE 1=1
    ";
    $params = [];
    $types = '';

    if (($user['role'] ?? '') === ROLE_SALES) {
        $base .= ' AND i.created_by = ?';
        $params[] = (int) $user['id'];
        $types .= 'i';
    }
    if ($invoiceId) {
        $base .= ' AND pl.invoice_id = ?';
        $params[] = $invoiceId;
        $types .= 'i';
    }
    if ($clientId) {
        $base .= ' AND pl.client_id = ?';
        $params[] = $clientId;
        $types .= 'i';
    }
    if ($provider) {
        $base .= ' AND pl.provider = ?';
        $params[] = $provider;
        $types .= 's';
    }
    if ($status) {
        $base .= ' AND pl.status = ?';
        $params[] = $status;
        $types .= 's';
    }
    if ($from) {
        $base .= ' AND DATE(pl.created_at) >= ?';
        $params[] = $from;
        $types .= 's';
    }
    if ($to) {
        $base .= ' AND DATE(pl.created_at) <= ?';
        $params[] = $to;
        $types .= 's';
    }
    if ($search) {
        $base .= ' AND (pl.reference LIKE ? OR i.invoice_number LIKE ? OR c.company_name LIKE ? OR pl.provider_reference LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
        $types .= 'ssss';
    }

    $count = $conn->prepare('SELECT COUNT(*) AS total ' . $base);
    if ($params) {
        $count->bind_param($types, ...$params);
    }
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $query = "
        SELECT pl.*, i.invoice_number, c.company_name AS client_name, c.email AS client_email,
               u.name AS created_by_name, r.receipt_number
        {$base}
        ORDER BY pl.created_at DESC, pl.id DESC
        LIMIT ? OFFSET ?
    ";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $links = [];
    while ($row = $result->fetch_assoc()) {
        $links[] = paymentLinkResponse($row);
    }
    $stmt->close();

    echo json_encode([
        'status' => 'success',
        'message' => 'Payment links fetched successfully.',
        'data' => $links,
        'meta' => [
            'total' => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
            'page' => $page,
            'limit' => $limit,
            'invoice_id' => $invoiceId,
            'client_id' => $clientId,
            'provider' => $provider,
            'status' => $status,
            'from' => $from,
            'to' => $to,
            'search' => $search,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Get Payment Links Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode(['status' => 'failed', 'message' => $clientError ? $e->getMessage() : 'Payment links could not be loaded right now.']);
}
