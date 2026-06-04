<?php
// routes/paymentLinks/getSinglePaymentLink.php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/paymentLinks.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING, ROLE_SALES], 'You do not have permission to view this payment link.');

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id < 1) {
        throw new Exception('A valid payment link ID is required.', 422);
    }

    $link = fetchPaymentLink($conn, $id);
    if (!$link) {
        throw new Exception('Payment link not found.', 404);
    }

    if (($user['role'] ?? '') === ROLE_SALES) {
        $invoiceStmt = $conn->prepare('SELECT created_by FROM invoices WHERE id = ? LIMIT 1');
        $invoiceStmt->bind_param('i', $link['invoice_id']);
        $invoiceStmt->execute();
        $owner = $invoiceStmt->get_result()->fetch_assoc();
        $invoiceStmt->close();
        if (!$owner || (int) $owner['created_by'] !== (int) $user['id']) {
            throw new Exception('You do not have permission to view this payment link.', 403);
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Payment link fetched successfully.', 'data' => paymentLinkResponse($link)]);
} catch (Throwable $e) {
    error_log('Get Single Payment Link Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode(['status' => 'failed', 'message' => $clientError ? $e->getMessage() : 'Payment link could not be loaded right now.']);
}
