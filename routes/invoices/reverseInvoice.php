<?php
// routes/invoices/reverseInvoice.php
// Reverses an unpaid finalised invoice without deleting its audit trail.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/financialAdjustments.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can reverse finalised invoices.');

    $invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $data = json_decode(file_get_contents('php://input'), true);
    $reason = trim((string) ($data['reason'] ?? ''));

    if ($invoiceId < 1) {
        throw new Exception('A valid invoice ID is required.', 422);
    }
    if (mb_strlen($reason) < 8) {
        throw new Exception('Please provide a clear reason for reversing this invoice.', 422);
    }

    $conn->begin_transaction();
    try {
        $invoiceStmt = $conn->prepare(
            'SELECT i.id, i.invoice_number, i.status, i.stock_deducted,
                    i.amount_paid, i.credited_amount, i.refunded_amount,
                    c.company_name AS client_name
             FROM invoices i
             JOIN clients c ON c.id = i.client_id
             WHERE i.id = ? FOR UPDATE'
        );
        $invoiceStmt->bind_param('i', $invoiceId);
        $invoiceStmt->execute();
        $invoice = $invoiceStmt->get_result()->fetch_assoc();
        $invoiceStmt->close();

        if (!$invoice) {
            throw new Exception('Invoice not found.', 404);
        }
        if (!in_array($invoice['status'], ['sent', 'overdue'], true)) {
            throw new Exception('Only an unpaid finalised invoice can be reversed. Use credit notes and refunds for settled invoices.', 409);
        }
        if ((float) $invoice['amount_paid'] > 0 || (float) $invoice['credited_amount'] > 0 || (float) $invoice['refunded_amount'] > 0) {
            throw new Exception('This invoice has financial activity. Issue a credit note and process a refund where required instead of reversing it.', 409);
        }

        $paymentCheck = $conn->prepare('SELECT COUNT(*) AS total FROM payments WHERE invoice_id = ?');
        $paymentCheck->bind_param('i', $invoiceId);
        $paymentCheck->execute();
        $paymentCount = (int) $paymentCheck->get_result()->fetch_assoc()['total'];
        $paymentCheck->close();
        if ($paymentCount > 0) {
            throw new Exception('Payments exist on this invoice. It cannot be reversed.', 409);
        }

        $userId = (int) $user['id'];
        if ((int) $invoice['stock_deducted'] === 1) {
            $itemsStmt = $conn->prepare(
                'SELECT product_id, SUM(quantity) AS qty
                 FROM invoice_items
                 WHERE invoice_id = ? AND product_id IS NOT NULL
                 GROUP BY product_id'
            );
            $itemsStmt->bind_param('i', $invoiceId);
            $itemsStmt->execute();
            $items = $itemsStmt->get_result();

            while ($item = $items->fetch_assoc()) {
                $productId = (int) $item['product_id'];
                $qty = (float) $item['qty'];
                $updateStock = $conn->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?');
                $updateStock->bind_param('di', $qty, $productId);
                $updateStock->execute();
                $updateStock->close();

                $note = "Stock restored after reversing invoice {$invoice['invoice_number']}";
                $movement = $conn->prepare(
                    "INSERT INTO stock_movements
                     (product_id, movement_type, quantity, reference_type, reference_id, notes, created_by)
                     VALUES (?, 'in', ?, 'invoice_reversal', ?, ?, ?)"
                );
                $movement->bind_param('idisi', $productId, $qty, $invoiceId, $note, $userId);
                $movement->execute();
                $movement->close();
            }
            $itemsStmt->close();
        }

        $update = $conn->prepare(
            "UPDATE invoices
             SET status = 'reversed', balance_due = 0, reversal_reason = ?, reversed_by = ?, reversed_at = NOW()
             WHERE id = ?"
        );
        $update->bind_param('sii', $reason, $userId, $invoiceId);
        $update->execute();
        $update->close();

        $description = "{$user['email']} reversed invoice {$invoice['invoice_number']} for '{$invoice['client_name']}'. Reason: {$reason}. Stock restored where applicable.";
        logFinancialAction($conn, $userId, 'invoice.reversed', 'Invoice', $invoiceId, $description);
        $conn->commit();

        echo json_encode([
            'status' => 'success',
            'message' => 'Invoice reversed successfully. Stock has been restored where applicable.',
            'data' => ['id' => $invoiceId, 'status' => 'reversed'],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('Reverse Invoice Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Invoice could not be reversed right now.' : $e->getMessage(),
    ]);
}
