<?php
// routes/quotations/sendQuotation.php
// POST /quotation/{id}/send
// Emails the frontend-generated quotation PDF and changes draft/rejected documents to sent.
// Roles allowed: Admin; Sales may send quotations they created.

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
        throw new Exception('Unauthorized: Only Admins or Sales staff can send quotations.', 403);
    }

    if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('A valid Quotation ID is required.', 400);
    }

    $quotationId = (int) $_GET['id'];

    $stmt = $conn->prepare(
        'SELECT q.id, q.quotation_number, q.created_by, q.issue_date, q.expiry_date,
                q.currency, q.total_amount, q.status, q.notes,
                c.company_name AS client_name, c.email AS client_email
         FROM quotations q
         JOIN clients c ON c.id = q.client_id
         WHERE q.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $quotationId);
    $stmt->execute();
    $quotation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$quotation) {
        throw new Exception('Quotation not found.', 404);
    }

    if ($role === 'sales' && (int) $quotation['created_by'] !== $userId) {
        throw new Exception('Unauthorized: You can only send quotations you created.', 403);
    }

    $allowedStatuses = ['draft', 'rejected', 'sent', 'accepted'];
    if (!in_array((string) $quotation['status'], $allowedStatuses, true)) {
        throw new Exception(
            "This quotation cannot be emailed. Current status: {$quotation['status']}.",
            409
        );
    }

    $recipientEmail = strtolower(trim((string) ($_POST['recipient_email'] ?? $quotation['client_email'] ?? '')));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid recipient email address is required.', 422);
    }

    $attachment = requireDocumentPdfUpload('quotation', (string) $quotation['quotation_number']);

    $settingsResult = $conn->query('SELECT company_name FROM company_settings LIMIT 1');
    $settings = $settingsResult ? $settingsResult->fetch_assoc() : [];
    $companyName = trim((string) ($settings['company_name'] ?? 'Otelex')) ?: 'Otelex';
    $companyName = str_replace(["\r", "\n"], ' ', $companyName);

    $currencySymbol = $quotation['currency'] === 'USD' ? '$' : '₦';
    $issueDate = date('d M Y', strtotime((string) $quotation['issue_date']));
    $expiryDate = date('d M Y', strtotime((string) $quotation['expiry_date']));
    $subject = "Quotation {$quotation['quotation_number']} from {$companyName}";

    $emailData = [
        'quotation_number' => (string) $quotation['quotation_number'],
        'client_name' => (string) $quotation['client_name'],
        'total_amount' => $currencySymbol . ' ' . number_format((float) $quotation['total_amount'], 2),
        'issue_date' => $issueDate,
        'expiry_date' => $expiryDate,
        'notes' => (string) ($quotation['notes'] ?? ''),
    ];

    $logData = [
        'document_type' => 'quotation',
        'document_id' => $quotationId,
        'document_number' => (string) $quotation['quotation_number'],
        'recipient_email' => $recipientEmail,
        'email_subject' => $subject,
        'attachment_name' => $attachment['name'],
        'attachment_size' => $attachment['size'],
        'sent_by' => $userId,
    ];

    try {
        sendMail(
            to: $recipientEmail,
            toName: (string) $quotation['client_name'],
            subject: $subject,
            body: emailQuotationDelivery($emailData, emailHtml($companyName)),
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

    $previousStatus = (string) $quotation['status'];
    $status = $previousStatus;

    if (in_array($previousStatus, ['draft', 'rejected'], true)) {
        $status = 'sent';
        $update = $conn->prepare("UPDATE quotations SET status = 'sent', updated_at = NOW() WHERE id = ?");
        $update->bind_param('i', $quotationId);
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
    $action = $isResend ? 'quotation.resent' : 'quotation.sent';
    $modelType = 'Quotation';
    $description = "{$senderEmail} emailed quotation {$quotation['quotation_number']} with PDF attachment to {$recipientEmail}. Valid until {$expiryDate}.";
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $activity->bind_param('ississ', $userId, $action, $modelType, $quotationId, $description, $ip);
    $activity->execute();
    $activity->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => "Quotation PDF emailed successfully to {$recipientEmail}.",
        'data' => [
            'status' => $status,
            'attachment_name' => $attachment['name'],
            'is_resend' => $isResend,
        ],
    ]);
} catch (Throwable $error) {
    respondDocumentEmailFailure($error, 'Send Quotation Error');
}
