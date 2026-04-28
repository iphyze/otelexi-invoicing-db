<?php
// routes/payments/recordPayment.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../cron/notificationHelper.php';

/**
 * POST /payments/record
 * Record a payment against a sent, partial, or overdue invoice.
 *   - Updates invoice.amount_paid and invoice.balance_due.
 *   - Auto-sets invoice status to 'partial' or 'paid'.
 *   - Clears next_reminder_at when fully paid.
 *   - Overpayment is blocked.
 * Roles allowed: Admin, Accountant
 *
 * Sample payload:
 * {
 *   "invoice_id": 7,
 *   "amount": 75000.00,
 *   "payment_date": "2026-04-25",
 *   "payment_method": "bank_transfer",
 *   "reference": "TRF/2026/00412",
 *   "notes": "First instalment received."
 * }
 *
 * Allowed payment_method values:
 *   cash | bank_transfer | pos | cheque | online
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    $userData          = authenticateUser();
    $loggedInUserId    = (int)$userData['id'];
    $loggedInUserRole  = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    if (!in_array($loggedInUserRole, ['admin', 'accountant'])) {
        throw new Exception("Unauthorized: Only Admins or Accountants can record payments.", 403);
    }

    // -------------------------------------------------------
    // 1. Parse & validate payload
    // -------------------------------------------------------
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    if (!isset($data['invoice_id']) || !is_numeric($data['invoice_id'])) {
        throw new Exception("A valid 'invoice_id' is required.", 422);
    }
    if (!isset($data['amount']) || !is_numeric($data['amount']) || (float)$data['amount'] <= 0) {
        throw new Exception("'amount' must be a positive number.", 422);
    }

    $invoiceId     = (int)$data['invoice_id'];
    $amount        = round((float)$data['amount'], 2);

    $allowedMethods = ['cash', 'bank_transfer', 'pos', 'cheque', 'online'];
    $paymentMethod  = isset($data['payment_method'])
        ? strtolower(trim($data['payment_method']))
        : 'bank_transfer';

    if (!in_array($paymentMethod, $allowedMethods)) {
        throw new Exception("Invalid payment_method. Allowed: " . implode(', ', $allowedMethods) . ".", 422);
    }

    $paymentDate = isset($data['payment_date']) && DateTime::createFromFormat('Y-m-d', trim($data['payment_date']))
        ? trim($data['payment_date'])
        : date('Y-m-d');

    $reference = isset($data['reference']) ? trim($data['reference']) : null;
    $notes     = isset($data['notes']) ? trim($data['notes']) : null;

    // -------------------------------------------------------
    // 2. Verify invoice exists and is payable
    // -------------------------------------------------------
    $invoiceStmt = $conn->prepare("
        SELECT i.id, i.invoice_number, i.status,
               i.total_amount, i.amount_paid, i.balance_due,
               i.currency, i.payment_terms, i.due_date,
               c.company_name AS client_name
        FROM invoices i
        JOIN clients c ON c.id = i.client_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $invoiceStmt->bind_param("i", $invoiceId);
    $invoiceStmt->execute();
    $invoice = $invoiceStmt->get_result()->fetch_assoc();
    $invoiceStmt->close();

    if (!$invoice) {
        throw new Exception("Invoice not found.", 404);
    }

    $payableStatuses = ['sent', 'partial', 'overdue'];
    if (!in_array($invoice['status'], $payableStatuses)) {
        throw new Exception(
            "Payments can only be recorded against sent, partial, or overdue invoices. " .
            "Current status: {$invoice['status']}.", 409
        );
    }

    $currentBalanceDue = round((float)$invoice['balance_due'], 2);
    $currentAmountPaid = round((float)$invoice['amount_paid'], 2);

    // Block overpayment
    if ($amount > $currentBalanceDue) {
        throw new Exception(
            "Payment amount ({$invoice['currency']} {$amount}) exceeds the outstanding balance " .
            "({$invoice['currency']} {$currentBalanceDue}). Overpayments are not allowed.", 422
        );
    }

    // -------------------------------------------------------
    // 3. Calculate new balances and determine new status
    // -------------------------------------------------------
    $newAmountPaid = round($currentAmountPaid + $amount, 2);
    $newBalanceDue = round((float)$invoice['total_amount'] - $newAmountPaid, 2);

    // Guard against floating point drift pushing balance below zero
    if ($newBalanceDue < 0) $newBalanceDue = 0.00;

    $newStatus   = ($newBalanceDue <= 0) ? 'paid' : 'partial';
    $nextReminder = ($newStatus === 'paid') ? null : $invoice['due_date'];

    // -------------------------------------------------------
    // 4. Transaction: insert payment, update invoice, log
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        // Insert payment record
        // Columns: invoice_id, recorded_by, amount, payment_date, payment_method,
        //          reference, notes
        // Types: i i d s s s s = 7 params → "iidssss"
        $paymentStmt = $conn->prepare("
            INSERT INTO payments (
                invoice_id, recorded_by, amount, payment_date,
                payment_method, reference, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$paymentStmt) throw new Exception("Failed to prepare payment insert: " . $conn->error, 500);

        $paymentStmt->bind_param("iidssss",
            $invoiceId,
            $loggedInUserId,
            $amount,
            $paymentDate,
            $paymentMethod,
            $reference,
            $notes
        );

        if (!$paymentStmt->execute()) {
            throw new Exception("Failed to record payment: " . $paymentStmt->error, 500);
        }
        $newPaymentId = $paymentStmt->insert_id;
        $paymentStmt->close();

        // Update invoice balances and status
        $updateStmt = $conn->prepare("
            UPDATE invoices
            SET amount_paid      = ?,
                balance_due      = ?,
                status           = ?,
                next_reminder_at = ?
            WHERE id = ?
        ");
        $updateStmt->bind_param("ddssi",
            $newAmountPaid,
            $newBalanceDue,
            $newStatus,
            $nextReminder,
            $invoiceId
        );
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update invoice: " . $updateStmt->error, 500);
        }
        $updateStmt->close();

        // Activity log
        $logStmt     = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "payment.recorded";
        $modelType   = "Payment";
        $refText     = $reference ? " Ref: {$reference}." : "";
        $description = "{$loggedInUserEmail} recorded {$invoice['currency']} {$amount} payment "
                     . "against invoice {$invoice['invoice_number']} ({$invoice['client_name']}) "
                     . "via {$paymentMethod}.{$refText} "
                     . "Balance: {$invoice['currency']} {$currentBalanceDue} → {$invoice['currency']} {$newBalanceDue}. "
                     . "Status: {$invoice['status']} → {$newStatus}.";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $newPaymentId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();



        // ============================================================
        // 2. recordPayment.php
        //    Add after: $conn->commit();
        // ============================================================
        
        // Notify the Sales person who owns the invoice
        createNotification($conn, [
            'user_id'    => (int)$invoice['created_by'],   // $invoice fetched earlier in the route
            'type'       => 'payment.received',
            'title'      => 'Payment Received',
            'message'    => "{$invoice['currency']} {$amount} received on invoice {$invoice['invoice_number']} "
                        . "({$invoice['client_name']}) via {$paymentMethod}. "
                        . ($newStatus === 'paid' ? 'Invoice is now fully paid.' : "Balance: {$invoice['currency']} {$newBalanceDue}."),
            'model_type' => 'Payment',
            'model_id'   => $newPaymentId
        ]);

        // Notify all admins when an invoice is fully paid
        if ($newStatus === 'paid') {
            createNotification($conn, [
                'role'       => 'admin',
                'type'       => 'invoice.paid',
                'title'      => 'Invoice Fully Paid',
                'message'    => "Invoice {$invoice['invoice_number']} for '{$invoice['client_name']}' "
                            . "has been fully paid. Total: {$invoice['currency']} {$invoice['total_amount']}.",
                'model_type' => 'Invoice',
                'model_id'   => $invoiceId
            ]);
        }

        // -------------------------------------------------------
        // 5. Return response
        // -------------------------------------------------------
        http_response_code(201);
        echo json_encode([
            "status"  => "success",
            "message" => $newStatus === 'paid'
                ? "Payment recorded. Invoice is now fully paid."
                : "Payment recorded. Invoice is partially paid.",
            "data"    => [
                "payment" => [
                    "id"             => $newPaymentId,
                    "invoice_id"     => $invoiceId,
                    "amount"         => $amount,
                    "payment_date"   => $paymentDate,
                    "payment_method" => $paymentMethod,
                    "reference"      => $reference,
                    "notes"          => $notes,
                    "recorded_by"    => $loggedInUserId
                ],
                "invoice" => [
                    "id"             => $invoiceId,
                    "invoice_number" => $invoice['invoice_number'],
                    "client_name"    => $invoice['client_name'],
                    "currency"       => $invoice['currency'],
                    "total_amount"   => (float)$invoice['total_amount'],
                    "amount_paid"    => $newAmountPaid,
                    "balance_due"    => $newBalanceDue,
                    "previous_status"=> $invoice['status'],
                    "new_status"     => $newStatus,
                    "fully_paid"     => $newStatus === 'paid'
                ]
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Record Payment Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
