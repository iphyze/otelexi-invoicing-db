<?php
// routes/payments/deletePayment.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * DELETE /payments/{id}/delete
 * Reverse (delete) a payment record and restore the invoice's balances.
 *   - Subtracts the payment amount from invoice.amount_paid.
 *   - Recalculates invoice.balance_due.
 *   - Reverts invoice status from 'paid' → 'sent/overdue' or
 *     from 'partial' → 'sent/overdue' depending on remaining balance and due date.
 *   - Hard-deletes the payment row.
 * Roles allowed: Admin only
 *
 * Query param: ?id=12
 * No request body needed.
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Route not found", 400);
    }

    $userData          = authenticateUser();
    $loggedInUserId    = (int)$userData['id'];
    $loggedInUserRole  = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    if ($loggedInUserRole !== 'admin') {
        throw new Exception("Unauthorized: Only Admins can reverse payments.", 403);
    }

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Payment ID is required.", 400);
    }
    $paymentId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 1. Fetch payment and its invoice
    // -------------------------------------------------------
    $paymentStmt = $conn->prepare("
        SELECT
            p.id, p.invoice_id, p.amount, p.payment_date,
            p.payment_method, p.reference,
            i.invoice_number, i.status AS invoice_status,
            i.total_amount, i.amount_paid, i.balance_due,
            i.currency, i.due_date,
            c.company_name AS client_name
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        JOIN clients  c ON c.id = i.client_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $paymentStmt->bind_param("i", $paymentId);
    $paymentStmt->execute();
    $payment = $paymentStmt->get_result()->fetch_assoc();
    $paymentStmt->close();

    if (!$payment) throw new Exception("Payment not found.", 404);

    // Cannot reverse a payment on a cancelled invoice
    if ($payment['invoice_status'] === 'cancelled') {
        throw new Exception("Cannot reverse a payment on a cancelled invoice.", 409);
    }

    // -------------------------------------------------------
    // 2. Recalculate invoice balances after reversal
    // -------------------------------------------------------
    $reversalAmount    = round((float)$payment['amount'], 2);
    $currentAmountPaid = round((float)$payment['amount_paid'], 2);
    $invoiceTotal      = round((float)$payment['total_amount'], 2);

    $newAmountPaid = round($currentAmountPaid - $reversalAmount, 2);
    if ($newAmountPaid < 0) $newAmountPaid = 0.00;

    $newBalanceDue = round($invoiceTotal - $newAmountPaid, 2);

    // -------------------------------------------------------
    // 3. Determine reverted invoice status
    //    After reversal, the invoice can never go back to 'draft' —
    //    it should be 'overdue' if past due date, otherwise 'sent'.
    //    If there's still a partial payment remaining, keep it 'partial'.
    // -------------------------------------------------------
    $today = date('Y-m-d');
    $isPastDue = $payment['due_date'] < $today;

    if ($newAmountPaid <= 0) {
        // No payments remain at all
        $revertedStatus = $isPastDue ? 'overdue' : 'sent';
    } else {
        // Some payment still remains
        $revertedStatus = $isPastDue ? 'overdue' : 'partial';
    }

    // Restore reminder if needed
    $nextReminder = ($revertedStatus === 'sent') ? null : $payment['due_date'];

    // -------------------------------------------------------
    // 4. Transaction: delete payment, update invoice, log
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        // Delete the payment
        $deleteStmt = $conn->prepare("DELETE FROM payments WHERE id = ?");
        $deleteStmt->bind_param("i", $paymentId);
        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete payment: " . $deleteStmt->error, 500);
        }
        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("Payment could not be deleted.", 500);
        }
        $deleteStmt->close();

        // Restore invoice balances and status
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
            $revertedStatus,
            $nextReminder,
            $payment['invoice_id']
        );
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to restore invoice balances: " . $updateStmt->error, 500);
        }
        $updateStmt->close();

        // Activity log
        $logStmt     = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "payment.reversed";
        $modelType   = "Payment";
        $refText     = $payment['reference'] ? " (Ref: {$payment['reference']})" : "";
        $description = "{$loggedInUserEmail} reversed {$payment['currency']} {$reversalAmount} payment "
                     . "on {$payment['payment_date']}{$refText} "
                     . "from invoice {$payment['invoice_number']} ({$payment['client_name']}). "
                     . "Invoice balance restored: {$payment['currency']} {$newBalanceDue}. "
                     . "Invoice status: {$payment['invoice_status']} → {$revertedStatus}.";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $paymentId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Payment reversed successfully. Invoice balances have been restored.",
            "data"    => [
                "reversed_payment" => [
                    "id"             => $paymentId,
                    "amount"         => $reversalAmount,
                    "payment_date"   => $payment['payment_date'],
                    "payment_method" => $payment['payment_method'],
                    "reference"      => $payment['reference']
                ],
                "invoice" => [
                    "id"              => (int)$payment['invoice_id'],
                    "invoice_number"  => $payment['invoice_number'],
                    "client_name"     => $payment['client_name'],
                    "currency"        => $payment['currency'],
                    "total_amount"    => $invoiceTotal,
                    "new_amount_paid" => $newAmountPaid,
                    "new_balance_due" => $newBalanceDue,
                    "previous_status" => $payment['invoice_status'],
                    "new_status"      => $revertedStatus
                ]
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete Payment Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
