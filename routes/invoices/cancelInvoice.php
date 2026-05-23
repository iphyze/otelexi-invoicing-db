<?php
// routes/invoices/cancelInvoice.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../cron/notificationHelper.php';

/**
 * POST /invoices/{id}/cancel
 * Cancel an invoice that is in 'sent', 'partial', or 'overdue' status.
 *   - If stock was deducted, it is restored back to each product.
 *   - If partial payments exist, they are NOT auto-refunded — a warning is returned.
 *   - Paid invoices cannot be cancelled.
 *   - Draft invoices should be deleted instead.
 * Roles allowed: Admin only
 *
 * Query param: ?id=7
 *
 * Sample payload (optional):
 * {
 *   "reason": "Client cancelled the order."
 * }
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    $userData          = authenticateUser();
    $loggedInUserId    = (int)$userData['id'];
    $loggedInUserRole  = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    if ($loggedInUserRole !== 'super_admin') {
        throw new Exception("Unauthorized: Only the Super Admin can cancel invoices.", 403);
    }

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Invoice ID is required.", 400);
    }
    $invoiceId = (int)$_GET['id'];

    $body   = json_decode(file_get_contents("php://input"), true);
    $reason = isset($body['reason']) && !empty(trim($body['reason'])) ? trim($body['reason']) : null;

    // -------------------------------------------------------
    // 1. Verify invoice and current status
    // -------------------------------------------------------
    $checkStmt = $conn->prepare("
        SELECT i.*, c.company_name AS client_name
        FROM invoices i
        JOIN clients c ON c.id = i.client_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $checkStmt->bind_param("i", $invoiceId);
    $checkStmt->execute();
    $invoice = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$invoice) throw new Exception("Invoice not found.", 404);

    $cancellableStatuses = ['sent', 'partial', 'overdue'];

    if ($invoice['status'] === 'draft') {
        throw new Exception("Draft invoices cannot be cancelled. Delete them instead.", 409);
    }
    if ($invoice['status'] === 'paid') {
        throw new Exception("Paid invoices cannot be cancelled.", 409);
    }
    if ($invoice['status'] === 'cancelled') {
        throw new Exception("Invoice is already cancelled.", 409);
    }
    if (!in_array($invoice['status'], $cancellableStatuses)) {
        throw new Exception("Invoice cannot be cancelled from its current status: {$invoice['status']}.", 409);
    }

    // -------------------------------------------------------
    // 2. Check for partial payments (warn but don't block)
    // -------------------------------------------------------
    $hasPartialPayments = (float)$invoice['amount_paid'] > 0;

    // -------------------------------------------------------
    // 3. Fetch product-linked items for stock restoration
    // -------------------------------------------------------
    $stockToRestore = [];

    if ((int)$invoice['stock_deducted'] === 1) {
        $itemsStmt = $conn->prepare("
            SELECT ii.product_id, ii.quantity,
                   p.name AS product_name
            FROM invoice_items ii
            JOIN products p ON p.id = ii.product_id
            WHERE ii.invoice_id = ? AND ii.product_id IS NOT NULL
        ");
        $itemsStmt->bind_param("i", $invoiceId);
        $itemsStmt->execute();
        $linkedItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();

        foreach ($linkedItems as $item) {
            $pid = (int)$item['product_id'];
            $stockToRestore[$pid] = ($stockToRestore[$pid] ?? 0) + (float)$item['quantity'];
        }
    }

    // -------------------------------------------------------
    // 4. Transaction: restore stock, cancel invoice, log
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        // Restore stock
        foreach ($stockToRestore as $productId => $qtyToRestore) {
            $restoreStmt = $conn->prepare("
                UPDATE products
                SET stock_quantity = stock_quantity + ?
                WHERE id = ?
            ");
            $restoreStmt->bind_param("di", $qtyToRestore, $productId);
            if (!$restoreStmt->execute()) {
                throw new Exception("Failed to restore stock for product ID {$productId}.", 500);
            }
            $restoreStmt->close();

            // Log stock movement (in = returned to stock)
            $stockLogStmt = $conn->prepare("
                INSERT INTO stock_movements (
                    product_id, movement_type, quantity, reference_type, reference_id, notes, created_by
                ) VALUES (?, 'in', ?, 'invoice', ?, ?, ?)
            ");
            $stockNote = "Stock restored on invoice cancellation: {$invoice['invoice_number']}";
            $stockLogStmt->bind_param("idiis", $productId, $qtyToRestore, $invoiceId, $stockNote, $loggedInUserId);
            $stockLogStmt->execute();
            $stockLogStmt->close();
        }

        // Cancel invoice
        $cancelStmt = $conn->prepare("
            UPDATE invoices
            SET status         = 'cancelled',
                stock_deducted = 0,
                next_reminder_at = NULL
            WHERE id = ?
        ");
        $cancelStmt->bind_param("i", $invoiceId);
        if (!$cancelStmt->execute()) {
            throw new Exception("Failed to cancel invoice: " . $cancelStmt->error, 500);
        }
        $cancelStmt->close();

        // Activity log
        $logStmt      = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action       = "invoice.cancelled";
        $modelType    = "Invoice";
        $reasonText   = $reason ? " Reason: {$reason}" : "";
        $restoredCount = count($stockToRestore);
        $stockText    = $restoredCount > 0
            ? " Stock restored for {$restoredCount} product(s)."
            : " No stock to restore.";
        $description  = "{$loggedInUserEmail} cancelled invoice {$invoice['invoice_number']} "
            . "for '{$invoice['client_name']}' (was {$invoice['status']}).{$stockText}{$reasonText}";
        $ipAddress    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $invoiceId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        // ============================================================
        // 3. cancelInvoice.php
        //    Add after: $conn->commit();
        // ============================================================
        
        // Notify the Sales person who created the invoice
        createNotification($conn, [
            'user_id'    => (int)$invoice['created_by'],
            'type'       => 'invoice.cancelled',
            'title'      => 'Invoice Cancelled',
            'message'    => "Invoice {$invoice['invoice_number']} for '{$invoice['client_name']}' "
                        . "has been cancelled." . ($reason ? " Reason: {$reason}" : ""),
            'model_type' => 'Invoice',
            'model_id'   => $invoiceId
        ]);
        

        $response = [
            "status"  => "success",
            "message" => "Invoice cancelled successfully.",
            "data"    => [
                "id"               => $invoiceId,
                "invoice_number"   => $invoice['invoice_number'],
                "client_name"      => $invoice['client_name'],
                "previous_status"  => $invoice['status'],
                "new_status"       => "cancelled",
                "stock_restored"   => $restoredCount > 0,
                "products_restored" => $restoredCount,
                "reason_recorded"  => $reason !== null
            ]
        ];

        if ($hasPartialPayments) {
            $response["warnings"] = [
                "This invoice had partial payments totalling {$invoice['currency']} {$invoice['amount_paid']}. "
                    . "These payments are NOT automatically refunded. Please process any refunds manually."
            ];
        }

        http_response_code(200);
        echo json_encode($response);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Exception $e) {
    error_log("Cancel Invoice Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
