<?php
// routes/quotations/convertToInvoice.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /quotations/{id}/convert-invoice
 * Convert an accepted quotation directly to an invoice (skip proforma).
 * Items can be edited during conversion.
 * Sets quotation status to 'converted'.
 * Creates invoice in 'draft' status (Admin must finalize to deduct stock).
 * Roles allowed: Admin, Sales (own only)
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId   = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin and Sales can convert quotations to invoices
    if (!in_array($loggedInUserRole, ['admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can convert quotations to invoices.", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Quotation ID
    // -------------------------------------------------------
    $quotationId = null;

    $data = json_decode(file_get_contents("php://input"), true);
    $data = $data ?: [];

    if (!$quotationId && isset($_GET['id']) && is_numeric($_GET['id'])) {
        $quotationId = (int)$_GET['id'];
    }

    if (!$quotationId) {
        throw new Exception("A valid Quotation ID is required.", 400);
    }

    // -------------------------------------------------------
    // 2. Verify Quotation Exists & Is Accepted
    // -------------------------------------------------------
    $quotationCheck = $conn->prepare("
        SELECT q.*,
               c.company_name AS client_name,
               c.currency AS client_currency,
               c.payment_terms AS client_payment_terms
        FROM quotations q
        JOIN clients c ON c.id = q.client_id
        WHERE q.id = ?
        LIMIT 1
    ");
    $quotationCheck->bind_param("i", $quotationId);
    $quotationCheck->execute();
    $quotationResult = $quotationCheck->get_result();

    if ($quotationResult->num_rows === 0) {
        throw new Exception("Quotation not found.", 404);
    }

    $quotation = $quotationResult->fetch_assoc();
    $quotationCheck->close();

    if ($quotation['status'] !== 'accepted') {
        throw new Exception("Only accepted quotations can be converted. Current status: {$quotation['status']}.", 409);
    }

    if ($loggedInUserRole === 'sales' && (int)$quotation['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only convert your own quotations.", 403);
    }

    // -------------------------------------------------------
    // 3. Fetch Original Quotation Items
    // -------------------------------------------------------
    $originalItemsStmt = $conn->prepare("
        SELECT
            product_id, description, quantity, unit_price,
            tax_rate, tax_amount, discount_type, discount_value,
            discount_amount, line_total, sort_order
        FROM quotation_items
        WHERE quotation_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $originalItemsStmt->bind_param("i", $quotationId);
    $originalItemsStmt->execute();
    $originalItems = $originalItemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $originalItemsStmt->close();

    if (empty($originalItems)) {
        throw new Exception("Quotation has no line items. Cannot convert.", 400);
    }

    // -------------------------------------------------------
    // 4. Determine Items to Use
    // -------------------------------------------------------
    $hasItemsOverride = isset($data['items']) && is_array($data['items']) && count($data['items']) > 0;
    $finalItems = [];

    if ($hasItemsOverride) {
        foreach ($data['items'] as $index => $item) {
            if (!isset($item['description']) || empty(trim($item['description']))) {
                throw new Exception("Override Item " . ($index + 1) . ": 'description' is required.", 422);
            }

            if (!isset($item['quantity']) || !is_numeric($item['quantity']) || (float)$item['quantity'] <= 0) {
                throw new Exception("Override Item " . ($index + 1) . ": 'quantity' must be positive.", 422);
            }

            if (!isset($item['unit_price']) || !is_numeric($item['unit_price']) || (float)$item['unit_price'] < 0) {
                throw new Exception("Override Item " . ($index + 1) . ": 'unit_price' must be valid.", 422);
            }

            $productId   = isset($item['product_id']) && is_numeric($item['product_id']) ? (int)$item['product_id'] : null;
            $description = trim($item['description']);
            $quantity    = (float)$item['quantity'];
            $unitPrice   = (float)$item['unit_price'];

            $taxRate = 7.50;
            if ($productId) {
                $productCheck = $conn->prepare("SELECT tax_rate FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
                $productCheck->bind_param("i", $productId);
                $productCheck->execute();
                $productRow = $productCheck->get_result()->fetch_assoc();
                $productCheck->close();
                if ($productRow) {
                    $taxRate = (float)$productRow['tax_rate'];
                }
            } elseif (isset($item['tax_rate']) && is_numeric($item['tax_rate'])) {
                $taxRate = (float)$item['tax_rate'];
            }

            $itemDiscountType  = isset($item['discount_type']) ? strtolower(trim($item['discount_type'])) : 'none';
            $itemDiscountValue = isset($item['discount_value']) ? (float)$item['discount_value'] : 0.00;

            if (!in_array($itemDiscountType, ['fixed', 'none'])) {
                throw new Exception("Override Item " . ($index + 1) . ": Invalid discount_type.", 422);
            }
            if ($itemDiscountType === 'none') $itemDiscountValue = 0.00;
            if ($itemDiscountType === 'fixed' && $itemDiscountValue < 0) {
                throw new Exception("Override Item " . ($index + 1) . ": Discount cannot be negative.", 422);
            }

            $grossAmount        = $quantity * $unitPrice;
            $itemDiscountAmount = $itemDiscountValue;
            $netAmount          = $grossAmount - $itemDiscountAmount;

            if ($netAmount < 0) {
                throw new Exception("Override Item " . ($index + 1) . ": Discount exceeds line total.", 422);
            }

            $itemTaxAmount = round($netAmount * ($taxRate / 100), 2);
            $lineTotal     = round($netAmount, 2);

            $finalItems[] = [
                'product_id'      => $productId,
                'description'     => $description,
                'quantity'        => $quantity,
                'unit_price'      => $unitPrice,
                'tax_rate'        => $taxRate,
                'tax_amount'      => $itemTaxAmount,
                'discount_type'   => $itemDiscountType,
                'discount_value'  => $itemDiscountValue,
                'discount_amount' => round($itemDiscountAmount, 2),
                'line_total'      => $lineTotal,
                'sort_order'      => $index
            ];
        }
    } else {
        foreach ($originalItems as $index => $item) {
            $grossAmount   = (float)$item['quantity'] * (float)$item['unit_price'];
            $netAmount     = $grossAmount - (float)$item['discount_amount'];
            $itemTaxAmount = round($netAmount * ((float)$item['tax_rate'] / 100), 2);
            $lineTotal     = round($netAmount, 2);

            $finalItems[] = [
                'product_id'      => $item['product_id'] ? (int)$item['product_id'] : null,
                'description'     => $item['description'],
                'quantity'        => (float)$item['quantity'],
                'unit_price'      => (float)$item['unit_price'],
                'tax_rate'        => (float)$item['tax_rate'],
                'tax_amount'      => $itemTaxAmount,
                'discount_type'   => $item['discount_type'],
                'discount_value'  => (float)$item['discount_value'],
                'discount_amount' => (float)$item['discount_amount'],
                'line_total'      => $lineTotal,
                'sort_order'      => $index
            ];
        }
    }

    // -------------------------------------------------------
    // 5. Calculate Document Totals
    // -------------------------------------------------------
    $calculatedSubtotal  = 0.00;
    $calculatedTaxAmount = 0.00;

    foreach ($finalItems as $item) {
        $calculatedSubtotal  += $item['line_total'];
        $calculatedTaxAmount += $item['tax_amount'];
    }

    $discountType  = isset($data['discount_type']) ? strtolower(trim($data['discount_type'])) : $quotation['discount_type'];
    $discountValue = isset($data['discount_value']) ? (float)$data['discount_value'] : (float)$quotation['discount_value'];

    if (!in_array($discountType, ['percentage', 'none'])) {
        throw new Exception("Invalid discount_type.", 422);
    }
    if ($discountType === 'none') $discountValue = 0.00;
    if ($discountType === 'percentage' && ($discountValue < 0 || $discountValue > 100)) {
        throw new Exception("Percentage discount must be between 0 and 100.", 422);
    }

    $discountAmount = 0.00;
    if ($discountType === 'percentage' && $discountValue > 0) {
        $discountAmount = round($calculatedSubtotal * ($discountValue / 100), 2);
    }

    $taxableAmount = round($calculatedSubtotal - $discountAmount, 2);
    $totalAmount   = round($taxableAmount + $calculatedTaxAmount, 2);

    // Currency and exchange rate
    $currency     = $quotation['currency'];
    $exchangeRate = (float)$quotation['exchange_rate'];

    // Payment terms (from client or override)
    $paymentTerms = isset($data['payment_terms']) ? strtolower(trim($data['payment_terms'])) : $quotation['client_payment_terms'];
    if (!in_array($paymentTerms, ['due_on_receipt', 'net_7'])) {
        throw new Exception("Invalid payment_terms. Must be 'due_on_receipt' or 'net_7'.", 422);
    }

    // Due date based on payment terms
    $issueDate = isset($data['issue_date']) && DateTime::createFromFormat('Y-m-d', trim($data['issue_date']))
        ? trim($data['issue_date'])
        : date('Y-m-d');

    $dueDate = ($paymentTerms === 'due_on_receipt')
        ? $issueDate
        : date('Y-m-d', strtotime($issueDate . ' + 7 days'));

    // Notes
    $notes = isset($data['notes']) ? trim($data['notes']) : $quotation['notes'];

    // Snapshot legal footer from company settings
    $footerStmt = $conn->prepare("SELECT legal_footer FROM company_settings LIMIT 1");
    $footerStmt->execute();
    $footerRow  = $footerStmt->get_result()->fetch_assoc();
    $footerText = $footerRow ? $footerRow['legal_footer'] : 'Goods sold are not returnable unless defective.';
    $footerStmt->close();

    // Convenience variable for balance_due (= total at creation)
    $balanceDue = $totalAmount;

    // -------------------------------------------------------
    // 6. Generate Invoice Number & Create Record (Transaction)
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $currentYear = (int)date('Y');

        // Get next sequence for invoice
        $seqCheck = $conn->prepare("
            SELECT id, last_sequence
            FROM document_number_sequences
            WHERE doc_type = 'invoice' AND year = ?
            FOR UPDATE
        ");
        $seqCheck->bind_param("i", $currentYear);
        $seqCheck->execute();
        $seqResult = $seqCheck->get_result();

        if ($seqResult->num_rows > 0) {
            $seqRow       = $seqResult->fetch_assoc();
            $nextSequence = (int)$seqRow['last_sequence'] + 1;
            $seqId        = (int)$seqRow['id'];

            $seqUpdate = $conn->prepare("UPDATE document_number_sequences SET last_sequence = ? WHERE id = ?");
            $seqUpdate->bind_param("ii", $nextSequence, $seqId);
            $seqUpdate->execute();
            $seqUpdate->close();
        } else {
            $nextSequence = 1;
            $seqInsert = $conn->prepare("INSERT INTO document_number_sequences (doc_type, year, last_sequence) VALUES ('invoice', ?, ?)");
            $seqInsert->bind_param("ii", $currentYear, $nextSequence);
            $seqInsert->execute();
            $seqInsert->close();
        }
        $seqCheck->close();

        // Format: INV/2026/001
        $invoiceNumber = "INV/" . $currentYear . "/" . str_pad($nextSequence, 3, '0', STR_PAD_LEFT);

        // Insert invoice
        // Columns (19 ?): invoice_number, quotation_id, client_id, created_by,
        //   issue_date, due_date, currency, exchange_rate,
        //   subtotal, discount_type, discount_value, discount_amount,
        //   taxable_amount, tax_amount, total_amount,
        //   [amount_paid = 0 literal], balance_due, payment_terms, footer_text, notes
        //   [status = 'draft' literal], [stock_deducted = 0 literal]
        // Types: s i i i  s s s d  d s d d  d d d  d s s s
        //      = 19 chars → "siiisssddsddddddssss" — let's count carefully:
        // s(invoice_number) i(quotation_id) i(client_id) i(created_by)
        // s(issue_date) s(due_date) s(currency) d(exchange_rate)
        // d(subtotal) s(discount_type) d(discount_value) d(discount_amount)
        // d(taxable_amount) d(tax_amount) d(total_amount)
        // d(balance_due) s(payment_terms) s(footer_text) s(notes)
        // = s i i i s s s d d s d d d d d d s s s = 19 chars
        $insertQuery = "
            INSERT INTO invoices (
                invoice_number, quotation_id, client_id, created_by,
                issue_date, due_date, currency, exchange_rate,
                subtotal, discount_type, discount_value, discount_amount,
                taxable_amount, tax_amount, total_amount,
                amount_paid, balance_due, payment_terms, footer_text, notes,
                status, stock_deducted
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'draft', 0)
        ";

        $insertStmt = $conn->prepare($insertQuery);
        if (!$insertStmt) {
            throw new Exception("Failed to prepare invoice insert: " . $conn->error, 500);
        }

        $clientIdVar = (int)$quotation['client_id'];

        // 19 bound params (amount_paid, status, stock_deducted are literals in SQL)
        // s  i  i  i  s  s  s  d  d  s  d  d  d  d  d  d  s  s  s
        $insertStmt->bind_param(
            "siiisssddsddddddsss",
            $invoiceNumber,        // s  invoice_number
            $quotationId,          // i  quotation_id
            $clientIdVar,          // i  client_id
            $loggedInUserId,       // i  created_by
            $issueDate,            // s  issue_date
            $dueDate,              // s  due_date
            $currency,             // s  currency
            $exchangeRate,         // d  exchange_rate
            $calculatedSubtotal,   // d  subtotal
            $discountType,         // s  discount_type
            $discountValue,        // d  discount_value
            $discountAmount,       // d  discount_amount
            $taxableAmount,        // d  taxable_amount
            $calculatedTaxAmount,  // d  tax_amount
            $totalAmount,          // d  total_amount
            $balanceDue,           // d  balance_due
            $paymentTerms,         // s  payment_terms
            $footerText,           // s  footer_text
            $notes                 // s  notes
        );

        if (!$insertStmt->execute()) {
            throw new Exception("Failed to create invoice: " . $insertStmt->error, 500);
        }

        $newInvoiceId = $insertStmt->insert_id;
        $insertStmt->close();

        // -------------------------------------------------------
        // Insert invoice items
        // FIX: Extract each field to a local variable so bind_param
        //      can receive them by reference (null product_id is safe).
        // -------------------------------------------------------
        $itemInsertQuery = "
            INSERT INTO invoice_items (
                invoice_id, product_id, description, quantity, unit_price,
                tax_rate, tax_amount, discount_type, discount_value,
                discount_amount, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $itemStmt = $conn->prepare($itemInsertQuery);
        if (!$itemStmt) {
            throw new Exception("Failed to prepare item insert: " . $conn->error, 500);
        }

        foreach ($finalItems as $item) {
            // Assign to local variables — required for bind_param by-reference.
            $iProductId      = $item['product_id'];   // int|null
            $iDescription    = $item['description'];
            $iQuantity       = $item['quantity'];
            $iUnitPrice      = $item['unit_price'];
            $iTaxRate        = $item['tax_rate'];
            $iTaxAmount      = $item['tax_amount'];
            $iDiscountType   = $item['discount_type'];
            $iDiscountValue  = $item['discount_value'];
            $iDiscountAmount = $item['discount_amount'];
            $iLineTotal      = $item['line_total'];
            $iSortOrder      = (int)$item['sort_order'];

            // Types: i i s d d d d s d d d i
            $itemStmt->bind_param(
                "iisddddsdddi",
                $newInvoiceId,
                $iProductId,
                $iDescription,
                $iQuantity,
                $iUnitPrice,
                $iTaxRate,
                $iTaxAmount,
                $iDiscountType,
                $iDiscountValue,
                $iDiscountAmount,
                $iLineTotal,
                $iSortOrder
            );

            if (!$itemStmt->execute()) {
                throw new Exception("Failed to add line item: " . $itemStmt->error, 500);
            }
        }
        $itemStmt->close();

        // Mark quotation as converted
        $updateQuotationStmt = $conn->prepare("UPDATE quotations SET status = 'converted' WHERE id = ?");
        $updateQuotationStmt->bind_param("i", $quotationId);
        $updateQuotationStmt->execute();
        $updateQuotationStmt->close();

        // Log quotation conversion
        $logStmt   = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "quotation.converted_to_invoice";
        $modelType   = "Quotation";
        $itemCount   = count($finalItems);
        $itemsEdited = $hasItemsOverride ? " (items edited)" : "";
        $description = "{$loggedInUserEmail} converted quotation {$quotation['quotation_number']} to invoice {$invoiceNumber} for '{$quotation['client_name']}'. {$itemCount} item(s){$itemsEdited}. Total: {$currency} {$totalAmount}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $quotationId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        // Log invoice creation
        $logStmt2    = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action2      = "invoice.created_from_quotation";
        $modelType2   = "Invoice";
        $description2 = "Invoice {$invoiceNumber} created from quotation {$quotation['quotation_number']}. Status: draft (awaiting finalization).";
        $logStmt2->bind_param("ississ", $loggedInUserId, $action2, $modelType2, $newInvoiceId, $description2, $ipAddress);
        $logStmt2->execute();
        $logStmt2->close();

        $conn->commit();

        // -------------------------------------------------------
        // 7. Return Response
        // -------------------------------------------------------
        http_response_code(201);
        echo json_encode([
            "status"  => "success",
            "message" => "Quotation converted to invoice successfully. Invoice is in draft status and awaiting finalization.",
            "data"    => [
                "quotation" => [
                    "id"               => $quotationId,
                    "quotation_number" => $quotation['quotation_number'],
                    "previous_status"  => "accepted",
                    "new_status"       => "converted"
                ],
                "invoice" => [
                    "id"             => $newInvoiceId,
                    "invoice_number" => $invoiceNumber,
                    "client_id"      => (int)$quotation['client_id'],
                    "client_name"    => $quotation['client_name'],
                    "issue_date"     => $issueDate,
                    "due_date"       => $dueDate,
                    "payment_terms"  => $paymentTerms,
                    "currency"       => $currency,
                    "exchange_rate"  => $exchangeRate,
                    "subtotal"       => $calculatedSubtotal,
                    "discount_amount" => $discountAmount,
                    "taxable_amount" => $taxableAmount,
                    "tax_amount"     => $calculatedTaxAmount,
                    "total_amount"   => $totalAmount,
                    "amount_paid"    => 0.00,
                    "balance_due"    => $totalAmount,
                    "status"         => "draft",
                    "stock_deducted" => false,
                    "item_count"     => $itemCount,
                    "items_edited"   => $hasItemsOverride,
                    "next_step"      => "Admin must finalize this invoice to deduct stock and send to client."
                ]
            ]
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Exception $e) {
    error_log("Convert Quotation to Invoice Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
