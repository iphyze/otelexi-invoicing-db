<?php
// routes/paymentLinks/getPublicPaymentRequest.php
// Public customer-facing payment request data for manual payment instructions and Paystack checkout handoff.

// This route intentionally does not require staff authentication because payment-request URLs are sent to customers.
// It only exposes safe customer-facing payment details and never exposes internal user IDs, access codes or metadata.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../utils/paymentLinks.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

function publicPaymentRequestStatusLabel(string $status): string
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

function publicPaymentRequestIsExpired(?string $expiresAt, string $status): bool
{
    if (!$expiresAt || !in_array($status, ['pending', 'processing'], true)) {
        return false;
    }

    try {
        return (new DateTime($expiresAt)) < new DateTime('now');
    } catch (Throwable $e) {
        return false;
    }
}

function safePublicString(?string $value): string
{
    return trim((string) ($value ?? ''));
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $reference = safePublicString($_GET['reference'] ?? '');
    if ($reference === '') {
        throw new Exception('A valid payment request reference is required.', 422);
    }

    if (!preg_match('/^[A-Z0-9\-]{8,80}$/i', $reference)) {
        throw new Exception('Invalid payment request reference.', 422);
    }

    $stmt = $conn->prepare(
        'SELECT pl.reference, pl.provider, pl.amount, pl.currency, pl.status, pl.authorization_url,
                pl.expires_at, pl.paid_at, pl.cancelled_at, pl.created_at,
                i.invoice_number,
                c.company_name AS client_name
         FROM payment_links pl
         JOIN invoices i ON i.id = pl.invoice_id
         JOIN clients c ON c.id = pl.client_id
         WHERE pl.reference = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $paymentRequest = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$paymentRequest) {
        throw new Exception('Payment request not found or no longer available.', 404);
    }

    $settingsResult = $conn->query(
        'SELECT company_name, address, city, state, country, phone, email, website, logo_path,
                bank_name, account_name, account_number, bank_branch, legal_footer
         FROM company_settings
         ORDER BY id ASC
         LIMIT 1'
    );
    $settings = $settingsResult ? ($settingsResult->fetch_assoc() ?: []) : [];

    $status = (string) $paymentRequest['status'];
    $isExpired = publicPaymentRequestIsExpired($paymentRequest['expires_at'] ?? null, $status);
    $publicStatus = $isExpired ? 'expired' : $status;
    $canBePaid = in_array($status, ['pending', 'processing'], true) && !$isExpired;
    $provider = (string) $paymentRequest['provider'];

    echo json_encode([
        'status' => 'success',
        'message' => 'Payment request loaded successfully.',
        'data' => [
            'reference' => (string) $paymentRequest['reference'],
            'provider' => $provider,
            'amount' => (float) $paymentRequest['amount'],
            'currency' => (string) $paymentRequest['currency'],
            'status' => $publicStatus,
            'status_label' => publicPaymentRequestStatusLabel($publicStatus),
            'original_status' => $status,
            'is_expired' => $isExpired,
            'can_be_paid' => $canBePaid,
            'expires_at' => $paymentRequest['expires_at'] ?? null,
            'paid_at' => $paymentRequest['paid_at'] ?? null,
            'cancelled_at' => $paymentRequest['cancelled_at'] ?? null,
            'created_at' => $paymentRequest['created_at'] ?? null,
            'invoice_number' => (string) $paymentRequest['invoice_number'],
            'client_name' => (string) $paymentRequest['client_name'],
            'paystack_url' => $provider === 'paystack' && $canBePaid ? safePublicString($paymentRequest['authorization_url'] ?? '') : null,
            'company' => [
                'company_name' => safePublicString($settings['company_name'] ?? 'Otelex Hospitality Supplies Ltd'),
                'address' => safePublicString($settings['address'] ?? ''),
                'city' => safePublicString($settings['city'] ?? ''),
                'state' => safePublicString($settings['state'] ?? ''),
                'country' => safePublicString($settings['country'] ?? 'Nigeria'),
                'phone' => safePublicString($settings['phone'] ?? ''),
                'email' => safePublicString($settings['email'] ?? ''),
                'website' => safePublicString($settings['website'] ?? ''),
                'logo_path' => safePublicString($settings['logo_path'] ?? ''),
                'legal_footer' => safePublicString($settings['legal_footer'] ?? ''),
            ],
            'bank' => [
                'bank_name' => safePublicString($settings['bank_name'] ?? ''),
                'account_name' => safePublicString($settings['account_name'] ?? ''),
                'account_number' => safePublicString($settings['account_number'] ?? ''),
                'bank_branch' => safePublicString($settings['bank_branch'] ?? ''),
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Public Payment Request Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 404, 405, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $e->getMessage() : 'Payment request could not be loaded right now.',
    ]);
}
