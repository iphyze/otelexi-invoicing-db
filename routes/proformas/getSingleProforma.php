<?php
// routes/proformas/getSingleProforma.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /proformas/{id}
 * Get a single proforma invoice with all line items and client details.
 * Roles allowed: Admin, Sales
 *
 * Query param: ?id=5
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData         = authenticateUser();
    $loggedInUserId   = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];

    if (!in_array($loggedInUserRole, ['admin', 'sales'])) {
        throw new Exception("Unauthorized: You do not have permission to view proforma invoices.", 403);
    }

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Proforma ID is required.", 400);
    }
    $proformaId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 1. Fetch proforma header
    // -------------------------------------------------------
    $proformaStmt = $conn->prepare("
        SELECT
            p.id, p.proforma_number, p.quotation_id,
            q.quotation_number,
            p.client_id,
            c.company_name AS client_name,
            c.billing_address AS client_address,
            c.city AS client_city, c.state AS client_state, c.country AS client_country,
            c.email AS client_email, c.phone AS client_phone,
            c.currency AS client_currency, c.payment_terms AS client_payment_terms,
            p.created_by, u.name AS created_by_name,
            p.issue_date, p.expiry_date,
            p.currency, p.exchange_rate,
            p.subtotal, p.discount_type, p.discount_value, p.discount_amount,
            p.taxable_amount, p.tax_amount, p.total_amount,
            p.notes, p.status, p.created_at, p.updated_at
        FROM proforma_invoices p
        JOIN clients c ON c.id = p.client_id
        LEFT JOIN users u ON u.id = p.created_by
        LEFT JOIN quotations q ON q.id = p.quotation_id
        WHERE p.id = ?
        LIMIT 1
    ");
    if (!$proformaStmt) throw new Exception("Database query failed: " . $conn->error, 500);

    $proformaStmt->bind_param("i", $proformaId);
    $proformaStmt->execute();
    $proformaResult = $proformaStmt->get_result();

    if ($proformaResult->num_rows === 0) {
        throw new Exception("Proforma invoice not found.", 404);
    }

    $proforma = $proformaResult->fetch_assoc();
    $proformaStmt->close();

    // Sales can only view their own
    if ($loggedInUserRole === 'sales' && (int)$proforma['created_by'] !== $loggedInUserId) {
        throw new Exception("Unauthorized: You do not have permission to view this proforma.", 403);
    }

    // -------------------------------------------------------
    // 2. Fetch line items
    // -------------------------------------------------------
    $itemsStmt = $conn->prepare("
        SELECT
            pi.id, pi.product_id, pi.description,
            pi.quantity, pi.unit_price,
            pi.tax_rate, pi.tax_amount,
            pi.discount_type, pi.discount_value, pi.discount_amount,
            pi.line_total, pi.sort_order,
            pr.name AS product_name, pr.sku AS product_sku,
            pr.unit_of_measure AS product_uom
        FROM proforma_items pi
        LEFT JOIN products pr ON pr.id = pi.product_id
        WHERE pi.proforma_id = ?
        ORDER BY pi.sort_order ASC, pi.id ASC
    ");
    $itemsStmt->bind_param("i", $proformaId);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();

    $items = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = [
            "id"              => (int)$row['id'],
            "product_id"      => $row['product_id'] ? (int)$row['product_id'] : null,
            "product_name"    => $row['product_name'],
            "product_sku"     => $row['product_sku'],
            "product_uom"     => $row['product_uom'],
            "description"     => $row['description'],
            "quantity"        => (float)$row['quantity'],
            "unit_price"      => (float)$row['unit_price'],
            "tax_rate"        => (float)$row['tax_rate'],
            "tax_amount"      => (float)$row['tax_amount'],
            "discount_type"   => $row['discount_type'],
            "discount_value"  => (float)$row['discount_value'],
            "discount_amount" => (float)$row['discount_amount'],
            "line_total"      => (float)$row['line_total'],
            "sort_order"      => (int)$row['sort_order']
        ];
    }
    $itemsStmt->close();

    $today     = date('Y-m-d');
    $isExpired = ($proforma['status'] === 'sent' && $proforma['expiry_date'] < $today);

    // -------------------------------------------------------
    // 3. Compose response
    // -------------------------------------------------------
    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Proforma invoice fetched successfully.",
        "data"    => [
            "id"               => (int)$proforma['id'],
            "proforma_number"  => $proforma['proforma_number'],
            "quotation_id"     => $proforma['quotation_id'] ? (int)$proforma['quotation_id'] : null,
            "quotation_number" => $proforma['quotation_number'],
            "client"           => [
                "id"            => (int)$proforma['client_id'],
                "company_name"  => $proforma['client_name'],
                "address"       => $proforma['client_address'],
                "city"          => $proforma['client_city'],
                "state"         => $proforma['client_state'],
                "country"       => $proforma['client_country'],
                "email"         => $proforma['client_email'],
                "phone"         => $proforma['client_phone'],
                "currency"      => $proforma['client_currency'],
                "payment_terms" => $proforma['client_payment_terms']
            ],
            "created_by"       => [
                "id"   => (int)$proforma['created_by'],
                "name" => $proforma['created_by_name']
            ],
            "issue_date"       => $proforma['issue_date'],
            "expiry_date"      => $proforma['expiry_date'],
            "is_expired"       => $isExpired,
            "currency"         => $proforma['currency'],
            "exchange_rate"    => (float)$proforma['exchange_rate'],
            "subtotal"         => (float)$proforma['subtotal'],
            "discount_type"    => $proforma['discount_type'],
            "discount_value"   => (float)$proforma['discount_value'],
            "discount_amount"  => (float)$proforma['discount_amount'],
            "taxable_amount"   => (float)$proforma['taxable_amount'],
            "tax_amount"       => (float)$proforma['tax_amount'],
            "total_amount"     => (float)$proforma['total_amount'],
            "notes"            => $proforma['notes'],
            "status"           => $proforma['status'],
            "items"            => $items,
            "created_at"       => $proforma['created_at'],
            "updated_at"       => $proforma['updated_at']
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Single Proforma Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
