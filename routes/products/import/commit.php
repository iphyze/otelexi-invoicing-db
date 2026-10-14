<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../utils/productImport.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $user = authenticateUser();
    if (!in_array($user['role'] ?? '', ['super_admin', 'admin'], true)) {
        throw new RuntimeException('You are not authorized to import products.', 403);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid or missing import payload.', 400);
    }

    $preview = productImportBuildPreview($conn, $payload);
    if (!$preview['can_import']) {
        http_response_code(422);
        echo json_encode([
            'status' => 'failed',
            'message' => 'The import contains invalid rows. Validate the changes before importing.',
            'data' => $preview,
        ]);
        exit;
    }

    $rows = $preview['rows'];
    if (!$rows) {
        throw new RuntimeException('There are no products to import.', 422);
    }

    $conn->begin_transaction();
    try {
        [$existingById, $existingByName] = productImportExistingCategories($conn);
        $createdCategories = [];

        // Recreate the category intent from the validated preview, then resolve
        // each category under the transaction to protect against race conditions.
        foreach ($preview['new_categories'] as $category) {
            $name = trim((string) ($category['name'] ?? $category['category_name'] ?? ''));
            if ($name === '') continue;
            $key = productImportLower($name);

            if (isset($existingByName[$key])) {
                continue;
            }

            $description = $category['description'] ?? null;
            $stmt = $conn->prepare('INSERT INTO product_categories (name, description) VALUES (?, ?)');
            $stmt->bind_param('ss', $name, $description);
            if (!$stmt->execute()) {
                if ($stmt->errno === 1062) {
                    $stmt->close();
                    $lookup = $conn->prepare('SELECT id, name, description FROM product_categories WHERE name = ? LIMIT 1');
                    $lookup->bind_param('s', $name);
                    $lookup->execute();
                    $found = $lookup->get_result()->fetch_assoc();
                    $lookup->close();
                    if (!$found) {
                        throw new RuntimeException("Unable to resolve category '{$name}'.", 409);
                    }
                    $item = ['id' => (int) $found['id'], 'name' => $found['name'], 'description' => $found['description']];
                    $existingById[$item['id']] = $item;
                    $existingByName[$key] = $item;
                    continue;
                }
                throw new RuntimeException('Unable to create category: ' . $stmt->error, 500);
            }
            $id = (int) $stmt->insert_id;
            $stmt->close();
            $item = ['id' => $id, 'name' => $name, 'description' => $description];
            $existingById[$id] = $item;
            $existingByName[$key] = $item;
            $createdCategories[] = $item;
        }

        $insert = $conn->prepare(
            'INSERT INTO products (category_id, name, sku, description, unit_price, unit_of_measure, tax_type, tax_rate, stock_quantity, reorder_level, is_active) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $createdProducts = [];
        foreach ($rows as $row) {
            $categoryId = isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : 0;
            if ($categoryId <= 0) {
                $key = productImportLower((string) $row['category_name']);
                $categoryId = (int) ($existingByName[$key]['id'] ?? 0);
            }
            if ($categoryId <= 0) {
                throw new RuntimeException("Unable to resolve category for SKU '{$row['sku']}'.", 422);
            }

            $name = (string) $row['name'];
            $sku = strtoupper((string) $row['sku']);
            $description = $row['description'] !== null ? (string) $row['description'] : null;
            $unitPrice = (float) $row['unit_price'];
            $unit = (string) $row['unit_of_measure'];
            $taxType = (string) $row['tax_type'];
            $taxRate = (float) $row['tax_rate'];
            $stock = (float) $row['stock_quantity'];
            $reorder = (float) $row['reorder_level'];
            $isActive = (int) $row['is_active'];

            $insert->bind_param(
                'isssdssdddi',
                $categoryId,
                $name,
                $sku,
                $description,
                $unitPrice,
                $unit,
                $taxType,
                $taxRate,
                $stock,
                $reorder,
                $isActive
            );
            if (!$insert->execute()) {
                if ($insert->errno === 1062) {
                    throw new RuntimeException("SKU '{$sku}' was added by another request. Revalidate the import and try again.", 409);
                }
                throw new RuntimeException('Unable to import product: ' . $insert->error, 500);
            }

            $createdProducts[] = [
                'id' => (int) $insert->insert_id,
                'category_id' => $categoryId,
                'category_name' => $existingById[$categoryId]['name'] ?? $row['category_name'],
                'name' => $name,
                'sku' => $sku,
            ];
        }
        $insert->close();

        $userId = (int) ($user['id'] ?? 0);
        $email = (string) ($user['email'] ?? 'User');
        $action = 'product.bulk_imported';
        $modelType = 'Product';
        $description = sprintf(
            '%s bulk imported %d product%s and created %d categor%s.',
            $email,
            count($createdProducts),
            count($createdProducts) === 1 ? '' : 's',
            count($createdCategories),
            count($createdCategories) === 1 ? 'y' : 'ies'
        );
        $properties = json_encode([
            'products_imported' => count($createdProducts),
            'categories_created' => count($createdCategories),
            'skus' => array_column($createdProducts, 'sku'),
        ], JSON_UNESCAPED_SLASHES);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $log = $conn->prepare(
            'INSERT INTO activity_log (user_id, action, model_type, model_id, description, properties, ip_address) '
            . 'VALUES (?, ?, ?, NULL, ?, ?, ?)'
        );
        $log->bind_param('isssss', $userId, $action, $modelType, $description, $properties, $ip);
        if (!$log->execute()) {
            error_log('Product bulk import activity log failed: ' . $log->error);
        }
        $log->close();

        $conn->commit();

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => count($createdProducts) . ' product' . (count($createdProducts) === 1 ? '' : 's') . ' imported successfully.',
            'data' => [
                'imported_count' => count($createdProducts),
                'created_categories_count' => count($createdCategories),
                'created_categories' => $createdCategories,
                'products' => $createdProducts,
            ],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) $code = 500;
    error_log('Product Bulk Import Error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code >= 500 ? 'Unable to import the products.' : $e->getMessage(),
    ]);
}
