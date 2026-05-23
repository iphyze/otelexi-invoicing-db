<?php
// routes/receipts/issueReceipt.php
// POST /payments/{id}/receipt
// Generates a receipt for an earlier payment that does not already have one.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/receipt.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    $userId = (int) $user['id'];
    $role = (string) $user['role'];
    $email = (string) $user['email'];

    if (!in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING], true)) {
        throw new Exception('Unauthorized: Only Admins or Accounting users can issue receipts.', 403);
    }

    if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('A valid Payment ID is required.', 400);
    }

    $paymentId = (int) $_GET['id'];
    $conn->begin_transaction();

    try {
        $issued = issuePaymentReceipt($conn, $paymentId, $userId);
        $receipt = $issued['receipt'];

        if ($issued['created']) {
            $activity = $conn->prepare(
                'INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $action = 'receipt.issued';
            $modelType = 'Receipt';
            $receiptId = (int) $receipt['id'];
            $description = "{$email} issued receipt {$receipt['receipt_number']} for payment on invoice {$receipt['invoice_number']}.";
            $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
            $activity->bind_param('ississ', $userId, $action, $modelType, $receiptId, $description, $ip);
            $activity->execute();
            $activity->close();
        }

        $conn->commit();
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }

    http_response_code($issued['created'] ? 201 : 200);
    echo json_encode([
        'status' => 'success',
        'message' => $issued['created']
            ? 'Receipt issued successfully.'
            : 'A receipt has already been issued for this payment.',
        'data' => [
            'receipt' => receiptResponseData($receipt),
            'created' => (bool) $issued['created'],
        ],
    ]);
} catch (Throwable $error) {
    error_log('Issue Receipt Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);
    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $error->getMessage() : 'Receipt could not be issued right now.',
    ]);
}
