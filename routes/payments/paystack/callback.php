<?php
// routes/payments/paystack/callback.php
// Public browser callback after Paystack checkout.

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../../../utils/paymentLinks.php';
require_once __DIR__ . '/../../../utils/paystackClient.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $reference = trim((string) ($_GET['reference'] ?? ''));
    if ($reference === '') {
        throw new Exception('Payment reference is required.', 422);
    }

    $verification = verifyPaystackTransaction($reference);
    $providerData = $verification['data'] ?? [];
    $settlement = settleSuccessfulPaystackPayment($conn, $reference, $providerData, null);
    $link = $settlement['payment_link'] ?? null;
    $status = is_array($link) ? (string) ($link['status'] ?? '') : 'unknown';

    $redirectUrl = trim((string) (config('PAYSTACK_CALLBACK_REDIRECT_URL', '') ?? ''));
    if ($redirectUrl !== '') {
        $separator = str_contains($redirectUrl, '?') ? '&' : '?';
        header('Location: ' . $redirectUrl . $separator . 'reference=' . rawurlencode($reference) . '&status=' . rawurlencode($status));
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'message' => $status === 'paid' ? 'Payment confirmed successfully.' : 'Payment status checked.',
        'data' => [
            'payment_link' => is_array($link) ? paymentLinkResponse($link) : null,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Paystack Callback Error: ' . $e->getMessage());
    $redirectUrl = trim((string) (config('PAYSTACK_CALLBACK_REDIRECT_URL', '') ?? ''));
    if ($redirectUrl !== '') {
        $separator = str_contains($redirectUrl, '?') ? '&' : '?';
        header('Location: ' . $redirectUrl . $separator . 'status=failed&message=' . rawurlencode('Payment could not be confirmed. Please contact Otelex.'));
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode(['status' => 'failed', 'message' => $clientError ? $e->getMessage() : 'Payment could not be confirmed right now.']);
}
