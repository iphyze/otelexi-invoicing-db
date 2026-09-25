<?php
// utils/mail/brevoProvider.php
// Brevo transactional-email API transport used by the central Otelex mail service.

declare(strict_types=1);

function brevoMailConfig(): array
{
    $apiKey = trim((string) (config('BREVO_API_KEY', '') ?? ''));
    $baseUrl = rtrim((string) (config('BREVO_API_BASE_URL', 'https://api.brevo.com/v3') ?? 'https://api.brevo.com/v3'), '/');
    $fromEmail = trim((string) (config('BREVO_FROM_ADDRESS', config('MAIL_FROM_ADDRESS', '')) ?? ''));
    $fromName = trim((string) (config('BREVO_FROM_NAME', config('MAIL_FROM_NAME', 'Otelex')) ?? 'Otelex'));
    $replyToEmail = trim((string) (config('BREVO_REPLY_TO', config('MAIL_REPLY_TO', $fromEmail)) ?? $fromEmail));
    $replyToName = trim((string) (config('BREVO_REPLY_TO_NAME', config('MAIL_REPLY_TO_NAME', $fromName)) ?? $fromName));
    $timeout = max(5, min(120, (int) config('BREVO_TIMEOUT', '30')));
    $verifyPeer = configBool('BREVO_VERIFY_PEER', true);

    if ($apiKey === '') {
        throw new MailProviderException(
            'brevo',
            'configuration_error',
            'Brevo is not configured yet.',
            false,
            'BREVO_API_KEY is missing.'
        );
    }

    if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || stripos($baseUrl, 'https://') !== 0) {
        throw new MailProviderException(
            'brevo',
            'configuration_error',
            'Brevo API configuration is invalid.',
            false,
            'BREVO_API_BASE_URL must be a valid HTTPS URL.'
        );
    }

    if (
        filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false
        || filter_var($replyToEmail, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new MailProviderException(
            'brevo',
            'configuration_error',
            'Brevo contains an invalid sender or reply-to address.',
            false,
            'BREVO_FROM_ADDRESS or BREVO_REPLY_TO is invalid.'
        );
    }

    return [
        'api_key' => $apiKey,
        'base_url' => $baseUrl,
        'from_email' => $fromEmail,
        'from_name' => $fromName !== '' ? $fromName : 'Otelex',
        'reply_to_email' => $replyToEmail,
        'reply_to_name' => $replyToName !== '' ? $replyToName : ($fromName !== '' ? $fromName : 'Otelex'),
        'timeout' => $timeout,
        'verify_peer' => $verifyPeer,
    ];
}

function brevoRequest(string $method, string $path, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        throw new MailProviderException(
            'brevo',
            'curl_unavailable',
            'Brevo cannot be used because the PHP cURL extension is not enabled.',
            false,
            'PHP cURL extension is unavailable.'
        );
    }

    $config = brevoMailConfig();
    $url = $config['base_url'] . '/' . ltrim($path, '/');
    $ch = curl_init($url);

    if ($ch === false) {
        throw new MailProviderException(
            'brevo',
            'connection_failed',
            'Brevo connection could not be initialized.',
            true,
            'curl_init returned false.'
        );
    }

    $headers = [
        'accept: application/json',
        'content-type: application/json',
        'api-key: ' . $config['api_key'],
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => min(15, $config['timeout']),
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_SSL_VERIFYPEER => $config['verify_peer'],
        CURLOPT_SSL_VERIFYHOST => $config['verify_peer'] ? 2 : 0,
    ]);

    if ($payload !== null) {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encodedPayload === false) {
            curl_close($ch);
            throw new MailProviderException(
                'brevo',
                'payload_error',
                'The email payload could not be prepared for Brevo.',
                false,
                'JSON encoding failed: ' . json_last_error_msg()
            );
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedPayload);
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $curlError !== '') {
        $rawDetails = "cURL {$curlErrno}: {$curlError}";
        error_log('Brevo API transport error: ' . $rawDetails . ' | URL: ' . $url);

        $ambiguous = in_array($curlErrno, [CURLE_OPERATION_TIMEDOUT, CURLE_RECV_ERROR], true);
        throw new MailProviderException(
            'brevo',
            $ambiguous ? 'request_ambiguous' : 'connection_failed',
            $ambiguous
                ? 'Brevo did not return a final response. The message may or may not have been accepted; automatic fallback was not attempted to avoid duplicate email.'
                : 'The application could not reach the Brevo API.',
            !$ambiguous,
            $rawDetails
        );
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $providerMessage = trim((string) ($decoded['message'] ?? ''));
        error_log(
            'Brevo API error. HTTP ' . $httpCode
            . ' | Message: ' . ($providerMessage !== '' ? $providerMessage : '[none]')
            . ' | Response: ' . substr((string) $raw, 0, 1200)
        );

        if (in_array($httpCode, [401, 403], true)) {
            throw new MailProviderException(
                'brevo',
                'authentication_failed',
                'Brevo authentication failed. Confirm the API key and account permissions.',
                true,
                "HTTP {$httpCode}: {$providerMessage}"
            );
        }

        if ($httpCode === 429) {
            throw new MailProviderException(
                'brevo',
                'rate_limited',
                'Brevo temporarily refused the request because the sending limit was reached.',
                true,
                "HTTP {$httpCode}: {$providerMessage}"
            );
        }

        $lowerMessage = strtolower($providerMessage);
        if (str_contains($lowerMessage, 'sender') || str_contains($lowerMessage, 'from')) {
            throw new MailProviderException(
                'brevo',
                'sender_rejected',
                'Brevo rejected the sender. Confirm that the sender/domain is verified in Brevo.',
                false,
                "HTTP {$httpCode}: {$providerMessage}"
            );
        }

        if ($httpCode >= 500) {
            throw new MailProviderException(
                'brevo',
                'provider_unavailable',
                'Brevo is temporarily unavailable.',
                true,
                "HTTP {$httpCode}: {$providerMessage}"
            );
        }

        throw new MailProviderException(
            'brevo',
            'request_rejected',
            'Brevo rejected the email request.',
            false,
            "HTTP {$httpCode}: {$providerMessage}"
        );
    }

    return [
        'status_code' => $httpCode,
        'data' => $decoded,
    ];
}

function brevoConfigurationSummary(): array
{
    try {
        $config = brevoMailConfig();

        $appUrl = rtrim((string) (config('APP_URL', '') ?? ''), '/');
        $webhookTokenConfigured = trim((string) (config('BREVO_WEBHOOK_TOKEN', '') ?? '')) !== '';

        return [
            'provider' => 'brevo',
            'configured' => true,
            'enabled' => mailProviderEnabled('brevo'),
            'api_base_url' => $config['base_url'],
            'from_address' => $config['from_email'],
            'from_name' => $config['from_name'],
            'verify_peer' => $config['verify_peer'],
            'webhook_url' => $appUrl !== '' ? $appUrl . '/webhooks/brevo/mail' : null,
            'webhook_token_configured' => $webhookTokenConfigured,
        ];
    } catch (Throwable $e) {
        $appUrl = rtrim((string) (config('APP_URL', '') ?? ''), '/');
        return [
            'provider' => 'brevo',
            'configured' => false,
            'enabled' => mailProviderEnabled('brevo'),
            'message' => $e instanceof MailProviderException ? $e->safeMessage() : 'Brevo is not configured.',
            'webhook_url' => $appUrl !== '' ? $appUrl . '/webhooks/brevo/mail' : null,
            'webhook_token_configured' => trim((string) (config('BREVO_WEBHOOK_TOKEN', '') ?? '')) !== '',
        ];
    }
}

function testBrevoConnection(): array
{
    $startedAt = microtime(true);

    try {
        $response = brevoRequest('GET', '/account');
        $account = $response['data'];

        return [
            'success' => true,
            'provider' => 'brevo',
            'code' => 'connected',
            'message' => 'Brevo API authentication succeeded.',
            'account_email' => isset($account['email']) && is_string($account['email']) ? $account['email'] : null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    } catch (Throwable $e) {
        $providerError = $e instanceof MailProviderException
            ? $e
            : new MailProviderException('brevo', 'connection_failed', 'Brevo connection failed.', true, $e->getMessage());

        return [
            'success' => false,
            'provider' => 'brevo',
            'code' => $providerError->diagnosticCode(),
            'message' => $providerError->safeMessage(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}

function sendViaBrevo(
    string $to,
    string $toName,
    string $subject,
    string $body,
    ?string $plainText = null,
    array $attachments = [],
    ?string $trackingId = null
): array {
    $config = brevoMailConfig();

    $payload = [
        'sender' => [
            'name' => $config['from_name'],
            'email' => $config['from_email'],
        ],
        'to' => [[
            'email' => $to,
            'name' => $toName !== '' ? $toName : $to,
        ]],
        'replyTo' => [
            'email' => $config['reply_to_email'],
            'name' => $config['reply_to_name'],
        ],
        'subject' => trim($subject),
        'htmlContent' => $body,
        'textContent' => buildMailPlainText($body, $plainText),
    ];

    if ($trackingId !== null && preg_match('/^[a-f0-9]{32}$/i', $trackingId)) {
        $trackingId = strtolower($trackingId);
        $payload['headers'] = [
            'X-Mailin-custom' => 'otelex_tracking_id=' . $trackingId,
            'X-Otelex-Tracking-ID' => $trackingId,
        ];
        $payload['tags'] = ['otelex_' . $trackingId];
    }

    $brevoAttachments = [];
    foreach ($attachments as $attachment) {
        $normalized = normalizeMailAttachment($attachment);
        if ($normalized === null) {
            continue;
        }

        $contents = file_get_contents($normalized['path']);
        if ($contents === false) {
            throw new MailProviderException(
                'brevo',
                'attachment_unavailable',
                'An email attachment could not be read.',
                false,
                'Unable to read attachment: ' . $normalized['path']
            );
        }

        $brevoAttachments[] = [
            'name' => $normalized['name'],
            'content' => base64_encode($contents),
        ];
    }

    if ($brevoAttachments !== []) {
        $payload['attachment'] = $brevoAttachments;
    }

    $response = brevoRequest('POST', '/smtp/email', $payload);
    $messageId = trim((string) ($response['data']['messageId'] ?? ''));

    return [
        'success' => true,
        'provider' => 'brevo',
        'code' => 'accepted',
        'message' => 'Brevo accepted the email for delivery.',
        'message_id' => $messageId !== '' ? $messageId : null,
    ];
}
