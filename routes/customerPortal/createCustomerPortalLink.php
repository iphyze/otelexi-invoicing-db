<?php
// routes/customerPortal/createCustomerPortalLink.php
// Creates or refreshes a secure customer portal link for a finalized invoice.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/customerPortal.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole(
        $user,
        [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING, ROLE_SALES],
        'Only authorized users can create customer portal links.'
    );

    $invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($invoiceId < 1) {
        throw new Exception('A valid invoice ID is required.', 422);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if ($data !== null && !is_array($data)) {
        throw new Exception('Invalid JSON payload.', 400);
    }

    $expiresInDays = isset($data['expires_in_days']) ? (int) $data['expires_in_days'] : 30;
    $invoice = fetchCustomerPortalInvoiceForStaff($conn, $invoiceId, $user);
    $prepared = createOrRefreshCustomerPortalLink($conn, $invoice, $user, $expiresInDays);

    logCustomerPortalAction(
        $conn,
        (int) $user['id'],
        'customer_portal.generated',
        (int) $prepared['link']['id'],
        "{$user['email']} generated a customer portal link for invoice {$invoice['invoice_number']}.",
        ['invoice_id' => $invoiceId, 'expires_in_days' => max(1, min(90, $expiresInDays))]
    );

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => 'Customer portal link generated successfully.',
        'data' => customerPortalLinkResponse($prepared['link'], $prepared['token']),
    ]);
} catch (Throwable $e) {
    error_log('Create Customer Portal Link Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $e->getMessage() : 'Customer portal link could not be generated right now.',
    ]);
}
