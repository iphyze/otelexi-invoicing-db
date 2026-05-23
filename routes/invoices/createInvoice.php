<?php
// routes/invoices/createInvoice.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /invoices/create
 * Create a standalone invoice directly (without a quotation or proforma source).
 * Invoice starts in 'draft' status. Admin must finalize it to deduct stock.
 * Generates document number: INV/YYYY/NNN
 * Roles allowed: Admin, Sales
 *
 * Sample payload:
 * {
 *   "client_id": 3,
 *   "payment_terms": "net_7",
 *   "currency": "NGN",
 *   "exchange_rate": 1,
 *   "issue_date": "2026-04-25",
 *   "discount_type": "percentage",
 *   "discount_value": 5,
 *   "notes": "Please process within 7 days.",
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

    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can create invoices.", 403);
    }

    // -------------------------------------------------------
    // 1. Parse payload
    // -------------------------------------------------------
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    if (!isset($data['client_id']) || !is_numeric($data['client_id'])) {
        throw new Exception("A valid 'client_id' is required.", 422);
    }
    if (!isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
        throw new Exception("At least one item is required.", 422);
    }

    $clientId = (int)$data['client_id'];

    // -------------------------------------------------------
    // 2. Header-level fields
    // -------------------------------------------------------
    $issueDate = isset($data['issue_date']) && DateTime::createFromFormat('Y-m-d', trim($data['issue_date']))
        ? trim($data['issue_date'])
        : date('Y-m-d');

    $currency     = isset($data['currency']) ? strtoupper(trim($data['currency'])) : null;
    $exchangeRate = 1.0000;

    if (isset($data['exchange_rate']) && is_numeric($data['exchange_rate'])) {
        $exchangeRate = (float)$data['exchange_rate'];
        if ($exchangeRate <= 0) throw new Exception("Exchange rate must be greater than 0.", 422);
    }

    $discountType  = isset($data['discount_type']) ? strtolower(trim($data['discount_type'])) : 'none';
    $discountValue = isset($data['discount_value']) ? (float)$data['discount_value'] : 0.00;

    if (!in_array($discountType, ['percentage', 'none'])) {
        throw new Exception("Invalid discount_type. Must be 'percentage' or 'none'.", 422);
    }
    if ($discountType === 'none') $discountValue = 0.00;
    if ($discountType === 'percentage' && ($discountValue < 0 || $discountValue > 100)) {
        throw new Exception("Percentage discount must be between 0 and 100.", 422);
    }

    $notes = isset($data['notes']) ? trim($data['notes']) : null;

    // -------------------------------------------------------
    // 3. Verify client
    // -------------------------------------------------------
    $clientCheck = $conn->prepare("
        SELECT id, company_name, currency, payment_terms, is_active
        FROM clients
        WHERE id = ?
        LIMIT 1
    ");
    $clientCheck->bind_param("i", $clientId);
    $clientCheck->execute();
    $client = $clientCheck->get_result()->fetch_assoc();
    $clientCheck->close();

    if (!$client) throw new Exception("Client not found.", 404);
    if ((int)$client['is_active'] === 0) throw new Exception("Cannot create an invoice for a deactivated client.", 409);

    if (!$currency) $currency = $client['currency'];
    if (!in_array($currency, ['NGN', 'USD'])) throw new Exception("Invalid currency. Must be 'NGN' or 'USD'.", 422);
    if ($currency === 'USD' && $exchangeRate === 1.0000 && !isset($data['exchange_rate'])) {
        throw new Exception("Exchange rate is required for USD invoices.", 422);
    }

    // Payment terms: override or client default
    $paymentTerms = isset($data['payment_terms']) ? strtolower(trim($data['payment_terms'])) : $client['payment_terms'];
    if (!in_array($paymentTerms, ['due_on_receipt', 'net_7'])) {
        throw new Exception("Invalid payment_terms. Must be 'due_on_receipt' or 'net_7'.", 422);
    }

    $dueDate = ($paymentTerms === 'due_on_receipt')
        ? $issueDate
        : date('Y-m-d', strtotime($issueDate . ' + 7 days'));

    // -------------------------------------------------------
    // 4. Validate & calculate line items
    // -------------------------------------------------------
    $validatedItems      = [];
    $calculatedSubtotal  = 0.00;
    $calculatedTaxAmount = 0.00;

    foreach ($data['items'] as $index => $item) {
        $n = $index + 1;

        if (!isset($item['description']) || empty(trim($item['description']))) {
            throw new Exception("Item {$n}: 'description' is required.", 422);
        }
        if (!isset($item['quantity']) || !is_numeric($item['quantity']) || (float)$item['quantity'] <= 0) {
            throw new Exception("Item {$n}: 'quantity' must be a positive number.", 422);
        }
        if (!isset($item['unit_price']) || !is_numeric($item['unit_price']) || (float)$item['unit_price'] < 0) {
            throw new Exception("Item {$n}: 'unit_price' must be a valid non-negative number.", 422);
        }

        $productId   = isset($item['product_id']) && is_numeric($item['product_id']) ? (int)$item['product_id'] : null;
        $description = trim($item['description']);
        $quantity    = (float)$item['quantity'];
        $unitPrice   = (float)$item['unit_price'];
        $taxRate     = 7.50;

        if ($productId) {
            $productCheck = $conn->prepare("
                SELECT tax_type, tax_rate, name, stock_quantity
                FROM products
                WHERE id = ? AND is_active = 1
                LIMIT 1
            ");
            $productCheck->bind_param("i", $productId);
            $productCheck->execute();
            $product = $productCheck->get_result()->fetch_assoc();
            $productCheck->close();

            if (!$product) throw new Exception("Item {$n}: Product not found or inactive.", 404);
            $taxRate = (float)$product['tax_rate'];
        } else {
            if (isset($item['tax_rate']) && is_numeric($item['tax_rate'])) {
                $taxRate = (float)$item['tax_rate'];
                if ($taxRate < 0 || $taxRate > 100) throw new Exception("Item {$n}: Invalid tax_rate.", 422);
            }
        }

        $itemDiscountType  = isset($item['discount_type']) ? strtolower(trim($item['discount_type'])) : 'none';
        $itemDiscountValue = isset($item['discount_value']) ? (float)$item['discount_value'] : 0.00;

        if (!in_array($itemDiscountType, ['fixed', 'none'])) {
            throw new Exception("Item {$n}: discount_type must be 'fixed' or 'none'.", 422);
        }
        if ($itemDiscountType === 'none') $itemDiscountValue = 0.00;
        if ($itemDiscountType === 'fixed' && $itemDiscountValue < 0) {
            throw new Exception("Item {$n}: Fixed discount cannot be negative.", 422);
        }

        $grossAmount        = $quantity * $unitPrice;
        $itemDiscountAmount = $itemDiscountValue;
        $netAmount          = $grossAmount - $itemDiscountAmount;

        if ($netAmount < 0) throw new Exception("Item {$n}: Discount cannot exceed the line total.", 422);

        $itemTaxAmount = round($netAmount * ($taxRate / 100), 2);
        $lineTotal     = round($netAmount, 2);

        $calculatedSubtotal  += $lineTotal;
        $calculatedTaxAmount += $itemTaxAmount;

        $validatedItems[] = [
            'product_id'      => $productId,
            'description'     => $description,
            'quantity'        => $quantity,
            'unit_price'      => $unitPrice,
            'tax_rate'        => $taxRate,
            'tax_amount'      => round($itemTaxAmount, 2),
            'discount_type'   => $itemDiscountType,
            'discount_value'  => $itemDiscountValue,
            'discount_amount' => round($itemDiscountAmount, 2),
            'line_total'      => $lineTotal,
            'sort_order'      => $index
        ];
    }

    // -------------------------------------------------------
    // 5. Document-level totals
    // -------------------------------------------------------
    $discountAmount = ($discountType === 'percentage' && $discountValue > 0)
        ? round($calculatedSubtotal * ($discountValue / 100), 2)
        : 0.00;

    $taxableAmount = round($calculatedSubtotal - $discountAmount, 2);
    $totalAmount   = round($taxableAmount + $calculatedTaxAmount, 2);
    $balanceDue    = $totalAmount;

    // Snapshot legal footer
    $footerStmt = $conn->prepare("SELECT legal_footer FROM company_settings LIMIT 1");
    $footerStmt->execute();
    $footerRow  = $footerStmt->get_result()->fetch_assoc();
    $footerText = $footerRow ? $footerRow['legal_footer'] : 'Goods sold are not returnable unless defective.';
    $footerStmt->close();

    // -------------------------------------------------------
    // 6. Transaction: sequence, header, items, log
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

        // INSERT invoices
        // Bound params (18): invoice_number, client_id, created_by,
        //   issue_date, due_date, currency, exchange_rate,
        //   subtotal, discount_type, discount_value, discount_amount,
        //   taxable_amount, tax_amount, total_amount,
        //   balance_due, payment_terms, footer_text, notes
        // proforma_id=NULL, quotation_id=NULL, amount_paid=0, status='draft', stock_deducted=0 → literals
        // Types: s i i s s s d d s d d d d d d s s s  = 18 chars → "siisssddsddddddss s"
        $insertStmt = $conn->prepare("
            INSERT INTO invoices (
                invoice_number, proforma_id, quotation_id, client_id, created_by,
                issue_date, due_date, currency, exchange_rate,
                subtotal, discount_type, discount_value, discount_amount,
                taxable_amount, tax_amount, total_amount,
                amount_paid, balance_due, payment_terms, footer_text, notes,
                status, stock_deducted
            ) VALUES (?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'draft', 0)
        ");
        if (!$insertStmt) throw new Exception("Failed to prepare invoice insert: " . $conn->error, 500);

        // 18 bound params: s i i s s s d d s d d d d d d s s s
        $insertStmt->bind_param("siisssddsddddddsss",
            $invoiceNumber,
            $clientId,
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

        // Insert items
        $itemStmt = $conn->prepare("
            INSERT INTO invoice_items (
                invoice_id, product_id, description, quantity, unit_price,
                tax_rate, tax_amount, discount_type, discount_value,
                discount_amount, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$itemStmt) throw new Exception("Failed to prepare item insert: " . $conn->error, 500);

        foreach ($validatedItems as $item) {
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
            if (!$itemStmt->execute()) throw new Exception("Failed to add line item: " . $itemStmt->error, 500);
        }
        $itemStmt->close();

        // Activity log
        $logStmt     = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "invoice.created";
        $modelType   = "Invoice";
        $itemCount   = count($validatedItems);
        $description = "{$loggedInUserEmail} created invoice {$invoiceNumber} for '{$client['company_name']}' with {$itemCount} item(s). Total: {$currency} {$totalAmount}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $newInvoiceId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        http_response_code(201);
        echo json_encode([
            "status"  => "success",
            "message" => "Invoice created successfully. Awaiting Admin finalization to deduct stock.",
            "data"    => [
                "id"             => $newInvoiceId,
                "invoice_number" => $invoiceNumber,
                "client_id"      => $clientId,
                "client_name"    => $client['company_name'],
                "issue_date"     => $issueDate,
                "due_date"       => $dueDate,
                "payment_terms"  => $paymentTerms,
                "currency"       => $currency,
                "exchange_rate"  => $exchangeRate,
                "subtotal"       => $calculatedSubtotal,
                "discount_type"  => $discountType,
                "discount_value" => $discountValue,
                "discount_amount"=> $discountAmount,
                "taxable_amount" => $taxableAmount,
                "tax_amount"     => $calculatedTaxAmount,
                "total_amount"   => $totalAmount,
                "amount_paid"    => 0.00,
                "balance_due"    => $balanceDue,
                "status"         => "draft",
                "stock_deducted" => false,
                "item_count"     => $itemCount
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Create Invoice Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
