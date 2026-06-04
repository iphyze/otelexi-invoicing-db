<?php
// utils/paymentLinks.php
// Shared helpers for manual payment requests and Paystack payment links.

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/financialAdjustments.php';
require_once __DIR__ . '/receipt.php';

function paymentLinkReference(int $invoiceId): string
{
    return 'PAY-' . date('Y') . '-' . $invoiceId . '-' . strtoupper(bin2hex(random_bytes(5)));
}

function paymentLinkFrontendUrl(?string $authorizationUrl, string $reference, string $provider): ?string
{
    if ($provider === 'paystack' && $authorizationUrl) {
        return $authorizationUrl;
    }

    $frontendUrl = rtrim((string) (config('FRONTEND_URL', '') ?? ''), '/');
    if ($frontendUrl === '') {
        return null;
    }

    return $frontendUrl . '/payment-request/' . rawurlencode($reference);
}

function paymentLinkStatusLabel(string $status): string
{
    return match ($status) {
        'pending' => 'Pending',
        'processing' => 'Processing',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
        default => ucfirst($status),
    };
}

function paymentLinkResponse(array $row): array
{
    $provider = (string) $row['provider'];
    $authorizationUrl = $row['authorization_url'] ?? null;

    return [
        'id' => (int) $row['id'],
        'invoice_id' => (int) $row['invoice_id'],
        'invoice_number' => $row['invoice_number'] ?? null,
        'client_id' => (int) $row['client_id'],
        'client_name' => $row['client_name'] ?? null,
        'client_email' => $row['client_email'] ?? null,
        'provider' => $provider,
        'reference' => $row['reference'],
        'amount' => (float) $row['amount'],
        'currency' => $row['currency'],
        'status' => $row['status'],
        'status_label' => paymentLinkStatusLabel((string) $row['status']),
        'authorization_url' => $authorizationUrl,
        'payment_url' => paymentLinkFrontendUrl($authorizationUrl, (string) $row['reference'], $provider),
        'provider_reference' => $row['provider_reference'] ?? null,
        'provider_status' => $row['provider_status'] ?? null,
        'gateway_response' => $row['gateway_response'] ?? null,
        'payment_id' => $row['payment_id'] ? (int) $row['payment_id'] : null,
        'receipt_id' => $row['receipt_id'] ? (int) $row['receipt_id'] : null,
        'receipt_number' => $row['receipt_number'] ?? null,
        'expires_at' => $row['expires_at'] ?? null,
        'paid_at' => $row['paid_at'] ?? null,
        'cancelled_at' => $row['cancelled_at'] ?? null,
        'last_verified_at' => $row['last_verified_at'] ?? null,
        'created_by' => $row['created_by'] ? (int) $row['created_by'] : null,
        'created_by_name' => $row['created_by_name'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function fetchPaymentLink(mysqli $conn, int $paymentLinkId): ?array
{
    $stmt = $conn->prepare(
        'SELECT pl.*, i.invoice_number, c.company_name AS client_name, c.email AS client_email,
                u.name AS created_by_name, r.receipt_number
         FROM payment_links pl
         JOIN invoices i ON i.id = pl.invoice_id
         JOIN clients c ON c.id = pl.client_id
         LEFT JOIN users u ON u.id = pl.created_by
         LEFT JOIN payment_receipts r ON r.id = pl.receipt_id
         WHERE pl.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $paymentLinkId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function fetchCompanyPaymentSettings(mysqli $conn): array
{
    $result = $conn->query('SELECT company_name, bank_name, account_name, account_number, bank_branch, email, phone FROM company_settings ORDER BY id ASC LIMIT 1');
    return $result ? ($result->fetch_assoc() ?: []) : [];
}

function paymentLinkAmountSubunit(array $link): int
{
    return (int) round(((float) $link['amount']) * 100);
}

function normalizePaystackPaidAt(?string $paidAt): string
{
    if (!$paidAt) {
        return date('Y-m-d H:i:s');
    }

    try {
        return (new DateTime($paidAt))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return date('Y-m-d H:i:s');
    }
}

function settleSuccessfulPaystackPayment(mysqli $conn, string $reference, array $providerData, ?int $actorUserId = null): array
{
    $reference = trim($reference);
    if ($reference === '') {
        throw new Exception('A valid payment reference is required.', 422);
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'SELECT pl.*, i.invoice_number, i.status AS invoice_status, i.total_amount,
                    i.amount_paid, i.credited_amount, i.refunded_amount, i.balance_due,
                    i.currency AS invoice_currency, i.due_date, i.created_by AS invoice_created_by,
                    c.company_name AS client_name
             FROM payment_links pl
             JOIN invoices i ON i.id = pl.invoice_id
             JOIN clients c ON c.id = pl.client_id
             WHERE pl.reference = ? FOR UPDATE'
        );
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $link = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$link) {
            throw new Exception('Payment link not found.', 404);
        }

        if ((string) $link['provider'] !== 'paystack') {
            throw new Exception('Only Paystack payment links can be settled through this flow.', 409);
        }

        if ((string) $link['status'] === 'paid') {
            $conn->commit();
            return ['already_processed' => true, 'payment_link' => fetchPaymentLink($conn, (int) $link['id'])];
        }

        if (in_array((string) $link['status'], ['cancelled', 'expired'], true)) {
            throw new Exception('This payment link is no longer active.', 409);
        }

        $providerStatus = strtolower((string) ($providerData['status'] ?? ''));
        if ($providerStatus !== 'success') {
            $gateway = substr((string) ($providerData['gateway_response'] ?? $providerStatus), 0, 255);
            $failedUpdate = $conn->prepare(
                'UPDATE payment_links SET status = ?, provider_status = ?, gateway_response = ?, last_verified_at = NOW(), updated_at = NOW() WHERE id = ?'
            );
            $failedStatus = in_array($providerStatus, ['failed', 'abandoned', 'reversed'], true) ? 'failed' : 'processing';
            $failedUpdate->bind_param('sssi', $failedStatus, $providerStatus, $gateway, $link['id']);
            $failedUpdate->execute();
            $failedUpdate->close();

            $conn->commit();
            return ['already_processed' => false, 'payment_link' => fetchPaymentLink($conn, (int) $link['id'])];
        }

        $expectedAmount = paymentLinkAmountSubunit($link);
        $receivedAmount = (int) ($providerData['amount'] ?? 0);
        $expectedCurrency = strtoupper((string) $link['currency']);
        $receivedCurrency = strtoupper((string) ($providerData['currency'] ?? ''));

        if ($receivedAmount !== $expectedAmount || $receivedCurrency !== $expectedCurrency) {
            throw new Exception('Paystack confirmation amount/currency does not match the payment link.', 409);
        }

        if (!in_array((string) $link['invoice_status'], ['sent', 'partial', 'overdue'], true)) {
            throw new Exception('The related invoice is no longer payable.', 409);
        }

        $paymentAmount = round((float) $link['amount'], 2);
        $currentBalanceDue = round((float) $link['balance_due'], 2);
        if ($paymentAmount > $currentBalanceDue) {
            throw new Exception('Payment amount now exceeds the invoice balance. Please review this invoice manually.', 409);
        }

        $actor = $actorUserId ?: (int) $link['created_by'];
        $paymentDate = normalizePaystackPaidAt($providerData['paid_at'] ?? $providerData['paidAt'] ?? null);
        $paymentDateOnly = substr($paymentDate, 0, 10);
        $providerReference = substr((string) ($providerData['reference'] ?? $reference), 0, 160);
        $gatewayResponse = substr((string) ($providerData['gateway_response'] ?? 'Successful'), 0, 255);
        $notes = 'Paystack checkout payment confirmed for payment link ' . $reference . '.';

        $paymentStmt = $conn->prepare(
            'INSERT INTO payments (invoice_id, recorded_by, amount, payment_date, payment_method, reference, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $paymentMethod = 'online';
        $paymentStmt->bind_param('iidssss', $link['invoice_id'], $actor, $paymentAmount, $paymentDateOnly, $paymentMethod, $providerReference, $notes);
        $paymentStmt->execute();
        $paymentId = (int) $paymentStmt->insert_id;
        $paymentStmt->close();

        $newAmountPaid = round((float) $link['amount_paid'] + $paymentAmount, 2);
        $paidUpdate = $conn->prepare('UPDATE invoices SET amount_paid = ? WHERE id = ?');
        $paidUpdate->bind_param('di', $newAmountPaid, $link['invoice_id']);
        $paidUpdate->execute();
        $paidUpdate->close();

        $summary = recalculateAdjustedInvoice($conn, (int) $link['invoice_id']);
        $nextReminder = $summary['status'] === 'paid' ? null : $link['due_date'];
        $reminderUpdate = $conn->prepare('UPDATE invoices SET next_reminder_at = ? WHERE id = ?');
        $reminderUpdate->bind_param('si', $nextReminder, $link['invoice_id']);
        $reminderUpdate->execute();
        $reminderUpdate->close();

        $receiptResult = issuePaymentReceipt($conn, $paymentId, $actor);
        $receipt = $receiptResult['receipt'];
        $metadata = json_encode([
            'provider_data' => $providerData,
            'settled_at' => date('c'),
            'invoice_summary' => $summary,
        ]);

        $paidAt = normalizePaystackPaidAt($providerData['paid_at'] ?? $providerData['paidAt'] ?? null);
        $update = $conn->prepare(
            'UPDATE payment_links
             SET status = ?, provider_status = ?, provider_reference = ?, gateway_response = ?,
                 payment_id = ?, receipt_id = ?, paid_at = ?, last_verified_at = NOW(), metadata = ?, updated_by = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $paidStatus = 'paid';
        $update->bind_param(
            'ssssiissii',
            $paidStatus,
            $providerStatus,
            $providerReference,
            $gatewayResponse,
            $paymentId,
            $receipt['id'],
            $paidAt,
            $metadata,
            $actor,
            $link['id']
        );
        $update->execute();
        $update->close();

        $description = "Paystack confirmed {$link['currency']} {$paymentAmount} payment for invoice {$link['invoice_number']} ({$link['client_name']}). Receipt {$receipt['receipt_number']} issued.";
        logFinancialAction($conn, $actor, 'payment_link.paid', 'PaymentLink', (int) $link['id'], $description);
        logFinancialAction($conn, $actor, 'payment.recorded', 'Payment', $paymentId, $description);
        logFinancialAction($conn, $actor, 'receipt.issued', 'Receipt', (int) $receipt['id'], "Receipt {$receipt['receipt_number']} issued from Paystack payment link {$reference}.");

        $conn->commit();

        return [
            'already_processed' => false,
            'payment_link' => fetchPaymentLink($conn, (int) $link['id']),
            'payment_id' => $paymentId,
            'receipt' => receiptResponseData($receipt),
            'invoice_summary' => $summary,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function logPaymentWebhook(mysqli $conn, array $data): int
{
    $stmt = $conn->prepare(
        'INSERT INTO payment_webhook_logs
            (provider, event_type, reference, signature_valid, processing_status, failure_reason, payload)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $provider = (string) ($data['provider'] ?? 'paystack');
    $eventType = (string) ($data['event_type'] ?? '');
    $reference = (string) ($data['reference'] ?? '');
    $signatureValid = (int) ($data['signature_valid'] ?? 0);
    $processingStatus = (string) ($data['processing_status'] ?? 'received');
    $failureReason = isset($data['failure_reason']) ? substr((string) $data['failure_reason'], 0, 255) : null;
    $payload = (string) ($data['payload'] ?? '');
    $stmt->bind_param('sssisss', $provider, $eventType, $reference, $signatureValid, $processingStatus, $failureReason, $payload);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function updatePaymentWebhookLog(mysqli $conn, int $id, string $status, ?string $failureReason = null): void
{
    $stmt = $conn->prepare('UPDATE payment_webhook_logs SET processing_status = ?, failure_reason = ? WHERE id = ?');
    $failureReason = $failureReason ? substr($failureReason, 0, 255) : null;
    $stmt->bind_param('ssi', $status, $failureReason, $id);
    $stmt->execute();
    $stmt->close();
}
