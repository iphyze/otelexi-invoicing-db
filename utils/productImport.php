<?php

declare(strict_types=1);

/**
 * Shared validation/normalisation helpers for bulk product imports.
 *
 * The frontend parses the Excel file and sends rows as JSON. This keeps the
 * API independent from a server-side spreadsheet library while still making
 * the backend the source of truth for validation before preview and commit.
 */

function productImportAllowedUnits(): array
{
    return ['single', 'set', 'carton', 'dozen'];
}

function productImportAllowedTaxTypes(): array
{
    return ['vat', 'exempt'];
}

function productImportMaximumUnitPrice(): float
{
    // products.unit_price is DECIMAL(15,2) => 13 integer digits + 2 decimal places.
    return 9999999999999.99;
}

function productImportMaximumStockValue(): float
{
    // products.stock_quantity/reorder_level are DECIMAL(10,2).
    return 99999999.99;
}

function productImportString(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_array($value) || is_object($value) || is_resource($value)) {
        return '';
    }

    return trim((string) $value);
}

function productImportKey(mixed $value): string
{
    $value = productImportString($value);
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function productImportNumber(mixed $value): ?float
{
    if (is_int($value) || is_float($value)) {
        return is_finite((float) $value) ? (float) $value : null;
    }

    $raw = productImportString($value);
    if ($raw === '') {
        return null;
    }

    // Friendly handling for values copied from formatted spreadsheets.
    $normalised = str_replace([',', '₦', ' '], '', $raw);
    if (str_ends_with($normalised, '%')) {
        $normalised = substr($normalised, 0, -1);
    }

    if ($normalised === '' || !is_numeric($normalised)) {
        return null;
    }

    return (float) $normalised;
}

function productImportBoolean(mixed $value, bool $default = true): ?bool
{
    if ($value === null || productImportString($value) === '') {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        if ((float) $value === 1.0) return true;
        if ((float) $value === 0.0) return false;
        return null;
    }

    $normalised = strtolower(productImportString($value));
    if (in_array($normalised, ['1', 'true', 'yes', 'y', 'active'], true)) {
        return true;
    }
    if (in_array($normalised, ['0', 'false', 'no', 'n', 'inactive'], true)) {
        return false;
    }

    return null;
}

function productImportAddError(array &$errors, string $field, string $message): void
{
    $errors[] = [
        'field' => $field,
        'message' => $message,
    ];
}

function productImportCategoryMaps(mysqli $conn): array
{
    $result = $conn->query("SELECT id, name, description FROM product_categories ORDER BY name ASC");
    if (!$result) {
        throw new Exception('Failed to load product categories.', 500);
    }

    $byId = [];
    $byName = [];
    $categories = [];

    while ($row = $result->fetch_assoc()) {
        $category = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => $row['description'] !== null ? (string) $row['description'] : '',
            'is_new' => false,
        ];
        $byId[$category['id']] = $category;
        $byName[productImportKey($category['name'])] = $category;
        $categories[] = $category;
    }

    return [
        'by_id' => $byId,
        'by_name' => $byName,
        'categories' => $categories,
    ];
}

function productImportExistingSkuMap(mysqli $conn, array $skus): array
{
    $uniqueSkus = [];
    foreach ($skus as $sku) {
        $sku = trim((string) $sku);
        if ($sku !== '') {
            $uniqueSkus[strtolower($sku)] = $sku;
        }
    }

    if (!$uniqueSkus) {
        return [];
    }

    $values = array_values($uniqueSkus);
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    $types = str_repeat('s', count($values));

    $stmt = $conn->prepare("SELECT id, sku FROM products WHERE sku IN ($placeholders)");
    if (!$stmt) {
        throw new Exception('Failed to validate existing product SKUs.', 500);
    }

    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $result = $stmt->get_result();

    $existing = [];
    while ($row = $result->fetch_assoc()) {
        $existing[strtolower((string) $row['sku'])] = [
            'id' => (int) $row['id'],
            'sku' => (string) $row['sku'],
        ];
    }
    $stmt->close();

    return $existing;
}

/**
 * Validate the Categories sheet.
 *
 * Existing template rows are accepted when their Category ID and name still
 * match the database. A row with a blank Category ID becomes a proposed new
 * category, unless that category already exists in the database (for example
 * if another user created it after the workbook was downloaded), in which
 * case it is safely matched to the existing record instead of being duplicated.
 */
function validateProductImportCategories(
    mysqli $conn,
    array $categoryRows,
    ?array $categoryMaps = null,
    int $maxRows = 5000
): array {
    $categoryRows = array_values($categoryRows);

    if (count($categoryRows) > $maxRows) {
        throw new Exception("A maximum of {$maxRows} category rows can be included in an import workbook.", 422);
    }

    $categoryMaps = $categoryMaps ?? productImportCategoryMaps($conn);

    $nameCounts = [];
    foreach ($categoryRows as $row) {
        if (!is_array($row)) continue;
        $name = productImportString($row['name'] ?? ($row['category_name'] ?? ''));
        if ($name === '') continue;
        $key = productImportKey($name);
        $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
    }

    $normalisedRows = [];
    $validCount = 0;
    $invalidCount = 0;
    $newCount = 0;
    $existingCount = 0;
    $validNewByName = [];
    $rowByName = [];

    foreach ($categoryRows as $index => $row) {
        $rowNumber = $index + 2;
        $errors = [];

        if (!is_array($row)) {
            $normalisedRows[] = [
                'row_number' => $rowNumber,
                'valid' => false,
                'is_new' => false,
                'matched_existing' => false,
                'errors' => [[
                    'field' => 'row',
                    'message' => 'This category row could not be read.',
                ]],
                'data' => null,
            ];
            $invalidCount++;
            continue;
        }

        if (isset($row['row_number']) && is_numeric($row['row_number'])) {
            $rowNumber = max(2, (int) $row['row_number']);
        }

        $categoryIdRaw = productImportString($row['category_id'] ?? '');
        $name = productImportString($row['name'] ?? ($row['category_name'] ?? ''));
        $description = productImportString($row['description'] ?? '');

        // Ignore fully blank rows if a client sends them despite spreadsheet cleanup.
        if ($categoryIdRaw === '' && $name === '' && $description === '') {
            continue;
        }

        if ($name === '') {
            productImportAddError($errors, 'name', 'Category name is required.');
        } elseif (strlen($name) > 100) {
            productImportAddError($errors, 'name', 'Category name cannot exceed 100 characters.');
        }

        $nameKey = productImportKey($name);
        if ($nameKey !== '' && ($nameCounts[$nameKey] ?? 0) > 1) {
            productImportAddError($errors, 'name', 'This category appears more than once in the Categories sheet.');
        }

        $resolvedCategory = null;
        $isNew = false;
        $matchedExisting = false;

        if ($categoryIdRaw !== '') {
            if (!ctype_digit($categoryIdRaw) || (int) $categoryIdRaw <= 0) {
                productImportAddError($errors, 'category_id', 'Category ID must be a valid positive number or left blank for a new category.');
            } else {
                $categoryId = (int) $categoryIdRaw;
                $resolvedCategory = $categoryMaps['by_id'][$categoryId] ?? null;
                if (!$resolvedCategory) {
                    productImportAddError($errors, 'category_id', 'This Category ID no longer exists. Download a fresh template or leave the ID blank for a new category.');
                } elseif ($name !== '' && productImportKey($resolvedCategory['name']) !== $nameKey) {
                    productImportAddError($errors, 'name', 'Category ID and category name do not match. Existing category rows should not be renamed in the workbook.');
                }
            }
        } elseif ($name !== '') {
            $resolvedCategory = $categoryMaps['by_name'][$nameKey] ?? null;
            if ($resolvedCategory) {
                // Safe race/stale-template behaviour: do not create a duplicate if
                // the same category was created after this workbook was downloaded.
                $matchedExisting = true;
            } else {
                $isNew = true;
            }
        }

        $isValid = count($errors) === 0;
        if ($isValid) {
            $validCount++;
            if ($isNew) {
                $newCount++;
            } else {
                $existingCount++;
            }
        } else {
            $invalidCount++;
        }

        $data = [
            'row_number' => $rowNumber,
            'category_id' => $resolvedCategory['id'] ?? null,
            'name' => $resolvedCategory['name'] ?? $name,
            'description' => $isNew ? $description : ($resolvedCategory['description'] ?? $description),
        ];

        $normalised = [
            'row_number' => $rowNumber,
            'valid' => $isValid,
            'is_new' => $isNew,
            'matched_existing' => $matchedExisting,
            'errors' => $errors,
            'data' => $data,
        ];
        $normalisedRows[] = $normalised;

        if ($nameKey !== '') {
            $rowByName[$nameKey] = $normalised;
        }

        if ($isValid && $isNew && $nameKey !== '') {
            $validNewByName[$nameKey] = [
                'id' => null,
                'name' => $name,
                'description' => $description,
                'is_new' => true,
            ];
        }
    }

    return [
        'rows' => $normalisedRows,
        'summary' => [
            'total' => count($normalisedRows),
            'valid' => $validCount,
            'invalid' => $invalidCount,
            'new' => $newCount,
            'existing' => $existingCount,
            'can_create' => $invalidCount === 0,
        ],
        'valid_new_by_name' => $validNewByName,
        'row_by_name' => $rowByName,
    ];
}

/**
 * Validate and normalise import rows plus any category rows parsed from the
 * workbook's Categories sheet.
 *
 * Accepted product keys:
 * category_id OR category/category_name, name, sku, description, unit_price,
 * unit_of_measure, tax_type, tax_rate, stock_quantity, reorder_level, is_active.
 */
function validateProductImportRows(
    mysqli $conn,
    array $rows,
    array $categoryRows = [],
    int $maxRows = 1000
): array {
    $rows = array_values($rows);

    if (!$rows) {
        throw new Exception('No product rows were provided for import.', 422);
    }

    if (count($rows) > $maxRows) {
        throw new Exception("A maximum of {$maxRows} products can be imported at once.", 422);
    }

    $categoryMaps = productImportCategoryMaps($conn);
    $categoryValidation = validateProductImportCategories($conn, $categoryRows, $categoryMaps);

    $skuCounts = [];
    $candidateSkus = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $sku = productImportString($row['sku'] ?? '');
        if ($sku === '') continue;
        $key = strtolower($sku);
        $skuCounts[$key] = ($skuCounts[$key] ?? 0) + 1;
        $candidateSkus[] = $sku;
    }

    $existingSkus = productImportExistingSkuMap($conn, $candidateSkus);

    $normalisedRows = [];
    $validCount = 0;
    $invalidCount = 0;

    foreach ($rows as $index => $row) {
        $rowNumber = $index + 2; // Header is row 1 in the spreadsheet template.
        $errors = [];

        if (!is_array($row)) {
            $normalisedRows[] = [
                'row_number' => $rowNumber,
                'valid' => false,
                'errors' => [[
                    'field' => 'row',
                    'message' => 'This row could not be read.',
                ]],
                'data' => null,
            ];
            $invalidCount++;
            continue;
        }

        if (isset($row['row_number']) && is_numeric($row['row_number'])) {
            $rowNumber = max(2, (int) $row['row_number']);
        }

        $name = productImportString($row['name'] ?? '');
        $sku = strtoupper(productImportString($row['sku'] ?? ''));
        $description = productImportString($row['description'] ?? '');

        if ($name === '') {
            productImportAddError($errors, 'name', 'Product name is required.');
        } elseif (strlen($name) > 200) {
            productImportAddError($errors, 'name', 'Product name cannot exceed 200 characters.');
        }

        if ($sku === '') {
            productImportAddError($errors, 'sku', 'SKU is required.');
        } elseif (strlen($sku) > 100) {
            productImportAddError($errors, 'sku', 'SKU cannot exceed 100 characters.');
        } else {
            $skuKey = strtolower($sku);
            if (($skuCounts[$skuKey] ?? 0) > 1) {
                productImportAddError($errors, 'sku', 'This SKU appears more than once in the import file.');
            }
            if (isset($existingSkus[$skuKey])) {
                productImportAddError($errors, 'sku', 'A product with this SKU already exists.');
            }
        }

        // Resolve category using an existing ID first, then an existing name,
        // then a valid new category proposed on the Categories sheet.
        $categoryIdRaw = $row['category_id'] ?? null;
        $categoryNameRaw = productImportString($row['category_name'] ?? ($row['category'] ?? ''));
        $resolvedCategory = null;
        $categoryIsNew = false;

        if ($categoryIdRaw !== null && productImportString($categoryIdRaw) !== '') {
            if (!is_numeric($categoryIdRaw) || (int) $categoryIdRaw <= 0) {
                productImportAddError($errors, 'category_id', 'Category must be a valid category.');
            } else {
                $categoryId = (int) $categoryIdRaw;
                $resolvedCategory = $categoryMaps['by_id'][$categoryId] ?? null;
                if (!$resolvedCategory) {
                    productImportAddError($errors, 'category_id', 'Selected category does not exist.');
                } elseif ($categoryNameRaw !== '' && productImportKey($resolvedCategory['name']) !== productImportKey($categoryNameRaw)) {
                    productImportAddError($errors, 'category_id', 'Category ID and category name do not match.');
                }
            }
        } elseif ($categoryNameRaw !== '') {
            $categoryNameKey = productImportKey($categoryNameRaw);
            $resolvedCategory = $categoryMaps['by_name'][$categoryNameKey] ?? null;

            if (!$resolvedCategory) {
                $newCategory = $categoryValidation['valid_new_by_name'][$categoryNameKey] ?? null;
                if ($newCategory) {
                    $resolvedCategory = $newCategory;
                    $categoryIsNew = true;
                } else {
                    $categorySheetRow = $categoryValidation['row_by_name'][$categoryNameKey] ?? null;
                    if ($categorySheetRow && !$categorySheetRow['valid']) {
                        productImportAddError($errors, 'category_id', "Category '{$categoryNameRaw}' has an error on the Categories sheet.");
                    } else {
                        productImportAddError($errors, 'category_id', "Category '{$categoryNameRaw}' does not exist. Add it to the Categories sheet with a blank Category ID, or use an existing category.");
                    }
                }
            }
        } else {
            productImportAddError($errors, 'category_id', 'Category is required.');
        }

        $unitPrice = productImportNumber($row['unit_price'] ?? null);
        if ($unitPrice === null) {
            productImportAddError($errors, 'unit_price', 'Unit price must be a valid number.');
        } elseif ($unitPrice < 0) {
            productImportAddError($errors, 'unit_price', 'Unit price cannot be negative.');
        } elseif ($unitPrice > productImportMaximumUnitPrice()) {
            productImportAddError($errors, 'unit_price', 'Unit price is too large for the product record.');
        }

        $unitOfMeasure = strtolower(productImportString($row['unit_of_measure'] ?? ''));
        if ($unitOfMeasure === '') {
            productImportAddError($errors, 'unit_of_measure', 'Unit of measure is required.');
        } elseif (!in_array($unitOfMeasure, productImportAllowedUnits(), true)) {
            productImportAddError($errors, 'unit_of_measure', 'Unit of measure must be single, set, carton, or dozen.');
        }

        $taxType = strtolower(productImportString($row['tax_type'] ?? 'vat'));
        if (!in_array($taxType, productImportAllowedTaxTypes(), true)) {
            productImportAddError($errors, 'tax_type', 'Tax type must be vat or exempt.');
        }

        $taxRate = productImportNumber($row['tax_rate'] ?? ($taxType === 'exempt' ? 0 : 7.5));
        if ($taxRate === null || $taxRate < 0 || $taxRate > 100) {
            productImportAddError($errors, 'tax_rate', 'Tax rate must be a number between 0 and 100.');
        }
        if ($taxType === 'exempt') {
            $taxRate = 0.0;
        }

        $stockQuantity = productImportNumber($row['stock_quantity'] ?? 0);
        if ($stockQuantity === null || $stockQuantity < 0) {
            productImportAddError($errors, 'stock_quantity', 'Stock quantity must be zero or greater.');
        } elseif ($stockQuantity > productImportMaximumStockValue()) {
            productImportAddError($errors, 'stock_quantity', 'Stock quantity is too large for the product record.');
        }

        $reorderLevel = productImportNumber($row['reorder_level'] ?? 0);
        if ($reorderLevel === null || $reorderLevel < 0) {
            productImportAddError($errors, 'reorder_level', 'Reorder level must be zero or greater.');
        } elseif ($reorderLevel > productImportMaximumStockValue()) {
            productImportAddError($errors, 'reorder_level', 'Reorder level is too large for the product record.');
        }

        $isActive = productImportBoolean($row['is_active'] ?? ($row['status'] ?? null), true);
        if ($isActive === null) {
            productImportAddError($errors, 'is_active', 'Status must be Active or Inactive.');
        }

        $data = [
            'row_number' => $rowNumber,
            'category_id' => $resolvedCategory['id'] ?? null,
            'category_name' => $resolvedCategory['name'] ?? $categoryNameRaw,
            'category_is_new' => $categoryIsNew,
            'name' => $name,
            'sku' => $sku,
            'description' => $description,
            'unit_price' => $unitPrice ?? 0.0,
            'unit_of_measure' => $unitOfMeasure,
            'tax_type' => in_array($taxType, productImportAllowedTaxTypes(), true) ? $taxType : 'vat',
            'tax_rate' => $taxRate ?? 0.0,
            'stock_quantity' => $stockQuantity ?? 0.0,
            'reorder_level' => $reorderLevel ?? 0.0,
            'is_active' => $isActive === null ? 1 : ($isActive ? 1 : 0),
        ];

        $isValid = count($errors) === 0;
        if ($isValid) {
            $validCount++;
        } else {
            $invalidCount++;
        }

        $normalisedRows[] = [
            'row_number' => $rowNumber,
            'valid' => $isValid,
            'errors' => $errors,
            'data' => $data,
        ];
    }

    $categoryOptions = $categoryMaps['categories'];
    foreach ($categoryValidation['valid_new_by_name'] as $newCategory) {
        $categoryOptions[] = $newCategory;
    }

    $categoryInvalidCount = (int) $categoryValidation['summary']['invalid'];
    $publicCategoryValidation = $categoryValidation;
    unset($publicCategoryValidation['valid_new_by_name'], $publicCategoryValidation['row_by_name']);

    return [
        'rows' => $normalisedRows,
        'categories' => $publicCategoryValidation,
        'summary' => [
            'total' => count($normalisedRows),
            'valid' => $validCount,
            'invalid' => $invalidCount,
            'new_categories' => (int) $categoryValidation['summary']['new'],
            'invalid_categories' => $categoryInvalidCount,
            'can_import' => $invalidCount === 0 && $categoryInvalidCount === 0 && $validCount > 0,
        ],
        'options' => [
            'categories' => $categoryOptions,
            'unit_of_measure' => productImportAllowedUnits(),
            'tax_type' => productImportAllowedTaxTypes(),
        ],
    ];
}
