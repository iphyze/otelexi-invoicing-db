<?php
// routes/settings/updateSettings.php
// Updates company branding, bank details and document footer settings.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can modify company settings.');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid or missing JSON payload.', 400);
    }

    $allowedFields = [
        'company_name', 'address', 'city', 'state', 'country',
        'phone', 'email', 'website', 'bank_name', 'account_name',
        'account_number', 'bank_branch', 'vat_number', 'legal_footer',
    ];
    $requiredIfProvided = ['company_name', 'address', 'city', 'state', 'phone', 'email'];
    $updateFields = [];
    $params = [];
    $types = '';

    foreach ($data as $key => $value) {
        if (!in_array($key, $allowedFields, true)) {
            continue;
        }
        $value = is_string($value) ? trim($value) : $value;
        if (in_array($key, $requiredIfProvided, true) && $value === '') {
            throw new Exception("The field '{$key}' cannot be empty.", 422);
        }
        if ($key === 'email' && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('The company email address is invalid.', 422);
        }
        $updateFields[] = "`{$key}` = ?";
        $params[] = $value;
        $types .= 's';
    }

    if (!$updateFields) {
        throw new Exception('No valid fields provided for update.', 400);
    }

    $stmt = $conn->prepare('UPDATE company_settings SET ' . implode(', ', $updateFields) . ' LIMIT 1');
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    $result = $conn->query('SELECT * FROM company_settings LIMIT 1');
    $updated = $result ? $result->fetch_assoc() : null;
    if (!$updated) {
        throw new Exception('Company settings could not be loaded after update.', 500);
    }
    unset($updated['id']);

    $action = 'settings.updated';
    $model = 'CompanySettings';
    $modelId = 1;
    $description = "{$user['email']} updated company document and branding settings.";
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $log = $conn->prepare('INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    $userId = (int) $user['id'];
    $log->bind_param('ississ', $userId, $action, $model, $modelId, $description, $ip);
    $log->execute();
    $log->close();

    echo json_encode(['status' => 'success', 'message' => 'Company settings updated successfully.', 'data' => $updated]);
} catch (Throwable $e) {
    error_log('Update Settings Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Company settings could not be updated right now.' : $e->getMessage(),
    ]);
}
