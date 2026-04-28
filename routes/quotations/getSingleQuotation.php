<?php
// routes/quotations/getSingleQuotation.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /quotations/{id}
 * Get single quotation details with all line items.
 * Roles allowed: Admin, Sales
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];

    // Only Admin and Sales can view quotation details
    if (!in_array($loggedInUserRole, ['admin', 'sales'])) {
        throw new Exception("Unauthorized: You do not have permission to view this quotation", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Quotation ID
    // -------------------------------------------------------
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Quotation ID is required.", 400);
    }
    
    $quotationId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 2. Fetch Quotation Details
    // -------------------------------------------------------
    $quotationQuery = "
        SELECT 
            q.id, 
            q.quotation_number, 
            q.client_id,
            c.company_name AS client_name,
            c.city AS client_city,
            c.state AS client_state,
            c.country AS client_country,
            c.email AS client_email,
            c.phone AS client_phone,
            c.billing_address AS client_address,
            c.currency AS client_currency,
            c.payment_terms AS client_payment_terms,
            q.created_by,
            u.name AS created_by_name,
            q.issue_date, 
            q.expiry_date, 
            q.currency, 
            q.exchange_rate,
            q.subtotal, 
            q.discount_type,
            q.discount_value,
            q.discount_amount,
            q.taxable_amount,
            q.tax_amount, 
            q.total_amount, 
            q.notes, 
            q.status, 
            q.created_at,
            q.updated_at
        FROM quotations q
        JOIN clients c ON c.id = q.client_id
        LEFT JOIN users u ON u.id = q.created_by
        WHERE q.id = ?
        LIMIT 1
    ";

    $quotationStmt = $conn->prepare($quotationQuery);
    if (!$quotationStmt) {
        throw new Exception("Database query failed: " . $conn->error, 500);
    }

    $quotationStmt->bind_param("i", $quotationId);
    $quotationStmt->execute();
    $quotationResult = $quotationStmt->get_result();

    if ($quotationResult->num_rows === 0) {
        throw new Exception("Quotation not found.", 404);
    }

    $quotation = $quotationResult->fetch_assoc();
    $quotationStmt->close();

    // Sales can only view their own quotations
    if ($loggedInUserRole === 'sales' && (int)$quotation['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You do not have permission to view this quotation", 403);
    }

    // -------------------------------------------------------
    // 3. Fetch Line Items
    // -------------------------------------------------------
    $itemsQuery = "
        SELECT 
            qi.id,
            qi.product_id,
            qi.description,
            qi.quantity,
            qi.unit_price,
            qi.tax_rate,
            qi.tax_amount,
            qi.discount_type,
            qi.discount_value,
            qi.discount_amount,
            qi.line_total,
            qi.sort_order,
            p.name AS product_name,
            p.sku AS product_sku,
            p.unit_of_measure AS product_uom
        FROM quotation_items qi
        LEFT JOIN products p ON p.id = qi.product_id
        WHERE qi.quotation_id = ?
        ORDER BY qi.sort_order ASC, qi.id ASC
    ";

    $itemsStmt = $conn->prepare($itemsQuery);
    $itemsStmt->bind_param("i", $quotationId);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();

    $items = [];
    while ($item = $itemsResult->fetch_assoc()) {
        $items[] = [
            "id"             => (int)$item['id'],
            "product_id"     => $item['product_id'] ? (int)$item['product_id'] : null,
            "product_name"   => $item['product_name'],
            "product_sku"    => $item['product_sku'],
            "product_uom"    => $item['product_uom'],
            "description"    => $item['description'],
            "quantity"       => (float)$item['quantity'],
            "unit_price"     => (float)$item['unit_price'],
            "tax_rate"       => (float)$item['tax_rate'],
            "tax_amount"     => (float)$item['tax_amount'],
            "discount_type"  => $item['discount_type'],
            "discount_value" => (float)$item['discount_value'],
            "discount_amount"=> (float)$item['discount_amount'],
            "line_total"     => (float)$item['line_total'],
            "sort_order"     => (int)$item['sort_order']
        ];
    }
    $itemsStmt->close();

    // Check if expired
    $isExpired = false;
    if ($quotation['status'] === 'sent' && strtotime($quotation['expiry_date']) < strtotime(date('Y-m-d'))) {
        $isExpired = true;
    }

    // -------------------------------------------------------
    // 4. Return Response
    // -------------------------------------------------------
    $formattedQuotation = [
        "id"               => (int)$quotation['id'],
        "quotation_number" => $quotation['quotation_number'],
        "client"           => [
            "id"            => (int)$quotation['client_id'],
            "company_name"  => $quotation['client_name'],
            "city"          => $quotation['client_city'],
            "state"         => $quotation['client_state'],
            "country"       => $quotation['client_country'],
            "email"         => $quotation['client_email'],
            "phone"         => $quotation['client_phone'],
            "address"       => $quotation['client_address'],
            "currency"      => $quotation['client_currency'],
            "payment_terms" => $quotation['client_payment_terms']
        ],
        "created_by"       => [
            "id"   => (int)$quotation['created_by'],
            "name" => $quotation['created_by_name']
        ],
        "issue_date"       => $quotation['issue_date'],
        "expiry_date"      => $quotation['expiry_date'],
        "is_expired"       => $isExpired,
        "currency"         => $quotation['currency'],
        "exchange_rate"    => (float)$quotation['exchange_rate'],
        "subtotal"         => (float)$quotation['subtotal'],
        "discount_type"    => $quotation['discount_type'],
        "discount_value"   => (float)$quotation['discount_value'],
        "discount_amount"  => (float)$quotation['discount_amount'],
        "taxable_amount"   => (float)$quotation['taxable_amount'],
        "tax_amount"       => (float)$quotation['tax_amount'],
        "total_amount"     => (float)$quotation['total_amount'],
        "notes"            => $quotation['notes'],
        "status"           => $quotation['status'],
        "items"            => $items,
        "created_at"       => $quotation['created_at'],
        "updated_at"       => $quotation['updated_at']
    ];

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Quotation fetched successfully",
        "data"    => $formattedQuotation
    ]);

} catch (Exception $e) {
    error_log("Get Single Quotation Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>