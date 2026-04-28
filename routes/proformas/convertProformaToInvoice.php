<?php
// routes/proformas/convertProformaToInvoice.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /proforma/{id}/convert-invoice
 * Convert an APPROVED proforma invoice to a final invoice.
 * - Items can be edited during conversion.
 * - Sets proforma status to 'converted'.
 * - Creates invoice in 'draft' status (Admin finalizes to lock + deduct stock).
 * - Preserves full lineage: invoice.proforma_id + invoice.quotation_id (if proforma came from a quotation).
 * Roles allowed: Admin, Sales (own only)
 *
 * Query param: ?id=5
 *
 * Sample payload (all optional — omit to use proforma items as-is):
 * {
 *   "payment_terms": "net_7",
 *   "issue_date": "2026-04-25",
 *   "notes": "Please process payment within 7 days.",
 *   "discount_type": "percentage",
 *   "discount_value": 5,
 *   "items": [
 *     {
 *       "product_id": 2,
 *       "description": "Stainless Steel Pot Set",
 *       "quantity": 10,
 *       "unit_price": 15000,
 *       "discount_type": "fixed",
 *       "discount_value": 500
 *     },
 *     {
 *       "description": "Delivery Charges",
 *       "quantity": 1,
 *       "unit_price": 5000,
 *       "tax_rate": 0
 *     }
 *   ]
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

    if (!in_array($loggedInUserRole, ['admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can convert proforma invoices.", 403);
    }

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Proforma ID is required.", 400);
    }
    $proformaId = (int)$_GET['id'];

    $data = json_decode(file_get_contents("php://input"), true);
    $data = $data ?: [];

    // -------------------------------------------------------
    // 1. Verify proforma exists and is approved
    // -------------------------------------------------------
    $checkStmt = $conn->prepare("
        SELECT p.*,
               c.company_name AS client_name,
               c.payment_terms AS client_payment_terms,
               c.currency AS client_currency
        FROM proforma_invoices p
        JOIN clients c ON c.id = p.client_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $checkStmt->bind_param("i", $proformaId);
    $checkStmt->execute();
    $proforma = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$proforma) {
        throw new Exception("Proforma invoice not found.", 404);
    }
    if ($proforma['status'] !== 'approved') {
        throw new Exception("Only approved proforma invoices can be converted. Current status: {$proforma['status']}.", 409);
    }
    if ($loggedInUserRole === 'sales' && (int)$proforma['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only convert your own proforma invoices.", 403);
    }

    // -------------------------------------------------------
    // 2. Fetch original proforma items
    // -------------------------------------------------------
    $originalItemsStmt = $conn->prepare("
        SELECT product_id, description, quantity, unit_price,
               tax_rate, tax_amount, discount_type, discount_value,
               discount_amount, line_total, sort_order
        FROM proforma_items
        WHERE proforma_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $originalItemsStmt->bind_param("i", $proformaId);
    $originalItemsStmt->execute();
    $originalItems = $originalItemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $originalItemsStmt->close();

    if (empty($originalItems)) {
        throw new Exception("Proforma invoice has no line items. Cannot convert.", 400);
    }

    // -------------------------------------------------------
    // 3. Determine final items (override or original)
    // -------------------------------------------------------
    $hasItemsOverride = isset($data['items']) && is_array($data['items']) && count($data['items']) > 0;
    $finalItems       = [];

    if ($hasItemsOverride) {
        foreach ($data['items'] as $index => $item) {
            $n = $index + 1;

            if (!isset($item['description']) || empty(trim($item['description']))) {
                throw new Exception("Item {$n}: 'description' is required.", 422);
            }
            if (!isset($item['quantity']) || !is_numeric($item['quantity']) || (float)$item['quantity'] <= 0) {
                throw new Exception("Item {$n}: 'quantity' must be positive.", 422);
            }
            if (!isset($item['unit_price']) || !is_numeric($item['unit_price']) || (float)$item['unit_price'] < 0) {
                throw new Exception("Item {$n}: 'unit_price' must be valid.", 422);
            }

            $productId   = isset($item['product_id']) && is_numeric($item['product_id']) ? (int)$item['product_id'] : null;
            $description = trim($item['description']);
            $quantity    = (float)$item['quantity'];
            $unitPrice   = (float)$item['unit_price'];
            $taxRate     = 7.50;

            if ($productId) {
                $productCheck = $conn->prepare("SELECT tax_rate FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
                $productCheck->bind_param("i", $productId);
                $productCheck->execute();
                $productRow = $productCheck->get_result()->fetch_assoc();
                $productCheck->close();
                if ($productRow) $taxRate = (float)$productRow['tax_rate'];
            } elseif (isset($item['tax_rate']) && is_numeric($item['tax_rate'])) {
                $taxRate = (float)$item['tax_rate'];
            }

            $itemDiscountType  = isset($item['discount_type']) ? strtolower(trim($item['discount_type'])) : 'none';
            $itemDiscountValue = isset($item['discount_value']) ? (float)$item['discount_value'] : 0.00;

            if (!in_array($itemDiscountType, ['fixed', 'none'])) {
                throw new Exception("Item {$n}: Invalid discount_type.", 422);
            }
            if ($itemDiscountType === 'none') $itemDiscountValue = 0.00;
            if ($itemDiscountType === 'fixed' && $itemDiscountValue < 0) {
                throw new Exception("Item {$n}: Discount cannot be negative.", 422);
            }

            $grossAmount        = $quantity * $unitPrice;
            $itemDiscountAmount = $itemDiscountValue;
            $netAmount          = $grossAmount - $itemDiscountAmount;

            if ($netAmount < 0) throw new Exception("Item {$n}: Discount exceeds line total.", 422);

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
        // Use proforma items as-is, recalculate for safety
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
    // 4. Calculate document totals
    // -------------------------------------------------------
    $calculatedSubtotal  = array_sum(array_column($finalItems, 'line_total'));
    $calculatedTaxAmount = array_sum(array_column($finalItems, 'tax_amount'));

    $discountType  = isset($data['discount_type']) ? strtolower(trim($data['discount_type'])) : $proforma['discount_type'];
    $discountValue = isset($data['discount_value']) ? (float)$data['discount_value'] : (float)$proforma['discount_value'];

    if (!in_array($discountType, ['percentage', 'none'])) throw new Exception("Invalid discount_type.", 422);
    if ($discountType === 'none') $discountValue = 0.00;
    if ($discountType === 'percentage' && ($discountValue < 0 || $discountValue > 100)) {
        throw new Exception("Percentage discount must be between 0 and 100.", 422);
    }

    $discountAmount = ($discountType === 'percentage' && $discountValue > 0)
        ? round($calculatedSubtotal * ($discountValue / 100), 2)
        : 0.00;

    $taxableAmount = round($calculatedSubtotal - $discountAmount, 2);
    $totalAmount   = round($taxableAmount + $calculatedTaxAmount, 2);
    $balanceDue    = $totalAmount;

    // Payment terms (override > client default)
    $paymentTerms = isset($data['payment_terms']) ? strtolower(trim($data['payment_terms'])) : $proforma['client_payment_terms'];
    if (!in_array($paymentTerms, ['due_on_receipt', 'net_7'])) {
        throw new Exception("Invalid payment_terms. Must be 'due_on_receipt' or 'net_7'.", 422);
    }

    $issueDate = isset($data['issue_date']) && DateTime::createFromFormat('Y-m-d', trim($data['issue_date']))
        ? trim($data['issue_date'])
        : date('Y-m-d');

    $dueDate = ($paymentTerms === 'due_on_receipt')
        ? $issueDate
        : date('Y-m-d', strtotime($issueDate . ' + 7 days'));

    $notes    = isset($data['notes']) ? trim($data['notes']) : $proforma['notes'];
    $currency = $proforma['currency'];
    $exchangeRate = (float)$proforma['exchange_rate'];

    // Snapshot legal footer
    $footerStmt = $conn->prepare("SELECT legal_footer FROM company_settings LIMIT 1");
    $footerStmt->execute();
    $footerRow  = $footerStmt->get_result()->fetch_assoc();
    $footerText = $footerRow ? $footerRow['legal_footer'] : 'Goods sold are not returnable unless defective.';
    $footerStmt->close();

    // Preserve quotation lineage if this proforma came from a quotation
    $linkedQuotationId = $proforma['quotation_id'] ? (int)$proforma['quotation_id'] : null;

    // -------------------------------------------------------
    // 5. Transaction: generate invoice number, insert records
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $currentYear = (int)date('Y');

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
            $seqUpdate    = $conn->prepare("UPDATE document_number_sequences SET last_sequence = ? WHERE id = ?");
            $seqUpdate->bind_param("ii", $nextSequence, $seqId);
            $seqUpdate->execute();
            $seqUpdate->close();
        } else {
            $nextSequence = 1;
            $seqInsert    = $conn->prepare("INSERT INTO document_number_sequences (doc_type, year, last_sequence) VALUES ('invoice', ?, ?)");
            $seqInsert->bind_param("ii", $currentYear, $nextSequence);
            $seqInsert->execute();
            $seqInsert->close();
        }
        $seqCheck->close();

        $invoiceNumber = "INV/" . $currentYear . "/" . str_pad($nextSequence, 3, '0', STR_PAD_LEFT);

        $clientIdVar = (int)$proforma['client_id'];

        // Insert invoice
        // Columns (19 bound ?):
        //   invoice_number, proforma_id, quotation_id, client_id, created_by,
        //   issue_date, due_date, currency, exchange_rate,
        //   subtotal, discount_type, discount_value, discount_amount,
        //   taxable_amount, tax_amount, total_amount,
        //   balance_due, payment_terms, footer_text, notes
        //   (amount_paid=0, status='draft', stock_deducted=0 are literals)
        // Types: s i i i i  s s s d  d s d d  d d d  d s s s  = 20 chars
        $insertStmt = $conn->prepare("
            INSERT INTO invoices (
                invoice_number, proforma_id, quotation_id, client_id, created_by,
                issue_date, due_date, currency, exchange_rate,
                subtotal, discount_type, discount_value, discount_amount,
                taxable_amount, tax_amount, total_amount,
                amount_paid, balance_due, payment_terms, footer_text, notes,
                status, stock_deducted
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'draft', 0)
        ");
        if (!$insertStmt) throw new Exception("Failed to prepare invoice insert: " . $conn->error, 500);

        // 20 bound params: s i i i i s s s d d s d d d d d d s s s
        $insertStmt->bind_param("siiiisssddsddddddsss",
            $invoiceNumber,
            $proformaId,
            $linkedQuotationId,
            $clientIdVar,
            $loggedInUserId,
            $issueDate,
            $dueDate,
            $currency,
            $exchangeRate,
            $calculatedSubtotal,
            $discountType,
            $discountValue,
            $discountAmount,
            $taxableAmount,
            $calculatedTaxAmount,
            $totalAmount,
            $balanceDue,
            $paymentTerms,
            $footerText,
            $notes
        );

        if (!$insertStmt->execute()) {
            throw new Exception("Failed to create invoice: " . $insertStmt->error, 500);
        }
        $newInvoiceId = $insertStmt->insert_id;
        $insertStmt->close();

        // Insert invoice items
        $itemStmt = $conn->prepare("
            INSERT INTO invoice_items (
                invoice_id, product_id, description, quantity, unit_price,
                tax_rate, tax_amount, discount_type, discount_value,
                discount_amount, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$itemStmt) throw new Exception("Failed to prepare item insert: " . $conn->error, 500);

        foreach ($finalItems as $item) {
            $iProductId      = $item['product_id'];
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

            $itemStmt->bind_param("iisddddsdddi",
                $newInvoiceId, $iProductId, $iDescription, $iQuantity, $iUnitPrice,
                $iTaxRate, $iTaxAmount, $iDiscountType, $iDiscountValue,
                $iDiscountAmount, $iLineTotal, $iSortOrder
            );

            if (!$itemStmt->execute()) {
                throw new Exception("Failed to add line item: " . $itemStmt->error, 500);
            }
        }
        $itemStmt->close();

        // Mark proforma as converted
        $updateProformaStmt = $conn->prepare("UPDATE proforma_invoices SET status = 'converted' WHERE id = ?");
        $updateProformaStmt->bind_param("i", $proformaId);
        $updateProformaStmt->execute();
        $updateProformaStmt->close();

        // Log proforma conversion
        $logStmt     = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "proforma.converted_to_invoice";
        $modelType   = "ProformaInvoice";
        $itemCount   = count($finalItems);
        $itemsEdited = $hasItemsOverride ? " (items edited during conversion)" : "";
        $description = "{$loggedInUserEmail} converted proforma {$proforma['proforma_number']} to invoice {$invoiceNumber} for '{$proforma['client_name']}'. {$itemCount} item(s){$itemsEdited}. Total: {$currency} {$totalAmount}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $proformaId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        // Log invoice creation
        $logStmt2    = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action2      = "invoice.created_from_proforma";
        $modelType2   = "Invoice";
        $description2 = "Invoice {$invoiceNumber} created from proforma {$proforma['proforma_number']}. Status: draft (awaiting Admin finalization).";
        $logStmt2->bind_param("ississ", $loggedInUserId, $action2, $modelType2, $newInvoiceId, $description2, $ipAddress);
        $logStmt2->execute();
        $logStmt2->close();

        $conn->commit();

        // -------------------------------------------------------
        // 6. Return response
        // -------------------------------------------------------
        http_response_code(201);
        echo json_encode([
            "status"  => "success",
            "message" => "Proforma invoice converted to invoice successfully. Invoice is in draft and awaiting finalization.",
            "data"    => [
                "proforma" => [
                    "id"              => $proformaId,
                    "proforma_number" => $proforma['proforma_number'],
                    "previous_status" => "approved",
                    "new_status"      => "converted"
                ],
                "invoice" => [
                    "id"             => $newInvoiceId,
                    "invoice_number" => $invoiceNumber,
                    "client_id"      => $clientIdVar,
                    "client_name"    => $proforma['client_name'],
                    "proforma_id"    => $proformaId,
                    "quotation_id"   => $linkedQuotationId,
                    "issue_date"     => $issueDate,
                    "due_date"       => $dueDate,
                    "payment_terms"  => $paymentTerms,
                    "currency"       => $currency,
                    "exchange_rate"  => $exchangeRate,
                    "subtotal"       => $calculatedSubtotal,
                    "discount_amount"=> $discountAmount,
                    "taxable_amount" => $taxableAmount,
                    "tax_amount"     => $calculatedTaxAmount,
                    "total_amount"   => $totalAmount,
                    "amount_paid"    => 0.00,
                    "balance_due"    => $totalAmount,
                    "status"         => "draft",
                    "stock_deducted" => false,
                    "item_count"     => $itemCount,
                    "items_edited"   => $hasItemsOverride,
                    "next_step"      => "Admin must finalize this invoice to lock it, deduct stock, and mark it as sent."
                ]
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Convert Proforma to Invoice Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
