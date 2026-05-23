<?php
// routes/proformas/createProforma.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /proformas/create
 * Create a standalone proforma invoice (not from a quotation).
 * Generates document number: PRO/YYYY/NNN
 * Roles allowed: Admin, Sales
 *
 * Sample payload:
 * {
 *   "client_id": 3,
 *   "currency": "NGN",
 *   "exchange_rate": 1,
 *   "discount_type": "percentage",
 *   "discount_value": 5,
 *   "notes": "Kindly approve before we proceed.",
 *   "issue_date": "2026-04-25",
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
 *       "description": "Custom Packaging",
 *       "quantity": 1,
 *       "unit_price": 3000,
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

    // Authenticate user
    $userData          = authenticateUser();
    $loggedInUserId    = (int)$userData['id'];
    $loggedInUserRole  = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can create proforma invoices.", 403);
    }

    // -------------------------------------------------------
    // 1. Parse & validate payload
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

    $expiryDate = date('Y-m-d', strtotime($issueDate . ' + 14 days'));

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
    // 3. Verify client exists and is active
    // -------------------------------------------------------
    $clientCheck = $conn->prepare("
        SELECT id, company_name, currency, is_active
        FROM clients
        WHERE id = ?
        LIMIT 1
    ");
    $clientCheck->bind_param("i", $clientId);
    $clientCheck->execute();
    $client = $clientCheck->get_result()->fetch_assoc();
    $clientCheck->close();

    if (!$client) {
        throw new Exception("Client not found.", 404);
    }
    if ((int)$client['is_active'] === 0) {
        throw new Exception("Cannot create a proforma for a deactivated client.", 409);
    }

    if (!$currency) $currency = $client['currency'];

    if (!in_array($currency, ['NGN', 'USD'])) {
        throw new Exception("Invalid currency. Must be 'NGN' or 'USD'.", 422);
    }
    if ($currency === 'USD' && $exchangeRate === 1.0000 && !isset($data['exchange_rate'])) {
        throw new Exception("Exchange rate is required for USD proformas.", 422);
    }

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
                SELECT tax_type, tax_rate, name
                FROM products
                WHERE id = ? AND is_active = 1
                LIMIT 1
            ");
            $productCheck->bind_param("i", $productId);
            $productCheck->execute();
            $product = $productCheck->get_result()->fetch_assoc();
            $productCheck->close();

            if (!$product) {
                throw new Exception("Item {$n}: Product not found or inactive.", 404);
            }
            $taxRate = (float)$product['tax_rate'];
        } else {
            if (isset($item['tax_rate']) && is_numeric($item['tax_rate'])) {
                $taxRate = (float)$item['tax_rate'];
                if ($taxRate < 0 || $taxRate > 100) {
                    throw new Exception("Item {$n}: Invalid tax_rate.", 422);
                }
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

        if ($netAmount < 0) {
            throw new Exception("Item {$n}: Discount cannot exceed the line total.", 422);
        }

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
    $discountAmount = 0.00;
    if ($discountType === 'percentage' && $discountValue > 0) {
        $discountAmount = round($calculatedSubtotal * ($discountValue / 100), 2);
    }

    $taxableAmount = round($calculatedSubtotal - $discountAmount, 2);
    $totalAmount   = round($taxableAmount + $calculatedTaxAmount, 2);

    // -------------------------------------------------------
    // 6. Transaction: generate number, insert header + items
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        $currentYear = (int)date('Y');

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
            $seqUpdate    = $conn->prepare("UPDATE document_number_sequences SET last_sequence = ? WHERE id = ?");
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

        $proformaNumber = "PRO/" . $currentYear . "/" . str_pad($nextSequence, 3, '0', STR_PAD_LEFT);

        // proforma_invoices INSERT — 16 bound params
        // s i i i s s s d d s d d d d d s  → 'draft' literal at end
        $insertStmt = $conn->prepare("
            INSERT INTO proforma_invoices (
                proforma_number, quotation_id, client_id, created_by,
                issue_date, expiry_date, currency, exchange_rate,
                subtotal, discount_type, discount_value, discount_amount,
                taxable_amount, tax_amount, total_amount, notes, status
            ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
        ");
        if (!$insertStmt) throw new Exception("Failed to prepare proforma insert: " . $conn->error, 500);

        // 16 params: s i i s s s d d s d d d d d s(notes)  → siiisssddsddddds
        $insertStmt->bind_param("siisssddsddddds",
            $proformaNumber,
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
            throw new Exception("Failed to create proforma invoice: " . $insertStmt->error, 500);
        }
        $newProformaId = $insertStmt->insert_id;
        $insertStmt->close();

        // Insert items
        $itemStmt = $conn->prepare("
            INSERT INTO proforma_items (
                proforma_id, product_id, description, quantity, unit_price,
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

        // Activity log
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "proforma.created";
        $modelType   = "ProformaInvoice";
        $itemCount   = count($validatedItems);
        $description = "{$loggedInUserEmail} created proforma {$proformaNumber} for '{$client['company_name']}' with {$itemCount} item(s). Total: {$currency} {$totalAmount}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $newProformaId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        // -------------------------------------------------------
        // 7. Return created proforma
        // -------------------------------------------------------
        http_response_code(201);
        echo json_encode([
            "status"  => "success",
            "message" => "Proforma invoice created successfully.",
            "data"    => [
                "id"              => $newProformaId,
                "proforma_number" => $proformaNumber,
                "client_id"       => $clientId,
                "client_name"     => $client['company_name'],
                "quotation_id"    => null,
                "issue_date"      => $issueDate,
                "expiry_date"     => $expiryDate,
                "currency"        => $currency,
                "exchange_rate"   => $exchangeRate,
                "subtotal"        => $calculatedSubtotal,
                "discount_type"   => $discountType,
                "discount_value"  => $discountValue,
                "discount_amount" => $discountAmount,
                "taxable_amount"  => $taxableAmount,
                "tax_amount"      => $calculatedTaxAmount,
                "total_amount"    => $totalAmount,
                "notes"           => $notes,
                "status"          => "draft",
                "item_count"      => $itemCount
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Create Proforma Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
