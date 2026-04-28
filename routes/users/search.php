<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user (all roles can view user dropdowns)
    $userData = authenticateUser();

    // Get search query (optional)
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    // Base query - always exclude inactive users
    $sql = "
        SELECT 
            id, 
            name, 
            email, 
            role
        FROM users
        WHERE is_active = 1
    ";

    $params = [];
    $types = "";

    // Search filter logic
    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR email LIKE ? OR role LIKE ?)";
        
        $likeSearch = "%" . $search . "%";
        
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        
        $types .= "sss";
    }

    // Sort by name and limit for dropdown performance
    $sql .= " ORDER BY name ASC LIMIT 50";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    
    // Format data for frontend dropdowns
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            "id"    => (int)$row['id'],
            "name"  => $row['name'],
            "email" => $row['email'],
            "role"  => $row['role']
        ];
    }
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "data"   => $users
    ]);

} catch (Exception $e) {
    error_log("User Dropdown Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}

?>