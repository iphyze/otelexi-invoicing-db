<?php
// routes/settings/getSettings.php
// require 'vendor/autoload.php';
// require_once 'includes/connection.php';
// require_once 'includes/authMiddleware.php';

/**
 * GET /settings
 * Fetch company settings (branding, bank details, legal footer).
 * Admin only.
 */

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        "status" => "Failed",
        "message" => "Method Not Allowed. Use GET."
    ]);
    exit;
}

// TODO: Add JWT authentication & role check here
// $userData = authenticateUser();
// $loggedInUserId = (int)$userData['id'];
// $loggedInUserName = $userData['email'];

// // Only Admin allowed
// if ($userData['role'] !== 'admin') {
//     throw new Exception("Unauthorized: Only Admins can create users", 403);
// }

try {
    // company_settings is a single-row table
    $query = "SELECT * FROM company_settings LIMIT 1";
    $result = mysqli_query($conn, $query);

    if (!$result) {
        throw new Exception("Database query failed: " . mysqli_error($conn));
    }

    $settings = mysqli_fetch_assoc($result);

    if (!$settings) {
        throw new Exception("Company settings not found. Please seed the database.");
    }

    // Remove the id from the response (internal field)
    unset($settings['id']);

    // Cast numeric/boolean fields for clean JSON
    $settings['updated_at'] = $settings['updated_at'] ?? null;

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Company settings retrieved successfully.",
        "data" => $settings
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
    exit;
}
?>