<?php
// routes/deliveryNotes/createDeliveryNote.php
// POST /delivery-notes/create
// Creates a delivery note from a finalized invoice. Delivery notes do not deduct stock;
// stock remains deducted only when the invoice is finalized.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/deliveryNotes.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    $userId = (int) $user['id'];
    $role = (string) $user['role'];
    $email = (string) $user['email'];

    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_SALES], 'Unauthorized: Only Admins or Sales users can create delivery notes.');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid or missing JSON payload.', 400);
    }

    if (empty($data['invoice_id']) || !is_numeric($data['invoice_id'])) {
        throw new Exception('A valid invoice is required.', 422);
    }

    $invoiceId = (int) $data['invoice_id'];

    $invoiceStmt = $conn->prepare(
        'SELECT i.id, i.invoice_number, i.client_id, i.created_by, i.status, i.stock_deducted,
                c.company_name AS client_name, c.billing_address, c.shipping_address,
                c.city, c.state, c.country, c.email AS client_email, c.phone AS client_phone
         FROM invoices i
         JOIN clients c ON c.id = i.client_id
         WHERE i.id = ?
         LIMIT 1'
    );
    $invoiceStmt->bind_param('i', $invoiceId);
    $invoiceStmt->execute();
    $invoice = $invoiceStmt->get_result()->fetch_assoc();
    $invoiceStmt->close();

    if (!$invoice) {
        throw new Exception('Invoice not found.', 404);
    }

    if ($role === ROLE_SALES && (int) $invoice['created_by'] !== $userId) {
        throw new Exception('Unauthorized: You can only create delivery notes for your own invoices.', 403);
    }

    if (in_array((string) $invoice['status'], ['draft', 'cancelled', 'reversed'], true)) {
        throw new Exception('Delivery notes can only be created for finalized active invoices.', 409);
    }

    if ((int) $invoice['stock_deducted'] !== 1) {
        throw new Exception('Stock must be deducted through invoice finalization before a delivery note can be created.', 409);
    }

    $itemsStmt = $conn->prepare(
        'SELECT ii.id AS invoice_item_id, ii.product_id, ii.description, ii.quantity,
                p.sku AS product_sku, p.unit_of_measure AS product_uom
         FROM invoice_items ii
         LEFT JOIN products p ON p.id = ii.product_id
         WHERE ii.invoice_id = ?
         ORDER BY ii.sort_order ASC, ii.id ASC'
    );
    $itemsStmt->bind_param('i', $invoiceId);
    $itemsStmt->execute();
    $invoiceItemsResult = $itemsStmt->get_result();

    $invoiceItems = [];
    while ($row = $invoiceItemsResult->fetch_assoc()) {
        $invoiceItems[(int) $row['invoice_item_id']] = [
            'invoice_item_id' => (int) $row['invoice_item_id'],
            'product_id' => $row['product_id'] ? (int) $row['product_id'] : null,
            'description' => (string) $row['description'],
            'ordered_quantity' => (float) $row['quantity'],
            'product_sku' => $row['product_sku'],
            'product_uom' => $row['product_uom'],
        ];
    }
    $itemsStmt->close();

    if (!$invoiceItems) {
        throw new Exception('This invoice has no items to deliver.', 409);
    }

    $deliveredStmt = $conn->prepare(
        'SELECT dni.invoice_item_id, COALESCE(SUM(dni.quantity), 0) AS delivered_quantity
         FROM delivery_note_items dni
         JOIN delivery_notes dn ON dn.id = dni.delivery_note_id
         WHERE dn.invoice_id = ? AND dn.status <> "cancelled"
         GROUP BY dni.invoice_item_id'
    );
    $deliveredStmt->bind_param('i', $invoiceId);
    $deliveredStmt->execute();
    $deliveredResult = $deliveredStmt->get_result();

    $alreadyDelivered = [];
    while ($row = $deliveredResult->fetch_assoc()) {
        $alreadyDelivered[(int) $row['invoice_item_id']] = (float) $row['delivered_quantity'];
    }
    $deliveredStmt->close();

    $payloadItems = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    $validatedItems = [];

    if ($payloadItems) {
        foreach ($payloadItems as $index => $payloadItem) {
            $lineNo = $index + 1;
            if (empty($payloadItem['invoice_item_id']) || !is_numeric($payloadItem['invoice_item_id'])) {
                throw new Exception("Item {$lineNo}: invoice item is required.", 422);
            }

            $invoiceItemId = (int) $payloadItem['invoice_item_id'];
            if (!isset($invoiceItems[$invoiceItemId])) {
                throw new Exception("Item {$lineNo}: invoice item does not belong to this invoice.", 422);
            }

            if (!isset($payloadItem['quantity']) || !is_numeric($payloadItem['quantity']) || (float) $payloadItem['quantity'] <= 0) {
                throw new Exception("Item {$lineNo}: delivery quantity must be greater than zero.", 422);
            }

            $quantity = round((float) $payloadItem['quantity'], 2);
            $item = $invoiceItems[$invoiceItemId];
            $remaining = round($item['ordered_quantity'] - ($alreadyDelivered[$invoiceItemId] ?? 0), 2);

            if ($quantity > $remaining) {
                throw new Exception("Item {$lineNo}: delivery quantity exceeds the remaining quantity ({$remaining}).", 422);
            }

            $validatedItems[] = [
                ...$item,
                'quantity' => $quantity,
                'sort_order' => $index,
            ];
        }
    } else {
        $sortOrder = 0;
        foreach ($invoiceItems as $invoiceItemId => $item) {
            $remaining = round($item['ordered_quantity'] - ($alreadyDelivered[$invoiceItemId] ?? 0), 2);
            if ($remaining <= 0) {
                continue;
            }

            $validatedItems[] = [
                ...$item,
                'quantity' => $remaining,
                'sort_order' => $sortOrder++,
            ];
        }
    }

    if (!$validatedItems) {
        throw new Exception('There are no remaining invoice items available for delivery.', 409);
    }

    $today = date('Y-m-d');
    $deliveryDate = validYmdOrDefault(trim((string) ($data['delivery_date'] ?? '')), $today);
    $dispatchDate = trimNullable($data['dispatch_date'] ?? null);
    if ($dispatchDate !== null) {
        $dispatchDate = validYmdOrDefault($dispatchDate, $deliveryDate);
    }

    $deliveryAddress = trimNullable($data['delivery_address'] ?? null)
        ?: trim((string) ($invoice['shipping_address'] ?: $invoice['billing_address']));
    $deliveryAddress = $deliveryAddress !== ''
        ? $deliveryAddress
        : trim(implode(', ', array_filter([(string) $invoice['city'], (string) $invoice['state'], (string) $invoice['country']])));

    $contactPerson = trimNullable($data['contact_person'] ?? null);
    $contactPhone = trimNullable($data['contact_phone'] ?? null) ?: trimNullable($invoice['client_phone'] ?? null);
    $driverName = trimNullable($data['driver_name'] ?? null);
    $vehicleNumber = trimNullable($data['vehicle_number'] ?? null);
    $receiverName = trimNullable($data['receiver_name'] ?? null);
    $notes = trimNullable($data['notes'] ?? null);

    $conn->begin_transaction();

    try {
        $deliveryNoteNumber = nextDeliveryNoteNumber($conn);

        $insert = $conn->prepare(
            'INSERT INTO delivery_notes
                (delivery_note_number, invoice_id, client_id, created_by, delivery_date, dispatch_date,
                 delivery_address, contact_person, contact_phone, driver_name, vehicle_number,
                 receiver_name, notes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft")'
        );
        $clientId = (int) $invoice['client_id'];
        $insert->bind_param(
            'siiisssssssss',
            $deliveryNoteNumber,
            $invoiceId,
            $clientId,
            $userId,
            $deliveryDate,
            $dispatchDate,
            $deliveryAddress,
            $contactPerson,
            $contactPhone,
            $driverName,
            $vehicleNumber,
            $receiverName,
            $notes
        );
        $insert->execute();
        $deliveryNoteId = (int) $insert->insert_id;
        $insert->close();

        $itemInsert = $conn->prepare(
            'INSERT INTO delivery_note_items
                (delivery_note_id, invoice_item_id, product_id, description, ordered_quantity, quantity,
                 product_sku, product_uom, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($validatedItems as $item) {
            $invoiceItemId = (int) $item['invoice_item_id'];
            $productId = $item['product_id'];
            $description = (string) $item['description'];
            $orderedQuantity = (float) $item['ordered_quantity'];
            $quantity = (float) $item['quantity'];
            $productSku = $item['product_sku'];
            $productUom = $item['product_uom'];
            $sortOrder = (int) $item['sort_order'];

            $itemInsert->bind_param(
                'iiisddssi',
                $deliveryNoteId,
                $invoiceItemId,
                $productId,
                $description,
                $orderedQuantity,
                $quantity,
                $productSku,
                $productUom,
                $sortOrder
            );
            $itemInsert->execute();
        }
        $itemInsert->close();

        logDeliveryNoteAction(
            $conn,
            $userId,
            'delivery_note.created',
            $deliveryNoteId,
            "{$email} created delivery note {$deliveryNoteNumber} from invoice {$invoice['invoice_number']} for '{$invoice['client_name']}'.",
            ['invoice_id' => $invoiceId, 'items' => count($validatedItems)]
        );

        $conn->commit();

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Delivery note created successfully.',
            'data' => [
                'id' => $deliveryNoteId,
                'delivery_note_number' => $deliveryNoteNumber,
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoice['invoice_number'],
                'client_name' => $invoice['client_name'],
                'status' => 'draft',
                'item_count' => count($validatedItems),
            ],
        ]);
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }
} catch (Throwable $error) {
    error_log('Create Delivery Note Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);

    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $error->getMessage() : 'Delivery note could not be created right now.',
    ]);
}
