<?php
// routes/paymentLinks/sendPaymentLink.php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/paymentLinks.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING], 'Only Super Admin, Admin or Accounting users can send payment links.');

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id < 1) {
        throw new Exception('A valid payment link ID is required.', 422);
    }

    $link = fetchPaymentLink($conn, $id);
    if (!$link) {
        throw new Exception('Payment link not found.', 404);
    }
    if (!in_array((string) $link['status'], ['pending', 'processing'], true)) {
        throw new Exception('Only active payment links can be emailed.', 409);
    }
    if (!isDeliverableEmail((string) $link['client_email'])) {
        throw new Exception('The client email address is invalid or missing.', 422);
    }

    $company = fetchCompanyPaymentSettings($conn);
    $body = emailPaymentLinkDelivery([
        'provider' => $link['provider'],
        'reference' => $link['reference'],
        'payment_url' => paymentLinkFrontendUrl($link['authorization_url'] ?? null, $link['reference'], $link['provider']),
        'invoice_number' => $link['invoice_number'],
        'client_name' => $link['client_name'],
        'amount' => $link['currency'] . ' ' . number_format((float) $link['amount'], 2),
        'currency' => $link['currency'],
        'expires_at' => $link['expires_at'],
        'bank_name' => $company['bank_name'] ?? '',
        'account_name' => $company['account_name'] ?? '',
        'account_number' => $company['account_number'] ?? '',
        'bank_branch' => $company['bank_branch'] ?? '',
        'company_email' => $company['email'] ?? '',
        'company_phone' => $company['phone'] ?? '',
    ], $company['company_name'] ?? 'Otelex Hospitality Supplies Ltd');

    sendMail((string) $link['client_email'], (string) $link['client_name'], "Payment Request for {$link['invoice_number']}", $body);
    logFinancialAction($conn, (int) $user['id'], 'payment_link.sent', 'PaymentLink', $id, "{$user['email']} emailed payment link {$link['reference']} to {$link['client_email']}.");

    echo json_encode(['status' => 'success', 'message' => 'Payment link emailed to the client successfully.', 'data' => paymentLinkResponse(fetchPaymentLink($conn, $id))]);
} catch (Throwable $e) {
    error_log('Send Payment Link Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode(['status' => 'failed', 'message' => $clientError ? $e->getMessage() : 'Payment link could not be emailed right now.']);
}
