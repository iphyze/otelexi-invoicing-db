<?php

declare(strict_types=1);

/**
 * Shared helpers for bulk product import preview + commit.
 * The frontend has evolved over time, so the normalizer intentionally accepts
 * both the current normalized keys and the human-readable Excel header names.
 */

function productImportString(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) {
            return trim((string) $row[$key]);
        }
    }
    return $default;
}

function productImportValue(array $row, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
    }
    return $default;
}

function productImportRows(array $payload): array
{
    if (isset($payload['preview']) && is_array($payload['preview'])) {
        $nested = productImportRows($payload['preview']);
        if ($nested) return $nested;
    }

    foreach (['rows', 'products', 'items', 'product_rows', 'productRows'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return array_values(array_filter($payload[$key], 'is_array'));
        }
    }

    // Some clients send the array directly.
    if (array_is_list($payload)) {
        return array_values(array_filter($payload, 'is_array'));
    }

    return [];
}

function productImportCategories(array $payload): array
{
    if (isset($payload['preview']) && is_array($payload['preview'])) {
        $nested = productImportCategories($payload['preview']);
        if ($nested) return $nested;
    }

    foreach (['categories', 'workbook_categories', 'category_rows', 'categoryRows', 'new_categories', 'newCategories'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return array_values(array_filter($payload[$key], 'is_array'));
        }
    }
    return [];
}

function productImportBoolean(mixed $value): bool
{
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return (float) $value !== 0.0;
    $normalized = strtolower(trim((string) ($value ?? '')));
    return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'new'], true);
}

/**
 * Reconstruct workbook category intent from already-previewed product rows.
 *
 * The frontend's Validate Changes flow can send the normalized product rows
 * without resending the original Categories sheet. New categories are therefore
 * carried on each preview row via category_will_create / a temporary negative
 * category_id. Rebuilding a unique category list here keeps revalidation
 * identical to the initial workbook preview.
 */
function productImportCategoriesFromRows(array $rows): array
{
    $categories = [];

    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $name = productImportString($row, ['category_name', 'category', 'Category', 'Category Name']);
        if ($name === '') continue;

        $idRaw = productImportValue($row, ['category_id', 'Category ID', 'categoryId']);
        $temporaryId = is_numeric($idRaw) ? (int) $idRaw : null;
        $willCreate = productImportBoolean(productImportValue(
            $row,
            ['category_will_create', 'categoryWillCreate', 'will_create', 'willCreate'],
            false
        ));

        if (!$willCreate && ($temporaryId === null || $temporaryId >= 0)) {
            continue;
        }

        $key = productImportLower($name);
        if (isset($categories[$key])) continue;

        $description = productImportString(
            $row,
            ['category_description', 'categoryDescription', 'Category Description'],
            ''
        );

        $categories[$key] = [
            'id' => $temporaryId !== null && $temporaryId < 0 ? $temporaryId : null,
            'category_id' => $temporaryId !== null && $temporaryId < 0 ? $temporaryId : null,
            'temporary_id' => $temporaryId !== null && $temporaryId < 0 ? $temporaryId : null,
            'name' => $name,
            'category_name' => $name,
            'description' => $description !== '' ? $description : null,
            'will_create' => true,
        ];
    }

    return array_values($categories);
}

function productImportLower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower(trim($value), 'UTF-8')
        : strtolower(trim($value));
}

function productImportStatus(mixed $value, float $stockQuantity): array
{
    $raw = trim((string) ($value ?? ''));
    $lower = strtolower($raw);

    // Excel formulas are occasionally passed through literally by SheetJS when
    // a workbook was generated without a cached formula value. Evaluate the
    // template's intended status instead of rejecting an otherwise valid row.
    if ($raw === '' || str_starts_with($raw, '=')) {
        return [$stockQuantity > 1 ? 1 : 0, $stockQuantity > 1 ? 'active' : 'inactive'];
    }

    if (in_array($lower, ['active', '1', 'yes', 'true', 'enabled'], true)) {
        return [1, 'active'];
    }
    if (in_array($lower, ['inactive', '0', 'no', 'false', 'disabled'], true)) {
        return [0, 'inactive'];
    }

    return [-1, $raw];
}

function productImportNormalizeTaxType(string $value): string
{
    $lower = strtolower(trim($value));
    if ($lower === '' || in_array($lower, ['vat', 'vat 7.5%', '7.5% vat', 'taxable'], true)) {
        return 'vat';
    }
    if (in_array($lower, ['exempt', 'vat exempt', 'non-vat', 'non vat', 'none'], true)) {
        return 'exempt';
    }
    return $lower;
}

function productImportNormalizeUnit(string $value): string
{
    $lower = strtolower(trim($value));
    $map = [
        'unit' => 'single',
        'single unit' => 'single',
        'piece' => 'single',
        'pieces' => 'single',
        'pc' => 'single',
        'pcs' => 'single',
        'single' => 'single',
        'set' => 'set',
        'sets' => 'set',
        'carton' => 'carton',
        'cartons' => 'carton',
        'ctn' => 'carton',
        'dozen' => 'dozen',
        'dozens' => 'dozen',
        'doz' => 'dozen',
    ];
    return $map[$lower] ?? $lower;
}

function productImportExistingCategories(mysqli $conn): array
{
    $result = $conn->query('SELECT id, name, description FROM product_categories ORDER BY name ASC');
    $byId = [];
    $byName = [];
    while ($row = $result->fetch_assoc()) {
        $item = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => $row['description'],
        ];
        $byId[(int) $row['id']] = $item;
        $byName[productImportLower((string) $row['name'])] = $item;
    }
    return [$byId, $byName];
}

function productImportExistingSkus(mysqli $conn, array $skus): array
{
    $skus = array_values(array_unique(array_filter(array_map('trim', $skus))));
    if (!$skus) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $stmt = $conn->prepare("SELECT id, sku FROM products WHERE sku IN ({$placeholders})");
    $types = str_repeat('s', count($skus));
    $stmt->bind_param($types, ...$skus);
    $stmt->execute();
    $result = $stmt->get_result();
    $found = [];
    while ($row = $result->fetch_assoc()) {
        $found[strtoupper(trim((string) $row['sku']))] = (int) $row['id'];
    }
    $stmt->close();
    return $found;
}

function productImportNormalizeCategories(mysqli $conn, array $rawCategories): array
{
    [$existingById, $existingByName] = productImportExistingCategories($conn);
    $items = [];
    $newByName = [];
    $seenWorkbookNames = [];
    $nextTemporaryCategoryId = -1;

    foreach ($rawCategories as $index => $raw) {
        $idRaw = productImportValue($raw, ['id', 'category_id', 'Category ID', 'Category Id']);
        $name = productImportString($raw, ['name', 'category_name', 'Category Name', 'category']);
        $description = productImportString($raw, ['description', 'Description', 'Description (Optional)']);
        $errors = [];
        $status = 'existing';
        $resolvedId = null;

        if ($name === '' && ($idRaw === null || $idRaw === '')) {
            continue;
        }

        if ($name === '') {
            $errors[] = 'Category name is required.';
        }
        if (strlen($name) > 100) {
            $errors[] = 'Category name cannot exceed 100 characters.';
        }

        if ($name !== '') {
            $nameKey = productImportLower($name);

            // Category names are the portable identity for workbook imports.
            // IDs can be stale when a template is prepared against another or
            // freshly-reset database, so resolve by name first whenever possible.
            if (isset($existingByName[$nameKey])) {
                $resolvedId = (int) $existingByName[$nameKey]['id'];
                $name = $existingByName[$nameKey]['name'];
                $status = 'existing';
            } elseif ($idRaw !== null && $idRaw !== '' && is_numeric($idRaw)) {
                $candidateId = (int) $idRaw;

                if (isset($existingById[$candidateId])
                    && productImportLower((string) $existingById[$candidateId]['name']) === $nameKey) {
                    $resolvedId = $candidateId;
                    $name = $existingById[$candidateId]['name'];
                    $status = 'existing';
                } else {
                    // The ID is not valid for this database (or points to a
                    // different category), but the workbook category name is
                    // genuinely new. Treat it as a category to create instead
                    // of rejecting all products that reference it.
                    $status = 'new';
                    if (isset($seenWorkbookNames[$nameKey])) {
                        $errors[] = 'This new category is duplicated in the Categories sheet.';
                        $status = 'invalid';
                    } else {
                        $seenWorkbookNames[$nameKey] = true;
                        $temporaryId = $nextTemporaryCategoryId--;
                        $resolvedId = $temporaryId;
                        $newByName[$nameKey] = [
                            'id' => $temporaryId,
                            'category_id' => $temporaryId,
                            'temporary_id' => $temporaryId,
                            'name' => $name,
                            'category_name' => $name,
                            'description' => $description !== '' ? $description : null,
                        ];
                    }
                }
            } else {
                $status = 'new';
                if (isset($seenWorkbookNames[$nameKey])) {
                    $errors[] = 'This new category is duplicated in the Categories sheet.';
                    $status = 'invalid';
                } else {
                    $seenWorkbookNames[$nameKey] = true;
                    $temporaryId = $nextTemporaryCategoryId--;
                    $resolvedId = $temporaryId;
                    $newByName[$nameKey] = [
                        'id' => $temporaryId,
                        'category_id' => $temporaryId,
                        'temporary_id' => $temporaryId,
                        'name' => $name,
                        'category_name' => $name,
                        'description' => $description !== '' ? $description : null,
                    ];
                }
            }
        } elseif ($idRaw !== null && $idRaw !== '' && is_numeric($idRaw)) {
            $candidateId = (int) $idRaw;
            if (!isset($existingById[$candidateId])) {
                $errors[] = "Category ID {$candidateId} does not exist and no category name was supplied.";
                $status = 'invalid';
            } else {
                $resolvedId = $candidateId;
                $name = $existingById[$candidateId]['name'];
                $status = 'existing';
            }
        }

        $items[] = [
            'row_number' => (int) (productImportValue($raw, ['row_number', '_row_number'], $index + 2)),
            'id' => $resolvedId,
            'category_id' => $resolvedId,
            'temporary_id' => ($status === 'new' && $resolvedId !== null) ? $resolvedId : null,
            'name' => $name,
            'category_name' => $name,
            'description' => $description !== '' ? $description : null,
            'status' => $errors ? 'invalid' : $status,
            'will_create' => !$errors && $status === 'new',
            'is_valid' => !$errors,
            'valid' => !$errors,
            'errors' => $errors,
        ];
    }

    return [$items, $newByName, $existingById, $existingByName];
}

function productImportBuildPreview(mysqli $conn, array $payload): array
{
    $rawRows = productImportRows($payload);
    $rawCategories = productImportCategories($payload);

    // Validate Changes may submit only the normalized product rows. Preserve
    // new-category intent from the initial preview so revalidation does not
    // suddenly turn valid products into "category does not exist" errors.
    $rowCategories = productImportCategoriesFromRows($rawRows);
    if ($rowCategories) {
        $categoryNames = [];
        foreach ($rawCategories as $category) {
            if (!is_array($category)) continue;
            $categoryName = productImportString($category, ['name', 'category_name', 'Category Name', 'category']);
            if ($categoryName !== '') {
                $categoryNames[productImportLower($categoryName)] = true;
            }
        }
        foreach ($rowCategories as $category) {
            $categoryName = productImportString($category, ['name', 'category_name']);
            $key = productImportLower($categoryName);
            if ($categoryName !== '' && !isset($categoryNames[$key])) {
                $rawCategories[] = $category;
                $categoryNames[$key] = true;
            }
        }
    }

    if (count($rawRows) > 1000) {
        throw new RuntimeException('A maximum of 1,000 products can be imported at once.', 422);
    }

    [$categoryItems, $newCategoriesByName, $existingById, $existingByName] = productImportNormalizeCategories($conn, $rawCategories);

    // New categories can also be referenced directly from Products even if the
    // Categories sheet is supplied by an older template. They remain invalid
    // unless explicitly present as a new category in the Categories sheet.
    $skus = [];
    foreach ($rawRows as $raw) {
        $sku = productImportString($raw, ['sku', 'SKU']);
        if ($sku !== '') {
            $skus[] = strtoupper($sku);
        }
    }
    $existingSkuMap = productImportExistingSkus($conn, $skus);

    $rows = [];
    $seenBatchSkus = [];
    $validCount = 0;
    $invalidCount = 0;

    foreach ($rawRows as $index => $raw) {
        $rowNumber = (int) productImportValue($raw, ['row_number', '_row_number', 'excel_row'], $index + 2);
        $categoryIdRaw = productImportValue($raw, ['category_id', 'Category ID', 'categoryId']);
        $categoryName = productImportString($raw, ['category_name', 'category', 'Category', 'Category Name']);
        $name = productImportString($raw, ['name', 'product_name', 'Product Name']);
        $sku = strtoupper(productImportString($raw, ['sku', 'SKU']));
        $description = productImportString($raw, ['description', 'Description']);
        $priceRaw = productImportValue($raw, ['unit_price', 'price', 'Unit Price']);
        $unit = productImportNormalizeUnit(productImportString($raw, ['unit_of_measure', 'unit', 'Unit of Measure', 'Unit']));
        $taxType = productImportNormalizeTaxType(productImportString($raw, ['tax_type', 'Tax Type'], 'vat'));
        $taxRateRaw = productImportValue($raw, ['tax_rate', 'Tax Rate (%)', 'Tax Rate'], $taxType === 'exempt' ? 0 : 7.5);
        $stockRaw = productImportValue($raw, ['stock_quantity', 'stock_qty', 'Stock Quantity', 'Stock Qty'], 0);
        $reorderRaw = productImportValue($raw, ['reorder_level', 'Reorder Level'], 0);

        $unitPrice = is_numeric($priceRaw) ? (float) $priceRaw : NAN;
        $taxRate = is_numeric($taxRateRaw) ? (float) $taxRateRaw : NAN;
        $stockQuantity = is_numeric($stockRaw) ? (float) $stockRaw : NAN;
        $reorderLevel = is_numeric($reorderRaw) ? (float) $reorderRaw : NAN;
        [$isActive, $statusLabel] = productImportStatus(productImportValue($raw, ['status', 'Status', 'is_active']), is_nan($stockQuantity) ? 0 : $stockQuantity);

        $errors = [];
        $resolvedCategoryId = null;
        $categoryWillCreate = false;

        if ($categoryIdRaw !== null && $categoryIdRaw !== '' && is_numeric($categoryIdRaw)) {
            $candidateId = (int) $categoryIdRaw;
            $categoryKey = $categoryName !== '' ? productImportLower($categoryName) : '';

            if ($candidateId > 0 && isset($existingById[$candidateId])) {
                $resolvedCategoryId = $candidateId;
                if ($categoryName === '') {
                    $categoryName = $existingById[$candidateId]['name'];
                }
            } elseif ($categoryKey !== '' && isset($existingByName[$categoryKey])) {
                // A workbook may carry an old/stale category ID. Prefer the
                // current database category when the supplied name matches.
                $resolvedCategoryId = (int) $existingByName[$categoryKey]['id'];
                $categoryName = $existingByName[$categoryKey]['name'];
            } elseif ($categoryKey !== '' && isset($newCategoriesByName[$categoryKey])) {
                // New workbook categories use a temporary negative ID during
                // preview so the frontend can keep the category selected and
                // still distinguish it from an existing database category.
                $categoryWillCreate = true;
                $resolvedCategoryId = (int) $newCategoriesByName[$categoryKey]['temporary_id'];
                $categoryName = $newCategoriesByName[$categoryKey]['name'];
            } else {
                $errors[] = "Category ID {$candidateId} does not exist.";
            }
        } elseif ($categoryName !== '') {
            $categoryKey = productImportLower($categoryName);
            if (isset($existingByName[$categoryKey])) {
                $resolvedCategoryId = (int) $existingByName[$categoryKey]['id'];
                $categoryName = $existingByName[$categoryKey]['name'];
            } elseif (isset($newCategoriesByName[$categoryKey])) {
                $categoryWillCreate = true;
                $resolvedCategoryId = (int) $newCategoriesByName[$categoryKey]['temporary_id'];
                $categoryName = $newCategoriesByName[$categoryKey]['name'];
            } else {
                $errors[] = "Category '{$categoryName}' does not exist. Add it to the Categories sheet first.";
            }
        } else {
            $errors[] = 'Category is required.';
        }

        if ($name === '') $errors[] = 'Product name is required.';
        if ($name !== '' && strlen($name) > 200) $errors[] = 'Product name cannot exceed 200 characters.';
        if ($sku === '') $errors[] = 'SKU is required.';
        if ($sku !== '' && strlen($sku) > 100) $errors[] = 'SKU cannot exceed 100 characters.';

        if ($sku !== '') {
            $skuKey = strtoupper($sku);
            if (isset($seenBatchSkus[$skuKey])) {
                $errors[] = "SKU '{$sku}' appears more than once in this import.";
            } else {
                $seenBatchSkus[$skuKey] = true;
            }
            if (isset($existingSkuMap[$skuKey])) {
                $errors[] = "SKU '{$sku}' already exists in the database.";
            }
        }

        if (is_nan($unitPrice) || $unitPrice < 0) $errors[] = 'Unit price must be a valid non-negative number.';
        if (!is_nan($unitPrice) && $unitPrice > 9999999999999.99) $errors[] = 'Unit price exceeds the supported database limit.';
        if (!in_array($unit, ['single', 'set', 'carton', 'dozen'], true)) $errors[] = "Unit of measure '{$unit}' is invalid.";
        if (!in_array($taxType, ['vat', 'exempt'], true)) $errors[] = "Tax type '{$taxType}' is invalid.";
        if (is_nan($taxRate) || $taxRate < 0 || $taxRate > 100) $errors[] = 'Tax rate must be between 0 and 100.';
        if ($taxType === 'exempt') $taxRate = 0.0;
        if (is_nan($stockQuantity) || $stockQuantity < 0 || $stockQuantity > 99999999.99) $errors[] = 'Stock quantity must be between 0 and 99,999,999.99.';
        if (is_nan($reorderLevel) || $reorderLevel < 0 || $reorderLevel > 99999999.99) $errors[] = 'Reorder level must be between 0 and 99,999,999.99.';
        if ($isActive === -1) $errors[] = "Status '{$statusLabel}' is invalid. Use Active or Inactive.";

        $valid = count($errors) === 0;
        $valid ? $validCount++ : $invalidCount++;

        $categoryDescription = null;
        if ($categoryName !== '') {
            $categoryKeyForDescription = productImportLower($categoryName);
            if ($categoryWillCreate && isset($newCategoriesByName[$categoryKeyForDescription])) {
                $categoryDescription = $newCategoriesByName[$categoryKeyForDescription]['description'] ?? null;
            } elseif (isset($existingByName[$categoryKeyForDescription])) {
                $categoryDescription = $existingByName[$categoryKeyForDescription]['description'] ?? null;
            }
        }

        $row = [
            'row_number' => $rowNumber,
            'category_id' => $resolvedCategoryId,
            'category_name' => $categoryName,
            'category' => $categoryName,
            'category_description' => $categoryDescription,
            'category_will_create' => $categoryWillCreate,
            'name' => $name,
            'product_name' => $name,
            'sku' => $sku,
            'description' => $description !== '' ? $description : null,
            'unit_price' => is_nan($unitPrice) ? $priceRaw : $unitPrice,
            'unit_of_measure' => $unit,
            'unit' => $unit,
            'tax_type' => $taxType,
            'tax_rate' => is_nan($taxRate) ? $taxRateRaw : $taxRate,
            'stock_quantity' => is_nan($stockQuantity) ? $stockRaw : $stockQuantity,
            'stock_qty' => is_nan($stockQuantity) ? $stockRaw : $stockQuantity,
            'reorder_level' => is_nan($reorderLevel) ? $reorderRaw : $reorderLevel,
            'is_active' => $isActive === -1 ? null : $isActive,
            'status' => $isActive === -1 ? $statusLabel : ($isActive ? 'active' : 'inactive'),
            'is_valid' => $valid,
            'valid' => $valid,
            'validation_status' => $valid ? 'valid' : 'invalid',
            'errors' => $errors,
            'validation_errors' => $errors,
        ];
        $rows[] = $row;
    }

    $invalidCategories = array_values(array_filter($categoryItems, static fn(array $item): bool => !$item['is_valid']));
    $newCategories = array_values(array_filter($categoryItems, static fn(array $item): bool => $item['will_create']));
    $canImport = count($rows) > 0 && $invalidCount === 0 && count($invalidCategories) === 0;

    $summary = [
        'total' => count($rows),
        'total_rows' => count($rows),
        'valid' => $validCount,
        'valid_count' => $validCount,
        'invalid' => $invalidCount,
        'invalid_count' => $invalidCount,
        'invalid_categories' => count($invalidCategories),
        'new_categories' => count($newCategories),
        'new_category_count' => count($newCategories),
        'can_import' => $canImport,
        'canImport' => $canImport,
    ];

    // Keep the preview contract explicit for the React import screen.  The
    // importer previously returned a flatter payload than the frontend
    // expected, which left category selects empty and the Import button
    // disabled even when every row was valid.
    $categoryOptionsByName = [];
    foreach ($existingByName as $key => $category) {
        $categoryOptionsByName[$key] = [
            'id' => (int) $category['id'],
            'category_id' => (int) $category['id'],
            'name' => (string) $category['name'],
            'is_new' => false,
            'will_create' => false,
        ];
    }
    foreach ($newCategories as $category) {
        $name = (string) ($category['name'] ?? $category['category_name'] ?? '');
        if ($name === '') continue;
        $key = productImportLower($name);
        $categoryOptionsByName[$key] = [
            'id' => $category['category_id'] ?? $category['temporary_id'] ?? null,
            'category_id' => $category['category_id'] ?? $category['temporary_id'] ?? null,
            'name' => $name,
            'is_new' => true,
            'will_create' => true,
        ];
    }
    $categoryOptions = array_values($categoryOptionsByName);
    usort($categoryOptions, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

    $preview = [
        'rows' => $rows,
        'products' => $rows,
        'categories' => $categoryItems,
        'category_rows' => $categoryItems,
        'new_categories' => $newCategories,
        'newCategories' => $newCategories,
        'options' => ['categories' => $categoryOptions],
        'summary' => $summary,
        'can_import' => $canImport,
        'canImport' => $canImport,
    ];

    return $preview + ['preview' => $preview];
}
