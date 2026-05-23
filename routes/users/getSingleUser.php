<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInUserRole = $userData['role'];

    // Determine target user ID (from URL param, or default to logged-in user)
    $targetUserId = isset($_GET['id']) ? (int)$_GET['id'] : $loggedInUserId;

    // Authorization: Only Admin or the actual user can view this profile
    if ($loggedInUserRole !== 'super_admin' && $targetUserId !== $loggedInUserId) {
        throw new Exception("Unauthorized: You can only view your own profile", 403);
    }

    /**
     * Fetch user data
     */
    $stmt = $conn->prepare("
        SELECT 
            id, 
            name, 
            email, 
            role, 
            is_active, 
            last_login, 
            created_at, 
            updated_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $targetUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new Exception("User record not found", 404);
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "User profile fetched successfully",
        "data"    => [
            "id"         => (int)$user['id'],
            "name"       => $user['name'],
            "email"      => $user['email'],
            "role"       => $user['role'],
            "is_active"  => (int)$user['is_active'],
            "last_login" => $user['last_login'],
            "created_at" => $user['created_at'],
            "updated_at" => $user['updated_at']
        ]
    ]);

} catch (Exception $e) {
    error_log("Get User Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}

?>