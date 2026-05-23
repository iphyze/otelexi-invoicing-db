<?php
// utils/financialAdjustments.php
// Credit note, refund and invoice balance helpers.

declare(strict_types=1);

/**
 * Reserve the next number for an adjustment document.
 * Must be called within a transaction.
 */
function nextAdjustmentNumber(mysqli $conn, string $docType): string
{
    $prefixes = ['credit_note' => 'CRN', 'refund' => 'RFD'];
    if (!isset($prefixes[$docType])) {
        throw new InvalidArgumentException('Unsupported adjustment document type.');
    }

    $year = (int) date('Y');
    $insert = $conn->prepare(
        'INSERT INTO document_number_sequences (doc_type, year, last_sequence)
         VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE last_sequence = last_sequence'
    );
    $insert->bind_param('si', $docType, $year);
    $insert->execute();
    $insert->close();

    $select = $conn->prepare(
        'SELECT last_sequence FROM document_number_sequences
         WHERE doc_type = ? AND year = ? FOR UPDATE'
    );
    $select->bind_param('si', $docType, $year);
    $select->execute();
    $sequence = (int) ($select->get_result()->fetch_assoc()['last_sequence'] ?? 0) + 1;
    $select->close();

    $update = $conn->prepare(
        'UPDATE document_number_sequences SET last_sequence = ?
         WHERE doc_type = ? AND year = ?'
    );
    $update->bind_param('isi', $sequence, $docType, $year);
    $update->execute();
    $update->close();

    return sprintf('%s/%d/%03d', $prefixes[$docType], $year, $sequence);
}

/**
 * Recalculate financial status after a credit note, refund or payment.
 * The calling route should be inside a transaction for write operations.
 *
 * @return array<string,mixed>
 */
function recalculateAdjustedInvoice(mysqli $conn, int $invoiceId): array
{
    $select = $conn->prepare(
        'SELECT id, status, due_date, total_amount, amount_paid,
                credited_amount, refunded_amount
         FROM invoices WHERE id = ? LIMIT 1'
    );
    $select->bind_param('i', $invoiceId);
    $select->execute();
    $invoice = $select->get_result()->fetch_assoc();
    $select->close();

    if (!$invoice) {
        throw new Exception('Invoice not found.', 404);
    }

    if (in_array($invoice['status'], ['draft', 'cancelled', 'reversed'], true)) {
        return $invoice;
    }

    $total = round((float) $invoice['total_amount'], 2);
    $paid = round((float) $invoice['amount_paid'], 2);
    $credited = round((float) $invoice['credited_amount'], 2);
    $refunded = round((float) $invoice['refunded_amount'], 2);
    $adjustedTotal = max(0, round($total - $credited, 2));
    $netPaid = max(0, round($paid - $refunded, 2));
    $balance = max(0, round($adjustedTotal - $netPaid, 2));

    if ($adjustedTotal <= 0.0) {
        $status = 'credited';
    } elseif ($balance <= 0.0) {
        $status = 'paid';
    } elseif ($netPaid > 0.0) {
        $status = 'partial';
    } elseif ($invoice['due_date'] < date('Y-m-d')) {
        $status = 'overdue';
    } else {
        $status = 'sent';
    }

    $update = $conn->prepare('UPDATE invoices SET balance_due = ?, status = ? WHERE id = ?');
    $update->bind_param('dsi', $balance, $status, $invoiceId);
    $update->execute();
    $update->close();

    return [
        'status' => $status,
        'adjusted_total' => $adjustedTotal,
        'net_paid' => $netPaid,
        'balance_due' => $balance,
        'credited_amount' => $credited,
        'refunded_amount' => $refunded,
    ];
}

/**
 * Add a consistent audit event for controlled financial actions.
 */
function logFinancialAction(
    mysqli $conn,
    int $userId,
    string $action,
    string $modelType,
    int $modelId,
    string $description
): void {
    $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'system'), 0, 45);
    $log = $conn->prepare(
        'INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $log->bind_param('ississ', $userId, $action, $modelType, $modelId, $description, $ipAddress);
    $log->execute();
    $log->close();
}
