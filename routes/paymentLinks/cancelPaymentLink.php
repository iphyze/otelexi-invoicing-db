<?php
// routes/paymentLinks/cancelPaymentLink.php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/paymentLinks.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING], 'Only Super Admin, Admin or Accounting users can cancel payment links.');

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id < 1) {
        throw new Exception('A valid payment link ID is required.', 422);
    }

    $link = fetchPaymentLink($conn, $id);
    if (!$link) {
        throw new Exception('Payment link not found.', 404);
    }
    if (!in_array((string) $link['status'], ['pending', 'processing'], true)) {
        throw new Exception('Only pending or processing payment links can be cancelled.', 409);
    }

    $stmt = $conn->prepare('UPDATE payment_links SET status = ?, cancelled_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?');
    $status = 'cancelled';
    $stmt->bind_param('sii', $status, $user['id'], $id);
    $stmt->execute();
    $stmt->close();

    logFinancialAction($conn, (int) $user['id'], 'payment_link.cancelled', 'PaymentLink', $id, "{$user['email']} cancelled payment link {$link['reference']} for invoice {$link['invoice_number']}.");

    echo json_encode(['status' => 'success', 'message' => 'Payment link cancelled successfully.', 'data' => paymentLinkResponse(fetchPaymentLink($conn, $id))]);
} catch (Throwable $e) {
    error_log('Cancel Payment Link Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode(['status' => 'failed', 'message' => $clientError ? $e->getMessage() : 'Payment link could not be cancelled right now.']);
}
