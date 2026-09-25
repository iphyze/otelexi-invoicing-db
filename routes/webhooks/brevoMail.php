<?php
// routes/webhooks/brevoMail.php
// POST /webhooks/brevo/mail
// Receives Brevo transactional delivery events and updates Otelex mail tracking.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../utils/mail/mailTracking.php';

header('Content-Type: application/json; charset=utf-8');

function brevoWebhookPresentedToken(): string
{
    $customHeader = trim((string) ($_SERVER['HTTP_X_OTELEX_WEBHOOK_TOKEN'] ?? ''));
    if ($customHeader !== '') {
        return $customHeader;
    }

    $authorization = trim((string) (
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    ));

    if ($authorization === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $customHeader = trim((string) ($headers['X-Otelex-Webhook-Token'] ?? $headers['x-otelex-webhook-token'] ?? ''));
            if ($customHeader !== '') {
                return $customHeader;
            }
            $authorization = trim((string) ($headers['Authorization'] ?? $headers['authorization'] ?? ''));
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
        return trim($match[1]);
    }

    return '';
}

function isSequentialArray(array $value): bool
{
    if ($value === []) {
        return true;
    }

    return array_keys($value) === range(0, count($value) - 1);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $configuredToken = trim((string) (config('BREVO_WEBHOOK_TOKEN', '') ?? ''));
    if ($configuredToken === '') {
        error_log('Brevo webhook rejected because BREVO_WEBHOOK_TOKEN is not configured.');
        throw new Exception('Webhook is not configured.', 503);
    }

    $receivedToken = brevoWebhookPresentedToken();
    if ($receivedToken === '' || !hash_equals($configuredToken, $receivedToken)) {
        throw new Exception('Unauthorized', 401);
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode((string) $rawBody, true);

    if (!is_array($payload)) {
        throw new Exception('Invalid webhook payload.', 400);
    }

    $events = isSequentialArray($payload) ? $payload : [$payload];
    $processed = 0;
    $ignored = 0;

    foreach ($events as $event) {
        if (!is_array($event)) {
            $ignored++;
            continue;
        }

        if (applyBrevoDeliveryEvent($event)) {
            $processed++;
        } else {
            // Brevo webhooks can cover account-wide traffic. Events that do not
            // match an Otelex tracking id/message id are acknowledged and ignored.
            $ignored++;
        }
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'processed' => $processed,
        'ignored' => $ignored,
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 600) ? $code : 500;
    http_response_code($code);

    if ($code >= 500) {
        error_log('Brevo Mail Webhook Error: ' . $e->getMessage());
    }

    echo json_encode([
        'status' => 'failed',
        'message' => $code >= 500 ? 'Webhook could not be processed right now.' : $e->getMessage(),
    ]);
}
