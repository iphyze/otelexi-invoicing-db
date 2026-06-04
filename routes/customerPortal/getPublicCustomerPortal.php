<?php
// routes/customerPortal/getPublicCustomerPortal.php
// Public customer portal data loaded through a secure, hashed magic link token.

// This route intentionally does not require staff authentication. It exposes only customer-safe
// invoice, delivery, receipt and payment-request data for the invoice attached to the portal link.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../utils/customerPortal.php';
require_once __DIR__ . '/../../utils/paymentLinks.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

function portalPublicStatusLabel(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'sent' => 'Sent',
        'partial' => 'Partially Paid',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'cancelled' => 'Cancelled',
        'reversed' => 'Reversed',
        'credited' => 'Credited',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
        'pending' => 'Pending',
        'processing' => 'Processing',
        'failed' => 'Failed',
        'expired' => 'Expired',
        default => ucfirst($status),
    };
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        throw new Exception('A valid customer portal token is required.', 422);
    }

    if (!preg_match('/^[A-Za-z0-9_-]{32,160}$/', $token)) {
        throw new Exception('Invalid customer portal token.', 422);
    }

    $tokenHash = customerPortalTokenHash($token);
    $stmt = $conn->prepare(
        "SELECT cpl.*, i.id AS invoice_id, i.invoice_number, i.client_id, i.issue_date, i.due_date,
                i.currency, i.subtotal, i.discount_amount, i.taxable_amount, i.tax_amount,
                i.total_amount, i.amount_paid, i.credited_amount, i.refunded_amount, i.balance_due,
                i.payment_terms, i.footer_text, i.notes, i.status AS invoice_status,
                c.company_name AS client_name, c.billing_address AS client_address,
                c.city AS client_city, c.state AS client_state, c.country AS client_country,
                c.email AS client_email, c.phone AS client_phone, c.tax_id AS client_tax_id
         FROM customer_portal_links cpl
         JOIN invoices i ON i.id = cpl.invoice_id
         JOIN clients c ON c.id = cpl.client_id
         WHERE cpl.token_hash = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $portal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$portal) {
        throw new Exception('Customer portal link not found or no longer available.', 404);
    }

    if ((string) $portal['status'] !== 'active') {
        throw new Exception('Customer portal link is no longer active.', 410);
    }

    if ((new DateTime((string) $portal['expires_at'])) < new DateTime('now')) {
        $expired = $conn->prepare("UPDATE customer_portal_links SET status = 'expired', updated_at = NOW() WHERE id = ?");
        $expired->bind_param('i', $portal['id']);
        $expired->execute();
        $expired->close();
        throw new Exception('Customer portal link has expired. Please request a fresh link from Otelex.', 410);
    }

    $invoiceId = (int) $portal['invoice_id'];

    $access = $conn->prepare('UPDATE customer_portal_links SET last_accessed_at = NOW(), access_count = access_count + 1 WHERE id = ?');
    $access->bind_param('i', $portal['id']);
    $access->execute();
    $access->close();

    $settings = fetchCustomerPortalCompanySettings($conn);

    $itemsStmt = $conn->prepare(
        'SELECT ii.id, ii.description, ii.quantity, ii.unit_price, ii.tax_rate, ii.tax_amount,
                ii.discount_amount, ii.line_total, p.sku AS product_sku, p.unit_of_measure AS product_uom
         FROM invoice_items ii
         LEFT JOIN products p ON p.id = ii.product_id
         WHERE ii.invoice_id = ?
         ORDER BY ii.sort_order ASC, ii.id ASC'
    );
    $itemsStmt->bind_param('i', $invoiceId);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    $items = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = [
            'id' => (int) $row['id'],
            'description' => (string) $row['description'],
            'quantity' => (float) $row['quantity'],
            'unit_price' => (float) $row['unit_price'],
            'tax_rate' => (float) $row['tax_rate'],
            'tax_amount' => (float) $row['tax_amount'],
            'discount_amount' => (float) $row['discount_amount'],
            'line_total' => (float) $row['line_total'],
            'product_sku' => $row['product_sku'],
            'product_uom' => $row['product_uom'],
        ];
    }
    $itemsStmt->close();

    $paymentLinksStmt = $conn->prepare(
        "SELECT pl.*, i.invoice_number, c.company_name AS client_name, c.email AS client_email, r.receipt_number
         FROM payment_links pl
         JOIN invoices i ON i.id = pl.invoice_id
         JOIN clients c ON c.id = pl.client_id
         LEFT JOIN payment_receipts r ON r.id = pl.receipt_id
         WHERE pl.invoice_id = ?
           AND pl.status IN ('pending','processing','paid','failed','expired')
         ORDER BY FIELD(pl.status, 'pending', 'processing', 'paid', 'failed', 'expired'), pl.created_at DESC, pl.id DESC
         LIMIT 10"
    );
    $paymentLinksStmt->bind_param('i', $invoiceId);
    $paymentLinksStmt->execute();
    $paymentLinksResult = $paymentLinksStmt->get_result();
    $paymentRequests = [];
    while ($row = $paymentLinksResult->fetch_assoc()) {
        $status = (string) $row['status'];
        $isExpired = false;
        if (in_array($status, ['pending', 'processing'], true) && !empty($row['expires_at'])) {
            try {
                $isExpired = (new DateTime((string) $row['expires_at'])) < new DateTime('now');
            } catch (Throwable $e) {
                $isExpired = false;
            }
        }
        $effectiveStatus = $isExpired ? 'expired' : $status;
        $paymentRequests[] = [
            'id' => (int) $row['id'],
            'provider' => (string) $row['provider'],
            'reference' => (string) $row['reference'],
            'amount' => (float) $row['amount'],
            'currency' => (string) $row['currency'],
            'status' => $effectiveStatus,
            'status_label' => paymentLinkStatusLabel($effectiveStatus),
            'expires_at' => $row['expires_at'],
            'paid_at' => $row['paid_at'],
            'payment_url' => in_array($effectiveStatus, ['pending', 'processing'], true)
                ? paymentLinkFrontendUrl($row['authorization_url'] ?? null, (string) $row['reference'], (string) $row['provider'])
                : null,
        ];
    }
    $paymentLinksStmt->close();

    $deliveryStmt = $conn->prepare(
        "SELECT dn.id, dn.delivery_note_number, dn.delivery_date, dn.dispatch_date, dn.delivered_at,
                dn.delivery_address, dn.contact_person, dn.contact_phone, dn.driver_name, dn.vehicle_number,
                dn.receiver_name, dn.notes, dn.status
         FROM delivery_notes dn
         WHERE dn.invoice_id = ? AND dn.status <> 'cancelled'
         ORDER BY dn.delivery_date DESC, dn.id DESC"
    );
    $deliveryStmt->bind_param('i', $invoiceId);
    $deliveryStmt->execute();
    $deliveryResult = $deliveryStmt->get_result();
    $deliveryNotes = [];
    while ($row = $deliveryResult->fetch_assoc()) {
        $deliveryNotes[(int) $row['id']] = [
            'id' => (int) $row['id'],
            'delivery_note_number' => (string) $row['delivery_note_number'],
            'delivery_date' => $row['delivery_date'],
            'dispatch_date' => $row['dispatch_date'],
            'delivered_at' => $row['delivered_at'],
            'delivery_address' => (string) $row['delivery_address'],
            'contact_person' => $row['contact_person'],
            'contact_phone' => $row['contact_phone'],
            'driver_name' => $row['driver_name'],
            'vehicle_number' => $row['vehicle_number'],
            'receiver_name' => $row['receiver_name'],
            'notes' => $row['notes'],
            'status' => (string) $row['status'],
            'status_label' => portalPublicStatusLabel((string) $row['status']),
            'items' => [],
        ];
    }
    $deliveryStmt->close();

    if ($deliveryNotes) {
        $ids = array_keys($deliveryNotes);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $itemStmt = $conn->prepare(
            "SELECT delivery_note_id, description, ordered_quantity, quantity, product_sku, product_uom
             FROM delivery_note_items
             WHERE delivery_note_id IN ({$placeholders})
             ORDER BY sort_order ASC, id ASC"
        );
        $itemStmt->bind_param($types, ...$ids);
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        while ($row = $itemResult->fetch_assoc()) {
            $deliveryNotes[(int) $row['delivery_note_id']]['items'][] = [
                'description' => (string) $row['description'],
                'ordered_quantity' => (float) $row['ordered_quantity'],
                'quantity' => (float) $row['quantity'],
                'product_sku' => $row['product_sku'],
                'product_uom' => $row['product_uom'],
            ];
        }
        $itemStmt->close();
    }

    $receiptsStmt = $conn->prepare(
        'SELECT r.id, r.receipt_number, r.amount_received, r.balance_after_payment,
                r.payment_date, r.payment_method, r.payment_reference, r.issued_at
         FROM payment_receipts r
         JOIN payments p ON p.id = r.payment_id
         WHERE p.invoice_id = ?
         ORDER BY r.issued_at DESC, r.id DESC'
    );
    $receiptsStmt->bind_param('i', $invoiceId);
    $receiptsStmt->execute();
    $receiptsResult = $receiptsStmt->get_result();
    $receipts = [];
    while ($row = $receiptsResult->fetch_assoc()) {
        $receipts[] = [
            'id' => (int) $row['id'],
            'receipt_number' => (string) $row['receipt_number'],
            'amount_received' => (float) $row['amount_received'],
            'balance_after_payment' => (float) $row['balance_after_payment'],
            'payment_date' => $row['payment_date'],
            'payment_method' => $row['payment_method'],
            'payment_reference' => $row['payment_reference'],
            'issued_at' => $row['issued_at'],
        ];
    }
    $receiptsStmt->close();

    $invoiceStatus = (string) $portal['invoice_status'];
    $today = date('Y-m-d');
    $isOverdue = in_array($invoiceStatus, ['sent', 'partial', 'overdue'], true)
        && (string) $portal['due_date'] < $today
        && (float) $portal['balance_due'] > 0;
    $daysOverdue = 0;
    if ($isOverdue) {
        $daysOverdue = (int) (new DateTime($today))->diff(new DateTime((string) $portal['due_date']))->days;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Customer portal loaded successfully.',
        'data' => [
            'portal' => [
                'reference' => (string) $portal['reference'],
                'expires_at' => $portal['expires_at'],
                'last_accessed_at' => date('Y-m-d H:i:s'),
            ],
            'company' => [
                'company_name' => safeCustomerPortalString($settings['company_name'] ?? 'Otelex Hospitality Supplies Ltd'),
                'address' => safeCustomerPortalString($settings['address'] ?? ''),
                'city' => safeCustomerPortalString($settings['city'] ?? ''),
                'state' => safeCustomerPortalString($settings['state'] ?? ''),
                'country' => safeCustomerPortalString($settings['country'] ?? 'Nigeria'),
                'phone' => safeCustomerPortalString($settings['phone'] ?? ''),
                'email' => safeCustomerPortalString($settings['email'] ?? ''),
                'website' => safeCustomerPortalString($settings['website'] ?? ''),
                'logo_path' => safeCustomerPortalString($settings['logo_path'] ?? ''),
                'legal_footer' => safeCustomerPortalString($settings['legal_footer'] ?? ''),
            ],
            'bank' => [
                'bank_name' => safeCustomerPortalString($settings['bank_name'] ?? ''),
                'account_name' => safeCustomerPortalString($settings['account_name'] ?? ''),
                'account_number' => safeCustomerPortalString($settings['account_number'] ?? ''),
                'bank_branch' => safeCustomerPortalString($settings['bank_branch'] ?? ''),
            ],
            'client' => [
                'company_name' => (string) $portal['client_name'],
                'email' => (string) $portal['client_email'],
                'phone' => $portal['client_phone'],
                'address' => $portal['client_address'],
                'city' => $portal['client_city'],
                'state' => $portal['client_state'],
                'country' => $portal['client_country'],
                'tax_id' => $portal['client_tax_id'],
            ],
            'invoice' => [
                'id' => (int) $portal['invoice_id'],
                'invoice_number' => (string) $portal['invoice_number'],
                'issue_date' => $portal['issue_date'],
                'due_date' => $portal['due_date'],
                'is_overdue' => $isOverdue,
                'days_overdue' => $daysOverdue,
                'currency' => (string) $portal['currency'],
                'subtotal' => (float) $portal['subtotal'],
                'discount_amount' => (float) $portal['discount_amount'],
                'taxable_amount' => (float) $portal['taxable_amount'],
                'tax_amount' => (float) $portal['tax_amount'],
                'total_amount' => (float) $portal['total_amount'],
                'amount_paid' => (float) $portal['amount_paid'],
                'credited_amount' => (float) $portal['credited_amount'],
                'refunded_amount' => (float) $portal['refunded_amount'],
                'balance_due' => (float) $portal['balance_due'],
                'payment_terms' => $portal['payment_terms'],
                'footer_text' => $portal['footer_text'],
                'notes' => $portal['notes'],
                'status' => $invoiceStatus,
                'status_label' => portalPublicStatusLabel($invoiceStatus),
                'items' => $items,
            ],
            'payment_requests' => $paymentRequests,
            'delivery_notes' => array_values($deliveryNotes),
            'receipts' => $receipts,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Public Customer Portal Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 404, 405, 410, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $e->getMessage() : 'Customer portal could not be loaded right now.',
    ]);
}
