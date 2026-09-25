<?php
// routes/admin/sendTestEmail.php
// Sends a Super Admin test email with an optional temporary attachment.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/mailer.php';

header('Content-Type: application/json; charset=utf-8');

const MAIL_TEST_MAX_ATTACHMENT_BYTES = 10485760; // 10 MB
const MAIL_TEST_ALLOWED_EXTENSIONS = [
    'pdf', 'png', 'jpg', 'jpeg', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx',
];

function mailTestAttachment(): ?array
{
    if (!isset($_FILES['attachment'])) {
        return null;
    }

    $file = $_FILES['attachment'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The attachment exceeds the server upload limit.',
            UPLOAD_ERR_PARTIAL => 'The attachment upload was interrupted. Please try again.',
            default => 'The attachment could not be uploaded.',
        };
        throw new Exception($message, 422);
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $originalName = trim((string) ($file['name'] ?? 'attachment'));
    $size = (int) ($file['size'] ?? 0);

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new Exception('The uploaded attachment is invalid.', 422);
    }

    if ($size <= 0 || $size > MAIL_TEST_MAX_ATTACHMENT_BYTES) {
        throw new Exception('The attachment must be larger than 0 bytes and no more than 10 MB.', 422);
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, MAIL_TEST_ALLOWED_EXTENSIONS, true)) {
        throw new Exception('Allowed attachment types: PDF, PNG, JPG, TXT, CSV, DOC, DOCX, XLS and XLSX.', 422);
    }

    // Strip path/control characters before the name reaches the MIME header.
    $safeName = preg_replace('/[\\\/\x00-\x1F\x7F]+/', '_', basename($originalName));
    $safeName = trim((string) $safeName);

    return [
        'path' => $tmpPath,
        'name' => $safeName !== '' ? $safeName : ('attachment.' . $extension),
        'size' => $size,
    ];
}

function auditMailTest(mysqli $conn, array $user, string $recipient, array $result, bool $hasAttachment): void
{
    try {
        $action = $result['success'] ? 'mail.test_sent' : 'mail.test_failed';
        $model = 'MailDiagnostics';
        $description = sprintf(
            '%s ran an SMTP test email to %s (%s).',
            $user['email'],
            $recipient,
            $result['success'] ? 'successful' : 'failed'
        );
        $properties = json_encode([
            'recipient' => $recipient,
            'attachment' => $hasAttachment,
            'diagnostic_code' => $result['code'] ?? null,
        ], JSON_UNESCAPED_SLASHES);
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
        $userId = (int) $user['id'];

        $stmt = $conn->prepare(
            'INSERT INTO activity_log (user_id, action, model_type, description, properties, ip_address) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssss', $userId, $action, $model, $description, $properties, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // A diagnostic email result should not be hidden by an audit-log failure.
        error_log('Mail Test Audit Error: ' . $e->getMessage());
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can send mail tests.');

    $recipient = strtolower(trim((string) ($_POST['recipient'] ?? '')));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));

    if (!isDeliverableEmail($recipient)) {
        throw new Exception('Enter a valid recipient email address.', 422);
    }

    // Prevent SMTP header injection and keep diagnostics deliberately small.
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    if ($subject === '') {
        throw new Exception('Email subject is required.', 422);
    }
    if (strlen($subject) > 180) {
        throw new Exception('Email subject must not exceed 180 characters.', 422);
    }
    if ($message === '') {
        throw new Exception('Email message is required.', 422);
    }
    if (strlen($message) > 10000) {
        throw new Exception('Email message must not exceed 10,000 characters.', 422);
    }

    $attachment = mailTestAttachment();
    $attachments = $attachment ? [[
        'path' => $attachment['path'],
        'name' => $attachment['name'],
    ]] : [];

    $escapedMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $html = '<div style="font-family:Arial,sans-serif;color:#0f172a;line-height:1.6">'
        . '<h2 style="margin:0 0 14px">Otelex Mail Test</h2>'
        . '<p style="margin:0 0 16px">' . $escapedMessage . '</p>'
        . '<hr style="border:0;border-top:1px solid #e2e8f0;margin:20px 0">'
        . '<p style="margin:0;color:#64748b;font-size:12px">Sent from the Super Admin mail diagnostics page.</p>'
        . '</div>';

    $result = sendDiagnosticMail(
        $recipient,
        '',
        $subject,
        $html,
        $message,
        $attachments,
        'zoho'
    );

    $result['recipient'] = $recipient;
    $result['attachment'] = $attachment ? [
        'name' => $attachment['name'],
        'size' => $attachment['size'],
    ] : null;

    auditMailTest($conn, $user, $recipient, $result, $attachment !== null);

    echo json_encode([
        'status' => 'success',
        'message' => $result['success']
            ? 'Test email sent successfully.'
            : 'The test email could not be sent.',
        'data' => $result,
    ]);
} catch (Throwable $e) {
    error_log('Send Test Email Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'The test email could not be processed right now.' : $e->getMessage(),
    ]);
}
