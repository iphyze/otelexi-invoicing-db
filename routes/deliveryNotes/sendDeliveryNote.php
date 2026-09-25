<?php
// routes/deliveryNotes/sendDeliveryNote.php
// POST /delivery-notes/{id}/send
// Emails a delivery note with the PDF generated from the frontend.

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';
require_once __DIR__ . '/../../utils/documentEmail.php';
require_once __DIR__ . '/../../utils/deliveryNotes.php';

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

    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_SALES, ROLE_ACCOUNTING], 'Unauthorized: You cannot email delivery notes.');

    if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('A valid delivery note ID is required.', 400);
    }

    $deliveryNoteId = (int) $_GET['id'];

    $stmt = $conn->prepare(
        'SELECT dn.id, dn.delivery_note_number, dn.invoice_id, dn.delivery_date, dn.dispatch_date,
                dn.delivery_address, dn.contact_person, dn.contact_phone, dn.status, dn.created_by,
                i.invoice_number, i.created_by AS invoice_created_by,
                c.company_name AS client_name, c.email AS client_email
         FROM delivery_notes dn
         JOIN invoices i ON i.id = dn.invoice_id
         JOIN clients c ON c.id = dn.client_id
         WHERE dn.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $deliveryNoteId);
    $stmt->execute();
    $note = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$note) {
        throw new Exception('Delivery note not found.', 404);
    }

    if ($role === ROLE_SALES && (int) $note['invoice_created_by'] !== $userId && (int) $note['created_by'] !== $userId) {
        throw new Exception('Unauthorized: You cannot email this delivery note.', 403);
    }

    if ($note['status'] === 'cancelled') {
        throw new Exception('Cannot email a cancelled delivery note.', 409);
    }

    $recipientEmail = strtolower(trim((string) ($_POST['recipient_email'] ?? $note['client_email'] ?? '')));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid recipient email address is required.', 422);
    }

    $mailProvider = requestedMailProviderFromRequest();

    $attachment = requireDocumentPdfUpload('delivery_note', (string) $note['delivery_note_number']);

    $settingsResult = $conn->query('SELECT * FROM company_settings LIMIT 1');
    $settings = $settingsResult ? $settingsResult->fetch_assoc() : [];
    $companyName = trim((string) ($settings['company_name'] ?? 'Otelex')) ?: 'Otelex';
    $companyName = str_replace(["\r", "\n"], ' ', $companyName);

    $subject = "Delivery Note {$note['delivery_note_number']} from {$companyName}";
    $emailData = [
        'delivery_note_number' => (string) $note['delivery_note_number'],
        'invoice_number' => (string) $note['invoice_number'],
        'client_name' => (string) $note['client_name'],
        'delivery_date' => date('d M Y', strtotime((string) $note['delivery_date'])),
        'dispatch_date' => $note['dispatch_date'] ? date('d M Y', strtotime((string) $note['dispatch_date'])) : 'Pending',
        'delivery_address' => (string) $note['delivery_address'],
        'contact_person' => (string) ($note['contact_person'] ?? ''),
        'contact_phone' => (string) ($note['contact_phone'] ?? ''),
        'status' => deliveryNoteStatusLabel((string) $note['status']),
    ];

    $logData = [
        'document_type' => 'delivery_note',
        'document_id' => $deliveryNoteId,
        'document_number' => (string) $note['delivery_note_number'],
        'recipient_email' => $recipientEmail,
        'email_subject' => $subject,
        'attachment_name' => $attachment['name'],
        'attachment_size' => $attachment['size'],
        'sent_by' => $userId,
    ];

    try {
        sendMail(
            to: $recipientEmail,
            toName: (string) $note['client_name'],
            subject: $subject,
            body: emailDeliveryNote($emailData, emailHtml($companyName)),
            attachments: [$attachment],
            provider: $mailProvider
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

    logDeliveryNoteAction(
        $conn,
        $userId,
        'delivery_note.emailed',
        $deliveryNoteId,
        "{$senderEmail} emailed delivery note {$note['delivery_note_number']} with PDF attachment to {$recipientEmail}."
    );

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => "Delivery note PDF emailed successfully to {$recipientEmail}.",
        'data' => [
            'attachment_name' => $attachment['name'],
            'status' => $note['status'],
        ],
    ]);
} catch (Throwable $error) {
    respondDocumentEmailFailure($error, 'Send Delivery Note Error');
}
