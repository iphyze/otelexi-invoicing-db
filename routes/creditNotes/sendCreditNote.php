<?php
// routes/creditNotes/sendCreditNote.php
// POST /credit-notes/{id}/send
// Emails an issued credit note with the PDF generated from the frontend component.

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
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

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can send credit notes.');
    $userId = (int) $user['id'];

    $creditNoteId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($creditNoteId < 1) {
        throw new Exception('A valid credit note ID is required.', 422);
    }

    $query = $conn->prepare(
        'SELECT cn.id, cn.credit_note_number, cn.currency, cn.amount, cn.reason, cn.issued_at, cn.status,
                i.invoice_number, i.total_amount, i.credited_amount,
                c.company_name AS client_name, c.email AS client_email
         FROM credit_notes cn
         JOIN invoices i ON i.id = cn.invoice_id
         JOIN clients c ON c.id = cn.client_id
         WHERE cn.id = ? LIMIT 1'
    );
    $query->bind_param('i', $creditNoteId);
    $query->execute();
    $creditNote = $query->get_result()->fetch_assoc();
    $query->close();

    if (!$creditNote) {
        throw new Exception('Credit note not found.', 404);
    }
    if ($creditNote['status'] !== 'issued') {
        throw new Exception('Only an issued credit note can be emailed.', 409);
    }

    $recipientEmail = strtolower(trim((string) ($_POST['recipient_email'] ?? $creditNote['client_email'] ?? '')));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid recipient email address is required.', 422);
    }

    $attachment = requireDocumentPdfUpload('credit_note', (string) $creditNote['credit_note_number']);
    $settingsResult = $conn->query('SELECT * FROM company_settings LIMIT 1');
    $settings = $settingsResult ? $settingsResult->fetch_assoc() : [];
    $companyName = trim((string) ($settings['company_name'] ?? 'Otelex')) ?: 'Otelex';
    $companyName = str_replace(["\r", "\n"], ' ', $companyName);
    $symbol = $creditNote['currency'] === 'USD' ? '$' : '₦';
    $adjustedTotal = max(0, (float) $creditNote['total_amount'] - (float) $creditNote['credited_amount']);
    $subject = "Credit Note {$creditNote['credit_note_number']} from {$companyName}";

    $emailData = [
        'credit_note_number' => (string) $creditNote['credit_note_number'],
        'invoice_number' => (string) $creditNote['invoice_number'],
        'client_name' => (string) $creditNote['client_name'],
        'credit_amount' => $symbol . ' ' . number_format((float) $creditNote['amount'], 2),
        'adjusted_total' => $symbol . ' ' . number_format($adjustedTotal, 2),
        'reason' => (string) $creditNote['reason'],
        'issued_date' => date('d M Y', strtotime((string) $creditNote['issued_at'])),
    ];

    $logData = [
        'document_type' => 'credit_note',
        'document_id' => $creditNoteId,
        'document_number' => (string) $creditNote['credit_note_number'],
        'recipient_email' => $recipientEmail,
        'email_subject' => $subject,
        'attachment_name' => $attachment['name'],
        'attachment_size' => $attachment['size'],
        'sent_by' => $userId,
    ];

    try {
        sendMail(
            to: $recipientEmail,
            toName: (string) $creditNote['client_name'],
            subject: $subject,
            body: emailCreditNote($emailData, emailHtml($companyName)),
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
    $action = 'credit_note.emailed';
    $modelType = 'CreditNote';
    $description = "{$user['email']} emailed credit note {$creditNote['credit_note_number']} with PDF attachment to {$recipientEmail}.";
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $activity->bind_param('ississ', $userId, $action, $modelType, $creditNoteId, $description, $ip);
    $activity->execute();
    $activity->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => "Credit note PDF emailed successfully to {$recipientEmail}.",
        'data' => [
            'credit_note_id' => $creditNoteId,
            'credit_note_number' => $creditNote['credit_note_number'],
            'attachment_name' => $attachment['name'],
        ],
    ]);
} catch (Throwable $error) {
    respondDocumentEmailFailure($error, 'Send Credit Note Error');
}
