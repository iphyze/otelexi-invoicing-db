<?php
// routes/clients/getClientContacts.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /clients/{id}/contacts
 * Get all contact persons for a specific client.
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

    // Only Admin, Sales, and Accounting can view client contacts
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales', 'accounting'])) {
        throw new Exception("Unauthorized: You do not have permission to view client contacts", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Client ID (injected by router as $_GET['id'])
    // -------------------------------------------------------
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Client ID is required.", 400);
    }
    
    $clientId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 2. Verify Client Exists
    // -------------------------------------------------------
    $clientCheck = $conn->prepare("SELECT id, company_name, is_active FROM clients WHERE id = ? LIMIT 1");
    $clientCheck->bind_param("i", $clientId);
    $clientCheck->execute();
    $clientResult = $clientCheck->get_result();

    if ($clientResult->num_rows === 0) {
        throw new Exception("Client not found.", 404);
    }

    $client = $clientResult->fetch_assoc();
    $clientCheck->close();

    // -------------------------------------------------------
    // 3. Fetch All Contacts for this Client
    // -------------------------------------------------------
    $contactsQuery = "
        SELECT 
            id, 
            name, 
            email, 
            phone, 
            position, 
            is_primary, 
            created_at
        FROM client_contacts 
        WHERE client_id = ?
        ORDER BY is_primary DESC, name ASC
    ";

    $contactsStmt = $conn->prepare($contactsQuery);
    if (!$contactsStmt) {
        throw new Exception("Database query failed: " . $conn->error, 500);
    }

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
    // 4. Return Response
    // -------------------------------------------------------
    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Client contacts fetched successfully",
        "data"    => [
            "client_id"    => $clientId,
            "company_name" => $client['company_name'],
            "contacts"     => $contacts
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Client Contacts Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>