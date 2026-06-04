<?php
// routes/customerPortal/sendCustomerPortalLink.php
// Sends a fresh secure customer portal link to the invoice customer.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/customerPortal.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING, ROLE_SALES], 'Only authorized users can email customer portal links.');

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id < 1) {
        throw new Exception('A valid customer portal link ID is required.', 422);
    }

    $link = fetchCustomerPortalLink($conn, $id);
    if (!$link) {
        throw new Exception('Customer portal link not found.', 404);
    }

    $invoice = fetchCustomerPortalInvoiceForStaff($conn, (int) $link['invoice_id'], $user);
    if (!isDeliverableEmail((string) $link['client_email'])) {
        throw new Exception('The client email address is invalid or missing.', 422);
    }

    // Refresh the token before emailing so old copied links can be rotated safely.
    $prepared = createOrRefreshCustomerPortalLink($conn, $invoice, $user, 30);
    $freshLink = $prepared['link'];
    $publicUrl = $prepared['public_url'];

    if (!$publicUrl) {
        throw new Exception('FRONTEND_URL is not configured, so a customer portal link cannot be emailed.', 422);
    }

    $company = fetchCustomerPortalCompanySettings($conn);
    $body = emailCustomerPortalLinkDelivery([
        'portal_url' => $publicUrl,
        'invoice_number' => $invoice['invoice_number'],
        'client_name' => $invoice['client_name'],
        'amount' => $invoice['currency'] . ' ' . number_format((float) $invoice['total_amount'], 2),
        'balance_due' => $invoice['currency'] . ' ' . number_format((float) $invoice['balance_due'], 2),
        'expires_at' => $freshLink['expires_at'],
        'company_email' => $company['email'] ?? '',
        'company_phone' => $company['phone'] ?? '',
    ], $company['company_name'] ?? 'Otelex Hospitality Supplies Ltd');

    sendMail(
        (string) $link['client_email'],
        (string) $link['client_name'],
        "Customer Portal for {$invoice['invoice_number']}",
        $body
    );

    $update = $conn->prepare('UPDATE customer_portal_links SET last_sent_at = NOW(), email_count = email_count + 1, updated_by = ?, updated_at = NOW() WHERE id = ?');
    $update->bind_param('ii', $user['id'], $freshLink['id']);
    $update->execute();
    $update->close();

    logCustomerPortalAction(
        $conn,
        (int) $user['id'],
        'customer_portal.sent',
        (int) $freshLink['id'],
        "{$user['email']} emailed customer portal link {$freshLink['reference']} to {$link['client_email']}.",
        ['invoice_id' => (int) $invoice['id']]
    );

    echo json_encode([
        'status' => 'success',
        'message' => 'Customer portal link emailed to the client successfully.',
        'data' => customerPortalLinkResponse(fetchCustomerPortalLink($conn, (int) $freshLink['id']), $prepared['token']),
    ]);
} catch (Throwable $e) {
    error_log('Send Customer Portal Link Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $e->getMessage() : 'Customer portal link could not be emailed right now.',
    ]);
}
