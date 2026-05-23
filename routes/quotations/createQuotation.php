<?php
// routes/quotations/createQuotation.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /quotations
 * Create a new quotation with line items.
 * Generates document number: QUO/2026/001
 * Roles allowed: Admin, Sales
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin and Sales can create quotations
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can create quotations.", 403);
    }

    // Read JSON payload
    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    // -------------------------------------------------------
    // 1. Validate Required Fields
    // -------------------------------------------------------
    if (!isset($data['client_id']) || !is_numeric($data['client_id'])) {
        throw new Exception("A valid 'client_id' is required.", 422);
    }

    if (!isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
        throw new Exception("At least one item is required.", 422);
    }

    $clientId = (int)$data['client_id'];

    // -------------------------------------------------------
    // 2. Validate & Prepare Document-Level Fields
    // -------------------------------------------------------
    $issueDate = isset($data['issue_date']) ? trim($data['issue_date']) : date('Y-m-d');

    // Validate date format
    if (!DateTime::createFromFormat('Y-m-d', $issueDate)) {
        throw new Exception("Invalid issue_date format. Use YYYY-MM-DD.", 422);
    }

    // Expiry date = issue_date + 14 days
    $expiryDate = date('Y-m-d', strtotime($issueDate . ' + 14 days'));

    // Currency - default to client's currency
    $currency = isset($data['currency']) ? strtoupper(trim($data['currency'])) : null;

    // Exchange rate
    $exchangeRate = 1.0000;
    if (isset($data['exchange_rate']) && is_numeric($data['exchange_rate'])) {
        $exchangeRate = (float)$data['exchange_rate'];
        if ($exchangeRate <= 0) {
            throw new Exception("Exchange rate must be greater than 0.", 422);
        }
    }

    // Document-level discount
    $discountType = isset($data['discount_type']) ? strtolower(trim($data['discount_type'])) : 'none';
    $discountValue = isset($data['discount_value']) ? (float)$data['discount_value'] : 0.00;

    if (!in_array($discountType, ['percentage', 'none'])) {
        throw new Exception("Invalid discount_type. Must be 'percentage' or 'none'.", 422);
    }

    if ($discountType === 'percentage' && ($discountValue < 0 || $discountValue > 100)) {
        throw new Exception("Percentage discount must be between 0 and 100.", 422);
    }

    if ($discountType === 'none') {
        $discountValue = 0.00;
    }

    $notes = isset($data['notes']) ? trim($data['notes']) : null;

    // -------------------------------------------------------
    // 3. Verify Client Exists & Is Active
    // -------------------------------------------------------
    $clientCheck = $conn->prepare("
        SELECT id, company_name, currency, is_active 
        FROM clients 
        WHERE id = ? 
        LIMIT 1
    ");
    $clientCheck->bind_param("i", $clientId);
    $clientCheck->execute();
    $clientResult = $clientCheck->get_result();

    if ($clientResult->num_rows === 0) {
        throw new Exception("Client not found.", 404);
    }

    $client = $clientResult->fetch_assoc();
    $clientCheck->close();

    if ((int)$client['is_active'] === 0) {
        throw new Exception("Cannot create quotation for a deactivated client.", 409);
    }

    // Set currency to client's default if not specified
    if (!$currency) {
        $currency = $client['currency'];
    }

    // Validate currency
    if (!in_array($currency, ['NGN', 'USD'])) {
        throw new Exception("Invalid currency. Must be 'NGN' or 'USD'.", 422);
    }

    // Require exchange rate for USD
    if ($currency === 'USD' && $exchangeRate === 1.0000 && !isset($data['exchange_rate'])) {
        throw new Exception("Exchange rate is required for USD quotations.", 422);
    }

    // -------------------------------------------------------
    // 4. Validate & Calculate Line Items
    // -------------------------------------------------------
    $validatedItems = [];
    $calculatedSubtotal = 0.00;
    $calculatedTaxAmount = 0.00;

    foreach ($data['items'] as $index => $item) {
        // Each item needs either product_id OR description
        if (!isset($item['description']) || empty(trim($item['description']))) {
            throw new Exception("Item " . ($index + 1) . ": 'description' is required.", 422);
        }

        if (!isset($item['quantity']) || !is_numeric($item['quantity']) || (float)$item['quantity'] <= 0) {
            throw new Exception("Item " . ($index + 1) . ": 'quantity' must be a positive number.", 422);
        }

        if (!isset($item['unit_price']) || !is_numeric($item['unit_price']) || (float)$item['unit_price'] < 0) {
            throw new Exception("Item " . ($index + 1) . ": 'unit_price' must be a valid non-negative number.", 422);
        }

        $productId = isset($item['product_id']) && is_numeric($item['product_id']) ? (int)$item['product_id'] : null;
        $description = trim($item['description']);
        $quantity = (float)$item['quantity'];
        $unitPrice = (float)$item['unit_price'];

        // Get tax rate from product if product_id provided, otherwise use provided/default
        $taxRate = 7.50; // Default VAT rate
        if ($productId) {
            $productCheck = $conn->prepare("
                SELECT tax_type, tax_rate, name, sku 
                FROM products 
                WHERE id = ? AND is_active = 1 
                LIMIT 1
            ");
            $productCheck->bind_param("i", $productId);
            $productCheck->execute();
            $productResult = $productCheck->get_result();

            if ($productResult->num_rows > 0) {
                $product = $productResult->fetch_assoc();
                $taxRate = (float)$product['tax_rate'];

                // Override description with product name if not explicitly different
                // (Keep user-provided description if it differs)
                if ($description === $product['name'] || empty($description)) {
                    $description = $product['name'];
                }
            } else {
                // Product not found or inactive - warn but allow (they might have custom description)
                throw new Exception("Item " . ($index + 1) . ": Product not found or inactive.", 404);
            }
            $productCheck->close();
        } else {
            // No product_id - use provided tax_rate or default
            if (isset($item['tax_rate']) && is_numeric($item['tax_rate'])) {
                $taxRate = (float)$item['tax_rate'];
                if ($taxRate < 0 || $taxRate > 100) {
                    throw new Exception("Item " . ($index + 1) . ": Invalid tax_rate.", 422);
                }
            }
        }

        // Item-level discount (fixed amount)
        $itemDiscountType = isset($item['discount_type']) ? strtolower(trim($item['discount_type'])) : 'none';
        $itemDiscountValue = isset($item['discount_value']) ? (float)$item['discount_value'] : 0.00;

        if (!in_array($itemDiscountType, ['fixed', 'none'])) {
            throw new Exception("Item " . ($index + 1) . ": Invalid discount_type. Must be 'fixed' or 'none'.", 422);
        }

        if ($itemDiscountType === 'none') {
            $itemDiscountValue = 0.00;
        }

        if ($itemDiscountType === 'fixed' && $itemDiscountValue < 0) {
            throw new Exception("Item " . ($index + 1) . ": Fixed discount cannot be negative.", 422);
        }

        // Calculate item totals
        $grossAmount = $quantity * $unitPrice;
        $itemDiscountAmount = $itemDiscountValue;
        $netAmount = $grossAmount - $itemDiscountAmount;

        if ($netAmount < 0) {
            throw new Exception("Item " . ($index + 1) . ": Discount cannot exceed the line total.", 422);
        }

        $itemTaxAmount = $netAmount * ($taxRate / 100);
        $lineTotal = $netAmount; // This is what gets summed for subtotal (before tax)

        $calculatedSubtotal += $lineTotal;
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
            'line_total'      => round($lineTotal, 2),
            'sort_order'      => $index
        ];
    }

    // -------------------------------------------------------
    // 5. Calculate Document Totals
    // -------------------------------------------------------
    $discountAmount = 0.00;
    if ($discountType === 'percentage' && $discountValue > 0) {
        $discountAmount = round($calculatedSubtotal * ($discountValue / 100), 2);
    }

    $taxableAmount = round($calculatedSubtotal - $discountAmount, 2);
    $totalAmount = round($taxableAmount + $calculatedTaxAmount, 2);

    // -------------------------------------------------------
    // 6. Generate Document Number (Inside Transaction)
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $currentYear = date('Y');

        // Check if sequence exists for this year
        $seqCheck = $conn->prepare("
            SELECT id, last_sequence 
            FROM document_number_sequences 
            WHERE doc_type = 'quotation' AND year = ? 
            FOR UPDATE
        ");
        $seqCheck->bind_param("i", $currentYear);
        $seqCheck->execute();
        $seqResult = $seqCheck->get_result();

        if ($seqResult->num_rows > 0) {
            $seqRow = $seqResult->fetch_assoc();
            $nextSequence = (int)$seqRow['last_sequence'] + 1;
            $seqId = (int)$seqRow['id'];

            // Update existing sequence
            $seqUpdate = $conn->prepare("
                UPDATE document_number_sequences 
                SET last_sequence = ? 
                WHERE id = ?
            ");
            $seqUpdate->bind_param("ii", $nextSequence, $seqId);
            $seqUpdate->execute();
            $seqUpdate->close();
        } else {
            $nextSequence = 1;

            // Insert new sequence
            $seqInsert = $conn->prepare("
                INSERT INTO document_number_sequences (doc_type, year, last_sequence) 
                VALUES ('quotation', ?, ?)
            ");
            $seqInsert->bind_param("ii", $currentYear, $nextSequence);
            $seqInsert->execute();
            $seqInsert->close();
        }
        $seqCheck->close();

        // Format: QUO/2026/001
        $quotationNumber = "QUO/" . $currentYear . "/" . str_pad($nextSequence, 3, '0', STR_PAD_LEFT);

        // -------------------------------------------------------
        // 7. Insert Quotation
        // -------------------------------------------------------
        $insertQuery = "
            INSERT INTO quotations (
                quotation_number, client_id, created_by, issue_date, expiry_date,
                currency, exchange_rate, subtotal, discount_type, discount_value,
                discount_amount, taxable_amount, tax_amount, total_amount, notes, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
        ";

        $insertStmt = $conn->prepare($insertQuery);
        if (!$insertStmt) {
            throw new Exception("Failed to prepare quotation insert: " . $conn->error, 500);
        }

        $insertStmt->bind_param(
            "siisssddsddddds",
            $quotationNumber,
            $clientId,
            $loggedInUserId,
            $issueDate,
            $expiryDate,
            $currency,
            $exchangeRate,
            $calculatedSubtotal,
            $discountType,
            $discountValue,
            $discountAmount,
            $taxableAmount,
            $calculatedTaxAmount,
            $totalAmount,
            $notes
        );

        if (!$insertStmt->execute()) {
            throw new Exception("Failed to create quotation: " . $insertStmt->error, 500);
        }

        $newQuotationId = $insertStmt->insert_id;
        $insertStmt->close();

        // -------------------------------------------------------
        // 8. Insert Line Items
        // -------------------------------------------------------
        $itemInsertQuery = "
            INSERT INTO quotation_items (
                quotation_id, product_id, description, quantity, unit_price,
                tax_rate, tax_amount, discount_type, discount_value,
                discount_amount, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $itemStmt = $conn->prepare($itemInsertQuery);
        if (!$itemStmt) {
            throw new Exception("Failed to prepare item insert: " . $conn->error, 500);
        }

        foreach ($validatedItems as $item) {
            $itemStmt->bind_param(
                "iisddddsdddi",
                $newQuotationId,
                $item['product_id'],
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['tax_rate'],
                $item['tax_amount'],
                $item['discount_type'],
                $item['discount_value'],
                $item['discount_amount'],
                $item['line_total'],
                $item['sort_order']
            );

            if (!$itemStmt->execute()) {
                throw new Exception("Failed to add line item: " . $itemStmt->error, 500);
            }
        }
        $itemStmt->close();

        // -------------------------------------------------------
        // 9. Log Activity
        // -------------------------------------------------------
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "quotation.created";
        $action      = "quotation.created";
        $modelType   = "Quotation";
        $itemCount = count($validatedItems);
        $description = "{$loggedInUserEmail} created quotation {$quotationNumber} for '{$client['company_name']}' with {$itemCount} item(s). Total: {$currency} {$totalAmount}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $newQuotationId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log quotation creation: " . $logStmt->error);
        }
        $logStmt->close();

        // Commit transaction
        $conn->commit();

        // -------------------------------------------------------
        // 10. Fetch & Return Created Quotation
        // -------------------------------------------------------
        $fetchStmt = $conn->prepare("
            SELECT q.*, c.company_name AS client_name
            FROM quotations q
            JOIN clients c ON c.id = q.client_id
            WHERE q.id = ?
        ");
        $fetchStmt->bind_param("i", $newQuotationId);
        $fetchStmt->execute();
        $newQuotation = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        http_response_code(201);
        echo json_encode([
            "status"  => "success",
            "message" => "Quotation created successfully.",
            "data"    => [
                "id"               => (int)$newQuotation['id'],
                "quotation_number" => $newQuotation['quotation_number'],
                "client_id"        => (int)$newQuotation['client_id'],
                "client_name"      => $newQuotation['client_name'],
                "issue_date"       => $newQuotation['issue_date'],
                "expiry_date"      => $newQuotation['expiry_date'],
                "currency"         => $newQuotation['currency'],
                "exchange_rate"    => (float)$newQuotation['exchange_rate'],
                "subtotal"         => (float)$newQuotation['subtotal'],
                "discount_type"    => $newQuotation['discount_type'],
                "discount_value"   => (float)$newQuotation['discount_value'],
                "discount_amount"  => (float)$newQuotation['discount_amount'],
                "taxable_amount"   => (float)$newQuotation['taxable_amount'],
                "tax_amount"       => (float)$newQuotation['tax_amount'],
                "total_amount"     => (float)$newQuotation['total_amount'],
                "notes"            => $newQuotation['notes'],
                "status"           => $newQuotation['status'],
                "item_count"       => $itemCount,
                "created_at"       => $newQuotation['created_at']
            ]
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Exception $e) {
    error_log("Create Quotation Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
