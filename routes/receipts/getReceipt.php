<?php
// routes/receipts/getReceipt.php
// GET /receipts/{id}
// Returns an immutable payment receipt snapshot for PDF preview/download/email.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/receipt.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    $userId = (int) $user['id'];
    $role = (string) $user['role'];

    if (!in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING, ROLE_SALES], true)) {
        throw new Exception('Unauthorized: You cannot view receipts.', 403);
    }

    if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('A valid Receipt ID is required.', 400);
    }

    $receipt = fetchReceiptById($conn, (int) $_GET['id']);
    if (!$receipt) {
        throw new Exception('Receipt not found.', 404);
    }

    if ($role === 'sales') {
        $invoiceStatement = $conn->prepare('SELECT created_by FROM invoices WHERE id = ? LIMIT 1');
        $invoiceId = (int) $receipt['invoice_id'];
        $invoiceStatement->bind_param('i', $invoiceId);
        $invoiceStatement->execute();
        $invoice = $invoiceStatement->get_result()->fetch_assoc();
        $invoiceStatement->close();

        if (!$invoice || (int) $invoice['created_by'] !== $userId) {
            throw new Exception('Unauthorized: You cannot view this receipt.', 403);
        }
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Receipt fetched successfully.',
        'data' => receiptResponseData($receipt),
    ]);
} catch (Throwable $error) {
    error_log('Get Receipt Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    $clientError = in_array($code, [400, 403, 404, 405], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $error->getMessage() : 'Receipt could not be loaded right now.',
    ]);
}
