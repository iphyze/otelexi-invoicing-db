<?php
// routes/mail/getMailProviderOptions.php
// Safe mail-provider options for authenticated send-email controls.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../utils/mailer.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    authenticateUser();

    echo json_encode([
        'status' => 'success',
        'message' => 'Mail provider options loaded successfully.',
        'data' => mailProviderSelectionSummary(),
    ]);
} catch (Throwable $e) {
    error_log('Get Mail Provider Options Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $clientError = in_array($code, [400, 401, 403, 405, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $e->getMessage() : 'Mail provider options could not be loaded right now.',
    ]);
}
