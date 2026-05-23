<?php
// routes/invoices/sendInvoice.php
// POST /invoices/{id}/send
// Emails a finalised invoice with the PDF generated from the frontend preview.
// Roles allowed: Admin, Accounting.

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';
require_once __DIR__ . '/../../utils/documentEmail.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $userData = authenticateUser();
    $userId = (int) $userData['id'];
    $role = (string) $userData['role'];
    $senderEmail = (string) $userData['email'];

    if (!in_array($role, ['super_admin', 'admin', 'accounting'], true)) {
        throw new Exception('Unauthorized: Only Admins or Accounting users can send invoices.', 403);
    }

    if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('A valid Invoice ID is required.', 400);
    }

    $invoiceId = (int) $_GET['id'];

    $stmt = $conn->prepare(
        'SELECT i.id, i.invoice_number, i.issue_date, i.due_date, i.currency,
                i.total_amount, i.balance_due, i.amount_paid, i.status,
                i.payment_terms, i.notes, i.footer_text,
                c.company_name AS client_name, c.email AS client_email
         FROM invoices i
         JOIN clients c ON c.id = i.client_id
         WHERE i.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        throw new Exception('Invoice not found.', 404);
    }

    if ($invoice['status'] === 'draft') {
        throw new Exception('Cannot email a draft invoice. Please finalize it first.', 409);
    }

    if ($invoice['status'] === 'cancelled') {
        throw new Exception('Cannot email a cancelled invoice.', 409);
    }

    $recipientEmail = strtolower(trim((string) ($_POST['recipient_email'] ?? $invoice['client_email'] ?? '')));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid recipient email address is required.', 422);
    }

    $attachment = requireDocumentPdfUpload('invoice', (string) $invoice['invoice_number']);

    $settingsResult = $conn->query('SELECT * FROM company_settings LIMIT 1');
    $settings = $settingsResult ? $settingsResult->fetch_assoc() : [];
    $companyName = trim((string) ($settings['company_name'] ?? 'Otelex')) ?: 'Otelex';
    $companyName = str_replace(["\r", "\n"], ' ', $companyName);

    $currencySymbol = $invoice['currency'] === 'USD' ? '$' : '₦';
    $issueDate = date('d M Y', strtotime((string) $invoice['issue_date']));
    $dueDate = date('d M Y', strtotime((string) $invoice['due_date']));
    $paymentTerms = $invoice['payment_terms'] === 'net_7' ? 'Net 7 Days' : 'Due on Receipt';
    $subject = "Invoice {$invoice['invoice_number']} from {$companyName}";

    $emailData = [
        'invoice_number' => (string) $invoice['invoice_number'],
        'client_name' => (string) $invoice['client_name'],
        'total_amount' => $currencySymbol . ' ' . number_format((float) $invoice['total_amount'], 2),
        'balance_due' => $currencySymbol . ' ' . number_format((float) $invoice['balance_due'], 2),
        'due_date' => $dueDate,
        'issue_date' => $issueDate,
        'payment_terms' => $paymentTerms,
        'notes' => (string) ($invoice['notes'] ?? ''),
        'footer_text' => emailHtml((string) ($invoice['footer_text'] ?? ($settings['legal_footer'] ?? ''))),
        'bank_name' => (string) ($settings['bank_name'] ?? ''),
        'account_name' => (string) ($settings['account_name'] ?? ''),
        'account_number' => (string) ($settings['account_number'] ?? ''),
    ];

    $logData = [
        'document_type' => 'invoice',
        'document_id' => $invoiceId,
        'document_number' => (string) $invoice['invoice_number'],
        'recipient_email' => $recipientEmail,
        'email_subject' => $subject,
        'attachment_name' => $attachment['name'],
        'attachment_size' => $attachment['size'],
        'sent_by' => $userId,
    ];

    try {
        sendMail(
            to: $recipientEmail,
            toName: (string) $invoice['client_name'],
            subject: $subject,
            body: emailInvoiceDelivery($emailData, emailHtml($companyName)),
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
    $action = 'invoice.emailed';
    $modelType = 'Invoice';
    $description = "{$senderEmail} emailed invoice {$invoice['invoice_number']} with PDF attachment to {$recipientEmail}.";
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $activity->bind_param('ississ', $userId, $action, $modelType, $invoiceId, $description, $ip);
    $activity->execute();
    $activity->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => "Invoice PDF emailed successfully to {$recipientEmail}.",
        'data' => [
            'attachment_name' => $attachment['name'],
            'status' => $invoice['status'],
        ],
    ]);
} catch (Throwable $error) {
    respondDocumentEmailFailure($error, 'Send Invoice Error');
}
