<?php
// routes/clients/updateClientContact.php
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

/**
 * PUT /clients/contacts/edit
 * Update a specific contact person.
 * Roles allowed: Admin, Sales
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin and Sales can update contacts
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can update client contacts.", 403);
    }

    // Read JSON payload
    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception("Invalid or missing JSON payload.", 400);
    }

    // -------------------------------------------------------
    // 1. Validate Contact ID
    // -------------------------------------------------------
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        throw new Exception("A valid Contact ID is required.", 400);
    }
    $contactId = (int)$data['id'];
    unset($data['id']); // Remove ID from data array

    // -------------------------------------------------------
    // 2. Verify Contact Exists & Get Client Info
    // -------------------------------------------------------
    $contactCheck = $conn->prepare("
        SELECT cc.id, cc.client_id, cc.name AS contact_name, cc.is_primary AS is_currently_primary,
               c.company_name, c.is_active AS client_active
        FROM client_contacts cc
        JOIN clients c ON c.id = cc.client_id
        WHERE cc.id = ? 
        LIMIT 1
    ");
    $contactCheck->bind_param("i", $contactId);
    $contactCheck->execute();
    $contactResult = $contactCheck->get_result();

    if ($contactResult->num_rows === 0) {
        throw new Exception("Contact not found.", 404);
    }

    $existingContact = $contactResult->fetch_assoc();
    $contactCheck->close();

    $oldClientId       = (int)$existingContact['client_id'];
    $oldCompanyName    = $existingContact['company_name'];
    $oldContactName    = $existingContact['contact_name'];
    $isCurrentlyPrimary = (int)$existingContact['is_currently_primary'];

    // Prevent editing contacts on deactivated clients
    if ((int)$existingContact['client_active'] === 0) {
        throw new Exception("Cannot edit contacts for a deactivated client.", 409);
    }

    // -------------------------------------------------------
    // 3. Whitelist & Validate Fields
    // -------------------------------------------------------
    $allowedFields = ['client_id', 'name', 'email', 'phone', 'position', 'is_primary'];
    
    // Fields that cannot be empty strings if provided
    $requiredIfProvided = ['name'];

    $updateFields = [];
    $params = [];
    $types = "";
    $isUpdatingPrimary = false;
    $isMovingClient = false;
    $newClientId = $oldClientId;

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
        if ($key === 'client_id') {
            if (!is_numeric($value)) {
                throw new Exception("The field 'client_id' must be a valid number.", 422);
            }
            $value = (int)$value;
            
            // Skip if same client (not actually moving)
            if ($value === $oldClientId) {
                continue;
            }
            
            $isMovingClient = true;
            $newClientId = $value;
        }

        if ($key === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("The provided email address is invalid.", 422);
        }

        if ($key === 'is_primary') {
            $value = (int)$value;
            if (!in_array($value, [0, 1])) {
                throw new Exception("The field 'is_primary' must be 0 or 1.", 422);
            }
            $isUpdatingPrimary = true;
        }

        $updateFields[] = "`{$key}` = ?";
        $params[] = $value;
        $types .= is_int($value) ? "i" : "s";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    // -------------------------------------------------------
    // 4. CLIENT MOVE VALIDATIONS
    // -------------------------------------------------------
    if ($isMovingClient) {
        // Verify new client exists
        $newClientCheck = $conn->prepare("
            SELECT id, company_name, is_active 
            FROM clients 
            WHERE id = ? 
            LIMIT 1
        ");
        $newClientCheck->bind_param("i", $newClientId);
        $newClientCheck->execute();
        $newClientResult = $newClientCheck->get_result();

        if ($newClientResult->num_rows === 0) {
            throw new Exception("The target client does not exist.", 404);
        }

        $newClient = $newClientResult->fetch_assoc();
        $newClientCheck->close();

        if ((int)$newClient['is_active'] === 0) {
            throw new Exception("Cannot move contacts to a deactivated client.", 409);
        }

        $newCompanyName = $newClient['company_name'];

        // Check for duplicate name on new client
        $currentName = $data['name'] ?? $oldContactName;
        $nameCheck = $conn->prepare("
            SELECT id FROM client_contacts 
            WHERE client_id = ? AND name = ? 
            LIMIT 1
        ");
        $nameCheck->bind_param("is", $newClientId, $currentName);
        $nameCheck->execute();
        if ($nameCheck->get_result()->num_rows > 0) {
            throw new Exception("The client '{$newCompanyName}' already has a contact named '{$currentName}'.", 409);
        }
        $nameCheck->close();

        // Check for duplicate email on new client
        $currentEmail = $data['email'] ?? null;
        if (!empty($currentEmail)) {
            $emailCheck = $conn->prepare("
                SELECT id FROM client_contacts 
                WHERE client_id = ? AND email = ? 
                LIMIT 1
            ");
            $emailCheck->bind_param("is", $newClientId, $currentEmail);
            $emailCheck->execute();
            if ($emailCheck->get_result()->num_rows > 0) {
                throw new Exception("The client '{$newCompanyName}' already has a contact with email '{$currentEmail}'.", 409);
            }
            $emailCheck->close();
        }
    } else {
        $newCompanyName = $oldCompanyName;
    }

    // -------------------------------------------------------
    // 5. DUPLICATE CHECKS (Only if NOT moving client)
    // If moving, we already checked above
    // -------------------------------------------------------
    if (!$isMovingClient) {
        // Check 1: Name uniqueness within same client
        if (in_array('name', array_keys($data)) && !empty($data['name'])) {
            $nameCheck = $conn->prepare("
                SELECT id FROM client_contacts 
                WHERE client_id = ? AND name = ? AND id != ? 
                LIMIT 1
            ");
            $nameCheck->bind_param("isi", $oldClientId, $data['name'], $contactId);
            $nameCheck->execute();
            if ($nameCheck->get_result()->num_rows > 0) {
                throw new Exception("This client already has another contact named '{$data['name']}'.", 409);
            }
            $nameCheck->close();
        }

        // Check 2: Email uniqueness within same client
        if (in_array('email', array_keys($data)) && !empty($data['email'])) {
            $emailCheck = $conn->prepare("
                SELECT id FROM client_contacts 
                WHERE client_id = ? AND email = ? AND id != ? 
                LIMIT 1
            ");
            $emailCheck->bind_param("isi", $oldClientId, $data['email'], $contactId);
            $emailCheck->execute();
            if ($emailCheck->get_result()->num_rows > 0) {
                throw new Exception("This client already has another contact with email '{$data['email']}'.", 409);
            }
            $emailCheck->close();
        }
    }

    // -------------------------------------------------------
    // 6. Execute Update (Inside Transaction)
    // -------------------------------------------------------
    $conn->begin_transaction();

    try {
        // Handle Primary Contact Logic — complex when moving clients
        
        if ($isMovingClient) {
            // MOVING SCENARIO: Handle primary on BOTH clients
            
            // Step A: If this contact was primary on OLD client, clear that
            if ($isCurrentlyPrimary === 1) {
                // No need to UPDATE this contact yet (we'll do it below)
                // But we DON'T need to demote others on old client since this one is leaving
            }

            // Step B: If setting is_primary = 1 on NEW client, demote existing primaries there
            $targetPrimary = $isUpdatingPrimary ? (int)$data['is_primary'] : 0;
            
            if ($targetPrimary === 1) {
                $demoteNewStmt = $conn->prepare("
                    UPDATE client_contacts 
                    SET is_primary = 0 
                    WHERE client_id = ? AND is_primary = 1
                ");
                $demoteNewStmt->bind_param("i", $newClientId);
                $demoteNewStmt->execute();
                $demoteNewStmt->close();
            } else {
                // Moving to new client but NOT making it primary — ensure it's set to 0
                // (in case it was primary on old client)
                if ($isCurrentlyPrimary === 1) {
                    $updateFields[] = "`is_primary` = 0";
                    // Don't add to params/types since it's a hardcoded value
                }
            }

        } else {
            // SAME CLIENT SCENARIO: Standard primary logic
            
            if ($isUpdatingPrimary && (int)$data['is_primary'] === 1) {
                $demoteStmt = $conn->prepare("
                    UPDATE client_contacts 
                    SET is_primary = 0 
                    WHERE client_id = ? AND is_primary = 1 AND id != ?
                ");
                $demoteStmt->bind_param("ii", $oldClientId, $contactId);
                $demoteStmt->execute();
                $demoteStmt->close();
            }
        }

        // Build and execute update query
        $sql = "UPDATE client_contacts SET " . implode(", ", $updateFields) . " WHERE id = ?";
        
        $updateStmt = $conn->prepare($sql);
        if (!$updateStmt) {
            error_log("DB Prepare Error (Update Contact): " . $conn->error);
            throw new Exception("Failed to prepare contact update.", 500);
        }

        // Append the Contact ID to parameters for WHERE clause
        $params[] = $contactId;
        $types .= "i";

        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            if ($updateStmt->errno === 1062) {
                throw new Exception("A duplicate contact matching these details already exists.", 409);
            }
            error_log("DB Execute Error (Update Contact): " . $updateStmt->error);
            throw new Exception("Failed to update contact in the database.", 500);
        }

        if ($updateStmt->affected_rows === 0) {
            throw new Exception("No changes were made. The contact data might be identical to what was submitted.", 200);
        }
        
        $updateStmt->close();

        // -------------------------------------------------------
        // 7. Log Activity
        // -------------------------------------------------------
        $changes = [];

        if ($isMovingClient) {
            $changes[] = "moved from '{$oldCompanyName}' to '{$newCompanyName}'";
        }

        $updatedName = $data['name'] ?? $oldContactName;
        if (in_array('name', array_keys($data)) && $data['name'] !== $oldContactName) {
            $changes[] = "name: '{$oldContactName}' → '{$data['name']}'";
        }
        if (in_array('email', array_keys($data))) {
            $changes[] = "email updated";
        }
        if (in_array('phone', array_keys($data))) {
            $changes[] = "phone updated";
        }
        if (in_array('position', array_keys($data))) {
            $changes[] = "position updated";
        }
        if ($isUpdatingPrimary) {
            $changes[] = ((int)$data['is_primary'] === 1) ? "set as primary" : "removed as primary";
        }

        $changeSummary = implode(', ', $changes);

        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = $isMovingClient ? "client_contact.moved" : "client_contact.updated";
        $modelType   = "ClientContact";
        $actionWord = $isMovingClient ? 'moved' : 'updated';
        $description = "{$loggedInUserEmail} {$actionWord} contact '{$updatedName}' (ID: {$contactId}). Changes: {$changeSummary}";
        // $description = "{$loggedInUserEmail} updated contact for '{$companyName}' (Contact ID: {$contactId}). Changes: {$changeSummary}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $contactId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            error_log("Failed to log contact update: " . $logStmt->error);
        }
        $logStmt->close();

        // Commit transaction
        $conn->commit();

        // -------------------------------------------------------
        // 8. Fetch & Return Updated Contact
        // -------------------------------------------------------
        $fetchStmt = $conn->prepare("
            SELECT id, client_id, name, email, phone, position, is_primary, created_at
            FROM client_contacts 
            WHERE id = ?
        ");
        $fetchStmt->bind_param("i", $contactId);
        $fetchStmt->execute();
        $updatedContact = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => $isMovingClient ? "Contact moved successfully." : "Contact updated successfully.",
            "data"    => [
                "id"         => (int)$updatedContact['id'],
                "client_id"  => (int)$updatedContact['client_id'],
                "name"       => $updatedContact['name'],
                "email"      => $updatedContact['email'],
                "phone"      => $updatedContact['phone'],
                "position"   => $updatedContact['position'],
                "is_primary" => (int)$updatedContact['is_primary'],
                "created_at" => $updatedContact['created_at']
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Update Client Contact Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>