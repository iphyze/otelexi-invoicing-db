<?php
// routes/products/previewProductImport.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../utils/productImport.php';

/**
 * POST /products/import/preview
 * Validate product rows parsed from an Excel workbook without saving them.
 * Roles allowed: Super Admin, Admin
 */

header('Content-Type: application/json');

date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    if (!in_array($userData['role'], ['super_admin', 'admin'], true)) {
        throw new Exception('Unauthorized: Only Super Admins or Admins can import products.', 403);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid or missing JSON payload.', 400);
    }

    $rows = $data['rows'] ?? null;
    if (!is_array($rows)) {
        throw new Exception("The field 'rows' must be an array.", 422);
    }

    $categories = $data['categories'] ?? [];
    if (!is_array($categories)) {
        throw new Exception("The field 'categories' must be an array.", 422);
    }

    $preview = validateProductImportRows($conn, $rows, $categories);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => $preview['summary']['can_import']
            ? ($preview['summary']['new_categories'] > 0
                ? "All product rows are valid. {$preview['summary']['new_categories']} new categor" . ($preview['summary']['new_categories'] === 1 ? 'y will' : 'ies will') . ' be created when you import.'
                : 'All product rows are valid and ready to import.')
            : 'Review and correct the highlighted product or category rows before importing.',
        'data' => $preview,
    ]);
} catch (Throwable $e) {
    error_log('Preview Product Import Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $e->getMessage(),
    ]);
}
