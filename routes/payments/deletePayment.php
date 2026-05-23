<?php
// routes/payments/deletePayment.php
// Super Admin-only reversal of an unreceipted payment.
// Payments with official receipts must be handled by credit-note/refund workflow.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/financialAdjustments.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can reverse an unreceipted payment.');
    $paymentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($paymentId < 1) {
        throw new Exception('A valid payment ID is required.', 422);
    }

    $conn->begin_transaction();
    try {
        $paymentStmt = $conn->prepare(
            'SELECT p.*, i.invoice_number, i.status AS invoice_status, i.amount_paid,
                    i.currency, c.company_name AS client_name
             FROM payments p
             JOIN invoices i ON i.id = p.invoice_id
             JOIN clients c ON c.id = i.client_id
             WHERE p.id = ? FOR UPDATE'
        );
        $paymentStmt->bind_param('i', $paymentId);
        $paymentStmt->execute();
        $payment = $paymentStmt->get_result()->fetch_assoc();
        $paymentStmt->close();
        if (!$payment) {
            throw new Exception('Payment not found.', 404);
        }

        $receiptStmt = $conn->prepare('SELECT receipt_number FROM payment_receipts WHERE payment_id = ? LIMIT 1');
        $receiptStmt->bind_param('i', $paymentId);
        $receiptStmt->execute();
        $receipt = $receiptStmt->get_result()->fetch_assoc();
        $receiptStmt->close();
        if ($receipt) {
            throw new Exception("Payment has issued receipt {$receipt['receipt_number']} and cannot be reversed directly. Issue a credit note and process a refund where required.", 409);
        }
        if (in_array($payment['invoice_status'], ['cancelled', 'reversed'], true)) {
            throw new Exception('A payment on a closed invoice cannot be directly reversed.', 409);
        }

        $invoiceId = (int) $payment['invoice_id'];
        $amount = round((float) $payment['amount'], 2);
        $newAmountPaid = max(0, round((float) $payment['amount_paid'] - $amount, 2));
        $delete = $conn->prepare('DELETE FROM payments WHERE id = ?');
        $delete->bind_param('i', $paymentId);
        $delete->execute();
        $delete->close();

        $update = $conn->prepare('UPDATE invoices SET amount_paid = ? WHERE id = ?');
        $update->bind_param('di', $newAmountPaid, $invoiceId);
        $update->execute();
        $update->close();
        $summary = recalculateAdjustedInvoice($conn, $invoiceId);

        $description = "{$user['email']} reversed unreceipted payment of {$payment['currency']} {$amount} from invoice {$payment['invoice_number']} ({$payment['client_name']}). New balance: {$payment['currency']} {$summary['balance_due']}.";
        logFinancialAction($conn, (int) $user['id'], 'payment.reversed', 'Payment', $paymentId, $description);
        $conn->commit();

        echo json_encode([
            'status' => 'success',
            'message' => 'Unreceipted payment reversed successfully.',
            'data' => ['payment_id' => $paymentId, 'invoice_id' => $invoiceId, 'invoice_summary' => $summary],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('Delete Payment Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'failed', 'message' => $code === 500 ? 'Payment could not be reversed right now.' : $e->getMessage()]);
}
