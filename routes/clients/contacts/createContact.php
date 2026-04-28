<?php
// routes/clients/createClientContact.php
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

/**
 * POST /clients/contacts/create
 * Add a contact person to a client.
 * Roles allowed: Admin, Sales
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin and Sales can create contacts
    if (!in_array($loggedInUserRole, ['admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can add client contacts.", 403);
    }

    // -------------------------------------------------------
    // 1. Read & Validate JSON Payload
    // -------------------------------------------------------
    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    // Required fields
    $requiredFields = ['client_id', 'name'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && empty(trim($data[$field])))) {
            throw new Exception("The field '{$field}' is required.", 422);
        }
    }

    if (!is_numeric($data['client_id'])) {
        throw new Exception("The field 'client_id' must be a valid number.", 422);
    }

    // Optional field validations
    if (!empty($data['email']) && !filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL)) {
        throw new Exception("The provided email address is invalid.", 422);
    }

    if (isset($data['is_primary'])) {
        $data['is_primary'] = (int)$data['is_primary'];
        if (!in_array($data['is_primary'], [0, 1])) {
            throw new Exception("The field 'is_primary' must be 0 or 1.", 422);
        }
    }

    $clientId = (int)$data['client_id'];

    // -------------------------------------------------------
    // 2. Verify Client Exists & Is Active
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

    if ((int)$client['is_active'] === 0) {
        throw new Exception("Cannot add contacts to a deactivated client.", 409);
    }

    // -------------------------------------------------------
    // 3. Prepare Data
    // -------------------------------------------------------
    $name       = trim($data['name']);
    $email      = !empty($data['email']) ? trim($data['email']) : NULL;
    $phone      = !empty($data['phone']) ? trim($data['phone']) : NULL;
    $position   = !empty($data['position']) ? trim($data['position']) : NULL;
    $isPrimary  = $data['is_primary'] ?? 0;

    // -------------------------------------------------------
    // FAST-FAIL DUPLICATE CHECKS (For better User Experience)
    // -------------------------------------------------------
    // Check 1: Same client cannot have two contacts with the same name
    $nameCheck = $conn->prepare("
        SELECT id FROM client_contacts 
        WHERE client_id = ? AND name = ? 
        LIMIT 1
    ");
    $nameCheck->bind_param("is", $clientId, $name);
    $nameCheck->execute();
    if ($nameCheck->get_result()->num_rows > 0) {
        throw new Exception("This client already has a contact named '{$name}'.", 409);
    }
    $nameCheck->close();

    // Check 2: Same client cannot have two contacts with the same email
    if (!empty($email)) {
        $emailCheck = $conn->prepare("
            SELECT id FROM client_contacts 
            WHERE client_id = ? AND email = ? 
            LIMIT 1
        ");
        $emailCheck->bind_param("is", $clientId, $email);
        $emailCheck->execute();
        if ($emailCheck->get_result()->num_rows > 0) {
            throw new Exception("This client already has a contact with email address '{$email}'.", 409);
        }
        $emailCheck->close();
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // -------------------------------------------------------
        // 4. Handle Primary Contact Logic
        // -------------------------------------------------------
        if ($isPrimary === 1) {
            $demoteStmt = $conn->prepare("
                UPDATE client_contacts 
                SET is_primary = 0 
                WHERE client_id = ? AND is_primary = 1
            ");
            $demoteStmt->bind_param("i", $clientId);
            $demoteStmt->execute();
            $demoteStmt->close();
        }

        // -------------------------------------------------------
        // 5. Insert New Contact (With Database Safety Net)
        // -------------------------------------------------------
        $insertQuery = "
            INSERT INTO client_contacts (
                client_id, name, email, phone, position, is_primary
            ) VALUES (?, ?, ?, ?, ?, ?)
        ";

        $insertStmt = $conn->prepare($insertQuery);
        if (!$insertStmt) {
            throw new Exception("Failed to prepare insert query: " . $conn->error, 500);
        }

        $insertStmt->bind_param("issssi",
            $clientId,
            $name,
            $email,
            $phone,
            $position,
            $isPrimary
        );

        if (!$insertStmt->execute()) {
            // -------------------------------------------------------
            // THE BULLETPROOF SAFETY NET
            // -------------------------------------------------------
            // 1062 is the MySQL error code for "Duplicate Entry"
            if ($insertStmt->errno === 1062) {
                throw new Exception(
                    "This contact already exists for this client. A matching name or email was found.", 
                    409
                );
            }
            
            error_log("DB Execute Error (Create Contact): " . $insertStmt->error);
            throw new Exception("Failed to save contact: " . $insertStmt->error, 500);
        }

        $newContactId = $insertStmt->insert_id;
        $insertStmt->close();

        // -------------------------------------------------------
        // 6. Log Activity
        // -------------------------------------------------------
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "client_contact.created";
        $modelType   = "ClientContact";
        $description = "{$loggedInUserEmail} added contact '{$name}' to client '{$client['company_name']}' (ID: {$clientId})";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $newContactId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log contact creation: " . $logStmt->error);
        }
        $logStmt->close();

        // Commit transaction
        $conn->commit();

        // -------------------------------------------------------
        // 7. Fetch & Return Created Contact
        // -------------------------------------------------------
        $fetchStmt = $conn->prepare("
            SELECT id, name, email, phone, position, is_primary, created_at
            FROM client_contacts 
            WHERE id = ?
        ");
        $fetchStmt->bind_param("i", $newContactId);
        $fetchStmt->execute();
        $newContact = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        http_response_code(201);
        echo json_encode([
            "status"  => "success",
            "message" => "Contact added successfully.",
            "data"    => [
                "id"         => (int)$newContact['id'],
                "client_id"  => $clientId,
                "name"       => $newContact['name'],
                "email"      => $newContact['email'],
                "phone"      => $newContact['phone'],
                "position"   => $newContact['position'],
                "is_primary" => (int)$newContact['is_primary'],
                "created_at" => $newContact['created_at']
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Create Client Contact Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>