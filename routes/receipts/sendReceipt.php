<?php
// routes/receipts/sendReceipt.php
// POST /receipts/{id}/send
// Emails a generated payment receipt with the PDF produced by the React preview/download component.

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';
require_once __DIR__ . '/../../utils/documentEmail.php';
require_once __DIR__ . '/../../utils/receipt.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    $userId = (int) $user['id'];
    $role = (string) $user['role'];
    $senderEmail = (string) $user['email'];

    if (!in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING], true)) {
        throw new Exception('Unauthorized: Only Admins or Accounting users can send receipts.', 403);
    }

    if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('A valid Receipt ID is required.', 400);
    }

    $receiptId = (int) $_GET['id'];
    $receipt = fetchReceiptById($conn, $receiptId);
    if (!$receipt) {
        throw new Exception('Receipt not found.', 404);
    }

    $recipientEmail = strtolower(trim((string) ($_POST['recipient_email'] ?? $receipt['client_email'] ?? '')));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid recipient email address is required.', 422);
    }

    $attachment = requireDocumentPdfUpload('receipt', (string) $receipt['receipt_number']);
    $settingsResult = $conn->query('SELECT * FROM company_settings LIMIT 1');
    $settings = $settingsResult ? $settingsResult->fetch_assoc() : [];
    $companyName = trim((string) ($settings['company_name'] ?? 'Otelex')) ?: 'Otelex';
    $companyName = str_replace(["\r", "\n"], ' ', $companyName);
    $symbol = $receipt['currency'] === 'USD' ? '$' : '₦';
    $subject = "Payment Receipt {$receipt['receipt_number']} from {$companyName}";

    $emailData = [
        'receipt_number' => (string) $receipt['receipt_number'],
        'invoice_number' => (string) $receipt['invoice_number'],
        'client_name' => (string) $receipt['client_name'],
        'amount_received' => $symbol . ' ' . number_format((float) $receipt['amount_received'], 2),
        'balance_after_payment' => $symbol . ' ' . number_format((float) $receipt['balance_after_payment'], 2),
        'payment_date' => date('d M Y', strtotime((string) $receipt['payment_date'])),
        'payment_method' => ucwords(str_replace('_', ' ', (string) $receipt['payment_method'])),
        'payment_reference' => (string) ($receipt['payment_reference'] ?? ''),
    ];

    $logData = [
        'document_type' => 'receipt',
        'document_id' => $receiptId,
        'document_number' => (string) $receipt['receipt_number'],
        'recipient_email' => $recipientEmail,
        'email_subject' => $subject,
        'attachment_name' => $attachment['name'],
        'attachment_size' => $attachment['size'],
        'sent_by' => $userId,
    ];

    try {
        sendMail(
            to: $recipientEmail,
            toName: (string) $receipt['client_name'],
            subject: $subject,
            body: emailPaymentReceipt($emailData, emailHtml($companyName)),
            attachments: [$attachment]
        );
    } catch (Throwable $mailError) {
        recordDocumentEmailLog($conn, [
            ...$logData,
            'delivery_status' => 'failed',
            'failure_reason' => $mailError->getMessage(),
        ]);
        throw $mailError;
    }

    recordDocumentEmailLog($conn, [
        ...$logData,
        'delivery_status' => 'sent',
        'failure_reason' => null,
    ]);

    $activity = $conn->prepare(
        'INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $action = 'receipt.emailed';
    $modelType = 'Receipt';
    $description = "{$senderEmail} emailed receipt {$receipt['receipt_number']} with PDF attachment to {$recipientEmail}.";
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $activity->bind_param('ississ', $userId, $action, $modelType, $receiptId, $description, $ip);
    $activity->execute();
    $activity->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => "Receipt PDF emailed successfully to {$recipientEmail}.",
        'data' => [
            'receipt_id' => $receiptId,
            'receipt_number' => $receipt['receipt_number'],
            'attachment_name' => $attachment['name'],
        ],
    ]);
} catch (Throwable $error) {
    respondDocumentEmailFailure($error, 'Send Receipt Error');
}
