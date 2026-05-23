<?php
// routes/invoices/getSingleInvoice.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';

/**
 * GET /invoices/{id}
 * Get a single invoice with all line items and full payment history.
 * Roles allowed: Admin, Sales (own only), Accounting
 *
 * Query param: ?id=7
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData         = authenticateUser();
    $loggedInUserId   = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];

    if (!in_array($loggedInUserRole, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_SALES, ROLE_ACCOUNTING], true)) {
        throw new Exception("Unauthorized: You do not have permission to view invoices.", 403);
    }

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Invoice ID is required.", 400);
    }
    $invoiceId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 1. Fetch invoice header
    // -------------------------------------------------------
    $invoiceStmt = $conn->prepare("
        SELECT
            i.id, i.invoice_number,
            i.proforma_id, i.quotation_id,
            i.client_id,
            c.company_name AS client_name,
            c.billing_address AS client_address,
            c.city AS client_city, c.state AS client_state, c.country AS client_country,
            c.email AS client_email, c.phone AS client_phone,
            c.tax_id AS client_tax_id,
            c.currency AS client_currency, c.payment_terms AS client_payment_terms,
            i.created_by, u.name AS created_by_name,
            i.approved_by, ua.name AS approved_by_name,
            i.issue_date, i.due_date,
            i.currency, i.exchange_rate,
            i.subtotal, i.discount_type, i.discount_value, i.discount_amount,
            i.taxable_amount, i.tax_amount, i.total_amount,
            i.amount_paid, i.credited_amount, i.refunded_amount, i.balance_due,
            i.payment_terms, i.footer_text, i.notes, i.reversal_reason,
            i.reversed_by, ur.name AS reversed_by_name, i.reversed_at,
            i.status, i.stock_deducted,
            i.reminder_count, i.last_reminder_at, i.next_reminder_at,
            i.created_at, i.updated_at
        FROM invoices i
        JOIN clients c ON c.id = i.client_id
        LEFT JOIN users u  ON u.id  = i.created_by
        LEFT JOIN users ua ON ua.id = i.approved_by
        LEFT JOIN users ur ON ur.id = i.reversed_by
        WHERE i.id = ?
        LIMIT 1
    ");
    if (!$invoiceStmt) throw new Exception("Database query failed: " . $conn->error, 500);

    $invoiceStmt->bind_param("i", $invoiceId);
    $invoiceStmt->execute();
    $invoiceResult = $invoiceStmt->get_result();

    if ($invoiceResult->num_rows === 0) throw new Exception("Invoice not found.", 404);

    $invoice = $invoiceResult->fetch_assoc();
    $invoiceStmt->close();

    // Sales can only view their own
    if ($loggedInUserRole === 'sales' && (int)$invoice['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You do not have permission to view this invoice.", 403);
    }

    // -------------------------------------------------------
    // 2. Fetch line items
    // -------------------------------------------------------
    $itemsStmt = $conn->prepare("
        SELECT
            ii.id, ii.product_id, ii.description,
            ii.quantity, ii.unit_price,
            ii.tax_rate, ii.tax_amount,
            ii.discount_type, ii.discount_value, ii.discount_amount,
            ii.line_total, ii.sort_order,
            p.name AS product_name, p.sku AS product_sku,
            p.unit_of_measure AS product_uom
        FROM invoice_items ii
        LEFT JOIN products p ON p.id = ii.product_id
        WHERE ii.invoice_id = ?
        ORDER BY ii.sort_order ASC, ii.id ASC
    ");
    $itemsStmt->bind_param("i", $invoiceId);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();

    $items = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = [
            "id"              => (int)$row['id'],
            "product_id"      => $row['product_id'] ? (int)$row['product_id'] : null,
            "product_name"    => $row['product_name'],
            "product_sku"     => $row['product_sku'],
            "product_uom"     => $row['product_uom'],
            "description"     => $row['description'],
            "quantity"        => (float)$row['quantity'],
            "unit_price"      => (float)$row['unit_price'],
            "tax_rate"        => (float)$row['tax_rate'],
            "tax_amount"      => (float)$row['tax_amount'],
            "discount_type"   => $row['discount_type'],
            "discount_value"  => (float)$row['discount_value'],
            "discount_amount" => (float)$row['discount_amount'],
            "line_total"      => (float)$row['line_total'],
            "sort_order"      => (int)$row['sort_order']
        ];
    }
    $itemsStmt->close();

    // -------------------------------------------------------
    // 3. Fetch payment history
    // -------------------------------------------------------
    $paymentsStmt = $conn->prepare("
        SELECT
            p.id, p.amount, p.payment_date, p.payment_method,
            p.reference, p.notes, p.created_at,
            u.name AS recorded_by_name,
            r.id AS receipt_id, r.receipt_number, r.invoice_number AS receipt_invoice_number,
            r.client_name AS receipt_client_name, r.client_email AS receipt_client_email,
            r.client_address AS receipt_client_address, r.currency AS receipt_currency,
            r.invoice_total AS receipt_invoice_total,
            r.credited_amount AS receipt_credited_amount,
            r.refunded_amount_before_payment AS receipt_refunded_before_payment,
            r.adjusted_invoice_total AS receipt_adjusted_total,
            r.previous_amount_paid, r.amount_received, r.balance_after_payment,
            r.payment_date AS receipt_payment_date, r.payment_method AS receipt_payment_method,
            r.payment_reference, r.payment_notes, r.issued_by, r.issued_at,
            ru.name AS issued_by_name
        FROM payments p
        LEFT JOIN users u ON u.id = p.recorded_by
        LEFT JOIN payment_receipts r ON r.payment_id = p.id
        LEFT JOIN users ru ON ru.id = r.issued_by
        WHERE p.invoice_id = ?
        ORDER BY p.payment_date ASC, p.id ASC
    ");
    $paymentsStmt->bind_param("i", $invoiceId);
    $paymentsStmt->execute();
    $paymentsResult = $paymentsStmt->get_result();

    $payments = [];
    while ($row = $paymentsResult->fetch_assoc()) {
        $payments[] = [
            "id"               => (int)$row['id'],
            "amount"           => (float)$row['amount'],
            "payment_date"     => $row['payment_date'],
            "payment_method"   => $row['payment_method'],
            "reference"        => $row['reference'],
            "notes"            => $row['notes'],
            "recorded_by_name" => $row['recorded_by_name'],
            "created_at"       => $row['created_at'],
            "receipt"          => $row['receipt_id'] ? [
                "id"                   => (int) $row['receipt_id'],
                "receipt_number"       => $row['receipt_number'],
                "payment_id"           => (int) $row['id'],
                "invoice_id"           => $invoiceId,
                "invoice_number"       => $row['receipt_invoice_number'],
                "client"               => [
                    "company_name" => $row['receipt_client_name'],
                    "email"        => $row['receipt_client_email'],
                    "address"      => $row['receipt_client_address']
                ],
                "currency"             => $row['receipt_currency'],
                "invoice_total"        => (float) $row['receipt_invoice_total'],
                "credited_amount"      => (float) ($row['receipt_credited_amount'] ?? 0),
                "refunded_amount_before_payment" => (float) ($row['receipt_refunded_before_payment'] ?? 0),
                "adjusted_invoice_total"=> (float) ($row['receipt_adjusted_total'] ?: $row['receipt_invoice_total']),
                "previous_amount_paid" => (float) $row['previous_amount_paid'],
                "amount_received"      => (float) $row['amount_received'],
                "balance_after_payment"=> (float) $row['balance_after_payment'],
                "payment_date"         => $row['receipt_payment_date'],
                "payment_method"       => $row['receipt_payment_method'],
                "payment_reference"    => $row['payment_reference'],
                "payment_notes"        => $row['payment_notes'],
                "issued_by"            => [
                    "id"   => (int) $row['issued_by'],
                    "name" => $row['issued_by_name']
                ],
                "issued_at"            => $row['issued_at']
            ] : null
        ];
    }
    $paymentsStmt->close();

    // -------------------------------------------------------
    // 4. Fetch overdue reminder history
    // -------------------------------------------------------
    $reminderStmt = $conn->prepare("
        SELECT
            irl.id, irl.reminder_stage, irl.days_overdue,
            irl.recipient_email, irl.delivery_status,
            irl.failure_reason, irl.trigger_source, irl.attempted_at,
            u.name AS sent_by_name
        FROM invoice_reminder_logs irl
        LEFT JOIN users u ON u.id = irl.sent_by
        WHERE irl.invoice_id = ?
        ORDER BY irl.attempted_at DESC, irl.id DESC
        LIMIT 20
    ");
    $reminderStmt->bind_param("i", $invoiceId);
    $reminderStmt->execute();
    $reminderResults = $reminderStmt->get_result();

    $reminderHistory = [];
    while ($row = $reminderResults->fetch_assoc()) {
        $reminderHistory[] = [
            "id"               => (int) $row['id'],
            "reminder_stage"   => (int) $row['reminder_stage'],
            "days_overdue"     => (int) $row['days_overdue'],
            "recipient_email"  => $row['recipient_email'],
            "delivery_status"  => $row['delivery_status'],
            "failure_reason"   => $row['failure_reason'],
            "trigger_source"   => $row['trigger_source'],
            "attempted_at"     => $row['attempted_at'],
            "sent_by_name"     => $row['sent_by_name']
        ];
    }
    $reminderStmt->close();

    // -------------------------------------------------------
    // 5. Fetch credit notes and refunds
    // -------------------------------------------------------
    $creditStmt = $conn->prepare("
        SELECT cn.id, cn.credit_note_number, cn.currency, cn.credit_type, cn.amount, cn.tax_amount,
               cn.reason, cn.restore_stock, cn.stock_restored_at, cn.status,
               cn.issued_at, u.name AS issued_by_name,
               COALESCE((SELECT SUM(rf.amount) FROM refunds rf WHERE rf.credit_note_id = cn.id AND rf.status = 'processed'), 0) AS refunded_amount
        FROM credit_notes cn
        LEFT JOIN users u ON u.id = cn.issued_by
        WHERE cn.invoice_id = ?
        ORDER BY cn.issued_at DESC, cn.id DESC
    ");
    $creditStmt->bind_param("i", $invoiceId);
    $creditStmt->execute();
    $creditResults = $creditStmt->get_result();
    $creditNotes = [];
    while ($row = $creditResults->fetch_assoc()) {
        $creditNotes[] = [
            "id" => (int) $row['id'],
            "credit_note_number" => $row['credit_note_number'],
            "currency" => $row['currency'],
            "credit_type" => $row['credit_type'],
            "amount" => (float) $row['amount'],
            "tax_amount" => (float) $row['tax_amount'],
            "reason" => $row['reason'],
            "restore_stock" => (bool) $row['restore_stock'],
            "stock_restored_at" => $row['stock_restored_at'],
            "status" => $row['status'],
            "issued_by_name" => $row['issued_by_name'],
            "issued_at" => $row['issued_at'],
            "refunded_amount" => (float) $row['refunded_amount'],
            "remaining_amount" => max(0, round((float) $row['amount'] - (float) $row['refunded_amount'], 2)),
            "refundable_amount" => min(
                max(0, round((float) $row['amount'] - (float) $row['refunded_amount'], 2)),
                max(0, round((float) $invoice['amount_paid'] - ((float) $invoice['total_amount'] - (float) $invoice['credited_amount']) - (float) $invoice['refunded_amount'], 2))
            ),
        ];
    }
    $creditStmt->close();

    $refundStmt = $conn->prepare("
        SELECT r.id, r.refund_number, r.credit_note_id, r.currency, r.amount,
               r.refund_date, r.payment_method, r.reference, r.notes, r.status,
               r.processed_at, u.name AS processed_by_name, cn.credit_note_number
        FROM refunds r
        JOIN credit_notes cn ON cn.id = r.credit_note_id
        LEFT JOIN users u ON u.id = r.processed_by
        WHERE r.invoice_id = ?
        ORDER BY r.processed_at DESC, r.id DESC
    ");
    $refundStmt->bind_param("i", $invoiceId);
    $refundStmt->execute();
    $refundResults = $refundStmt->get_result();
    $refunds = [];
    while ($row = $refundResults->fetch_assoc()) {
        $refunds[] = [
            "id" => (int) $row['id'],
            "refund_number" => $row['refund_number'],
            "credit_note_id" => (int) $row['credit_note_id'],
            "credit_note_number" => $row['credit_note_number'],
            "currency" => $row['currency'],
            "amount" => (float) $row['amount'],
            "refund_date" => $row['refund_date'],
            "payment_method" => $row['payment_method'],
            "reference" => $row['reference'],
            "notes" => $row['notes'],
            "status" => $row['status'],
            "processed_by_name" => $row['processed_by_name'],
            "processed_at" => $row['processed_at'],
        ];
    }
    $refundStmt->close();

    $today    = date('Y-m-d');
    $isOverdue = in_array($invoice['status'], ['sent', 'partial']) && $invoice['due_date'] < $today;
    $daysOverdue = ($isOverdue || $invoice['status'] === 'overdue')
        ? max(0, (int)(new DateTime($today))->diff(new DateTime($invoice['due_date']))->days)
        : 0;

    // -------------------------------------------------------
    // 6. Compose response
    // -------------------------------------------------------
    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Invoice fetched successfully.",
        "data"    => [
            "id"             => (int)$invoice['id'],
            "invoice_number" => $invoice['invoice_number'],
            "proforma_id"    => $invoice['proforma_id'] ? (int)$invoice['proforma_id'] : null,
            "quotation_id"   => $invoice['quotation_id'] ? (int)$invoice['quotation_id'] : null,
            "client"         => [
                "id"            => (int)$invoice['client_id'],
                "company_name"  => $invoice['client_name'],
                "address"       => $invoice['client_address'],
                "city"          => $invoice['client_city'],
                "state"         => $invoice['client_state'],
                "country"       => $invoice['client_country'],
                "email"         => $invoice['client_email'],
                "phone"         => $invoice['client_phone'],
                "tax_id"        => $invoice['client_tax_id'],
                "currency"      => $invoice['client_currency'],
                "payment_terms" => $invoice['client_payment_terms']
            ],
            "created_by"     => [
                "id"   => (int)$invoice['created_by'],
                "name" => $invoice['created_by_name']
            ],
            "approved_by"    => $invoice['approved_by'] ? [
                "id"   => (int)$invoice['approved_by'],
                "name" => $invoice['approved_by_name']
            ] : null,
            "issue_date"     => $invoice['issue_date'],
            "due_date"       => $invoice['due_date'],
            "is_overdue"     => $isOverdue,
            "days_overdue"   => $daysOverdue,
            "currency"       => $invoice['currency'],
            "exchange_rate"  => (float)$invoice['exchange_rate'],
            "subtotal"       => (float)$invoice['subtotal'],
            "discount_type"  => $invoice['discount_type'],
            "discount_value" => (float)$invoice['discount_value'],
            "discount_amount"=> (float)$invoice['discount_amount'],
            "taxable_amount" => (float)$invoice['taxable_amount'],
            "tax_amount"     => (float)$invoice['tax_amount'],
            "total_amount"   => (float)$invoice['total_amount'],
            "amount_paid"    => (float)$invoice['amount_paid'],
            "credited_amount"=> (float)($invoice['credited_amount'] ?? 0),
            "refunded_amount"=> (float)($invoice['refunded_amount'] ?? 0),
            "adjusted_total" => max(0, (float)$invoice['total_amount'] - (float)($invoice['credited_amount'] ?? 0)),
            "net_paid"       => max(0, (float)$invoice['amount_paid'] - (float)($invoice['refunded_amount'] ?? 0)),
            "balance_due"    => (float)$invoice['balance_due'],
            "payment_terms"  => $invoice['payment_terms'],
            "footer_text"    => $invoice['footer_text'],
            "notes"          => $invoice['notes'],
            "reversal_reason"=> $invoice['reversal_reason'],
            "reversed_by"    => $invoice['reversed_by'] ? ["id" => (int)$invoice['reversed_by'], "name" => $invoice['reversed_by_name']] : null,
            "reversed_at"    => $invoice['reversed_at'],
            "status"         => $invoice['status'],
            "stock_deducted" => (bool)$invoice['stock_deducted'],
            "reminder_count" => (int)$invoice['reminder_count'],
            "last_reminder_at" => $invoice['last_reminder_at'],
            "next_reminder_at" => $invoice['next_reminder_at'],
            "items"          => $items,
            "payments"       => $payments,
            "reminder_history"=> $reminderHistory,
            "credit_notes"   => $creditNotes,
            "refunds"        => $refunds,
            "created_at"     => $invoice['created_at'],
            "updated_at"     => $invoice['updated_at']
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Single Invoice Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
