<?php
// routes/products/importProducts.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../utils/productImport.php';
require_once __DIR__ . '/../../cron/notificationHelper.php';

/**
 * POST /products/import
 * Re-validates and saves an entire product/category import atomically.
 * Roles allowed: Super Admin, Admin
 */

header('Content-Type: application/json');

date_default_timezone_set('Africa/Lagos');

$rows = null;
$categories = [];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    if (!in_array($userData['role'], ['super_admin', 'admin'], true)) {
        throw new Exception('Unauthorized: Only Super Admins or Admins can import products.', 403);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid or missing JSON payload.', 400);
    }

    $rows = $data['rows'] ?? null;
    if (!is_array($rows)) {
        throw new Exception("The field 'rows' must be an array.", 422);
    }

    $categories = $data['categories'] ?? [];
    if (!is_array($categories)) {
        throw new Exception("The field 'categories' must be an array.", 422);
    }

    // Never trust a previous browser preview. Re-run every validation against
    // the current database immediately before the transaction starts.
    $validated = validateProductImportRows($conn, $rows, $categories);
    if (!$validated['summary']['can_import']) {
        http_response_code(422);
        echo json_encode([
            'status' => 'failed',
            'message' => 'Some product or category rows are invalid. Correct them before importing.',
            'data' => $validated,
        ]);
        exit;
    }

    $conn->begin_transaction();

    try {
        $createdCategories = [];
        $newCategoryIdsByName = [];
        $newCategoryRows = array_values(array_filter(
            $validated['categories']['rows'] ?? [],
            static fn ($categoryRow) => is_array($categoryRow)
                && ($categoryRow['valid'] ?? false) === true
                && ($categoryRow['is_new'] ?? false) === true
        ));

        if ($newCategoryRows) {
            $categoryInsertStmt = $conn->prepare(
                'INSERT INTO product_categories (name, description) VALUES (?, ?)'
            );

            if (!$categoryInsertStmt) {
                throw new Exception('Failed to prepare the category import.', 500);
            }

            foreach ($newCategoryRows as $categoryRow) {
                $category = $categoryRow['data'];
                $categoryName = productImportString($category['name'] ?? '');
                $categoryDescriptionRaw = productImportString($category['description'] ?? '');
                $categoryDescription = $categoryDescriptionRaw !== '' ? $categoryDescriptionRaw : null;

                $categoryInsertStmt->bind_param('ss', $categoryName, $categoryDescription);

                if (!$categoryInsertStmt->execute()) {
                    if ($categoryInsertStmt->errno === 1062) {
                        throw new Exception(
                            "Category '{$categoryName}' was created by another user before this import completed. Please validate the workbook again.",
                            409
                        );
                    }
                    throw new Exception('Failed to save imported product categories.', 500);
                }

                $newCategoryId = (int) $categoryInsertStmt->insert_id;
                $newCategoryIdsByName[productImportKey($categoryName)] = $newCategoryId;
                $createdCategories[] = [
                    'id' => $newCategoryId,
                    'name' => $categoryName,
                    'description' => $categoryDescription,
                ];
            }

            $categoryInsertStmt->close();
        }

        $insertStmt = $conn->prepare("
            INSERT INTO products (
                category_id, name, sku, description, unit_price,
                unit_of_measure, tax_type, tax_rate, stock_quantity,
                reorder_level, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$insertStmt) {
            throw new Exception('Failed to prepare the product import.', 500);
        }

        $created = [];
        foreach ($validated['rows'] as $validatedRow) {
            $product = $validatedRow['data'];

            $categoryId = isset($product['category_id']) && $product['category_id'] !== null
                ? (int) $product['category_id']
                : 0;

            if ($categoryId <= 0 && !empty($product['category_is_new'])) {
                $categoryId = (int) ($newCategoryIdsByName[productImportKey($product['category_name'] ?? '')] ?? 0);
            }

            if ($categoryId <= 0) {
                $categoryName = productImportString($product['category_name'] ?? '');
                throw new Exception("Could not resolve category '{$categoryName}' for the product import.", 422);
            }

            $name = (string) $product['name'];
            $sku = (string) $product['sku'];
            $description = $product['description'] !== '' ? (string) $product['description'] : null;
            $unitPrice = (float) $product['unit_price'];
            $unitOfMeasure = (string) $product['unit_of_measure'];
            $taxType = (string) $product['tax_type'];
            $taxRate = (float) $product['tax_rate'];
            $stockQuantity = (float) $product['stock_quantity'];
            $reorderLevel = (float) $product['reorder_level'];
            $isActive = (int) $product['is_active'];

            $insertStmt->bind_param(
                'isssdssdddi',
                $categoryId,
                $name,
                $sku,
                $description,
                $unitPrice,
                $unitOfMeasure,
                $taxType,
                $taxRate,
                $stockQuantity,
                $reorderLevel,
                $isActive
            );

            if (!$insertStmt->execute()) {
                if ($insertStmt->errno === 1062) {
                    throw new Exception("SKU '{$sku}' already exists. The import was not saved.", 409);
                }
                if ($insertStmt->errno === 1452) {
                    throw new Exception("The category for SKU '{$sku}' no longer exists. The import was not saved.", 422);
                }
                throw new Exception('Failed to save imported products.', 500);
            }

            $created[] = [
                'id' => (int) $insertStmt->insert_id,
                'name' => $name,
                'sku' => $sku,
                'category_id' => $categoryId,
            ];
        }
        $insertStmt->close();

        $loggedInUserId = (int) $userData['id'];
        $loggedInUserEmail = (string) $userData['email'];
        $count = count($created);
        $categoryCount = count($createdCategories);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($categoryCount > 0) {
            $categoryAction = 'category.bulk_imported';
            $categoryModelType = 'ProductCategory';
            $categoryDescription = "{$loggedInUserEmail} created {$categoryCount} product categor" . ($categoryCount === 1 ? 'y' : 'ies') . ' during Excel product import';
            $categoryProperties = json_encode([
                'count' => $categoryCount,
                'categories' => $createdCategories,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $categoryLogStmt = $conn->prepare("
                INSERT INTO activity_log (
                    user_id, action, model_type, model_id, description, properties, ip_address
                ) VALUES (?, ?, ?, NULL, ?, ?, ?)
            ");

            if ($categoryLogStmt) {
                $categoryLogStmt->bind_param(
                    'isssss',
                    $loggedInUserId,
                    $categoryAction,
                    $categoryModelType,
                    $categoryDescription,
                    $categoryProperties,
                    $ipAddress
                );
                if (!$categoryLogStmt->execute()) {
                    error_log('Failed to log bulk category import: ' . $categoryLogStmt->error);
                }
                $categoryLogStmt->close();
            }
        }

        $action = 'product.bulk_imported';
        $modelType = 'Product';
        $description = "{$loggedInUserEmail} bulk imported {$count} product(s) from Excel";
        $properties = json_encode([
            'count' => $count,
            'products' => $created,
            'categories_created' => $createdCategories,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $logStmt = $conn->prepare("
            INSERT INTO activity_log (
                user_id, action, model_type, model_id, description, properties, ip_address
            ) VALUES (?, ?, ?, NULL, ?, ?, ?)
        ");

        if ($logStmt) {
            $logStmt->bind_param(
                'isssss',
                $loggedInUserId,
                $action,
                $modelType,
                $description,
                $properties,
                $ipAddress
            );
            if (!$logStmt->execute()) {
                error_log('Failed to log product bulk import: ' . $logStmt->error);
            }
            $logStmt->close();
        }

        $conn->commit();

        $firstProductId = !empty($created) ? (int) ($created[0]['id'] ?? 0) : null;
        createNotificationSafe($conn, [
            'roles' => ['super_admin', 'admin'],
            'type' => 'product.bulk_imported',
            'title' => 'Products Imported',
            'message' => "{$count} product(s) were imported successfully"
                . ($categoryCount > 0 ? " with {$categoryCount} new categor" . ($categoryCount === 1 ? 'y' : 'ies') : '')
                . '.',
            'model_type' => $firstProductId ? 'Product' : null,
            'model_id' => $firstProductId ?: null,
        ]);

        $message = "{$count} product(s) imported successfully.";
        if ($categoryCount > 0) {
            $message .= " {$categoryCount} new categor" . ($categoryCount === 1 ? 'y was' : 'ies were') . ' created.';
        }

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => $message,
            'data' => [
                'imported_count' => $count,
                'products' => $created,
                'created_category_count' => $categoryCount,
                'categories' => $createdCategories,
            ],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('Import Products Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $httpCode = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($httpCode);

    $response = [
        'status' => 'failed',
        'message' => $e->getMessage(),
    ];

    // A duplicate SKU/category or deleted category can appear between preview
    // and commit. Revalidate after rollback so the frontend can immediately
    // show the current workbook state instead of leaving the user with a toast.
    if (is_array($rows) && is_array($categories) && in_array($httpCode, [409, 422], true)) {
        try {
            $revalidated = validateProductImportRows($conn, $rows, $categories);
            $response['data'] = $revalidated;
        } catch (Throwable $revalidationError) {
            error_log('Product import revalidation failed: ' . $revalidationError->getMessage());
        }
    }

    echo json_encode($response);
}
