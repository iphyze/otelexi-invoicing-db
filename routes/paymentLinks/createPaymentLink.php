<?php
// routes/paymentLinks/createPaymentLink.php
// Creates either a manual payment request or a Paystack checkout link for an active invoice.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/paymentLinks.php';
require_once __DIR__ . '/../../utils/paystackClient.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING], 'Only Super Admin, Admin or Accounting users can create payment links.');

    $invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($invoiceId < 1) {
        throw new Exception('A valid invoice ID is required.', 422);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid or missing JSON payload.', 400);
    }

    $provider = strtolower(trim((string) ($data['provider'] ?? 'manual')));
    if (!in_array($provider, ['manual', 'paystack'], true)) {
        throw new Exception('Payment provider must be manual or paystack.', 422);
    }

    $amountInput = $data['amount'] ?? null;
    $expiresInDays = isset($data['expires_in_days']) ? max(1, min(30, (int) $data['expires_in_days'])) : 7;
    $sendEmail = !empty($data['send_email']);

    $invoiceStmt = $conn->prepare(
        'SELECT i.id, i.invoice_number, i.client_id, i.status, i.total_amount, i.amount_paid,
                i.credited_amount, i.refunded_amount, i.balance_due, i.currency, i.due_date,
                c.company_name AS client_name, c.email AS client_email
         FROM invoices i
         JOIN clients c ON c.id = i.client_id
         WHERE i.id = ? LIMIT 1'
    );
    $invoiceStmt->bind_param('i', $invoiceId);
    $invoiceStmt->execute();
    $invoice = $invoiceStmt->get_result()->fetch_assoc();
    $invoiceStmt->close();

    if (!$invoice) {
        throw new Exception('Invoice not found.', 404);
    }

    if (!in_array((string) $invoice['status'], ['sent', 'partial', 'overdue'], true)) {
        throw new Exception('Payment links can only be created for active outstanding invoices.', 409);
    }

    $balanceDue = round((float) $invoice['balance_due'], 2);
    if ($balanceDue <= 0) {
        throw new Exception('This invoice has no outstanding balance.', 409);
    }

    $amount = $amountInput === null || $amountInput === '' ? $balanceDue : round((float) $amountInput, 2);
    if ($amount <= 0 || $amount > $balanceDue) {
        throw new Exception('Payment link amount must be greater than zero and not exceed the invoice balance.', 422);
    }

    if ($provider === 'paystack') {
        if (strtoupper((string) $invoice['currency']) !== 'NGN' && !configBool('PAYSTACK_ALLOW_NON_NGN', false)) {
            throw new Exception('Paystack is currently enabled for NGN invoices only. Set PAYSTACK_ALLOW_NON_NGN=true only after confirming your Paystack account supports that currency.', 422);
        }
        if (!isDeliverableEmail((string) $invoice['client_email'])) {
            throw new Exception('The client must have a valid email address before a Paystack checkout link can be created.', 422);
        }
    }

    $reference = paymentLinkReference($invoiceId);
    $expiresAt = (new DateTime('now'))->modify("+{$expiresInDays} days")->format('Y-m-d H:i:s');
    $authorizationUrl = null;
    $accessCode = null;
    $providerReference = null;
    $providerStatus = null;
    $gatewayResponse = null;
    $status = 'pending';
    $metadata = [
        'created_source' => 'staff_portal',
        'requested_amount' => $amount,
        'invoice_balance_at_creation' => $balanceDue,
    ];

    if ($provider === 'paystack') {
        $callbackUrl = paystackCallbackUrl($reference);
        $payload = [
            'email' => (string) $invoice['client_email'],
            'amount' => (string) paystackAmountToSubunit($amount),
            'currency' => (string) $invoice['currency'],
            'reference' => $reference,
            'metadata' => json_encode([
                'invoice_id' => (int) $invoice['id'],
                'invoice_number' => (string) $invoice['invoice_number'],
                'client_id' => (int) $invoice['client_id'],
                'client_name' => (string) $invoice['client_name'],
                'payment_link_reference' => $reference,
            ]),
        ];
        if ($callbackUrl) {
            $payload['callback_url'] = $callbackUrl;
        }

        $paystack = initializePaystackTransaction($payload);
        $paystackData = $paystack['data'] ?? [];
        $authorizationUrl = (string) ($paystackData['authorization_url'] ?? '');
        $accessCode = (string) ($paystackData['access_code'] ?? '');
        $providerReference = (string) ($paystackData['reference'] ?? $reference);
        $providerStatus = 'initialized';
        $gatewayResponse = (string) ($paystack['message'] ?? 'Authorization URL created');
        $status = 'pending';
        $metadata['paystack_initialize'] = $paystackData;

        if ($authorizationUrl === '' || $providerReference === '') {
            throw new Exception('Paystack did not return a usable authorization URL. Please try again.', 500);
        }
    }

    $stmt = $conn->prepare(
        'INSERT INTO payment_links
            (invoice_id, client_id, provider, reference, amount, currency, status,
             authorization_url, access_code, provider_reference, provider_status, gateway_response,
             expires_at, metadata, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $metadataJson = json_encode($metadata);
    $stmt->bind_param(
        'iissdsssssssssi',
        $invoice['id'],
        $invoice['client_id'],
        $provider,
        $reference,
        $amount,
        $invoice['currency'],
        $status,
        $authorizationUrl,
        $accessCode,
        $providerReference,
        $providerStatus,
        $gatewayResponse,
        $expiresAt,
        $metadataJson,
        $user['id']
    );
    $stmt->execute();
    $paymentLinkId = (int) $stmt->insert_id;
    $stmt->close();

    logFinancialAction(
        $conn,
        (int) $user['id'],
        'payment_link.created',
        'PaymentLink',
        $paymentLinkId,
        "{$user['email']} created {$provider} payment link {$reference} for invoice {$invoice['invoice_number']} ({$invoice['currency']} {$amount})."
    );

    $paymentLink = fetchPaymentLink($conn, $paymentLinkId);
    $emailSent = false;
    $emailWarning = null;

    if ($sendEmail && $paymentLink) {
        try {
            if (!isDeliverableEmail((string) $invoice['client_email'])) {
                throw new RuntimeException('The client email address is invalid or missing.');
            }

            $company = fetchCompanyPaymentSettings($conn);
            $body = emailPaymentLinkDelivery([
                'provider' => $paymentLink['provider'],
                'reference' => $paymentLink['reference'],
                'payment_url' => paymentLinkFrontendUrl($paymentLink['authorization_url'] ?? null, $paymentLink['reference'], $paymentLink['provider']),
                'invoice_number' => $invoice['invoice_number'],
                'client_name' => $invoice['client_name'],
                'amount' => $invoice['currency'] . ' ' . number_format($amount, 2),
                'currency' => $invoice['currency'],
                'expires_at' => $expiresAt,
                'bank_name' => $company['bank_name'] ?? '',
                'account_name' => $company['account_name'] ?? '',
                'account_number' => $company['account_number'] ?? '',
                'bank_branch' => $company['bank_branch'] ?? '',
                'company_email' => $company['email'] ?? '',
                'company_phone' => $company['phone'] ?? '',
            ], $company['company_name'] ?? 'Otelex Hospitality Supplies Ltd');

            sendMail((string) $invoice['client_email'], (string) $invoice['client_name'], "Payment Request for {$invoice['invoice_number']}", $body);
            $emailSent = true;
            logFinancialAction($conn, (int) $user['id'], 'payment_link.sent', 'PaymentLink', $paymentLinkId, "{$user['email']} emailed payment link {$reference} to {$invoice['client_email']}.");
        } catch (Throwable $mailError) {
            $emailWarning = 'Payment request was created, but the email could not be sent. You can resend it from the payment request actions after checking the email settings.';
            error_log('Payment Link Email Warning: ' . $mailError->getMessage());
            logFinancialAction($conn, (int) $user['id'], 'payment_link.email_failed', 'PaymentLink', $paymentLinkId, "{$user['email']} created payment link {$reference}, but the email delivery failed.");
        }
    }

    $message = $provider === 'paystack'
        ? ($emailSent ? 'Paystack checkout link created and emailed to the client.' : 'Paystack checkout link created successfully.')
        : ($emailSent ? 'Manual payment request created and emailed to the client.' : 'Manual payment request created successfully.');

    if ($emailWarning !== null) {
        $message .= ' ' . $emailWarning;
    }

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'email_sent' => $emailSent,
        'email_warning' => $emailWarning,
        'data' => paymentLinkResponse($paymentLink),
    ]);
} catch (Throwable $e) {
    error_log('Create Payment Link Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422, 502, 503], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $e->getMessage() : 'Payment link could not be created right now. Please check the PHP error log for details.',
    ]);
}
