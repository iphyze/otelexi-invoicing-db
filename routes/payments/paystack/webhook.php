<?php
// routes/payments/paystack/webhook.php
// Public Paystack webhook endpoint. Does not require app auth or CSRF.

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../../../utils/paymentLinks.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $rawBody = file_get_contents('php://input') ?: '';
    $signature = requestHeader('X-Paystack-Signature') ?? '';
    $secret = requiredConfig('PAYSTACK_SECRET_KEY');
    $computed = hash_hmac('sha512', $rawBody, $secret);
    $validSignature = $signature !== '' && hash_equals($computed, $signature);

    $event = json_decode($rawBody, true);
    $eventType = is_array($event) ? (string) ($event['event'] ?? '') : '';
    $data = is_array($event['data'] ?? null) ? $event['data'] : [];
    $reference = (string) ($data['reference'] ?? '');

    $logId = logPaymentWebhook($conn, [
        'provider' => 'paystack',
        'event_type' => $eventType,
        'reference' => $reference,
        'signature_valid' => $validSignature ? 1 : 0,
        'processing_status' => 'received',
        'payload' => $rawBody,
    ]);

    if (!$validSignature) {
        updatePaymentWebhookLog($conn, $logId, 'ignored', 'Invalid Paystack signature.');
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Webhook received.']);
        exit;
    }

    if (!in_array($eventType, ['charge.success', 'transaction.success'], true) || $reference === '') {
        updatePaymentWebhookLog($conn, $logId, 'ignored', 'Webhook event is not a successful charge event.');
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Webhook ignored.']);
        exit;
    }

    settleSuccessfulPaystackPayment($conn, $reference, $data, null);
    updatePaymentWebhookLog($conn, $logId, 'processed', null);

    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Webhook processed.']);
} catch (Throwable $e) {
    error_log('Paystack Webhook Error: ' . $e->getMessage());
    if (isset($logId) && is_int($logId)) {
        try { updatePaymentWebhookLog($conn, $logId, 'failed', $e->getMessage()); } catch (Throwable $ignored) {}
    }
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Webhook received.']);
}
