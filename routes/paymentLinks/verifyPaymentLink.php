<?php
// routes/paymentLinks/verifyPaymentLink.php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/paymentLinks.php';
require_once __DIR__ . '/../../utils/paystackClient.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING], 'Only Super Admin, Admin or Accounting users can verify payment links.');

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id < 1) {
        throw new Exception('A valid payment link ID is required.', 422);
    }

    $link = fetchPaymentLink($conn, $id);
    if (!$link) {
        throw new Exception('Payment link not found.', 404);
    }
    if ((string) $link['provider'] !== 'paystack') {
        throw new Exception('Only Paystack payment links require gateway verification.', 409);
    }

    $verification = verifyPaystackTransaction((string) $link['reference']);
    $providerData = $verification['data'] ?? [];
    $settlement = settleSuccessfulPaystackPayment($conn, (string) $link['reference'], $providerData, (int) $user['id']);
    $updatedLink = fetchPaymentLink($conn, $id);

    echo json_encode([
        'status' => 'success',
        'message' => ($updatedLink['status'] ?? '') === 'paid'
            ? 'Payment verified successfully. Invoice payment and receipt have been recorded.'
            : 'Payment status checked. The transaction is not confirmed as paid yet.',
        'data' => [
            'payment_link' => paymentLinkResponse($updatedLink),
            'settlement' => $settlement,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Verify Payment Link Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422, 502, 503], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode(['status' => 'failed', 'message' => $clientError ? $e->getMessage() : 'Payment link could not be verified right now. Please check the PHP error log for details.']);
}
