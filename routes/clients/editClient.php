<?php
// routes/clients/updateClient.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * PUT /clients/update
 * Update a specific client.
 * Roles allowed: Admin, Sales
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];

    // Only Admin and Sales can update clients
    if (!in_array($userData['role'], ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can update clients.", 403);
    }

    // Read JSON payload
    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    // -------------------------------------------------------
    // 1. Validate Client ID
    // -------------------------------------------------------
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        throw new Exception("A valid Client ID is required.", 400);
    }
    $clientId = (int)$data['id'];
    unset($data['id']); // Remove ID from the data array so it doesn't get updated as a column

    // -------------------------------------------------------
    // 2. Whitelist & Validate Fields
    // -------------------------------------------------------
    $allowedFields = [
        'company_name', 'billing_address', 'shipping_address', 'city', 'state', 'country',
        'email', 'phone', 'tax_id', 'currency', 'payment_terms', 'is_active'
    ];
    
    // Fields that cannot be empty strings if provided in the payload
    $requiredIfProvided = ['company_name', 'billing_address', 'city', 'state', 'phone'];

    $updateFields = [];
    $params = [];
    $types = "";

    foreach ($data as $key => $value) {
        if (!in_array($key, $allowedFields)) {
            continue; // Ignore unknown fields
        }

        // Handle string trimming
        if (is_string($value)) {
            $value = trim($value);
        }

        // Prevent emptying required fields
        if (in_array($key, $requiredIfProvided) && $value === '') {
            throw new Exception("The field '{$key}' cannot be empty.", 422);
        }

        // Specific validations
        if ($key === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("The provided email address is invalid.", 422);
        }
        if ($key === 'currency' && !in_array($value, ['NGN', 'USD'])) {
            throw new Exception("Invalid currency. Must be 'NGN' or 'USD'.", 422);
        }
        if ($key === 'payment_terms' && !in_array($value, ['due_on_receipt', 'net_7'])) {
            throw new Exception("Invalid payment terms.", 422);
        }
        if ($key === 'is_active') {
            $value = (int)$value; // Cast to integer (0 or 1)
        }

        $updateFields[] = "`{$key}` = ?";
        $params[] = $value;
        $types .= is_int($value) ? "i" : "s";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    // -------------------------------------------------------
    // 3. DUPLICATE CHECKS (Excluding current client ID)
    // -------------------------------------------------------
    
    // Check 1: Email uniqueness
    if (in_array('email', array_keys($data)) && !empty($data['email'])) {
        $emailCheck = $conn->prepare("SELECT id FROM clients WHERE email = ? AND id != ? LIMIT 1");
        $emailCheck->bind_param("si", $data['email'], $clientId);
        $emailCheck->execute();
        if ($emailCheck->get_result()->num_rows > 0) {
            throw new Exception("Another client with this email address already exists.", 409);
        }
        $emailCheck->close();
    }

    // Check 2: Company Name + Phone combination
    // Only check if BOTH fields are being updated in this request
    if (in_array('company_name', array_keys($data)) && in_array('phone', array_keys($data))) {
        $companyCheck = $conn->prepare("SELECT id FROM clients WHERE company_name = ? AND phone = ? AND id != ? LIMIT 1");
        $companyCheck->bind_param("ssi", $data['company_name'], $data['phone'], $clientId);
        $companyCheck->execute();
        if ($companyCheck->get_result()->num_rows > 0) {
            throw new Exception("Another client with this exact Company Name and Phone Number already exists.", 409);
        }
        $companyCheck->close();
    }

    // -------------------------------------------------------
    // 4. Execute Update
    // -------------------------------------------------------
    $sql = "UPDATE clients SET " . implode(", ", $updateFields) . " WHERE id = ?";
    
    $updateStmt = $conn->prepare($sql);
    if (!$updateStmt) {
        error_log("DB Prepare Error (Update Client): " . $conn->error);
        throw new Exception("Failed to prepare client update.", 500);
    }

    // Append the Client ID to the parameters for the WHERE clause
    $params[] = $clientId;
    $types .= "i";

    $updateStmt->bind_param($types, ...$params);

    if (!$updateStmt->execute()) {
        // Catch database-level unique constraint violations just in case
        if ($updateStmt->errno === 1062) {
            throw new Exception("A duplicate record matching these details already exists.", 409);
        }
        error_log("DB Execute Error (Update Client): " . $updateStmt->error);
        throw new Exception("Failed to update client in the database.", 500);
    }

    // Check if any rows were actually affected
    if ($updateStmt->affected_rows === 0) {
        throw new Exception("No changes were made. The client data might be identical to what was submitted.", 200);
    }
    
    $updateStmt->close();

    // -------------------------------------------------------
    // 5. Fetch & Return Updated Client + Contacts
    // -------------------------------------------------------
    $fetchStmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
    $fetchStmt->bind_param("i", $clientId);
    $fetchStmt->execute();
    $updatedClient = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    // Fetch contacts to keep frontend state consistent
    $contactsStmt = $conn->prepare("SELECT id, name, email, phone, position, is_primary, created_at FROM client_contacts WHERE client_id = ? ORDER BY is_primary DESC, name ASC");
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

    // Format final output
    $formattedClient = [
        "id"               => (int)$updatedClient['id'],
        "company_name"     => $updatedClient['company_name'],
        "billing_address"  => $updatedClient['billing_address'],
        "shipping_address" => $updatedClient['shipping_address'],
        "city"             => $updatedClient['city'],
        "state"            => $updatedClient['state'],
        "country"          => $updatedClient['country'],
        "email"            => $updatedClient['email'],
        "phone"            => $updatedClient['phone'],
        "tax_id"           => $updatedClient['tax_id'],
        "currency"         => $updatedClient['currency'],
        "payment_terms"    => $updatedClient['payment_terms'],
        "is_active"        => (int)$updatedClient['is_active'],
        "created_at"       => $updatedClient['created_at'],
        "updated_at"       => $updatedClient['updated_at'],
        "contacts"         => $contacts 
    ];

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Client updated successfully",
        "data"    => $formattedClient
    ]);

} catch (Exception $e) {
    error_log("Update Client Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>