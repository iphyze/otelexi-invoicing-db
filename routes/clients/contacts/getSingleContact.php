<?php
// routes/clients/getSingleContact.php
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

/**
 * GET /client/contact?id=1
 * Get single contact details with associated client info.
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

    // Only Admin, Sales, and Accounting can view contact details
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales', 'accounting'])) {
        throw new Exception("Unauthorized: You do not have permission to view this contact", 403);
    }

    // -------------------------------------------------------
    // 1. Validate Contact ID
    // -------------------------------------------------------
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("A valid Contact ID is required.", 400);
    }
    
    $contactId = (int)$_GET['id'];

    // -------------------------------------------------------
    // 2. Fetch Main Contact Details + Client Info
    // -------------------------------------------------------
    $contactQuery = "
        SELECT 
            cc.id, 
            cc.client_id, 
            cc.name, 
            cc.email, 
            cc.phone, 
            cc.position, 
            cc.is_primary, 
            cc.created_at,
            c.company_name,
            c.city AS client_city,
            c.state AS client_state,
            c.country AS client_country,
            c.phone AS client_phone,
            c.email AS client_email,
            c.is_active AS client_active,
            (SELECT COUNT(*) 
             FROM client_contacts 
             WHERE client_id = cc.client_id
            ) AS total_contacts_for_client
        FROM client_contacts cc
        JOIN clients c ON c.id = cc.client_id
        WHERE cc.id = ?
        LIMIT 1
    ";

    $contactStmt = $conn->prepare($contactQuery);
    if (!$contactStmt) {
        throw new Exception("Database query failed: " . $conn->error, 500);
    }

    $contactStmt->bind_param("i", $contactId);
    $contactStmt->execute();
    $contactResult = $contactStmt->get_result();

    if ($contactResult->num_rows === 0) {
        throw new Exception("Contact not found.", 404);
    }

    $contact = $contactResult->fetch_assoc();
    $contactStmt->close();

    // -------------------------------------------------------
    // 3. Combine and Return Response
    // -------------------------------------------------------
    $formattedContact = [
        "id"         => (int)$contact['id'],
        "client_id"  => (int)$contact['client_id'],
        "name"       => $contact['name'],
        "email"      => $contact['email'],
        "phone"      => $contact['phone'],
        "position"   => $contact['position'],
        "is_primary" => (int)$contact['is_primary'],
        "created_at" => $contact['created_at'],
        "client"     => [
            "company_name"         => $contact['company_name'],
            "city"                 => $contact['client_city'],
            "state"                => $contact['client_state'],
            "country"              => $contact['client_country'],
            "phone"                => $contact['client_phone'],
            "email"                => $contact['client_email'],
            "is_active"            => (int)$contact['client_active'],
            "total_contacts"       => (int)$contact['total_contacts_for_client']
        ]
    ];

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Contact fetched successfully",
        "data"    => $formattedContact
    ]);

} catch (Exception $e) {
    error_log("Get Single Contact Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>