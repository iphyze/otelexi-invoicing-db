<?php
// routes/creditNotes/processRefund.php
// Records a controlled refund against a previously issued credit note.

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
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can process refunds.');

    $creditNoteId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($creditNoteId < 1) {
        throw new Exception('A valid credit note ID is required.', 422);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid or missing JSON payload.', 400);
    }

    $amount = round((float) ($data['amount'] ?? 0), 2);
    $refundDate = trim((string) ($data['refund_date'] ?? date('Y-m-d')));
    $paymentMethod = strtolower(trim((string) ($data['payment_method'] ?? 'bank_transfer')));
    $reference = trim((string) ($data['reference'] ?? '')) ?: null;
    $notes = trim((string) ($data['notes'] ?? '')) ?: null;

    if ($amount <= 0) {
        throw new Exception('Refund amount must be greater than zero.', 422);
    }
    $date = DateTime::createFromFormat('Y-m-d', $refundDate);
    if (!$date || $date->format('Y-m-d') !== $refundDate) {
        throw new Exception('Refund date is invalid.', 422);
    }
    $allowedMethods = ['bank_transfer', 'cash', 'cheque', 'pos', 'other'];
    if (!in_array($paymentMethod, $allowedMethods, true)) {
        throw new Exception('Invalid refund payment method.', 422);
    }

    $conn->begin_transaction();
    try {
        $noteStmt = $conn->prepare(
            'SELECT cn.*, i.invoice_number, i.total_amount, i.amount_paid,
                    i.credited_amount, i.refunded_amount, i.status AS invoice_status,
                    c.company_name AS client_name
             FROM credit_notes cn
             JOIN invoices i ON i.id = cn.invoice_id
             JOIN clients c ON c.id = cn.client_id
             WHERE cn.id = ? AND cn.status = \'issued\' FOR UPDATE'
        );
        $noteStmt->bind_param('i', $creditNoteId);
        $noteStmt->execute();
        $note = $noteStmt->get_result()->fetch_assoc();
        $noteStmt->close();

        if (!$note) {
            throw new Exception('Issued credit note not found.', 404);
        }
        if ($note['invoice_status'] === 'reversed') {
            throw new Exception('A reversed invoice cannot be refunded.', 409);
        }

        $refundedForNoteStmt = $conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM refunds WHERE credit_note_id = ? AND status = 'processed'"
        );
        $refundedForNoteStmt->bind_param('i', $creditNoteId);
        $refundedForNoteStmt->execute();
        $refundedForNote = (float) $refundedForNoteStmt->get_result()->fetch_assoc()['total'];
        $refundedForNoteStmt->close();

        $noteAvailable = max(0, round((float) $note['amount'] - $refundedForNote, 2));
        $adjustedInvoiceTotal = max(0, round((float) $note['total_amount'] - (float) $note['credited_amount'], 2));
        $clientCreditAvailable = max(0, round((float) $note['amount_paid'] - $adjustedInvoiceTotal - (float) $note['refunded_amount'], 2));
        $refundableAmount = min($noteAvailable, $clientCreditAvailable);

        if ($refundableAmount <= 0) {
            throw new Exception('There is currently no refundable credit available for this credit note.', 409);
        }
        if ($amount > $refundableAmount) {
            throw new Exception('Refund amount exceeds the available refundable balance.', 422);
        }

        $refundNumber = nextAdjustmentNumber($conn, 'refund');
        $processedBy = (int) $user['id'];
        $invoiceId = (int) $note['invoice_id'];
        $clientId = (int) $note['client_id'];
        $currency = (string) $note['currency'];

        $insert = $conn->prepare(
            'INSERT INTO refunds
             (refund_number, credit_note_id, invoice_id, client_id, currency, amount, refund_date, payment_method, reference, notes, processed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->bind_param(
            'siiisdssssi',
            $refundNumber,
            $creditNoteId,
            $invoiceId,
            $clientId,
            $currency,
            $amount,
            $refundDate,
            $paymentMethod,
            $reference,
            $notes,
            $processedBy
        );
        $insert->execute();
        $refundId = (int) $insert->insert_id;
        $insert->close();

        $update = $conn->prepare('UPDATE invoices SET refunded_amount = refunded_amount + ? WHERE id = ?');
        $update->bind_param('di', $amount, $invoiceId);
        $update->execute();
        $update->close();

        $summary = recalculateAdjustedInvoice($conn, $invoiceId);
        $description = sprintf(
            '%s processed refund %s against credit note %s for invoice %s. Amount: %s %.2f.',
            $user['email'],
            $refundNumber,
            $note['credit_note_number'],
            $note['invoice_number'],
            $currency,
            $amount
        );
        logFinancialAction($conn, $processedBy, 'refund.processed', 'Refund', $refundId, $description);

        $conn->commit();

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Refund recorded successfully.',
            'data' => [
                'id' => $refundId,
                'refund_number' => $refundNumber,
                'credit_note_id' => $creditNoteId,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'currency' => $currency,
                'invoice_summary' => $summary,
            ],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('Process Refund Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Refund could not be processed right now.' : $e->getMessage(),
    ]);
}
