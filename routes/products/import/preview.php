<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../utils/productImport.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    if (!in_array($user['role'] ?? '', ['super_admin', 'admin'], true)) {
        throw new RuntimeException('You are not authorized to import products.', 403);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid or missing import payload.', 400);
    }

    $preview = productImportBuildPreview($conn, $payload);

    echo json_encode([
        'status' => 'success',
        'message' => $preview['can_import']
            ? 'Product import preview is valid and ready to import.'
            : 'Product import preview contains items that need attention.',
        'data' => $preview,
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) $code = 500;
    error_log('Product Import Preview Error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code >= 500 ? 'Unable to validate the product import.' : $e->getMessage(),
    ]);
}
