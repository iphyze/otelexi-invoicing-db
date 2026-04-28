<?php
/**
 * NOTIFICATION PATCHES
 * ============================================================
 * Add these blocks to the 4 existing routes listed below.
 * Each block goes AFTER $conn->commit(); and BEFORE the http_response_code() call.
 * Also add at the top of each file (after the authMiddleware require):
 *
 *   require_once __DIR__ . '/../../includes/notificationHelper.php';
 *
 * ============================================================
 */


// ============================================================
// 1. finalizeInvoice.php
//    Add after: $conn->commit();
// ============================================================
/*
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
*/


// ============================================================
// 2. recordPayment.php
//    Add after: $conn->commit();
// ============================================================
/*
// Notify the Sales person who owns the invoice
createNotification($conn, [
    'user_id'    => (int)$invoice['created_by'],   // $invoice fetched earlier in the route
    'type'       => 'payment.received',
    'title'      => 'Payment Received',
    'message'    => "{$invoice['currency']} {$amount} received on invoice {$invoice['invoice_number']} "
                  . "({$invoice['client_name']}) via {$paymentMethod}. "
                  . ($newStatus === 'paid' ? 'Invoice is now fully paid.' : "Balance: {$invoice['currency']} {$newBalanceDue}."),
    'model_type' => 'Payment',
    'model_id'   => $newPaymentId
]);

// Notify all admins when an invoice is fully paid
if ($newStatus === 'paid') {
    createNotification($conn, [
        'role'       => 'admin',
        'type'       => 'invoice.paid',
        'title'      => 'Invoice Fully Paid',
        'message'    => "Invoice {$invoice['invoice_number']} for '{$invoice['client_name']}' "
                      . "has been fully paid. Total: {$invoice['currency']} {$invoice['total_amount']}.",
        'model_type' => 'Invoice',
        'model_id'   => $invoiceId
    ]);
}
*/


// ============================================================
// 3. cancelInvoice.php
//    Add after: $conn->commit();
// ============================================================
/*
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
*/


// ============================================================
// 4. markOverdue.php
//    Add after: $conn->commit();
//    Only fires if invoices were actually marked overdue
// ============================================================
/*
if ($markedOverdueCount > 0) {
    // Notify all accountants of the overdue batch
    createNotification($conn, [
        'role'       => 'accountant',
        'type'       => 'invoice.overdue_batch',
        'title'      => "{$markedOverdueCount} Invoice(s) Now Overdue",
        'message'    => "{$markedOverdueCount} invoice(s) have passed their due date and are now marked overdue. "
                      . "{$remindedCount} reminder(s) are scheduled.",
        'model_type' => 'Invoice',
        'model_id'   => null
    ]);

    // Notify all admins too
    createNotification($conn, [
        'role'       => 'admin',
        'type'       => 'invoice.overdue_batch',
        'title'      => "{$markedOverdueCount} Invoice(s) Overdue",
        'message'    => "{$markedOverdueCount} invoice(s) marked overdue as of {$today}. "
                      . "Check invoice aging for details.",
        'model_type' => 'Invoice',
        'model_id'   => null
    ]);
}

// Per-stock low-stock notifications (fire for each product that hits reorder level)
// Add this inside finalizeInvoice.php, inside the foreach ($stockDeductions ...) loop,
// after the deduct stmt executes successfully:
//
//   $newStock = $product['stock_quantity'] - $qtyToDeduct;  // from preflight data
//   if ($newStock <= $product['reorder_level']) {
//       createNotification($conn, [
//           'role'       => 'admin',
//           'type'       => 'stock.low',
//           'title'      => 'Low Stock Alert',
//           'message'    => "'{$product['product_name']}' stock is now {$newStock} units "
//                         . "(reorder level: {$product['reorder_level']}).",
//           'model_type' => 'Product',
//           'model_id'   => $productId
//       ]);
//   }
*/
?>
