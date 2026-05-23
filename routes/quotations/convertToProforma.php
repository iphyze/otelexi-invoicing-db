<?php
// routes/quotations/convertToProforma.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /quotations/{id}/convert-proforma
 * Convert an accepted quotation to a proforma invoice.
 * Items can be edited during conversion (if provided in body).
 * Sets quotation status to 'converted'.
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
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin and Sales can convert quotations
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can convert quotations.", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Quotation ID
    // -------------------------------------------------------
    $quotationId = null;

    // Read body for items override or ID fallback
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

    // Only accepted can be converted
    if ($quotation['status'] !== 'accepted') {
        throw new Exception("Only accepted quotations can be converted. Current status: {$quotation['status']}.", 409);
    }

    // Sales can only convert their own
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
    // 4. Determine Items to Use (Override or Original)
    // -------------------------------------------------------
    $hasItemsOverride = isset($data['items']) && is_array($data['items']) && count($data['items']) > 0;
    $finalItems = [];

    if ($hasItemsOverride) {
        // Validate and calculate overridden items
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

            // Get tax rate from product if linked
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

            // Item discount
            $itemDiscountType  = isset($item['discount_type']) ? strtolower(trim($item['discount_type'])) : 'none';
            $itemDiscountValue = isset($item['discount_value']) ? (float)$item['discount_value'] : 0.00;

            if (!in_array($itemDiscountType, ['fixed', 'none'])) {
                throw new Exception("Override Item " . ($index + 1) . ": Invalid discount_type.", 422);
            }
            if ($itemDiscountType === 'none') $itemDiscountValue = 0.00;
            if ($itemDiscountType === 'fixed' && $itemDiscountValue < 0) {
                throw new Exception("Override Item " . ($index + 1) . ": Discount cannot be negative.", 422);
            }

            // Calculate
            $grossAmount       = $quantity * $unitPrice;
            $itemDiscountAmount = $itemDiscountValue;
            $netAmount         = $grossAmount - $itemDiscountAmount;

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
        // Use original items (recalculate to ensure consistency)
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

    // Discount (use quotation's original unless overridden)
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

    // Currency and exchange rate (use quotation's)
    $currency     = $quotation['currency'];
    $exchangeRate = (float)$quotation['exchange_rate'];

    // Notes
    $notes = isset($data['notes']) ? trim($data['notes']) : $quotation['notes'];

    // Issue date
    $issueDate = isset($data['issue_date']) && DateTime::createFromFormat('Y-m-d', trim($data['issue_date']))
        ? trim($data['issue_date'])
        : date('Y-m-d');

    // Expiry date (same 14-day rule)
    $expiryDate = date('Y-m-d', strtotime($issueDate . ' + 14 days'));

    // -------------------------------------------------------
    // 6. Generate Proforma Number & Create Record (Transaction)
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $currentYear = (int)date('Y');

        // Get next sequence for proforma
        $seqCheck = $conn->prepare("
            SELECT id, last_sequence
            FROM document_number_sequences
            WHERE doc_type = 'proforma' AND year = ?
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
            $seqInsert = $conn->prepare("INSERT INTO document_number_sequences (doc_type, year, last_sequence) VALUES ('proforma', ?, ?)");
            $seqInsert->bind_param("ii", $currentYear, $nextSequence);
            $seqInsert->execute();
            $seqInsert->close();
        }
        $seqCheck->close();

        // Format: PRO/2026/001
        $proformaNumber = "PRO/" . $currentYear . "/" . str_pad($nextSequence, 3, '0', STR_PAD_LEFT);

        // Insert proforma invoice
        // Columns: proforma_number, quotation_id, client_id, created_by,
        //          issue_date, expiry_date, currency, exchange_rate,
        //          subtotal, discount_type, discount_value, discount_amount,
        //          taxable_amount, tax_amount, total_amount, notes, status
        // Types:   s              i            i         i
        //          s           s            s        d
        //          d        s              d              d
        //          d              d          d             s      (s='draft' literal)
        // = 16 placeholders, type string: "siissddddsddddds"
        $insertQuery = "
            INSERT INTO proforma_invoices (
                proforma_number, quotation_id, client_id, created_by,
                issue_date, expiry_date, currency, exchange_rate,
                subtotal, discount_type, discount_value, discount_amount,
                taxable_amount, tax_amount, total_amount, notes, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
        ";

        $insertStmt = $conn->prepare($insertQuery);
        if (!$insertStmt) {
            throw new Exception("Failed to prepare proforma insert: " . $conn->error, 500);
        }

        $clientIdVar = (int)$quotation['client_id'];

        // 16 params: s i i i s s s d d s d d d d d s
        $insertStmt->bind_param(
            "siiisssddsddddds",
            $proformaNumber,       // s
            $quotationId,          // i
            $clientIdVar,          // i
            $loggedInUserId,       // i
            $issueDate,            // s
            $expiryDate,           // s
            $currency,             // s
            $exchangeRate,         // d
            $calculatedSubtotal,   // d
            $discountType,         // s
            $discountValue,        // d
            $discountAmount,       // d
            $taxableAmount,        // d
            $calculatedTaxAmount,  // d
            $totalAmount,          // d
            $notes                 // s
        );

        if (!$insertStmt->execute()) {
            throw new Exception("Failed to create proforma invoice: " . $insertStmt->error, 500);
        }

        $newProformaId = $insertStmt->insert_id;
        $insertStmt->close();

        // -------------------------------------------------------
        // Insert proforma items
        // FIX: Extract each item's fields to local variables inside
        //      the loop so bind_param can take them by reference.
        // -------------------------------------------------------
        $itemInsertQuery = "
            INSERT INTO proforma_items (
                proforma_id, product_id, description, quantity, unit_price,
                tax_rate, tax_amount, discount_type, discount_value,
                discount_amount, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $itemStmt = $conn->prepare($itemInsertQuery);
        if (!$itemStmt) {
            throw new Exception("Failed to prepare item insert: " . $conn->error, 500);
        }

        foreach ($finalItems as $item) {
            // Assign each field to a dedicated local variable.
            // bind_param() requires references; array subscripts and
            // null-coalescing expressions are NOT pass-by-reference.
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
                $newProformaId,
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
        $updateQuotationStmt = $conn->prepare("
            UPDATE quotations
            SET status = 'converted'
            WHERE id = ?
        ");
        $updateQuotationStmt->bind_param("i", $quotationId);
        $updateQuotationStmt->execute();
        $updateQuotationStmt->close();

        // Log activity
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action    = "quotation.converted_to_proforma";
        $modelType = "Quotation";
        $itemCount = count($finalItems);
        $itemsEdited = $hasItemsOverride ? " (items edited during conversion)" : "";
        $description = "{$loggedInUserEmail} converted quotation {$quotation['quotation_number']} to proforma {$proformaNumber} for '{$quotation['client_name']}'. {$itemCount} item(s){$itemsEdited}. Total: {$currency} {$totalAmount}";
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $quotationId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        // Log proforma creation too
        $logStmt2    = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action2      = "proforma.created_from_quotation";
        $modelType2   = "ProformaInvoice";
        $description2 = "Proforma {$proformaNumber} created from quotation {$quotation['quotation_number']}";
        $logStmt2->bind_param("ississ", $loggedInUserId, $action2, $modelType2, $newProformaId, $description2, $ipAddress);
        $logStmt2->execute();
        $logStmt2->close();

        $conn->commit();

        // -------------------------------------------------------
        // 7. Return Response
        // -------------------------------------------------------
        http_response_code(201);
        echo json_encode([
            "status"  => "success",
            "message" => "Quotation converted to proforma invoice successfully.",
            "data"    => [
                "quotation" => [
                    "id"               => $quotationId,
                    "quotation_number" => $quotation['quotation_number'],
                    "previous_status"  => "accepted",
                    "new_status"       => "converted"
                ],
                "proforma" => [
                    "id"              => $newProformaId,
                    "proforma_number" => $proformaNumber,
                    "client_id"       => (int)$quotation['client_id'],
                    "client_name"     => $quotation['client_name'],
                    "issue_date"      => $issueDate,
                    "expiry_date"     => $expiryDate,
                    "currency"        => $currency,
                    "exchange_rate"   => $exchangeRate,
                    "subtotal"        => $calculatedSubtotal,
                    "discount_amount" => $discountAmount,
                    "taxable_amount"  => $taxableAmount,
                    "tax_amount"      => $calculatedTaxAmount,
                    "total_amount"    => $totalAmount,
                    "status"          => "draft",
                    "item_count"      => $itemCount,
                    "items_edited"    => $hasItemsOverride
                ]
            ]
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Exception $e) {
    error_log("Convert Quotation to Proforma Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
