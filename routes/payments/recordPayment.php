<?php
// routes/payments/recordPayment.php
// Records payment against a finalised invoice and issues one immutable receipt.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/receipt.php';
require_once __DIR__ . '/../../utils/financialAdjustments.php';
require_once __DIR__ . '/../../../cron/notificationHelper.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $userData = authenticateUser();
    requireRole(
        $userData,
        [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING],
        'Only Super Admin, Admin or Accounting users can record payments.'
    );
    $loggedInUserId = (int) $userData['id'];
    $loggedInUserEmail = (string) $userData['email'];

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid or missing JSON payload.', 400);
    }
    if (!isset($data['invoice_id']) || !is_numeric($data['invoice_id'])) {
        throw new Exception("A valid 'invoice_id' is required.", 422);
    }
    if (!isset($data['amount']) || !is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
        throw new Exception("'amount' must be a positive number.", 422);
    }

    $invoiceId = (int) $data['invoice_id'];
    $amount = round((float) $data['amount'], 2);
    $allowedMethods = ['cash', 'bank_transfer', 'pos', 'cheque', 'online', 'other'];
    $paymentMethod = strtolower(trim((string) ($data['payment_method'] ?? 'bank_transfer')));
    if (!in_array($paymentMethod, $allowedMethods, true)) {
        throw new Exception('Invalid payment_method. Allowed: ' . implode(', ', $allowedMethods) . '.', 422);
    }

    $requestedDate = trim((string) ($data['payment_date'] ?? ''));
    $dateObject = DateTime::createFromFormat('Y-m-d', $requestedDate);
    $paymentDate = $dateObject && $dateObject->format('Y-m-d') === $requestedDate ? $requestedDate : date('Y-m-d');
    $reference = trim((string) ($data['reference'] ?? '')) ?: null;
    $notes = trim((string) ($data['notes'] ?? '')) ?: null;

    $conn->begin_transaction();
    try {
        $invoiceStmt = $conn->prepare(
            'SELECT i.id, i.invoice_number, i.status, i.created_by,
                    i.total_amount, i.amount_paid, i.credited_amount, i.refunded_amount, i.balance_due,
                    i.currency, i.payment_terms, i.due_date, c.company_name AS client_name
             FROM invoices i JOIN clients c ON c.id = i.client_id
             WHERE i.id = ? FOR UPDATE'
        );
        $invoiceStmt->bind_param('i', $invoiceId);
        $invoiceStmt->execute();
        $invoice = $invoiceStmt->get_result()->fetch_assoc();
        $invoiceStmt->close();

        if (!$invoice) {
            throw new Exception('Invoice not found.', 404);
        }
        if (!in_array($invoice['status'], ['sent', 'partial', 'overdue'], true)) {
            throw new Exception("Payments can only be recorded against active outstanding invoices. Current status: {$invoice['status']}.", 409);
        }

        $currentBalanceDue = round((float) $invoice['balance_due'], 2);
        if ($currentBalanceDue <= 0) {
            throw new Exception('This invoice has no outstanding balance available for payment.', 409);
        }
        if ($amount > $currentBalanceDue) {
            throw new Exception(
                "Payment amount ({$invoice['currency']} {$amount}) exceeds the outstanding balance ({$invoice['currency']} {$currentBalanceDue}). Overpayments are not allowed.",
                422
            );
        }

        $paymentStmt = $conn->prepare(
            'INSERT INTO payments (invoice_id, recorded_by, amount, payment_date, payment_method, reference, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $paymentStmt->bind_param('iidssss', $invoiceId, $loggedInUserId, $amount, $paymentDate, $paymentMethod, $reference, $notes);
        $paymentStmt->execute();
        $newPaymentId = (int) $paymentStmt->insert_id;
        $paymentStmt->close();

        $newAmountPaid = round((float) $invoice['amount_paid'] + $amount, 2);
        $paidUpdate = $conn->prepare('UPDATE invoices SET amount_paid = ? WHERE id = ?');
        $paidUpdate->bind_param('di', $newAmountPaid, $invoiceId);
        $paidUpdate->execute();
        $paidUpdate->close();

        $summary = recalculateAdjustedInvoice($conn, $invoiceId);
        $nextReminder = $summary['status'] === 'paid' ? null : $invoice['due_date'];
        $reminderUpdate = $conn->prepare('UPDATE invoices SET next_reminder_at = ? WHERE id = ?');
        $reminderUpdate->bind_param('si', $nextReminder, $invoiceId);
        $reminderUpdate->execute();
        $reminderUpdate->close();

        $issuedReceipt = issuePaymentReceipt($conn, $newPaymentId, $loggedInUserId);
        $receipt = $issuedReceipt['receipt'];

        $description = "{$loggedInUserEmail} recorded {$invoice['currency']} {$amount} payment against invoice {$invoice['invoice_number']} ({$invoice['client_name']}) via {$paymentMethod}. Receipt {$receipt['receipt_number']} issued. Balance: {$invoice['currency']} {$currentBalanceDue} → {$invoice['currency']} {$summary['balance_due']}. Status: {$invoice['status']} → {$summary['status']}.";
        logFinancialAction($conn, $loggedInUserId, 'payment.recorded', 'Payment', $newPaymentId, $description);
        logFinancialAction($conn, $loggedInUserId, 'receipt.issued', 'Receipt', (int) $receipt['id'], "{$loggedInUserEmail} issued receipt {$receipt['receipt_number']} for invoice {$invoice['invoice_number']}.");

        $conn->commit();
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }

    try {
        if ((int) $invoice['created_by'] !== $loggedInUserId) {
            createNotification($conn, [
                'user_id' => (int) $invoice['created_by'],
                'type' => 'payment.received',
                'title' => 'Payment Received',
                'message' => "{$invoice['currency']} {$amount} received on invoice {$invoice['invoice_number']} ({$invoice['client_name']}). Receipt {$receipt['receipt_number']} issued.",
                'model_type' => 'Payment',
                'model_id' => $newPaymentId,
            ]);
        }
        if ($summary['status'] === 'paid') {
            foreach ([ROLE_SUPER_ADMIN, ROLE_ADMIN] as $alertRole) {
                createNotification($conn, [
                    'role' => $alertRole,
                    'type' => 'invoice.paid',
                    'title' => 'Invoice Fully Paid',
                    'message' => "Invoice {$invoice['invoice_number']} for '{$invoice['client_name']}' has been fully paid.",
                    'model_type' => 'Invoice',
                    'model_id' => $invoiceId,
                ]);
            }
        }
    } catch (Throwable $notificationError) {
        error_log('Payment Notification Error: ' . $notificationError->getMessage());
    }

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => $summary['status'] === 'paid'
            ? 'Payment recorded and receipt issued. Invoice is now fully paid.'
            : 'Payment recorded and receipt issued. Invoice is partially paid.',
        'data' => [
            'payment' => [
                'id' => $newPaymentId, 'invoice_id' => $invoiceId, 'amount' => $amount,
                'payment_date' => $paymentDate, 'payment_method' => $paymentMethod,
                'reference' => $reference, 'notes' => $notes, 'recorded_by' => $loggedInUserId,
                'receipt' => receiptResponseData($receipt),
            ],
            'invoice' => [
                'id' => $invoiceId, 'invoice_number' => $invoice['invoice_number'],
                'client_name' => $invoice['client_name'], 'currency' => $invoice['currency'],
                'total_amount' => (float) $invoice['total_amount'],
                'credited_amount' => (float) $invoice['credited_amount'],
                'refunded_amount' => (float) $invoice['refunded_amount'],
                'amount_paid' => $newAmountPaid,
                'balance_due' => (float) $summary['balance_due'],
                'previous_status' => $invoice['status'], 'new_status' => $summary['status'],
                'fully_paid' => $summary['status'] === 'paid',
            ],
        ],
    ]);
} catch (Throwable $error) {
    error_log('Record Payment Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $error->getMessage() : 'Payment could not be recorded right now.',
    ]);
}
