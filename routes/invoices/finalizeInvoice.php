<?php
// routes/invoices/finalizeInvoice.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../cron/notificationHelper.php';

/**
 * POST /invoices/{id}/finalize
 * Finalize a draft invoice:
 *   1. Validates stock availability for each product-linked item.
 *   2. Deducts stock from products (only items with a product_id).
 *   3. Locks the invoice (immutable after this point).
 *   4. Sets status to 'sent'.
 *   5. Records approved_by.
 *   6. Schedules next_reminder_at per payment_terms.
 *
 * Per client spec: stock is only deducted on final invoice issuance.
 * Roles allowed: Admin only
 *
 * Query param: ?id=7
 * No request body needed.
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

    // Admin only — stock deduction is a critical operation
    if ($loggedInUserRole !== 'admin') {
        throw new Exception("Unauthorized: Only Admins can finalize invoices.", 403);
    }

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Invoice ID is required.", 400);
    }
    $invoiceId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 1. Verify invoice exists and is draft
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

    if ($invoice['status'] !== 'draft') {
        throw new Exception("Only draft invoices can be finalized. Current status: {$invoice['status']}.", 409);
    }

    if ((int)$invoice['stock_deducted'] === 1) {
        throw new Exception("Stock has already been deducted for this invoice.", 409);
    }

    // -------------------------------------------------------
    // 2. Fetch all line items (product-linked ones need stock check)
    // -------------------------------------------------------
    $itemsStmt = $conn->prepare("
        SELECT ii.id, ii.product_id, ii.description, ii.quantity,
               p.name AS product_name, p.stock_quantity
        FROM invoice_items ii
        LEFT JOIN products p ON p.id = ii.product_id
        WHERE ii.invoice_id = ?
        ORDER BY ii.sort_order ASC, ii.id ASC
    ");
    $itemsStmt->bind_param("i", $invoiceId);
    $itemsStmt->execute();
    $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();

    if (empty($items)) throw new Exception("Invoice has no line items. Cannot finalize.", 400);

    // -------------------------------------------------------
    // 3. Pre-flight stock availability check (before any writes)
    // -------------------------------------------------------
    $stockDeductions = []; // [product_id => quantity_to_deduct]
    $stockErrors     = [];

    foreach ($items as $item) {
        if (!$item['product_id']) continue;           // Custom/non-product line — skip

        $productId      = (int)$item['product_id'];
        $qtyRequired    = (float)$item['quantity'];
        $stockAvailable = (float)$item['stock_quantity'];

        // Accumulate required qty per product (handles duplicate products on same invoice)
        $stockDeductions[$productId] = ($stockDeductions[$productId] ?? 0) + $qtyRequired;

        if ($stockAvailable < $stockDeductions[$productId]) {
            $stockErrors[] = "'{$item['product_name']}': requires {$stockDeductions[$productId]}, available {$stockAvailable}.";
        }
    }

    if (!empty($stockErrors)) {
        http_response_code(409);
        echo json_encode([
            "status"  => "failed",
            "message" => "Insufficient stock. Please adjust quantities before finalizing.",
            "errors"  => $stockErrors
        ]);
        exit;
    }

    // -------------------------------------------------------
    // 4. Transaction: deduct stock, update invoice, log
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        // Deduct stock for each product
        foreach ($stockDeductions as $productId => $qtyToDeduct) {
            $deductStmt = $conn->prepare("
                UPDATE products
                SET stock_quantity = stock_quantity - ?
                WHERE id = ?
            ");
            $deductStmt->bind_param("di", $qtyToDeduct, $productId);
            if (!$deductStmt->execute()) {
                throw new Exception("Failed to deduct stock for product ID {$productId}: " . $deductStmt->error, 500);
            }

            // Per-stock low-stock notifications (fire for each product that hits reorder level)
            // Add this inside finalizeInvoice.php, inside the foreach ($stockDeductions ...) loop,
            // after the deduct stmt executes successfully:

            $newStock = $product['stock_quantity'] - $qtyToDeduct;  // from preflight data
            if ($newStock <= $product['reorder_level']) {
                createNotification($conn, [
                    'role'       => 'admin',
                    'type'       => 'stock.low',
                    'title'      => 'Low Stock Alert',
                    'message'    => "'{$product['product_name']}' stock is now {$newStock} units "
                                    . "(reorder level: {$product['reorder_level']}).",
                    'model_type' => 'Product',
                    'model_id'   => $productId
                ]);
            }


            $deductStmt->close();

            // Log per-product stock movement
            $stockLogStmt = $conn->prepare("
                INSERT INTO stock_movements (
                    product_id, movement_type, quantity, reference_type, reference_id, notes, created_by
                ) VALUES (?, 'out', ?, 'invoice', ?, ?, ?)
            ");
            $stockNote = "Deducted on invoice finalization: {$invoice['invoice_number']}";
            $stockLogStmt->bind_param("idiis", $productId, $qtyToDeduct, $invoiceId, $stockNote, $loggedInUserId);
            $stockLogStmt->execute();
            $stockLogStmt->close();
        }

        // Determine next reminder date based on payment terms
        $today       = date('Y-m-d');
        $nextReminder = ($invoice['payment_terms'] === 'due_on_receipt')
            ? date('Y-m-d', strtotime($today . ' + 1 day'))
            : date('Y-m-d', strtotime($invoice['due_date'] . ' + 1 day'));

        // Lock and send invoice
        $updateStmt = $conn->prepare("
            UPDATE invoices
            SET status          = 'sent',
                stock_deducted  = 1,
                approved_by     = ?,
                next_reminder_at = ?
            WHERE id = ? AND status = 'draft'
        ");
        $updateStmt->bind_param("isi", $loggedInUserId, $nextReminder, $invoiceId);
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to finalize invoice: " . $updateStmt->error, 500);
        }
        if ($updateStmt->affected_rows === 0) {
            throw new Exception("Invoice could not be finalized. It may have been modified by another user.", 409);
        }
        $updateStmt->close();

        // Activity log
        $logStmt     = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "invoice.finalized";
        $modelType   = "Invoice";
        $deductCount = count($stockDeductions);
        $description = "{$loggedInUserEmail} finalized invoice {$invoice['invoice_number']} for '{$invoice['client_name']}'. "
            . "Stock deducted for {$deductCount} product(s). "
            . "Total: {$invoice['currency']} {$invoice['total_amount']}. "
            . "Due: {$invoice['due_date']}.";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $invoiceId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();


        // ============================================================
        // 1. finalizeInvoice.php
        //    Add after: $conn->commit();
        // ============================================================
        
        // Notify the Sales staff member who created the invoice
        createNotification($conn, [
            'user_id'    => (int)$invoice['created_by'],
            'type'       => 'invoice.finalized',
            'title'      => 'Invoice Finalized',
            'message'    => "Invoice {$invoice['invoice_number']} for '{$invoice['client_name']}' "
                        . "has been finalized. Amount: {$invoice['currency']} {$invoice['total_amount']}. "
                        . "Due: {$invoice['due_date']}.",
            'model_type' => 'Invoice',
            'model_id'   => $invoiceId
        ]);

        // Notify all accountants so they know a new invoice is outstanding
        createNotification($conn, [
            'role'       => 'accountant',
            'type'       => 'invoice.finalized',
            'title'      => 'New Invoice Sent',
            'message'    => "Invoice {$invoice['invoice_number']} ({$invoice['currency']} {$invoice['total_amount']}) "
                        . "for '{$invoice['client_name']}' is now sent and awaiting payment.",
            'model_type' => 'Invoice',
            'model_id'   => $invoiceId
        ]);


        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Invoice finalized successfully. Stock has been deducted and invoice is now sent.",
            "data"    => [
                "id"                  => $invoiceId,
                "invoice_number"      => $invoice['invoice_number'],
                "client_name"         => $invoice['client_name'],
                "previous_status"     => "draft",
                "new_status"          => "sent",
                "stock_deducted"      => true,
                "products_deducted"   => $deductCount,
                "total_amount"        => (float)$invoice['total_amount'],
                "currency"            => $invoice['currency'],
                "due_date"            => $invoice['due_date'],
                "next_reminder_at"    => $nextReminder,
                "approved_by"         => $loggedInUserId
            ]
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Exception $e) {
    error_log("Finalize Invoice Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
