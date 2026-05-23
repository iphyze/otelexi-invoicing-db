<?php
// routes/inventory/adjustStock.php
// POST /inventory/adjustments - controlled manual stock change.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/inventory.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN], 'Only a Super Admin or Admin can adjust stock.');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request payload.', 400);
    }

    $productId = (int) ($data['product_id'] ?? 0);
    $adjustmentType = strtolower(trim((string) ($data['adjustment_type'] ?? '')));
    $quantity = round((float) ($data['quantity'] ?? 0), 2);
    $reasonCode = strtolower(trim((string) ($data['reason_code'] ?? '')));
    $reason = trim((string) ($data['reason'] ?? ''));

    $types = ['increase', 'decrease', 'set'];
    $reasons = ['opening_stock', 'physical_count', 'damaged', 'returned_goods', 'correction', 'other'];

    if ($productId < 1) {
        throw new Exception('Please select a product.', 422);
    }
    if (!in_array($adjustmentType, $types, true)) {
        throw new Exception('Select a valid adjustment type.', 422);
    }
    if ($quantity < 0 || ($quantity == 0.0 && $adjustmentType !== 'set')) {
        throw new Exception('Quantity must be greater than zero.', 422);
    }
    if (!in_array($reasonCode, $reasons, true)) {
        throw new Exception('Select a valid adjustment reason.', 422);
    }
    if (mb_strlen($reason) < 8) {
        throw new Exception('Provide a clear adjustment explanation of at least 8 characters.', 422);
    }

    $conn->begin_transaction();

    try {
        $productStmt = $conn->prepare(
            'SELECT id, name, sku, stock_quantity, unit_of_measure, is_active
             FROM products WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $productStmt->bind_param('i', $productId);
        $productStmt->execute();
        $product = $productStmt->get_result()->fetch_assoc();
        $productStmt->close();

        if (!$product) {
            throw new Exception('Product not found.', 404);
        }
        if ((int) $product['is_active'] !== 1) {
            throw new Exception('Stock cannot be adjusted for an inactive product.', 409);
        }

        $before = round((float) $product['stock_quantity'], 2);
        if ($adjustmentType === 'increase') {
            $after = round($before + $quantity, 2);
        } elseif ($adjustmentType === 'decrease') {
            $after = round($before - $quantity, 2);
        } else {
            $after = $quantity;
        }

        if ($after < 0) {
            throw new Exception('This adjustment would result in negative available stock.', 422);
        }

        $change = round($after - $before, 2);
        if (abs($change) < 0.001) {
            throw new Exception('The adjustment does not change the current stock balance.', 422);
        }

        $adjustmentNumber = nextStockAdjustmentNumber($conn);
        $createdBy = (int) $user['id'];

        $insertAdjustment = $conn->prepare(
            'INSERT INTO stock_adjustments
             (adjustment_number, product_id, adjustment_type, quantity, quantity_change,
              balance_before, balance_after, reason_code, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertAdjustment->bind_param(
            'sisddddssi',
            $adjustmentNumber,
            $productId,
            $adjustmentType,
            $quantity,
            $change,
            $before,
            $after,
            $reasonCode,
            $reason,
            $createdBy
        );
        $insertAdjustment->execute();
        $adjustmentId = (int) $insertAdjustment->insert_id;
        $insertAdjustment->close();

        $updateProduct = $conn->prepare('UPDATE products SET stock_quantity = ? WHERE id = ?');
        $updateProduct->bind_param('di', $after, $productId);
        $updateProduct->execute();
        $updateProduct->close();

        $movementNote = sprintf('%s: %s', $reasonCode, $reason);
        $movement = $conn->prepare(
            "INSERT INTO stock_movements
             (movement_number, product_id, movement_type, quantity, balance_before, balance_after,
              reference_type, reference_id, reason_code, notes, created_by)
             VALUES (?, ?, 'adjustment', ?, ?, ?, 'stock_adjustment', ?, ?, ?, ?)"
        );
        $movement->bind_param(
            'sidddissi',
            $adjustmentNumber,
            $productId,
            $change,
            $before,
            $after,
            $adjustmentId,
            $reasonCode,
            $movementNote,
            $createdBy
        );
        $movement->execute();
        $movement->close();

        $description = sprintf(
            '%s adjusted stock for %s (%s) from %.2f to %.2f. Reference: %s.',
            (string) $user['email'],
            (string) $product['name'],
            (string) $product['sku'],
            $before,
            $after,
            $adjustmentNumber
        );
        logInventoryAction($conn, $createdBy, 'inventory.stock_adjusted', 'StockAdjustment', $adjustmentId, $description, [
            'adjustment_number' => $adjustmentNumber,
            'product_id' => $productId,
            'adjustment_type' => $adjustmentType,
            'quantity_change' => $change,
            'balance_before' => $before,
            'balance_after' => $after,
            'reason_code' => $reasonCode,
        ]);

        $conn->commit();

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Stock adjustment recorded successfully.',
            'data' => [
                'id' => $adjustmentId,
                'adjustment_number' => $adjustmentNumber,
                'product_id' => $productId,
                'product_name' => $product['name'],
                'sku' => $product['sku'],
                'quantity_change' => $change,
                'balance_before' => $before,
                'balance_after' => $after,
                'reason_code' => $reasonCode,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('Adjust Stock Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Stock adjustment could not be completed right now.' : $e->getMessage(),
    ]);
}
