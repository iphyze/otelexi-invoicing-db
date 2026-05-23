<?php
// utils/receipt.php
// Receipt numbering and immutable payment receipt snapshot helpers.

declare(strict_types=1);

function fetchReceiptById(mysqli $conn, int $receiptId): ?array
{
    $statement = $conn->prepare(
        'SELECT r.*, u.name AS issued_by_name
         FROM payment_receipts r
         LEFT JOIN users u ON u.id = r.issued_by
         WHERE r.id = ? LIMIT 1'
    );
    $statement->bind_param('i', $receiptId);
    $statement->execute();
    $receipt = $statement->get_result()->fetch_assoc();
    $statement->close();
    return $receipt ?: null;
}

function fetchReceiptByPaymentId(mysqli $conn, int $paymentId): ?array
{
    $statement = $conn->prepare(
        'SELECT r.*, u.name AS issued_by_name
         FROM payment_receipts r
         LEFT JOIN users u ON u.id = r.issued_by
         WHERE r.payment_id = ? LIMIT 1'
    );
    $statement->bind_param('i', $paymentId);
    $statement->execute();
    $receipt = $statement->get_result()->fetch_assoc();
    $statement->close();
    return $receipt ?: null;
}

function nextReceiptNumber(mysqli $conn): string
{
    $year = (int) date('Y');
    $docType = 'receipt';
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

    return sprintf('RCT/%d/%03d', $year, $sequence);
}

/**
 * Issue a single immutable receipt for a recorded payment.
 * If an invoice has a partial credit note, the receipt snapshots the revised total and any refund already processed.
 * The calling route must already be inside a DB transaction.
 *
 * @return array{receipt:array<string,mixed>,created:bool}
 */
function issuePaymentReceipt(mysqli $conn, int $paymentId, int $issuedBy): array
{
    $existing = fetchReceiptByPaymentId($conn, $paymentId);
    if ($existing) {
        return ['receipt' => $existing, 'created' => false];
    }

    $paymentStatement = $conn->prepare(
        'SELECT p.id AS payment_id, p.invoice_id, p.amount, p.payment_date,
                p.payment_method, p.reference, p.notes,
                i.invoice_number, i.total_amount, i.credited_amount, i.refunded_amount, i.currency,
                c.company_name AS client_name, c.email AS client_email,
                c.billing_address AS client_address,
                COALESCE((
                    SELECT SUM(prior.amount)
                    FROM payments prior
                    WHERE prior.invoice_id = p.invoice_id AND prior.id < p.id
                ), 0) AS previous_amount_paid
         FROM payments p
         JOIN invoices i ON i.id = p.invoice_id
         JOIN clients c ON c.id = i.client_id
         WHERE p.id = ? LIMIT 1'
    );
    $paymentStatement->bind_param('i', $paymentId);
    $paymentStatement->execute();
    $payment = $paymentStatement->get_result()->fetch_assoc();
    $paymentStatement->close();

    if (!$payment) {
        throw new Exception('Payment not found.', 404);
    }

    $receiptNumber = nextReceiptNumber($conn);
    $invoiceId = (int) $payment['invoice_id'];
    $invoiceNumber = (string) $payment['invoice_number'];
    $clientName = (string) $payment['client_name'];
    $clientEmail = $payment['client_email'] !== null ? (string) $payment['client_email'] : null;
    $clientAddress = $payment['client_address'] !== null ? (string) $payment['client_address'] : null;
    $currency = (string) $payment['currency'];
    $invoiceTotal = round((float) $payment['total_amount'], 2);
    $creditedAmount = round((float) ($payment['credited_amount'] ?? 0), 2);
    $refundedAmount = round((float) ($payment['refunded_amount'] ?? 0), 2);
    $adjustedInvoiceTotal = max(0, round($invoiceTotal - $creditedAmount, 2));
    $previousAmountPaid = round((float) $payment['previous_amount_paid'], 2);
    $amountReceived = round((float) $payment['amount'], 2);
    $netReceivedAfterPayment = max(0, round($previousAmountPaid + $amountReceived - $refundedAmount, 2));
    $balanceAfterPayment = max(0, round($adjustedInvoiceTotal - $netReceivedAfterPayment, 2));
    $paymentDate = (string) $payment['payment_date'];
    $paymentMethod = (string) $payment['payment_method'];
    $paymentReference = $payment['reference'] !== null ? (string) $payment['reference'] : null;
    $paymentNotes = $payment['notes'] !== null ? (string) $payment['notes'] : null;

    $insert = $conn->prepare(
        'INSERT INTO payment_receipts (
            receipt_number, payment_id, invoice_id, invoice_number,
            client_name, client_email, client_address, currency,
            invoice_total, credited_amount, refunded_amount_before_payment, adjusted_invoice_total,
            previous_amount_paid, amount_received, balance_after_payment,
            payment_date, payment_method, payment_reference, payment_notes, issued_by
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->bind_param(
        'siisssssdddddddssssi',
        $receiptNumber, $paymentId, $invoiceId, $invoiceNumber,
        $clientName, $clientEmail, $clientAddress, $currency,
        $invoiceTotal, $creditedAmount, $refundedAmount, $adjustedInvoiceTotal,
        $previousAmountPaid, $amountReceived, $balanceAfterPayment,
        $paymentDate, $paymentMethod, $paymentReference, $paymentNotes, $issuedBy
    );
    $insert->execute();
    $receiptId = (int) $insert->insert_id;
    $insert->close();

    $receipt = fetchReceiptById($conn, $receiptId);
    if (!$receipt) {
        throw new RuntimeException('Receipt could not be retrieved after it was generated.');
    }
    return ['receipt' => $receipt, 'created' => true];
}

function receiptResponseData(array $receipt): array
{
    $adjustedTotal = isset($receipt['adjusted_invoice_total']) && (float) $receipt['adjusted_invoice_total'] > 0
        ? (float) $receipt['adjusted_invoice_total']
        : (float) $receipt['invoice_total'];

    return [
        'id' => (int) $receipt['id'],
        'receipt_number' => $receipt['receipt_number'],
        'payment_id' => (int) $receipt['payment_id'],
        'invoice_id' => (int) $receipt['invoice_id'],
        'invoice_number' => $receipt['invoice_number'],
        'client' => [
            'company_name' => $receipt['client_name'],
            'email' => $receipt['client_email'],
            'address' => $receipt['client_address'],
        ],
        'currency' => $receipt['currency'],
        'invoice_total' => (float) $receipt['invoice_total'],
        'credited_amount' => (float) ($receipt['credited_amount'] ?? 0),
        'refunded_amount_before_payment' => (float) ($receipt['refunded_amount_before_payment'] ?? 0),
        'adjusted_invoice_total' => $adjustedTotal,
        'previous_amount_paid' => (float) $receipt['previous_amount_paid'],
        'amount_received' => (float) $receipt['amount_received'],
        'balance_after_payment' => (float) $receipt['balance_after_payment'],
        'payment_date' => $receipt['payment_date'],
        'payment_method' => $receipt['payment_method'],
        'payment_reference' => $receipt['payment_reference'],
        'payment_notes' => $receipt['payment_notes'],
        'issued_by' => ['id' => (int) $receipt['issued_by'], 'name' => $receipt['issued_by_name'] ?? null],
        'issued_at' => $receipt['issued_at'],
    ];
}
