<?php
// routes/clients/getSingleClient.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /client?id=1
 * Get single client details with their contact persons.
 * Roles allowed: Admin, Sales, Accounting
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    // Only Admin, Sales, and Accounting can view client details
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales', 'accounting'])) {
        throw new Exception("Unauthorized: You do not have permission to view this client", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Client ID
    // -------------------------------------------------------
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Client ID is required.", 400);
    }
    
    $clientId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 2. Fetch Main Client Details
    // -------------------------------------------------------
    $clientQuery = "
        SELECT 
            id, company_name, billing_address, shipping_address, 
            city, state, country, email, phone, tax_id, 
            currency, payment_terms, is_active, created_by, 
            created_at, updated_at
        FROM clients 
        WHERE id = ?
        LIMIT 1
    ";

    $clientStmt = $conn->prepare($clientQuery);
    if (!$clientStmt) {
        throw new Exception("Database query failed: " . $conn->error, 500);
    }

    $clientStmt->bind_param("i", $clientId);
    $clientStmt->execute();
    $clientResult = $clientStmt->get_result();

    if ($clientResult->num_rows === 0) {
        throw new Exception("Client not found.", 404);
    }

    $client = $clientResult->fetch_assoc();
    $clientStmt->close();

    // -------------------------------------------------------
    // 3. Fetch Associated Contact Persons
    // -------------------------------------------------------
    $contactsQuery = "
        SELECT 
            id, name, email, phone, position, is_primary, created_at
        FROM client_contacts 
        WHERE client_id = ?
        ORDER BY is_primary DESC, name ASC
    ";

    $contactsStmt = $conn->prepare($contactsQuery);
    $contactsStmt->bind_param("i", $clientId);
    $contactsStmt->execute();
    $contactsResult = $contactsStmt->get_result();

    $contacts = [];
    while ($contact = $contactsResult->fetch_assoc()) {
        $contacts[] = [
            "id"         => (int)$contact['id'],
            "name"       => $contact['name'],
            "email"      => $contact['email'],
            "phone"      => $contact['phone'],
            "position"   => $contact['position'],
            "is_primary" => (int)$contact['is_primary'],
            "created_at" => $contact['created_at']
        ];
    }
    $contactsStmt->close();

    // -------------------------------------------------------
    // 4. Combine and Return Response
    // -------------------------------------------------------
    // Build the final formatted client object
    $formattedClient = [
        "id"               => (int)$client['id'],
        "company_name"     => $client['company_name'],
        "billing_address"  => $client['billing_address'],
        "shipping_address" => $client['shipping_address'],
        "city"             => $client['city'],
        "state"            => $client['state'],
        "country"          => $client['country'],
        "email"            => $client['email'],
        "phone"            => $client['phone'],
        "tax_id"           => $client['tax_id'],
        "currency"         => $client['currency'],
        "payment_terms"    => $client['payment_terms'],
        "is_active"        => (int)$client['is_active'],
        "created_by"       => (int)$client['created_by'],
        "created_at"       => $client['created_at'],
        "updated_at"       => $client['updated_at'],
        "contacts"         => $contacts // Nest the array of contacts here
    ];

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Client fetched successfully",
        "data"    => $formattedClient
    ]);

} catch (Exception $e) {
    error_log("Get Single Client Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>