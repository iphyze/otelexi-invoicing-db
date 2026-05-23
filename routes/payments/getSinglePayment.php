<?php
// routes/payments/getSinglePayment.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../utils/receipt.php';

/**
 * GET /payments/{id}
 * Fetch a single payment with full invoice and client context.
 * Sales can only view payments on their own invoices.
 * Roles allowed: Admin, Accounting, Sales
 *
 * Query param: ?id=12
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData         = authenticateUser();
    $loggedInUserId   = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];

    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'accounting', 'sales'])) {
        throw new Exception("Unauthorized: You do not have permission to view payments.", 403);
    }

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Payment ID is required.", 400);
    }
    $paymentId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 1. Fetch payment with context
    // -------------------------------------------------------
    $stmt = $conn->prepare("
        SELECT
            p.id, p.invoice_id, p.recorded_by,
            p.amount, p.payment_date, p.payment_method,
            p.reference, p.notes, p.created_at,
            u.name AS recorded_by_name,
            i.invoice_number, i.issue_date, i.due_date,
            i.total_amount AS invoice_total,
            i.amount_paid  AS invoice_amount_paid,
            i.balance_due  AS invoice_balance_due,
            i.currency, i.status AS invoice_status,
            i.payment_terms, i.created_by AS invoice_created_by,
            c.id AS client_id, c.company_name AS client_name,
            c.email AS client_email, c.phone AS client_phone,
            c.billing_address AS client_address,
            r.id AS receipt_id
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        JOIN clients  c ON c.id = i.client_id
        LEFT JOIN users u ON u.id = p.recorded_by
        LEFT JOIN payment_receipts r ON r.payment_id = p.id
        WHERE p.id = ?
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Database query failed: " . $conn->error, 500);

    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) throw new Exception("Payment not found.", 404);

    $row = $result->fetch_assoc();
    $stmt->close();

    // Sales can only view payments on their own invoices
    if ($loggedInUserRole === 'sales' && (int)$row['invoice_created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You do not have permission to view this payment.", 403);
    }

    $receipt = $row['receipt_id'] ? fetchReceiptById($conn, (int) $row['receipt_id']) : null;

    // -------------------------------------------------------
    // 2. Return response
    // -------------------------------------------------------
    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Payment fetched successfully.",
        "data"    => [
            "id"             => (int)$row['id'],
            "amount"         => (float)$row['amount'],
            "payment_date"   => $row['payment_date'],
            "payment_method" => $row['payment_method'],
            "reference"      => $row['reference'],
            "notes"          => $row['notes'],
            "recorded_by"    => [
                "id"   => (int)$row['recorded_by'],
                "name" => $row['recorded_by_name']
            ],
            "created_at"     => $row['created_at'],
            "receipt"        => $receipt ? receiptResponseData($receipt) : null,
            "invoice"        => [
                "id"             => (int)$row['invoice_id'],
                "invoice_number" => $row['invoice_number'],
                "issue_date"     => $row['issue_date'],
                "due_date"       => $row['due_date'],
                "payment_terms"  => $row['payment_terms'],
                "currency"       => $row['currency'],
                "total_amount"   => (float)$row['invoice_total'],
                "amount_paid"    => (float)$row['invoice_amount_paid'],
                "balance_due"    => (float)$row['invoice_balance_due'],
                "status"         => $row['invoice_status']
            ],
            "client"         => [
                "id"           => (int)$row['client_id'],
                "company_name" => $row['client_name'],
                "email"        => $row['client_email'],
                "phone"        => $row['client_phone'],
                "address"      => $row['client_address']
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Single Payment Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
