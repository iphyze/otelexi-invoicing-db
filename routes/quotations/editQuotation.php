<?php
// routes/quotations/updateQuotation.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * PUT /quotations/{id}
 * Update a draft quotation and its items.
 * Only draft quotations can be edited.
 * Roles allowed: Admin, Sales (own only)
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin and Sales can update quotations
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can update quotations.", 403);
    }

    // Read JSON payload
    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    // -------------------------------------------------------
    // 1. Validate Quotation ID
    // -------------------------------------------------------
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Quotation ID is required.", 400);
    }
    $quotationId = (int)$_GET['id'];
    unset($_GET['id']);

    // -------------------------------------------------------
    // 2. Verify Quotation Exists & Is Draft
    // -------------------------------------------------------
    $quotationCheck = $conn->prepare("
        SELECT q.*, c.company_name AS client_name, c.currency AS client_currency
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

    $existingQuotation = $quotationResult->fetch_assoc();
    $quotationCheck->close();

    // Only draft can be edited
    if ($existingQuotation['status'] !== 'draft') {
        throw new Exception("Only draft quotations can be edited. Current status: {$existingQuotation['status']}.", 409);
    }

    // Sales can only edit their own
    if ($loggedInUserRole === 'sales' && (int)$existingQuotation['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only edit your own quotations.", 403);
    }

    // -------------------------------------------------------
    // 3. Handle Items Update (Full Replace Strategy)
    // -------------------------------------------------------
    // If items array is provided, we delete all existing items and insert new ones
    $hasItemsUpdate = isset($data['items']) && is_array($data['items']);
    $validatedItems = [];

    if ($hasItemsUpdate) {
        if (count($data['items']) === 0) {
            throw new Exception("At least one item is required.", 422);
        }

        // Reuse the same validation logic from create
        foreach ($data['items'] as $index => $item) {
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

            // Get tax rate from product if product_id provided
            $taxRate = 7.50;
            if ($productId) {
                $productCheck = $conn->prepare("
                    SELECT tax_type, tax_rate, name 
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
                } else {
                    throw new Exception("Item " . ($index + 1) . ": Product not found or inactive.", 404);
                }
                $productCheck->close();
            } else {
                if (isset($item['tax_rate']) && is_numeric($item['tax_rate'])) {
                    $taxRate = (float)$item['tax_rate'];
                }
            }

            // Item discount
            $itemDiscountType = isset($item['discount_type']) ? strtolower(trim($item['discount_type'])) : 'none';
            $itemDiscountValue = isset($item['discount_value']) ? (float)$item['discount_value'] : 0.00;

            if (!in_array($itemDiscountType, ['fixed', 'none'])) {
                throw new Exception("Item " . ($index + 1) . ": Invalid discount_type.", 422);
            }
            if ($itemDiscountType === 'none') $itemDiscountValue = 0.00;
            if ($itemDiscountType === 'fixed' && $itemDiscountValue < 0) {
                throw new Exception("Item " . ($index + 1) . ": Fixed discount cannot be negative.", 422);
            }

            // Calculate
            $grossAmount = $quantity * $unitPrice;
            $itemDiscountAmount = $itemDiscountValue;
            $netAmount = $grossAmount - $itemDiscountAmount;

            if ($netAmount < 0) {
                throw new Exception("Item " . ($index + 1) . ": Discount exceeds line total.", 422);
            }

            $itemTaxAmount = round($netAmount * ($taxRate / 100), 2);
            $lineTotal = round($netAmount, 2);

            $validatedItems[] = [
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
    }

    // -------------------------------------------------------
    // 4. Handle Header Fields Update
    // -------------------------------------------------------
    // discount_type and discount_value are excluded from this loop intentionally.
    // They are read from \$data in the totals block and written as recalculated fields,
    // so including them here would write them twice into the SET clause and
    // break the discount recalculation logic.
    $allowedFields = ['client_id', 'issue_date', 'currency', 'exchange_rate', 'notes'];

    $updateFields = [];
    $params = [];
    $types = "";

    foreach ($data as $key => $value) {
        if (!in_array($key, $allowedFields)) continue;
        if ($key === 'items') continue; // Handled separately

        if (is_string($value)) $value = trim($value);

        // Validations
        if ($key === 'client_id') {
            if (!is_numeric($value)) throw new Exception("Invalid client_id.", 422);
            $value = (int)$value;
        }

        if ($key === 'issue_date') {
            if (!DateTime::createFromFormat('Y-m-d', $value)) {
                throw new Exception("Invalid issue_date format.", 422);
            }
        }

        if ($key === 'currency') {
            $value = strtoupper($value);
            if (!in_array($value, ['NGN', 'USD'])) {
                throw new Exception("Invalid currency.", 422);
            }
        }

        if ($key === 'exchange_rate') {
            if (!is_numeric($value) || (float)$value <= 0) {
                throw new Exception("Invalid exchange rate.", 422);
            }
            $value = (float)$value;
        }

        if ($key === 'discount_type') {
            $value = strtolower($value);
            if (!in_array($value, ['percentage', 'none'])) {
                throw new Exception("Invalid discount_type.", 422);
            }
        }

        if ($key === 'discount_value') {
            if (!is_numeric($value) || (float)$value < 0 || (float)$value > 100) {
                throw new Exception("Invalid discount_value.", 422);
            }
            $value = (float)$value;
        }

        $updateFields[] = "`{$key}` = ?";
        $params[] = $value;
        $types .= is_int($value) || is_float($value) ? "d" : "s";
    }

    // If client_id changed, verify new client exists
    if (in_array('client_id', array_keys($data))) {
        $newClientId = (int)$data['client_id'];
        $clientCheck = $conn->prepare("SELECT id, company_name, is_active FROM clients WHERE id = ? LIMIT 1");
        $clientCheck->bind_param("i", $newClientId);
        $clientCheck->execute();
        $clientResult = $clientCheck->get_result();
        if ($clientResult->num_rows === 0) {
            throw new Exception("Client not found.", 404);
        }
        $newClient = $clientResult->fetch_assoc();
        if ((int)$newClient['is_active'] === 0) {
            throw new Exception("Cannot use a deactivated client.", 409);
        }
        $clientCheck->close();
    }

    // -------------------------------------------------------
    // 5. Calculate Totals
    // -------------------------------------------------------
    $calculatedSubtotal = 0.00;
    $calculatedTaxAmount = 0.00;

    if ($hasItemsUpdate) {
        foreach ($validatedItems as $item) {
            $calculatedSubtotal += $item['line_total'];
            $calculatedTaxAmount += $item['tax_amount'];
        }
    } else {
        // Use existing totals if items not updated
        $calculatedSubtotal = (float)$existingQuotation['subtotal'];
        $calculatedTaxAmount = (float)$existingQuotation['tax_amount'];
    }

    // Get discount values
    $discountType = isset($data['discount_type']) ? strtolower(trim($data['discount_type'])) : $existingQuotation['discount_type'];
    $discountValue = isset($data['discount_value']) ? (float)$data['discount_value'] : (float)$existingQuotation['discount_value'];

    if ($discountType === 'none') $discountValue = 0.00;

    $discountAmount = 0.00;
    if ($discountType === 'percentage' && $discountValue > 0) {
        $discountAmount = round($calculatedSubtotal * ($discountValue / 100), 2);
    }

    $taxableAmount = round($calculatedSubtotal - $discountAmount, 2);
    $totalAmount = round($taxableAmount + $calculatedTaxAmount, 2);

    // Always update calculated fields (includes discount fields to keep DB consistent)
    $updateFields[] = "`discount_type` = ?";
    $params[] = $discountType;
    $types .= "s";

    $updateFields[] = "`discount_value` = ?";
    $params[] = $discountValue;
    $types .= "d";

    $updateFields[] = "`subtotal` = ?";
    $params[] = $calculatedSubtotal;
    $types .= "d";

    $updateFields[] = "`discount_amount` = ?";
    $params[] = $discountAmount;
    $types .= "d";

    $updateFields[] = "`taxable_amount` = ?";
    $params[] = $taxableAmount;
    $types .= "d";

    $updateFields[] = "`tax_amount` = ?";
    $params[] = $calculatedTaxAmount;
    $types .= "d";

    $updateFields[] = "`total_amount` = ?";
    $params[] = $totalAmount;
    $types .= "d";

    // Update expiry date if issue_date changed
    if (in_array('issue_date', array_keys($data))) {
        $newExpiryDate = date('Y-m-d', strtotime($data['issue_date'] . ' + 14 days'));
        $updateFields[] = "`expiry_date` = ?";
        $params[] = $newExpiryDate;
        $types .= "s";
    }

    // -------------------------------------------------------
    // 6. Execute Updates (Transaction)
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        // Update quotation header
        if (!empty($updateFields)) {
            $sql = "UPDATE quotations SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $updateStmt = $conn->prepare($sql);

            $params[] = $quotationId;
            $types .= "i";

            $updateStmt->bind_param($types, ...$params);

            if (!$updateStmt->execute()) {
                throw new Exception("Failed to update quotation: " . $updateStmt->error, 500);
            }
            $updateStmt->close();
        }

        // Update items if provided
        if ($hasItemsUpdate) {
            // Delete existing items
            $deleteItemsStmt = $conn->prepare("DELETE FROM quotation_items WHERE quotation_id = ?");
            $deleteItemsStmt->bind_param("i", $quotationId);
            $deleteItemsStmt->execute();
            $deleteItemsStmt->close();

            // Insert new items
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
                    $quotationId,
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
        }

        // -------------------------------------------------------
        // 7. Log Activity
        // -------------------------------------------------------
        $changes = [];
        if ($hasItemsUpdate) $changes[] = "items updated";
        if (in_array('client_id', array_keys($data))) $changes[] = "client changed";
        if (in_array('issue_date', array_keys($data))) $changes[] = "issue date changed";
        if (in_array('currency', array_keys($data))) $changes[] = "currency changed";
        if (in_array('discount_type', array_keys($data)) || in_array('discount_value', array_keys($data))) $changes[] = "discount updated";
        if (in_array('notes', array_keys($data))) $changes[] = "notes updated";

        $changeSummary = implode(', ', $changes);

        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "quotation.updated";
        $modelType   = "Quotation";
        $description = "{$loggedInUserEmail} updated quotation {$existingQuotation['quotation_number']}. Changes: {$changeSummary}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $quotationId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log quotation update: " . $logStmt->error);
        }
        $logStmt->close();

        $conn->commit();

        // -------------------------------------------------------
        // 8. Return Response
        // -------------------------------------------------------
        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Quotation updated successfully.",
            "data"    => [
                "id"               => $quotationId,
                "quotation_number" => $existingQuotation['quotation_number'],
                "subtotal"         => $calculatedSubtotal,
                "discount_amount"  => $discountAmount,
                "taxable_amount"   => $taxableAmount,
                "tax_amount"       => $calculatedTaxAmount,
                "total_amount"     => $totalAmount,
                "item_count"       => $hasItemsUpdate ? count($validatedItems) : null
            ]
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Exception $e) {
    error_log("Update Quotation Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
