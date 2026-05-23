<?php
// routes/clients/deleteClientContact.php
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

/**
 * DELETE /clients/contacts/delete
 * Delete one or more contact persons.
 * Roles allowed: Admin, Sales
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin and Sales can delete contacts
    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales'])) {
        throw new Exception("Unauthorized: Only Admins or Sales staff can delete client contacts.", 403);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['contactIds']) || !is_array($data['contactIds']) || count($data['contactIds']) === 0) {
        throw new Exception("Please select at least one contact to delete.", 400);
    }

    $contactIds = array_map('intval', $data['contactIds']);

    // Start transaction
    $conn->begin_transaction();

    try {
        // -------------------------------------------------------
        // 1. Fetch Contact Details for Logging (Before Delete)
        // -------------------------------------------------------
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        
        $fetchQuery = "
            SELECT cc.id, cc.name, cc.is_primary, c.company_name
            FROM client_contacts cc
            JOIN clients c ON c.id = cc.client_id
            WHERE cc.id IN ($placeholders)
        ";
        
        $fetchStmt = $conn->prepare($fetchQuery);
        if (!$fetchStmt) {
            throw new Exception("Database error: Failed to prepare fetch statement.", 500);
        }

        $fetchStmt->bind_param(str_repeat('i', count($contactIds)), ...$contactIds);
        $fetchStmt->execute();
        $contactsToDelete = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fetchStmt->close();

        if (empty($contactsToDelete)) {
            throw new Exception("No matching contacts found to delete.", 404);
        }

        // -------------------------------------------------------
        // 2. Delete Contacts
        // -------------------------------------------------------
        $deleteQuery = "DELETE FROM client_contacts WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error: Failed to prepare delete statement.", 500);
        }

        $deleteStmt->bind_param(str_repeat('i', count($contactIds)), ...$contactIds);

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete contacts: " . $deleteStmt->error, 500);
        }

        $deletedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        // -------------------------------------------------------
        // 3. Build Log Description
        // -------------------------------------------------------
        $deletedNames = [];
        $primaryDeletedCount = 0;

        foreach ($contactsToDelete as $contact) {
            $deletedNames[] = "'{$contact['name']}' ({$contact['company_name']})";
            if ((int)$contact['is_primary'] === 1) {
                $primaryDeletedCount++;
            }
        }

        $nameList = implode(', ', $deletedNames);
        $warningNote = $primaryDeletedCount > 0 
            ? " WARNING: {$primaryDeletedCount} primary contact(s) were deleted." 
            : "";

        // -------------------------------------------------------
        // 4. Log Action
        // -------------------------------------------------------
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $action      = "client_contact.deleted";
        $modelType   = "ClientContact";
        $modelId     = null; // Bulk action
        $description = "{$loggedInUserEmail} deleted {$deletedCount} contact(s): {$nameList}.{$warningNote}";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log delete action: " . $logStmt->error, 500);
        }

        $logStmt->close();

        // Commit transaction
        $conn->commit();

        // -------------------------------------------------------
        // 5. Return Response
        // -------------------------------------------------------
        $responseMessage = "{$deletedCount} contact(s) permanently deleted.";
        
        if ($primaryDeletedCount > 0) {
            $responseMessage .= " Note: {$primaryDeletedCount} primary contact(s) were removed. You may want to set a new primary contact.";
        }

        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => $responseMessage,
            "meta"    => [
                "deleted_count"          => $deletedCount,
                "primary_contacts_removed" => $primaryDeletedCount
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Delete Client Contacts Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>