<?php
// routes/settings/getSettings.php
// Returns company document settings to authenticated users for screen/PDF rendering.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }
    $user = authenticateUser();
    requireRole($user, APP_ROLES, 'Authentication is required to view company document settings.');

    $result = $conn->query('SELECT * FROM company_settings LIMIT 1');
    $settings = $result ? $result->fetch_assoc() : null;
    if (!$settings) {
        throw new Exception('Company settings not found.', 404);
    }
    unset($settings['id']);
    echo json_encode(['status' => 'success', 'message' => 'Company settings retrieved successfully.', 'data' => $settings]);
} catch (Throwable $e) {
    error_log('Get Settings Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'failed', 'message' => $code === 500 ? 'Settings could not be loaded right now.' : $e->getMessage()]);
}
