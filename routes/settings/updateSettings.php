<?php
// routes/settings/updateSettings.php
// require 'vendor/autoload.php';
// require_once 'includes/connection.php';
// require_once 'includes/authMiddleware.php';

/**
 * PUT /settings/update
 * Update company settings (branding, bank details, legal footer).
 * Admin only.
 */

// Only allow PUT requests
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode([
        "status" => "Failed",
        "message" => "Method Not Allowed. Use PUT."
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

// Read the raw JSON payload from the request body
 $json = file_get_contents("php://input");
 $data = json_decode($json, true);

// Validate JSON format
if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "status" => "Failed",
        "message" => "Invalid or missing JSON payload."
    ]);
    exit;
}

// Define exactly which fields are allowed to be updated (whitelist)
 $allowedFields = [
    'company_name', 'address', 'city', 'state', 'country',
    'phone', 'email', 'website', 'logo_path', 
    'bank_name', 'account_name', 'account_number', 'bank_branch',
    'vat_number', 'legal_footer'
];

// Fields that are NOT NULL in the database (cannot be sent as empty strings)
 $requiredIfProvided = ['company_name', 'address', 'city', 'state', 'phone', 'email'];

 $updateFields = [];
 $params = [];
 $types = "";

foreach ($data as $key => $value) {
    // Ignore any fields that aren't in our whitelist
    if (!in_array($key, $allowedFields)) {
        continue;
    }

    // Trim string values
    if (is_string($value)) {
        $value = trim($value);
    }

    // Validate required fields if they are passed in the payload
    if (in_array($key, $requiredIfProvided) && $value === '') {
        http_response_code(422); // Unprocessable Entity
        echo json_encode([
            "status" => "Failed",
            "message" => "The field '{$key}' cannot be empty."
        ]);
        exit;
    }

    // Add to our dynamic query arrays
    $updateFields[] = "`{$key}` = ?";
    $params[] = $value;
    $types .= "s"; // 's' indicates string type for mysqli bind_param
}

// Ensure at least one valid field was provided
if (empty($updateFields)) {
    http_response_code(400);
    echo json_encode([
        "status" => "Failed",
        "message" => "No valid fields provided for update."
    ]);
    exit;
}

try {
    // Build the dynamic UPDATE query
    // LIMIT 1 ensures we only ever update the single configuration row
    $sql = "UPDATE company_settings SET " . implode(", ", $updateFields) . " LIMIT 1";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . mysqli_error($conn));
    }

    // Bind the dynamic parameters
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    // Execute the update
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Database update failed: " . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);

    // Fetch and return the newly updated settings
    $result = mysqli_query($conn, "SELECT * FROM company_settings LIMIT 1");
    
    if (!$result || mysqli_num_rows($result) === 0) {
        throw new Exception("Company settings not found after update. Please seed the database.");
    }

    $updatedSettings = mysqli_fetch_assoc($result);
    
    // Remove the internal id from the response
    unset($updatedSettings['id']);

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Company settings updated successfully.",
        "data" => $updatedSettings
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