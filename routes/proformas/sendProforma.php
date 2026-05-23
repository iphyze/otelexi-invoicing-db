<?php
// routes/proformas/sendProforma.php
// POST /proforma/{id}/send
// Emails the frontend-generated proforma PDF and changes draft/rejected documents to sent.
// Roles allowed: Admin; Sales may send proformas they created.

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

    if (!in_array($role, ['super_admin', 'admin', 'sales'], true)) {
        throw new Exception('Unauthorized: Only Admins or Sales staff can send proformas.', 403);
    }

    if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('A valid Proforma ID is required.', 400);
    }

    $proformaId = (int) $_GET['id'];

    $stmt = $conn->prepare(
        'SELECT p.id, p.proforma_number, p.created_by, p.issue_date, p.expiry_date,
                p.currency, p.total_amount, p.status, p.notes,
                c.company_name AS client_name, c.email AS client_email
         FROM proforma_invoices p
         JOIN clients c ON c.id = p.client_id
         WHERE p.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $proformaId);
    $stmt->execute();
    $proforma = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$proforma) {
        throw new Exception('Proforma invoice not found.', 404);
    }

    if ($role === 'sales' && (int) $proforma['created_by'] !== $userId) {
        throw new Exception('Unauthorized: You can only send proformas you created.', 403);
    }

    $allowedStatuses = ['draft', 'rejected', 'sent', 'approved'];
    if (!in_array((string) $proforma['status'], $allowedStatuses, true)) {
        throw new Exception(
            "This proforma cannot be emailed. Current status: {$proforma['status']}.",
            409
        );
    }

    $recipientEmail = strtolower(trim((string) ($_POST['recipient_email'] ?? $proforma['client_email'] ?? '')));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid recipient email address is required.', 422);
    }

    $attachment = requireDocumentPdfUpload('proforma', (string) $proforma['proforma_number']);

    $settingsResult = $conn->query('SELECT * FROM company_settings LIMIT 1');
    $settings = $settingsResult ? $settingsResult->fetch_assoc() : [];
    $companyName = trim((string) ($settings['company_name'] ?? 'Otelex')) ?: 'Otelex';
    $companyName = str_replace(["\r", "\n"], ' ', $companyName);

    $currencySymbol = $proforma['currency'] === 'USD' ? '$' : '₦';
    $issueDate = date('d M Y', strtotime((string) $proforma['issue_date']));
    $expiryDate = !empty($proforma['expiry_date'])
        ? date('d M Y', strtotime((string) $proforma['expiry_date']))
        : 'N/A';
    $subject = "Proforma Invoice {$proforma['proforma_number']} from {$companyName}";

    $emailData = [
        'proforma_number' => (string) $proforma['proforma_number'],
        'client_name' => (string) $proforma['client_name'],
        'total_amount' => $currencySymbol . ' ' . number_format((float) $proforma['total_amount'], 2),
        'issue_date' => $issueDate,
        'expiry_date' => $expiryDate,
        'notes' => (string) ($proforma['notes'] ?? ''),
        'bank_name' => (string) ($settings['bank_name'] ?? ''),
        'account_name' => (string) ($settings['account_name'] ?? ''),
        'account_number' => (string) ($settings['account_number'] ?? ''),
    ];

    $logData = [
        'document_type' => 'proforma',
        'document_id' => $proformaId,
        'document_number' => (string) $proforma['proforma_number'],
        'recipient_email' => $recipientEmail,
        'email_subject' => $subject,
        'attachment_name' => $attachment['name'],
        'attachment_size' => $attachment['size'],
        'sent_by' => $userId,
    ];

    try {
        sendMail(
            to: $recipientEmail,
            toName: (string) $proforma['client_name'],
            subject: $subject,
            body: emailProformaDelivery($emailData, emailHtml($companyName)),
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

    $previousStatus = (string) $proforma['status'];
    $status = $previousStatus;

    if (in_array($previousStatus, ['draft', 'rejected'], true)) {
        $status = 'sent';
        $update = $conn->prepare("UPDATE proforma_invoices SET status = 'sent', updated_at = NOW() WHERE id = ?");
        $update->bind_param('i', $proformaId);
        $update->execute();
        $update->close();
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
    $isResend = !in_array($previousStatus, ['draft', 'rejected'], true);
    $action = $isResend ? 'proforma.resent' : 'proforma.sent';
    $modelType = 'ProformaInvoice';
    $description = "{$senderEmail} emailed proforma {$proforma['proforma_number']} with PDF attachment to {$recipientEmail}. Valid until {$expiryDate}.";
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $activity->bind_param('ississ', $userId, $action, $modelType, $proformaId, $description, $ip);
    $activity->execute();
    $activity->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => "Proforma PDF emailed successfully to {$recipientEmail}.",
        'data' => [
            'status' => $status,
            'attachment_name' => $attachment['name'],
            'is_resend' => $isResend,
        ],
    ]);
} catch (Throwable $error) {
    respondDocumentEmailFailure($error, 'Send Proforma Error');
}
