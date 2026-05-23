<?php
// routes/invoices/createCreditNote.php
// Issues a controlled credit note against a finalised invoice.

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
    requireRole(
        $user,
        [ROLE_SUPER_ADMIN],
        'Only the Super Admin can issue credit notes.'
    );

    $invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($invoiceId < 1) {
        throw new Exception('A valid invoice ID is required.', 422);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid or missing JSON payload.', 400);
    }

    $amount = round((float) ($data['amount'] ?? 0), 2);
    $reason = trim((string) ($data['reason'] ?? ''));
    $restoreStock = !empty($data['restore_stock']) ? 1 : 0;

    if ($amount <= 0) {
        throw new Exception('Credit note amount must be greater than zero.', 422);
    }
    if (mb_strlen($reason) < 8) {
        throw new Exception('Please provide a clear reason for issuing the credit note.', 422);
    }

    $conn->begin_transaction();

    try {
        $invoiceStmt = $conn->prepare(
            'SELECT i.id, i.invoice_number, i.client_id, i.currency, i.status,
                    i.total_amount, i.tax_amount, i.amount_paid, i.credited_amount, i.refunded_amount,
                    i.stock_deducted, c.company_name AS client_name
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
        if (in_array($invoice['status'], ['draft', 'cancelled', 'reversed'], true)) {
            throw new Exception('Credit notes can only be issued against a finalised active invoice.', 409);
        }

        $remainingCreditable = round((float) $invoice['total_amount'] - (float) $invoice['credited_amount'], 2);
        if ($remainingCreditable <= 0) {
            throw new Exception('This invoice has already been fully credited.', 409);
        }
        if ($amount > $remainingCreditable) {
            throw new Exception('Credit note amount cannot exceed the remaining creditable invoice value.', 422);
        }

        $willFullyCredit = abs($amount - $remainingCreditable) < 0.01;
        if ($restoreStock && (!$willFullyCredit || (float) $invoice['credited_amount'] > 0)) {
            throw new Exception('Stock can only be restored when issuing one full credit note for the entire invoice.', 422);
        }

        $creditType = $willFullyCredit && (float) $invoice['credited_amount'] <= 0 ? 'full' : 'partial';
        $number = nextAdjustmentNumber($conn, 'credit_note');
        $issuedBy = (int) $user['id'];
        $currency = (string) $invoice['currency'];
        $taxAmount = round(((float) $invoice['total_amount'] > 0 ? ((float) $invoice['tax_amount'] * ($amount / (float) $invoice['total_amount'])) : 0), 2);
        $clientId = (int) $invoice['client_id'];

        $insert = $conn->prepare(
            'INSERT INTO credit_notes
             (credit_note_number, invoice_id, client_id, currency, credit_type, amount, tax_amount, reason, restore_stock, issued_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->bind_param(
            'siissddsii',
            $number,
            $invoiceId,
            $clientId,
            $currency,
            $creditType,
            $amount,
            $taxAmount,
            $reason,
            $restoreStock,
            $issuedBy
        );
        $insert->execute();
        $creditNoteId = (int) $insert->insert_id;
        $insert->close();

        $updateCredit = $conn->prepare(
            'UPDATE invoices SET credited_amount = credited_amount + ? WHERE id = ?'
        );
        $updateCredit->bind_param('di', $amount, $invoiceId);
        $updateCredit->execute();
        $updateCredit->close();

        if ($restoreStock && (int) $invoice['stock_deducted'] === 1) {
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

                $stockUpdate = $conn->prepare(
                    'UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?'
                );
                $stockUpdate->bind_param('di', $qty, $productId);
                $stockUpdate->execute();
                $stockUpdate->close();

                $movementNote = "Stock restored on credit note {$number} for invoice {$invoice['invoice_number']}";
                $movement = $conn->prepare(
                    "INSERT INTO stock_movements
                     (product_id, movement_type, quantity, reference_type, reference_id, notes, created_by)
                     VALUES (?, 'in', ?, 'credit_note', ?, ?, ?)"
                );
                $movement->bind_param('idisi', $productId, $qty, $creditNoteId, $movementNote, $issuedBy);
                $movement->execute();
                $movement->close();
            }
            $itemsStmt->close();

            $stockTimestamp = $conn->prepare(
                'UPDATE credit_notes SET stock_restored_at = NOW() WHERE id = ?'
            );
            $stockTimestamp->bind_param('i', $creditNoteId);
            $stockTimestamp->execute();
            $stockTimestamp->close();
        }

        $summary = recalculateAdjustedInvoice($conn, $invoiceId);
        $description = sprintf(
            '%s issued credit note %s for invoice %s. Amount: %s %.2f. Reason: %s%s',
            $user['email'],
            $number,
            $invoice['invoice_number'],
            $currency,
            $amount,
            $reason,
            $restoreStock ? ' Stock was restored.' : ''
        );
        logFinancialAction($conn, $issuedBy, 'credit_note.issued', 'CreditNote', $creditNoteId, $description);

        $conn->commit();

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Credit note issued successfully.',
            'data' => [
                'id' => $creditNoteId,
                'credit_note_number' => $number,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'tax_amount' => $taxAmount,
                'currency' => $currency,
                'credit_type' => $creditType,
                'restore_stock' => (bool) $restoreStock,
                'invoice_summary' => $summary,
            ],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('Create Credit Note Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Credit note could not be issued right now.' : $e->getMessage(),
    ]);
}
