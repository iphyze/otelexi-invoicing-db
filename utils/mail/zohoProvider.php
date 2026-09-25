<?php
// utils/mail/zohoProvider.php
// Zoho SMTP transport used by the central Otelex mail service.

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

function zohoMailConfig(): array
{
    $smtpHost = trim((string) config('SMTP_HOST', 'smtppro.zoho.com'));
    $smtpPort = (int) config('SMTP_PORT', '465');
    $smtpUser = trim((string) (config('SMTP_USERNAME', config('SMTP_USER', '')) ?? ''));
    $smtpPass = (string) (config('SMTP_PASSWORD', config('SMTP_PASS', '')) ?? '');

    $fromEmail = trim((string) (config('MAIL_FROM_ADDRESS', $smtpUser) ?? $smtpUser));
    $fromName = trim((string) (config('MAIL_FROM_NAME', config('SMTP_FROM_NAME', 'Otelex')) ?? 'Otelex'));
    $replyToEmail = trim((string) (config('MAIL_REPLY_TO', $fromEmail) ?? $fromEmail));
    $replyToName = trim((string) (config('MAIL_REPLY_TO_NAME', $fromName) ?? $fromName));

    $smtpEncryption = normalizeSmtpEncryption(
        (string) (config('SMTP_ENCRYPTION', '') ?? ''),
        $smtpPort
    );

    $timeout = max(5, min(120, (int) config('SMTP_TIMEOUT', '30')));
    $debugLevel = max(0, min(4, (int) config('SMTP_DEBUG', '0')));
    $verifyPeer = configBool('SMTP_VERIFY_PEER', true);

    if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '') {
        throw new MailProviderException(
            'zoho',
            'configuration_error',
            'Zoho SMTP is not configured completely.',
            false,
            'SMTP_HOST, SMTP_USERNAME and SMTP_PASSWORD must be configured.'
        );
    }

    if ($smtpPort < 1 || $smtpPort > 65535) {
        throw new MailProviderException(
            'zoho',
            'configuration_error',
            'Zoho SMTP contains an invalid port.',
            false,
            'SMTP_PORT is invalid.'
        );
    }

    if (
        filter_var($smtpUser, FILTER_VALIDATE_EMAIL) === false
        || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false
        || filter_var($replyToEmail, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new MailProviderException(
            'zoho',
            'configuration_error',
            'Zoho SMTP contains an invalid sender or reply-to address.',
            false,
            'SMTP_USERNAME, MAIL_FROM_ADDRESS or MAIL_REPLY_TO contains an invalid email address.'
        );
    }

    return [
        'host' => $smtpHost,
        'port' => $smtpPort,
        'username' => $smtpUser,
        'password' => $smtpPass,
        'encryption' => $smtpEncryption,
        'from_email' => $fromEmail,
        'from_name' => $fromName !== '' ? $fromName : 'Otelex',
        'reply_to_email' => $replyToEmail,
        'reply_to_name' => $replyToName !== '' ? $replyToName : ($fromName !== '' ? $fromName : 'Otelex'),
        'timeout' => $timeout,
        'debug' => $debugLevel,
        'verify_peer' => $verifyPeer,
    ];
}

function configureZohoMailer(PHPMailer $mail, array $config): void
{
    $mail->isSMTP();
    $mail->Host = $config['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['username'];
    $mail->Password = $config['password'];
    $mail->Port = $config['port'];
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Timeout = $config['timeout'];

    if ($config['encryption'] === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        // Port 465 starts encrypted immediately; STARTTLS negotiation is not used.
        $mail->SMTPAutoTLS = false;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
    }

    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => $config['verify_peer'],
            'verify_peer_name' => $config['verify_peer'],
            'allow_self_signed' => !$config['verify_peer'],
        ],
    ];

    $mail->SMTPDebug = $config['debug'];

    if ($config['debug'] > 0) {
        $mail->Debugoutput = static function (string $message, int $level): void {
            error_log("Zoho SMTP Debug [{$level}]: {$message}");
        };
    }
}

function classifyZohoFailure(string $details): MailProviderException
{
    $normalized = strtolower($details);

    if (
        str_contains($normalized, 'authenticate')
        || str_contains($normalized, 'authentication')
        || str_contains($normalized, '535')
    ) {
        return new MailProviderException(
            'zoho',
            'authentication_failed',
            'Zoho authentication failed. Confirm the mailbox address and app password.',
            true,
            $details
        );
    }

    if (
        str_contains($normalized, 'certificate')
        || str_contains($normalized, 'peer certificate')
        || str_contains($normalized, 'crypto')
        || (str_contains($normalized, 'tls') && str_contains($normalized, 'failed'))
    ) {
        return new MailProviderException(
            'zoho',
            'tls_failed',
            'The secure Zoho connection failed. Confirm the SSL/TLS mode and server certificate configuration.',
            true,
            $details
        );
    }

    if (
        str_contains($normalized, 'could not connect')
        || str_contains($normalized, 'connection refused')
        || str_contains($normalized, 'timed out')
        || str_contains($normalized, 'connection timeout')
        || str_contains($normalized, 'getaddrinfo')
    ) {
        return new MailProviderException(
            'zoho',
            'connection_failed',
            'The application could not reach Zoho SMTP.',
            true,
            $details
        );
    }

    if (
        str_contains($normalized, 'sender address rejected')
        || str_contains($normalized, 'from address')
        || str_contains($normalized, 'sender rejected')
        || str_contains($normalized, '553')
    ) {
        return new MailProviderException(
            'zoho',
            'sender_rejected',
            'Zoho rejected the sender address. Use the authenticated mailbox or a verified alias.',
            false,
            $details
        );
    }

    if (
        str_contains($normalized, 'recipient address rejected')
        || str_contains($normalized, 'recipient rejected')
        || str_contains($normalized, '550')
    ) {
        return new MailProviderException(
            'zoho',
            'recipient_rejected',
            'Zoho rejected the recipient address.',
            false,
            $details
        );
    }

    return new MailProviderException(
        'zoho',
        'smtp_failed',
        'Zoho SMTP could not complete the request.',
        true,
        $details
    );
}

function zohoConfigurationSummary(): array
{
    try {
        $config = zohoMailConfig();

        return [
            'provider' => 'zoho',
            'configured' => true,
            'enabled' => mailProviderEnabled('zoho'),
            'host' => $config['host'],
            'port' => $config['port'],
            'encryption' => $config['encryption'],
            'username' => $config['username'],
            'from_address' => $config['from_email'],
            'from_name' => $config['from_name'],
            'verify_peer' => $config['verify_peer'],
        ];
    } catch (Throwable $e) {
        return [
            'provider' => 'zoho',
            'configured' => false,
            'enabled' => mailProviderEnabled('zoho'),
            'message' => $e instanceof MailProviderException ? $e->safeMessage() : 'Zoho SMTP is not configured.',
        ];
    }
}

function testZohoConnection(): array
{
    $mail = new PHPMailer(true);
    $startedAt = microtime(true);

    try {
        $config = zohoMailConfig();
        configureZohoMailer($mail, $config);

        if (!$mail->smtpConnect()) {
            throw classifyZohoFailure(
                trim($mail->ErrorInfo) !== '' ? $mail->ErrorInfo : 'SMTP connection failed.'
            );
        }

        $mail->smtpClose();

        return [
            'success' => true,
            'provider' => 'zoho',
            'code' => 'connected',
            'message' => 'Zoho SMTP connection and authentication succeeded.',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    } catch (Throwable $e) {
        $mail->smtpClose();

        if ($e instanceof MailProviderException) {
            $providerError = $e;
        } else {
            $details = trim($mail->ErrorInfo) !== '' ? $mail->ErrorInfo : $e->getMessage();
            $providerError = classifyZohoFailure($details);
        }

        error_log('Zoho connection diagnostic failed [' . $providerError->diagnosticCode() . ']: ' . $providerError->rawDetails());

        return [
            'success' => false,
            'provider' => 'zoho',
            'code' => $providerError->diagnosticCode(),
            'message' => $providerError->safeMessage(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}

function sendViaZoho(
    string $to,
    string $toName,
    string $subject,
    string $body,
    ?string $plainText = null,
    array $attachments = [],
    ?string $trackingId = null
): array {
    $config = zohoMailConfig();

    if (strcasecmp($config['username'], $config['from_email']) !== 0) {
        error_log(
            'Zoho mail configuration note: MAIL_FROM_ADDRESS differs from SMTP_USERNAME. '
            . 'The from address must be a verified alias of the authenticated mailbox.'
        );
    }

    $mail = new PHPMailer(true);

    try {
        configureZohoMailer($mail, $config);
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addReplyTo($config['reply_to_email'], $config['reply_to_name']);
        $mail->addAddress($to, $toName !== '' ? $toName : $to);
        if ($trackingId !== null && preg_match('/^[a-f0-9]{32}$/i', $trackingId)) {
            $mail->addCustomHeader('X-Otelex-Tracking-ID', strtolower($trackingId));
        }
        $mail->isHTML(true);
        $mail->Subject = trim($subject);
        $mail->Body = $body;
        $mail->AltBody = buildMailPlainText($body, $plainText);

        foreach ($attachments as $attachment) {
            $normalized = normalizeMailAttachment($attachment);
            if ($normalized === null) {
                continue;
            }

            $mail->addAttachment($normalized['path'], $normalized['name']);
        }

        $mail->send();

        return [
            'success' => true,
            'provider' => 'zoho',
            'code' => 'accepted',
            'message' => 'Zoho SMTP accepted the email for delivery.',
            'message_id' => trim((string) $mail->getLastMessageID()) ?: null,
        ];
    } catch (Throwable $e) {
        if ($e instanceof MailProviderException) {
            throw $e;
        }

        $details = trim($mail->ErrorInfo) !== '' ? $mail->ErrorInfo : $e->getMessage();
        throw classifyZohoFailure($details);
    }
}
