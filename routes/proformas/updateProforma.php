<?php
// routes/proformas/updateProforma.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * PUT /proformas/edit/{id}
 * Update a draft proforma invoice header and/or items.
 * Only 'draft' proformas can be edited.
 * Roles allowed: Admin, Sales (own only)
 *
 * Sample payload (all fields optional, send only what changes):
 * {
 *   "client_id": 3,
 *   "issue_date": "2026-04-26",
 *   "currency": "NGN",
 *   "exchange_rate": 1,
 *   "discount_type": "percentage",
 *   "discount_value": 10,
 *   "notes": "Updated terms.",
 *   "items": [
 *     {
 *       "product_id": 2,
 *       "description": "Stainless Steel Pot Set",
 *       "quantity": 12,
 *       "unit_price": 15000,
 *       "discount_type": "fixed",
 *       "discount_value": 500
 *     }
 *   ]
 * }
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    $userData          = authenticateUser();
    $loggedInUserId    = (int)$userData['id'];
    $loggedInUserRole  = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can update proforma invoices.", 403);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Proforma ID is required.", 400);
    }
    $proformaId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 1. Verify proforma exists, is draft, and caller owns it
    // -------------------------------------------------------
    $checkStmt = $conn->prepare("
        SELECT p.*, c.company_name AS client_name, c.currency AS client_currency
        FROM proforma_invoices p
        JOIN clients c ON c.id = p.client_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $checkStmt->bind_param("i", $proformaId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existing) {
        throw new Exception("Proforma invoice not found.", 404);
    }
    if ($existing['status'] !== 'draft') {
        throw new Exception("Only draft proforma invoices can be edited. Current status: {$existing['status']}.", 409);
    }
    if ($loggedInUserRole === 'sales' && (int)$existing['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only edit your own proforma invoices.", 403);
    }

    // -------------------------------------------------------
    // 2. Items update (full replace if provided)
    // -------------------------------------------------------
    $hasItemsUpdate = isset($data['items']) && is_array($data['items']);
    $validatedItems = [];

    if ($hasItemsUpdate) {
        if (count($data['items']) === 0) {
            throw new Exception("At least one item is required.", 422);
        }

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
                $productCheck = $conn->prepare("SELECT tax_rate FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
                $productCheck->bind_param("i", $productId);
                $productCheck->execute();
                $product = $productCheck->get_result()->fetch_assoc();
                $productCheck->close();
                if (!$product) throw new Exception("Item {$n}: Product not found or inactive.", 404);
                $taxRate = (float)$product['tax_rate'];
            } elseif (isset($item['tax_rate']) && is_numeric($item['tax_rate'])) {
                $taxRate = (float)$item['tax_rate'];
                if ($taxRate < 0 || $taxRate > 100) throw new Exception("Item {$n}: Invalid tax_rate.", 422);
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
    }

    // -------------------------------------------------------
    // 3. Build header update fields
    // -------------------------------------------------------
    $allowedFields = ['client_id', 'issue_date', 'currency', 'exchange_rate', 'discount_type', 'discount_value', 'notes'];
    $updateFields  = [];
    $params        = [];
    $types         = "";

    foreach ($allowedFields as $field) {
        if (!array_key_exists($field, $data)) continue;

        $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];

        switch ($field) {
            case 'client_id':
                if (!is_numeric($value)) throw new Exception("Invalid client_id.", 422);
                $value = (int)$value;
                // Verify new client exists and is active
                $cCheck = $conn->prepare("SELECT id, is_active FROM clients WHERE id = ? LIMIT 1");
                $cCheck->bind_param("i", $value);
                $cCheck->execute();
                $cRow = $cCheck->get_result()->fetch_assoc();
                $cCheck->close();
                if (!$cRow) throw new Exception("Client not found.", 404);
                if ((int)$cRow['is_active'] === 0) throw new Exception("Cannot use a deactivated client.", 409);
                break;

            case 'issue_date':
                if (!DateTime::createFromFormat('Y-m-d', $value)) {
                    throw new Exception("Invalid issue_date format. Use YYYY-MM-DD.", 422);
                }
                break;

            case 'currency':
                $value = strtoupper($value);
                if (!in_array($value, ['NGN', 'USD'])) throw new Exception("Invalid currency.", 422);
                break;

            case 'exchange_rate':
                if (!is_numeric($value) || (float)$value <= 0) throw new Exception("Invalid exchange rate.", 422);
                $value = (float)$value;
                break;

            case 'discount_type':
                $value = strtolower($value);
                if (!in_array($value, ['percentage', 'none'])) throw new Exception("Invalid discount_type.", 422);
                break;

            case 'discount_value':
                if (!is_numeric($value) || (float)$value < 0 || (float)$value > 100) {
                    throw new Exception("Invalid discount_value.", 422);
                }
                $value = (float)$value;
                break;
        }

        $updateFields[] = "`{$field}` = ?";
        $params[]       = $value;
        $types         .= (is_int($value) || is_float($value)) ? "d" : "s";
    }

    // Recalculate totals
    $newDiscountType  = isset($data['discount_type']) ? strtolower(trim($data['discount_type'])) : $existing['discount_type'];
    $newDiscountValue = isset($data['discount_value']) ? (float)$data['discount_value'] : (float)$existing['discount_value'];
    if ($newDiscountType === 'none') $newDiscountValue = 0.00;

    if ($hasItemsUpdate) {
        $calculatedSubtotal  = array_sum(array_column($validatedItems, 'line_total'));
        $calculatedTaxAmount = array_sum(array_column($validatedItems, 'tax_amount'));
    } else {
        $calculatedSubtotal  = (float)$existing['subtotal'];
        $calculatedTaxAmount = (float)$existing['tax_amount'];
    }

    $discountAmount = ($newDiscountType === 'percentage' && $newDiscountValue > 0)
        ? round($calculatedSubtotal * ($newDiscountValue / 100), 2)
        : 0.00;

    $taxableAmount = round($calculatedSubtotal - $discountAmount, 2);
    $totalAmount   = round($taxableAmount + $calculatedTaxAmount, 2);

    // Always recalculate stored totals
    $updateFields[] = "`subtotal` = ?";        $params[] = $calculatedSubtotal;  $types .= "d";
    $updateFields[] = "`discount_amount` = ?"; $params[] = $discountAmount;       $types .= "d";
    $updateFields[] = "`taxable_amount` = ?";  $params[] = $taxableAmount;        $types .= "d";
    $updateFields[] = "`tax_amount` = ?";      $params[] = $calculatedTaxAmount;  $types .= "d";
    $updateFields[] = "`total_amount` = ?";    $params[] = $totalAmount;          $types .= "d";

    // Recalculate expiry if issue_date changed
    if (array_key_exists('issue_date', $data)) {
        $newExpiry      = date('Y-m-d', strtotime($data['issue_date'] . ' + 14 days'));
        $updateFields[] = "`expiry_date` = ?";
        $params[]       = $newExpiry;
        $types         .= "s";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    // -------------------------------------------------------
    // 4. Transaction
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        // Update header
        $sql        = "UPDATE proforma_invoices SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $params[]   = $proformaId;
        $types     .= "i";
        $updateStmt = $conn->prepare($sql);
        if (!$updateStmt) throw new Exception("Failed to prepare update: " . $conn->error, 500);

        $updateStmt->bind_param($types, ...$params);
        if (!$updateStmt->execute()) throw new Exception("Failed to update proforma: " . $updateStmt->error, 500);
        $updateStmt->close();

        // Replace items if provided
        if ($hasItemsUpdate) {
            $delStmt = $conn->prepare("DELETE FROM proforma_items WHERE proforma_id = ?");
            $delStmt->bind_param("i", $proformaId);
            $delStmt->execute();
            $delStmt->close();

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
                    $proformaId, $iProductId, $iDescription, $iQuantity, $iUnitPrice,
                    $iTaxRate, $iTaxAmount, $iDiscountType, $iDiscountValue,
                    $iDiscountAmount, $iLineTotal, $iSortOrder
                );
                if (!$itemStmt->execute()) throw new Exception("Failed to add line item: " . $itemStmt->error, 500);
            }
            $itemStmt->close();
        }

        // Activity log
        $logStmt     = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "proforma.updated";
        $modelType   = "ProformaInvoice";
        $description = "{$loggedInUserEmail} updated proforma {$existing['proforma_number']}" . ($hasItemsUpdate ? " (items replaced)" : "") . ".";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $proformaId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Proforma invoice updated successfully.",
            "data"    => [
                "id"              => $proformaId,
                "proforma_number" => $existing['proforma_number'],
                "subtotal"        => $calculatedSubtotal,
                "discount_amount" => $discountAmount,
                "taxable_amount"  => $taxableAmount,
                "tax_amount"      => $calculatedTaxAmount,
                "total_amount"    => $totalAmount,
                "item_count"      => $hasItemsUpdate ? count($validatedItems) : null
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Update Proforma Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
