<?php
// utils/paystackClient.php
// Server-side Paystack helper. Secret keys are read only from .env/server env.

// Safety notes:
// - The secret key must never be exposed to the frontend.
// - SSL verification stays enabled by default. For local XAMPP SSL/certificate issues,
//   set PAYSTACK_SSL_VERIFY=false only in local development, never in production.

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';

function paystackConfigurationError(string $message): RuntimeException
{
    return new RuntimeException($message, 422);
}

function paystackSecretKey(): string
{
    $secret = trim((string) (config('PAYSTACK_SECRET_KEY', '') ?? ''));

    if ($secret === '') {
        throw paystackConfigurationError(
            'Paystack is not configured yet. Add PAYSTACK_SECRET_KEY to the backend .env file, then try again.'
        );
    }

    if (!preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $secret)) {
        throw paystackConfigurationError(
            'PAYSTACK_SECRET_KEY is not valid. Use the Paystack secret key that starts with sk_test_ or sk_live_.'
        );
    }

    return $secret;
}

function paystackBaseUrl(): string
{
    $baseUrl = rtrim((string) (config('PAYSTACK_BASE_URL', 'https://api.paystack.co') ?? 'https://api.paystack.co'), '/');

    if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        throw paystackConfigurationError('PAYSTACK_BASE_URL is not a valid URL.');
    }

    return $baseUrl;
}

function paystackCallbackUrl(string $reference): ?string
{
    $configured = trim((string) (config('PAYSTACK_CALLBACK_URL', '') ?? ''));
    if ($configured === '') {
        return null;
    }

    if (!filter_var($configured, FILTER_VALIDATE_URL)) {
        throw paystackConfigurationError('PAYSTACK_CALLBACK_URL is not a valid URL.');
    }

    $separator = str_contains($configured, '?') ? '&' : '?';
    return $configured . $separator . 'reference=' . rawurlencode($reference);
}

function paystackAmountToSubunit(float $amount): int
{
    return (int) round($amount * 100);
}

function paystackSafePayload(?array $payload): array
{
    if ($payload === null) {
        return [];
    }

    $safe = $payload;
    unset($safe['authorization'], $safe['Authorization']);
    return $safe;
}

function paystackRequest(string $method, string $path, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'The PHP cURL extension is not enabled. Enable php_curl in XAMPP/cPanel before using Paystack checkout.',
            503
        );
    }

    $url = paystackBaseUrl() . '/' . ltrim($path, '/');
    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('Paystack connection could not be initialized.', 503);
    }

    $headers = [
        'Authorization: Bearer ' . paystackSecretKey(),
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $verifySsl = configBool('PAYSTACK_SSL_VERIFY', true);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);

    if ($payload !== null) {
        $encodedPayload = json_encode($payload);
        if ($encodedPayload === false) {
            curl_close($ch);
            throw new InvalidArgumentException('Paystack request payload could not be encoded.', 422);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedPayload);
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $curlError !== '') {
        error_log('Paystack cURL Error: ' . $curlError . ' | URL: ' . $url . ' | Payload: ' . json_encode(paystackSafePayload($payload)));

        if (stripos($curlError, 'SSL') !== false || stripos($curlError, 'certificate') !== false) {
            throw new RuntimeException(
                'Paystack SSL verification failed on this local server. Install/update your PHP CA certificate bundle, or set PAYSTACK_SSL_VERIFY=false only while testing locally.',
                503
            );
        }

        throw new RuntimeException('Paystack connection failed. Please check your internet/server connection and try again.', 503);
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        error_log('Paystack unreadable response. HTTP ' . $httpCode . ' | Body: ' . substr((string) $raw, 0, 800));
        throw new RuntimeException('Paystack returned an unreadable response.', 502);
    }

    if ($httpCode < 200 || $httpCode >= 300 || !($decoded['status'] ?? false)) {
        $message = is_string($decoded['message'] ?? null)
            ? trim((string) $decoded['message'])
            : 'Paystack request was not successful.';

        error_log('Paystack API Error. HTTP ' . $httpCode . ' | Message: ' . $message . ' | Response: ' . substr((string) $raw, 0, 1000));

        // Paystack can return HTTP 200 with status=false for validation/configuration issues.
        // Treat those as client-visible setup/request errors instead of gateway failures.
        $safeCode = (($httpCode >= 400 && $httpCode < 500) || ($httpCode >= 200 && $httpCode < 300)) ? 422 : 502;
        throw new RuntimeException('Paystack rejected the request: ' . $message, $safeCode);
    }

    return $decoded;
}

function initializePaystackTransaction(array $payload): array
{
    return paystackRequest('POST', '/transaction/initialize', $payload);
}

function verifyPaystackTransaction(string $reference): array
{
    $reference = trim($reference);
    if ($reference === '') {
        throw new InvalidArgumentException('A valid Paystack reference is required.', 422);
    }

    return paystackRequest('GET', '/transaction/verify/' . rawurlencode($reference));
}
