<?php
// routes/admin/updateMailSettings.php
// Saves provider routing preferences. Provider credentials remain in .env.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/mailer.php';

header('Content-Type: application/json; charset=utf-8');

function requestBoolean(array $data, string $key): bool
{
    if (!array_key_exists($key, $data)) {
        throw new Exception("The field '{$key}' is required.", 422);
    }

    $value = $data[$key];
    if (is_bool($value)) {
        return $value;
    }
    if ($value === 1 || $value === 0 || $value === '1' || $value === '0') {
        return (bool) $value;
    }

    throw new Exception("The field '{$key}' must be true or false.", 422);
}

function providerIsConfigured(string $provider): bool
{
    try {
        if ($provider === 'zoho') {
            zohoMailConfig();
        } else {
            brevoMailConfig();
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can modify mail settings.');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid or missing JSON payload.', 400);
    }

    $defaultProvider = normalizeMailProvider((string) ($data['default_provider'] ?? ''), false);
    $fallbackProvider = normalizeMailProvider((string) ($data['fallback_provider'] ?? ''), false);
    $zohoEnabled = requestBoolean($data, 'zoho_enabled');
    $brevoEnabled = requestBoolean($data, 'brevo_enabled');
    $fallbackEnabled = requestBoolean($data, 'fallback_enabled');

    $enabled = [
        'zoho' => $zohoEnabled,
        'brevo' => $brevoEnabled,
    ];

    if (!$zohoEnabled && !$brevoEnabled) {
        throw new Exception('At least one mail provider must remain enabled.', 422);
    }

    foreach ($enabled as $provider => $isEnabled) {
        if ($isEnabled && !providerIsConfigured($provider)) {
            throw new Exception(ucfirst($provider) . ' cannot be enabled until its server credentials are configured.', 422);
        }
    }

    if (!$enabled[$defaultProvider]) {
        throw new Exception('The default mail provider must be enabled.', 422);
    }

    if ($fallbackEnabled) {
        if ($fallbackProvider === $defaultProvider) {
            throw new Exception('The fallback provider must be different from the default provider.', 422);
        }
        if (!$enabled[$fallbackProvider]) {
            throw new Exception('The fallback provider must be enabled.', 422);
        }
        if (!providerIsConfigured($fallbackProvider)) {
            throw new Exception('The fallback provider is not configured.', 422);
        }
    }

    $saved = saveMailRoutingSettings($conn, [
        'default_provider' => $defaultProvider,
        'zoho_enabled' => $zohoEnabled,
        'brevo_enabled' => $brevoEnabled,
        'fallback_enabled' => $fallbackEnabled,
        'fallback_provider' => $fallbackProvider,
    ], (int) $user['id']);

    try {
        $action = 'mail.settings_updated';
        $model = 'MailSettings';
        $description = sprintf(
            '%s updated mail routing settings (default: %s, fallback: %s).',
            $user['email'],
            $defaultProvider,
            $fallbackEnabled ? $fallbackProvider : 'disabled'
        );
        $properties = json_encode([
            'default_provider' => $defaultProvider,
            'zoho_enabled' => $zohoEnabled,
            'brevo_enabled' => $brevoEnabled,
            'fallback_enabled' => $fallbackEnabled,
            'fallback_provider' => $fallbackProvider,
        ], JSON_UNESCAPED_SLASHES);
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
        $userId = (int) $user['id'];

        $stmt = $conn->prepare(
            'INSERT INTO activity_log (user_id, action, model_type, description, properties, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssss', $userId, $action, $model, $description, $properties, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $auditError) {
        error_log('Mail Settings Audit Error: ' . $auditError->getMessage());
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Mail settings updated successfully.',
        'data' => mailProvidersSummary(),
    ]);
} catch (Throwable $e) {
    error_log('Update Mail Settings Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Mail settings could not be updated right now.' : $e->getMessage(),
    ]);
}
