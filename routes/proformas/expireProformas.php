<?php
// routes/proformas/expireProformas.php
// Backward-compatible proforma-only expiry endpoint.
// New complete document checks use POST /automation/document-maintenance/run.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../utils/documentMaintenance.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();

    if (($user['role'] ?? '') !== 'super_admin') {
        throw new Exception('Only an administrator can run proforma expiration.', 403);
    }

    $expiredCount = expireSentProformas($conn, [
        'id' => (int) $user['id'],
        'label' => (string) $user['email'],
        'trigger' => 'manual',
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
    ]);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => "{$expiredCount} proforma invoice(s) marked as expired.",
        'data' => [
            'expired_count' => $expiredCount,
            'run_date' => date('Y-m-d'),
        ],
    ]);
} catch (Throwable $error) {
    error_log('Expire Proformas Error: ' . $error->getMessage());

    $code = (int) $error->getCode();
    $isClientError = in_array($code, [400, 403, 405], true);

    http_response_code($isClientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $isClientError
            ? $error->getMessage()
            : 'Proforma expiration could not be completed right now.',
    ]);
}
