<?php
// utils/mailer.php
// Central multi-provider mail service for Otelex.
// Existing sendMail() callers remain compatible; provider selection is optional.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/mail/mailSettings.php';
require_once __DIR__ . '/mail/mailTracking.php';

use PHPMailer\PHPMailer\PHPMailer;

final class MailProviderException extends RuntimeException
{
    private string $providerName;
    private string $diagnostic;
    private string $safe;
    private bool $canFallback;
    private string $details;

    public function __construct(
        string $provider,
        string $diagnosticCode,
        string $safeMessage,
        bool $retryable,
        string $rawDetails = ''
    ) {
        parent::__construct($safeMessage);
        $this->providerName = $provider;
        $this->diagnostic = $diagnosticCode;
        $this->safe = $safeMessage;
        $this->canFallback = $retryable;
        $this->details = $rawDetails !== '' ? $rawDetails : $safeMessage;
    }

    public function provider(): string
    {
        return $this->providerName;
    }

    public function diagnosticCode(): string
    {
        return $this->diagnostic;
    }

    public function safeMessage(): string
    {
        return $this->safe;
    }

    public function retryable(): bool
    {
        return $this->canFallback;
    }

    public function rawDetails(): string
    {
        return $this->details;
    }
}

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

function normalizeSmtpEncryption(string $value, int $port): string
{
    $value = strtolower(trim($value));

    if ($value === '') {
        return $port === 465 ? 'ssl' : 'tls';
    }

    if (in_array($value, ['ssl', 'smtps'], true)) {
        return 'ssl';
    }

    if (in_array($value, ['tls', 'starttls'], true)) {
        return 'tls';
    }

    throw new RuntimeException('SMTP_ENCRYPTION must be tls/starttls or ssl/smtps.');
}

function buildMailPlainText(string $htmlBody, ?string $plainText = null): string
{
    if ($plainText !== null) {
        return trim($plainText);
    }

    return trim(strip_tags(str_replace(
        ['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'],
        "\n",
        $htmlBody
    )));
}

/**
 * Normalize an attachment for both SMTP and API providers.
 */
function normalizeMailAttachment(array $attachment): ?array
{
    $path = trim((string) ($attachment['path'] ?? ''));
    if ($path === '') {
        return null;
    }

    if (!is_file($path) || !is_readable($path)) {
        throw new MailProviderException(
            'system',
            'attachment_unavailable',
            'An email attachment is no longer available.',
            false,
            'Attachment unavailable: ' . $path
        );
    }

    $name = trim((string) ($attachment['name'] ?? basename($path)));

    return [
        'path' => $path,
        'name' => $name !== '' ? $name : basename($path),
        'size' => (int) (filesize($path) ?: 0),
    ];
}

function supportedMailProviders(): array
{
    return ['zoho', 'brevo'];
}

function normalizeMailProvider(string $provider, bool $allowSystem = true): string
{
    $provider = strtolower(trim($provider));

    if ($provider === '' && $allowSystem) {
        return 'system';
    }

    if ($allowSystem && in_array($provider, ['system', 'default'], true)) {
        return 'system';
    }

    if (!in_array($provider, supportedMailProviders(), true)) {
        throw new InvalidArgumentException('Unsupported mail provider.', 422);
    }

    return $provider;
}

function defaultMailProvider(): string
{
    return (string) resolvedMailRoutingSettings()['default_provider'];
}

function mailProviderEnabled(string $provider): bool
{
    $provider = normalizeMailProvider($provider, false);
    $settings = resolvedMailRoutingSettings();

    return $provider === 'zoho'
        ? (bool) $settings['zoho_enabled']
        : (bool) $settings['brevo_enabled'];
}

function mailFallbackEnabled(): bool
{
    return (bool) resolvedMailRoutingSettings()['fallback_enabled'];
}

function fallbackMailProvider(): ?string
{
    $configured = strtolower(trim((string) (resolvedMailRoutingSettings()['fallback_provider'] ?? '')));
    return in_array($configured, supportedMailProviders(), true) ? $configured : null;
}

/**
 * Build the provider attempt order.
 *
 * An explicit provider selection is respected exactly and never silently
 * switched. Automatic fallback applies only when the caller chooses "system".
 */
function mailProviderPlan(string $requestedProvider = 'system'): array
{
    $requestedProvider = normalizeMailProvider($requestedProvider);
    $primary = $requestedProvider === 'system' ? defaultMailProvider() : $requestedProvider;

    if (!mailProviderEnabled($primary)) {
        throw new MailProviderException(
            $primary,
            'provider_disabled',
            ucfirst($primary) . ' mail is disabled.',
            false
        );
    }

    $plan = [$primary];

    if ($requestedProvider === 'system' && mailFallbackEnabled()) {
        $fallback = fallbackMailProvider();
        if (
            $fallback !== null
            && $fallback !== $primary
            && mailProviderEnabled($fallback)
        ) {
            $plan[] = $fallback;
        }
    }

    return $plan;
}

require_once __DIR__ . '/mail/zohoProvider.php';
require_once __DIR__ . '/mail/brevoProvider.php';

/**
 * Backwards-compatible Zoho SMTP helpers used by the current diagnostics page.
 * Batch 2 will expose both providers in Administration settings.
 */
function mailTransportConfig(): array
{
    return zohoMailConfig();
}

function configureSmtpMailer(PHPMailer $mail, array $config): void
{
    configureZohoMailer($mail, $config);
}

function classifyMailTransportFailure(string $details): array
{
    $error = classifyZohoFailure($details);

    return [
        'code' => $error->diagnosticCode(),
        'message' => $error->safeMessage(),
    ];
}

function mailConfigurationSummary(): array
{
    return zohoConfigurationSummary();
}

function testMailTransportConnection(): array
{
    return testZohoConnection();
}

/**
 * New provider-aware helpers consumed by the upcoming Admin Mail Settings UI.
 */
function mailProvidersSummary(): array
{
    $routing = resolvedMailRoutingSettings();

    return [
        'default_provider' => $routing['default_provider'],
        'fallback_enabled' => (bool) $routing['fallback_enabled'],
        'fallback_provider' => $routing['fallback_provider'],
        'source' => $routing['source'],
        'updated_at' => $routing['updated_at'],
        'providers' => [
            'zoho' => zohoConfigurationSummary(),
            'brevo' => brevoConfigurationSummary(),
        ],
    ];
}

function testMailProviderConnection(string $provider, bool $requireEnabled = true): array
{
    $provider = normalizeMailProvider($provider, false);

    if ($requireEnabled && !mailProviderEnabled($provider)) {
        return [
            'success' => false,
            'provider' => $provider,
            'code' => 'provider_disabled',
            'message' => ucfirst($provider) . ' mail is disabled.',
            'duration_ms' => 0,
        ];
    }

    return $provider === 'zoho'
        ? testZohoConnection()
        : testBrevoConnection();
}

function sendMailViaProvider(
    string $provider,
    string $to,
    string $toName,
    string $subject,
    string $body,
    ?string $plainText = null,
    array $attachments = [],
    ?string $trackingId = null
): array {
    return $provider === 'zoho'
        ? sendViaZoho($to, $toName, $subject, $body, $plainText, $attachments, $trackingId)
        : sendViaBrevo($to, $toName, $subject, $body, $plainText, $attachments, $trackingId);
}

/**
 * Send an email through the selected provider.
 *
 * Existing callers can continue calling sendMail(...) unchanged. New callers
 * may pass provider: 'system'|'zoho'|'brevo' as the final argument.
 *
 * Automatic fallback is intentionally used only for "system" sends. A user
 * who explicitly picks Zoho or Brevo gets exactly the provider selected.
 *
 * @return array Safe delivery-submission metadata for logging/tracking.
 */
function sendMail(
    string $to,
    string $toName,
    string $subject,
    string $body,
    ?string $plainText = null,
    array $attachments = [],
    string $provider = 'system'
): array {
    $to = strtolower(trim($to));
    $toName = trim($toName);

    if (!isDeliverableEmail($to)) {
        error_log('Mailer skipped: recipient email address is not deliverable.');
        throw new RuntimeException('Email could not be sent at this time.');
    }

    try {
        $requestedProvider = normalizeMailProvider($provider);
    } catch (Throwable $e) {
        throw new RuntimeException('Email service is not configured correctly.');
    }

    $tracking = beginMailDispatch([
        'recipient_email' => $to,
        'recipient_name' => $toName,
        'subject' => $subject,
        'requested_provider' => $requestedProvider,
        'has_attachment' => $attachments !== [],
    ]);

    try {
        $plan = mailProviderPlan($requestedProvider);
    } catch (Throwable $e) {
        $details = $e instanceof MailProviderException ? $e->rawDetails() : $e->getMessage();
        $safeMessage = $e instanceof MailProviderException ? $e->safeMessage() : 'Mail provider configuration is invalid.';
        $code = $e instanceof MailProviderException ? $e->diagnosticCode() : 'configuration_error';
        markMailDispatchFailed($tracking['id'], [], $code, $safeMessage, null, $to);
        error_log('Mail provider configuration error: ' . $details);
        throw new RuntimeException('Email service is not configured correctly.');
    }

    $attempts = [];
    $lastError = null;

    foreach ($plan as $index => $providerName) {
        try {
            $result = sendMailViaProvider(
                $providerName,
                $to,
                $toName,
                $subject,
                $body,
                $plainText,
                $attachments,
                $tracking['tracking_id']
            );

            $attempts[] = [
                'provider' => $providerName,
                'success' => true,
                'code' => $result['code'] ?? 'accepted',
            ];

            markMailDispatchSubmitted(
                $tracking['id'],
                $providerName,
                $result['message_id'] ?? null,
                $index > 0,
                $attempts,
                $to
            );

            $result['requested_provider'] = $requestedProvider;
            $result['fallback_used'] = $index > 0;
            $result['attempts'] = $attempts;
            $result['tracking_id'] = $tracking['tracking_id'];
            $result['dispatch_id'] = $tracking['id'];

            return $result;
        } catch (Throwable $e) {
            $providerError = $e instanceof MailProviderException
                ? $e
                : new MailProviderException(
                    $providerName,
                    'provider_failed',
                    ucfirst($providerName) . ' could not complete the email request.',
                    true,
                    $e->getMessage()
                );

            $lastError = $providerError;
            $attempts[] = [
                'provider' => $providerName,
                'success' => false,
                'code' => $providerError->diagnosticCode(),
            ];

            recordMailDeliveryEvent(
                $tracking['id'],
                $providerName,
                'attempt_failed',
                'failed_attempt',
                null,
                $to,
                $providerError->safeMessage(),
                date('Y-m-d H:i:s')
            );

            error_log(
                'Mailer Error via ' . $providerName
                . ' to ' . $to
                . ' [' . $providerError->diagnosticCode() . ']: '
                . $providerError->rawDetails()
            );

            $hasFallback = isset($plan[$index + 1]);
            if (!$hasFallback || !$providerError->retryable()) {
                break;
            }
        }
    }

    markMailDispatchFailed(
        $tracking['id'],
        $attempts,
        $lastError?->diagnosticCode() ?? 'mail_failed',
        $lastError?->safeMessage() ?? 'The email could not be submitted.',
        $lastError?->provider(),
        $to
    );

    // Keep provider/server internals out of ordinary API responses.
    throw new RuntimeException('Email could not be sent at this time.');
}

/**
 * Provider-aware diagnostic send. Safe details are returned to Super Admin;
 * raw provider responses continue to go only to the server log.
 */
function sendDiagnosticMail(
    string $to,
    string $toName,
    string $subject,
    string $body,
    ?string $plainText = null,
    array $attachments = [],
    string $provider = 'system'
): array {
    $startedAt = microtime(true);
    $to = strtolower(trim($to));
    $toName = trim($toName);

    if (!isDeliverableEmail($to)) {
        return [
            'success' => false,
            'provider' => null,
            'code' => 'invalid_recipient',
            'message' => 'The recipient email address is invalid.',
            'duration_ms' => 0,
            'fallback_used' => false,
            'attempts' => [],
        ];
    }

    try {
        $requestedProvider = normalizeMailProvider($provider);
    } catch (Throwable $e) {
        return [
            'success' => false,
            'provider' => null,
            'code' => 'unsupported_provider',
            'message' => 'The selected mail provider is invalid.',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'fallback_used' => false,
            'attempts' => [],
        ];
    }

    $tracking = beginMailDispatch([
        'recipient_email' => $to,
        'recipient_name' => $toName,
        'subject' => $subject,
        'requested_provider' => $requestedProvider,
        'has_attachment' => $attachments !== [],
    ]);

    try {
        $plan = mailProviderPlan($requestedProvider);
    } catch (Throwable $e) {
        $safeMessage = $e instanceof MailProviderException
            ? $e->safeMessage()
            : 'Mail provider configuration is invalid.';
        $code = $e instanceof MailProviderException ? $e->diagnosticCode() : 'configuration_error';
        markMailDispatchFailed($tracking['id'], [], $code, $safeMessage, null, $to);

        return [
            'success' => false,
            'provider' => null,
            'code' => $code,
            'message' => $safeMessage,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'fallback_used' => false,
            'attempts' => [],
            'tracking_id' => $tracking['tracking_id'],
            'dispatch_id' => $tracking['id'],
        ];
    }

    $attempts = [];
    $lastError = null;

    foreach ($plan as $index => $providerName) {
        try {
            $result = sendMailViaProvider(
                $providerName,
                $to,
                $toName,
                $subject,
                $body,
                $plainText,
                $attachments,
                $tracking['tracking_id']
            );

            $attempts[] = [
                'provider' => $providerName,
                'success' => true,
                'code' => $result['code'] ?? 'accepted',
            ];

            markMailDispatchSubmitted(
                $tracking['id'],
                $providerName,
                $result['message_id'] ?? null,
                $index > 0,
                $attempts,
                $to
            );

            return [
                ...$result,
                'requested_provider' => $requestedProvider,
                'fallback_used' => $index > 0,
                'attempts' => $attempts,
                'tracking_id' => $tracking['tracking_id'],
                'dispatch_id' => $tracking['id'],
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (Throwable $e) {
            $providerError = $e instanceof MailProviderException
                ? $e
                : new MailProviderException(
                    $providerName,
                    'provider_failed',
                    ucfirst($providerName) . ' could not complete the email request.',
                    true,
                    $e->getMessage()
                );

            $lastError = $providerError;
            $attempts[] = [
                'provider' => $providerName,
                'success' => false,
                'code' => $providerError->diagnosticCode(),
            ];

            recordMailDeliveryEvent(
                $tracking['id'],
                $providerName,
                'attempt_failed',
                'failed_attempt',
                null,
                $to,
                $providerError->safeMessage(),
                date('Y-m-d H:i:s')
            );

            error_log(
                'Mail diagnostic send failed via ' . $providerName
                . ' to ' . $to
                . ' [' . $providerError->diagnosticCode() . ']: '
                . $providerError->rawDetails()
            );

            $hasFallback = isset($plan[$index + 1]);
            if (!$hasFallback || !$providerError->retryable()) {
                break;
            }
        }
    }

    $failureCode = $lastError?->diagnosticCode() ?? 'mail_failed';
    $failureMessage = $lastError?->safeMessage() ?? 'The email could not be submitted.';
    markMailDispatchFailed(
        $tracking['id'],
        $attempts,
        $failureCode,
        $failureMessage,
        $lastError?->provider(),
        $to
    );

    return [
        'success' => false,
        'provider' => $lastError?->provider(),
        'code' => $failureCode,
        'message' => $failureMessage,
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'fallback_used' => count($attempts) > 1,
        'attempts' => $attempts,
        'tracking_id' => $tracking['tracking_id'],
        'dispatch_id' => $tracking['id'],
    ];
}

