<?php
// routes/clients/getClientDropdown.php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user (all roles can view client dropdowns)
    $userData = authenticateUser();

    // Get search query (optional)
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    // Base query - always exclude inactive clients
    $sql = "
        SELECT 
            id, 
            company_name, 
            city, 
            phone
        FROM clients
        WHERE is_active = 1
    ";

    $params = [];
    $types = "";

    // Search filter logic (Optimized for client dropdowns)
    if (!empty($search)) {
        $sql .= " AND (company_name LIKE ? OR phone LIKE ? OR email LIKE ? OR city LIKE ?)";
        
        $likeSearch = "%" . $search . "%";
        
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        
        $types .= "ssss";
    }

    // Sort by company name and limit for dropdown performance
    $sql .= " ORDER BY company_name ASC LIMIT 50";

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
    $clients = [];
    while ($row = $result->fetch_assoc()) {
        $clients[] = [
            "id"           => (int)$row['id'],
            "company_name" => $row['company_name'],
            "city"         => $row['city'],
            "phone"        => $row['phone']
        ];
    }
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "data"   => $clients
    ]);

} catch (Exception $e) {
    error_log("Client Dropdown Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}
?>