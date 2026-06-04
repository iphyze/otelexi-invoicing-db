<?php
// routes/customerPortal/revokeCustomerPortalLink.php
// Revokes a customer portal link immediately.

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
    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING], 'Only Super Admin, Admin or Accounting users can revoke customer portal links.');

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id < 1) {
        throw new Exception('A valid customer portal link ID is required.', 422);
    }

    $link = fetchCustomerPortalLink($conn, $id);
    if (!$link) {
        throw new Exception('Customer portal link not found.', 404);
    }

    if ((string) $link['status'] === 'revoked') {
        throw new Exception('This customer portal link has already been revoked.', 409);
    }

    $stmt = $conn->prepare("UPDATE customer_portal_links SET status = 'revoked', updated_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ii', $user['id'], $id);
    $stmt->execute();
    $stmt->close();

    logCustomerPortalAction(
        $conn,
        (int) $user['id'],
        'customer_portal.revoked',
        $id,
        "{$user['email']} revoked customer portal link {$link['reference']} for invoice {$link['invoice_number']}.",
        ['invoice_id' => (int) $link['invoice_id']]
    );

    echo json_encode([
        'status' => 'success',
        'message' => 'Customer portal link revoked successfully.',
        'data' => customerPortalLinkResponse(fetchCustomerPortalLink($conn, $id)),
    ]);
} catch (Throwable $e) {
    error_log('Revoke Customer Portal Link Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $e->getMessage() : 'Customer portal link could not be revoked right now.',
    ]);
}
