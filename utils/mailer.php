<?php
// utils/mailer.php
// Central PHPMailer SMTP wrapper for Otelex.
// Keeps the existing Otelex sendMail() signature used by password reset,
// invoice, quotation and proforma email routes.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Determine whether an outbound recipient is a deliverable email address.
 */
function isDeliverableEmail(string $email): bool
{
    $email = strtolower(trim($email));

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    $domain = substr(strrchr($email, '@') ?: '', 1);

    if (
        $domain === ''
        || $domain === 'invalid'
        || str_ends_with($domain, '.invalid')
        || str_starts_with($email, 'legacy.')
        || str_contains($email, '@archive.invalid')
    ) {
        return false;
    }

    return true;
}

/**
 * Read an environment value while allowing a compatibility fallback key.
 */
function mailEnv(string $primaryKey, ?string $fallbackKey = null, string $default = ''): string
{
    $primaryValue = trim((string) ($_ENV[$primaryKey] ?? ''));

    if ($primaryValue !== '') {
        return $primaryValue;
    }

    if ($fallbackKey !== null) {
        $fallbackValue = trim((string) ($_ENV[$fallbackKey] ?? ''));

        if ($fallbackValue !== '') {
            return $fallbackValue;
        }
    }

    return $default;
}

/**
 * Send an HTML email through the configured SMTP mailbox.
 *
 * Otelex environment keys:
 * - SMTP_HOST
 * - SMTP_PORT
 * - SMTP_USERNAME   (SMTP_USER is supported as fallback)
 * - SMTP_PASSWORD   (SMTP_PASS is supported as fallback)
 * - SMTP_ENCRYPTION (ssl/smtps or tls/starttls; inferred from port if omitted)
 * - MAIL_FROM_ADDRESS
 * - MAIL_FROM_NAME
 * - MAIL_REPLY_TO       (optional)
 * - MAIL_REPLY_TO_NAME  (optional)
 * - SMTP_DEBUG          (optional; set to 2 temporarily and inspect PHP error_log)
 *
 * @param string      $to          Recipient email address
 * @param string      $toName      Recipient display name
 * @param string      $subject     Email subject line
 * @param string      $body        HTML email body
 * @param string|null $plainText   Plain-text fallback; generated from HTML when null
 * @param array       $attachments [['path' => '/absolute/file.pdf', 'name' => 'Invoice.pdf'], ...]
 *
 * @throws RuntimeException when configuration is missing or delivery fails.
 */
function sendMail(
    string $to,
    string $toName,
    string $subject,
    string $body,
    ?string $plainText = null,
    array $attachments = []
): void {
    if (!isset($_ENV['SMTP_HOST'])) {
        Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
    }

    $to = strtolower(trim($to));
    $toName = trim($toName);

    if (!isDeliverableEmail($to)) {
        error_log('Mailer skipped: recipient email address is not deliverable.');
        throw new RuntimeException('Email could not be sent at this time.');
    }

    $smtpHost = mailEnv('SMTP_HOST');
    $smtpPort = (int) mailEnv('SMTP_PORT', null, '587');
    $smtpUser = mailEnv('SMTP_USERNAME', 'SMTP_USER');
    $smtpPass = mailEnv('SMTP_PASSWORD', 'SMTP_PASS');

    $fromEmail = mailEnv('MAIL_FROM_ADDRESS', null, $smtpUser);
    $fromName = mailEnv('MAIL_FROM_NAME', 'SMTP_FROM_NAME', 'Otelex');
    $replyToEmail = mailEnv('MAIL_REPLY_TO', null, $fromEmail);
    $replyToName = mailEnv('MAIL_REPLY_TO_NAME', null, $fromName);

    if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '') {
        error_log(
            'Mailer configuration error: SMTP_HOST, SMTP_USERNAME and/or SMTP_PASSWORD is missing.'
        );
        throw new RuntimeException('Email service is not configured correctly.');
    }

    if ($smtpPort < 1 || $smtpPort > 65535) {
        error_log('Mailer configuration error: invalid SMTP_PORT value.');
        throw new RuntimeException('Email service is not configured correctly.');
    }

    if (
        filter_var($smtpUser, FILTER_VALIDATE_EMAIL) === false
        || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false
        || filter_var($replyToEmail, FILTER_VALIDATE_EMAIL) === false
    ) {
        error_log('Mailer configuration error: SMTP sender or reply-to email is invalid.');
        throw new RuntimeException('Email service is not configured correctly.');
    }

    $mail = new PHPMailer(true);

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->Port = $smtpPort;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 20;

        /*
         * Use explicit SMTP_ENCRYPTION when configured.
         * Otherwise:
         * - Port 465 uses implicit SSL/SMTPS
         * - Other authenticated SMTP ports use STARTTLS
         */
        $smtpEncryption = strtolower(mailEnv('SMTP_ENCRYPTION'));

        if (in_array($smtpEncryption, ['ssl', 'smtps'], true)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (in_array($smtpEncryption, ['tls', 'starttls'], true)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($smtpPort === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        /*
         * Do not disable SSL certificate verification in production.
         * If SMTP certificate validation fails, use the correct SMTP host
         * supplied by your email provider or hosting provider.
         */

        $debugLevel = max(0, min(4, (int) mailEnv('SMTP_DEBUG', null, '0')));
        $mail->SMTPDebug = $debugLevel;

        if ($debugLevel > 0) {
            $mail->Debugoutput = static function (string $message, int $level): void {
                error_log("SMTP Debug [{$level}]: {$message}");
            };
        }

        // Sender and recipient
        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($replyToEmail, $replyToName);
        $mail->addAddress($to, $toName !== '' ? $toName : $to);

        // Email content
        $mail->isHTML(true);
        $mail->Subject = trim($subject);
        $mail->Body = $body;
        $mail->AltBody = $plainText !== null
            ? trim($plainText)
            : trim(strip_tags(str_replace(
                ['<br>', '<br/>', '<br />', '</p>'],
                "\n",
                $body
            )));

        // Attachments
        foreach ($attachments as $attachment) {
            $path = trim((string) ($attachment['path'] ?? ''));

            if ($path === '') {
                continue;
            }

            if (!is_file($path) || !is_readable($path)) {
                error_log("Mailer attachment skipped: unavailable file {$path}");
                continue;
            }

            $name = trim((string) ($attachment['name'] ?? basename($path)));

            $mail->addAttachment(
                $path,
                $name !== '' ? $name : basename($path)
            );
        }

        $mail->send();
    } catch (MailException $e) {
        $details = trim($mail->ErrorInfo) !== ''
            ? $mail->ErrorInfo
            : $e->getMessage();

        error_log("Mailer Error to {$to}: {$details}");

        // Do not expose SMTP account or server details in an API response.
        throw new RuntimeException('Email could not be sent at this time.');
    }
}