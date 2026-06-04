<?php
// routes/deliveryNotes/getSingleDeliveryNote.php
// GET /delivery-notes/{id}

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

    if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('A valid delivery note ID is required.', 400);
    }

    $deliveryNoteId = (int) $_GET['id'];

    $stmt = $conn->prepare(
        'SELECT dn.*, i.invoice_number, i.issue_date AS invoice_issue_date, i.due_date AS invoice_due_date,
                i.status AS invoice_status, i.currency, i.total_amount,
                i.created_by AS invoice_created_by,
                c.company_name AS client_name, c.billing_address AS client_billing_address,
                c.shipping_address AS client_shipping_address, c.city AS client_city,
                c.state AS client_state, c.country AS client_country, c.email AS client_email,
                c.phone AS client_phone, c.tax_id AS client_tax_id,
                u.name AS created_by_name
         FROM delivery_notes dn
         JOIN invoices i ON i.id = dn.invoice_id
         JOIN clients c ON c.id = dn.client_id
         LEFT JOIN users u ON u.id = dn.created_by
         WHERE dn.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $deliveryNoteId);
    $stmt->execute();
    $note = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$note) {
        throw new Exception('Delivery note not found.', 404);
    }

    if ($role === ROLE_SALES && (int) $note['invoice_created_by'] !== $userId && (int) $note['created_by'] !== $userId) {
        throw new Exception('Unauthorized: You cannot view this delivery note.', 403);
    }

    $itemsStmt = $conn->prepare(
        'SELECT dni.id, dni.invoice_item_id, dni.product_id, dni.description,
                dni.ordered_quantity, dni.quantity, dni.product_sku, dni.product_uom,
                dni.sort_order, p.name AS product_name
         FROM delivery_note_items dni
         LEFT JOIN products p ON p.id = dni.product_id
         WHERE dni.delivery_note_id = ?
         ORDER BY dni.sort_order ASC, dni.id ASC'
    );
    $itemsStmt->bind_param('i', $deliveryNoteId);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();

    $items = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = [
            'id' => (int) $row['id'],
            'invoice_item_id' => (int) $row['invoice_item_id'],
            'product_id' => $row['product_id'] ? (int) $row['product_id'] : null,
            'product_name' => $row['product_name'],
            'product_sku' => $row['product_sku'],
            'product_uom' => $row['product_uom'],
            'description' => $row['description'],
            'ordered_quantity' => (float) $row['ordered_quantity'],
            'quantity' => (float) $row['quantity'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }
    $itemsStmt->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Delivery note fetched successfully.',
        'data' => [
            'id' => (int) $note['id'],
            'delivery_note_number' => $note['delivery_note_number'],
            'invoice_id' => (int) $note['invoice_id'],
            'invoice_number' => $note['invoice_number'],
            'invoice' => [
                'id' => (int) $note['invoice_id'],
                'invoice_number' => $note['invoice_number'],
                'issue_date' => $note['invoice_issue_date'],
                'due_date' => $note['invoice_due_date'],
                'status' => $note['invoice_status'],
                'currency' => $note['currency'],
                'total_amount' => (float) $note['total_amount'],
            ],
            'client' => [
                'id' => (int) $note['client_id'],
                'company_name' => $note['client_name'],
                'billing_address' => $note['client_billing_address'],
                'shipping_address' => $note['client_shipping_address'],
                'city' => $note['client_city'],
                'state' => $note['client_state'],
                'country' => $note['client_country'],
                'email' => $note['client_email'],
                'phone' => $note['client_phone'],
                'tax_id' => $note['client_tax_id'],
            ],
            'created_by' => [
                'id' => (int) $note['created_by'],
                'name' => $note['created_by_name'],
            ],
            'delivery_date' => $note['delivery_date'],
            'dispatch_date' => $note['dispatch_date'],
            'delivered_at' => $note['delivered_at'],
            'cancelled_at' => $note['cancelled_at'],
            'delivery_address' => $note['delivery_address'],
            'contact_person' => $note['contact_person'],
            'contact_phone' => $note['contact_phone'],
            'driver_name' => $note['driver_name'],
            'vehicle_number' => $note['vehicle_number'],
            'receiver_name' => $note['receiver_name'],
            'notes' => $note['notes'],
            'status' => $note['status'],
            'items' => $items,
            'created_at' => $note['created_at'],
            'updated_at' => $note['updated_at'],
        ],
    ]);
} catch (Throwable $error) {
    error_log('Get Single Delivery Note Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    $clientError = in_array($code, [400, 403, 404, 405], true);

    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $error->getMessage() : 'Delivery note could not be loaded right now.',
    ]);
}
