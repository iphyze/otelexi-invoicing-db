<?php
// routes/clients/createClient.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /clients
 * Create a new client.
 * Roles allowed: Admin, Sales
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "Failed", "message" => "Method Not Allowed. Use POST."]);
    exit;
}

// Authenticate user
 $userData = authenticateUser();
 $loggedInUserId = (int)$userData['id'];

// Only Admin and Sales can create clients
if (!in_array($userData['role'], ['super_admin', 'admin', 'sales'])) {
    throw new Exception("Access denied. Only Admins or Sales staff can create clients.", 403);
}

// Read JSON payload
 $json = file_get_contents("php://input");
 $data = json_decode($json, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "Failed", "message" => "Invalid or missing JSON payload."]);
    exit;
}

// -------------------------------------------------------
// Validation
// -------------------------------------------------------
 $requiredFields = ['company_name', 'billing_address', 'city', 'state', 'phone'];
foreach ($requiredFields as $field) {
    if (empty(trim($data[$field] ?? ''))) {
        http_response_code(422);
        echo json_encode(["status" => "Failed", "message" => "The field '{$field}' is required."]);
        exit;
    }
}

if (!empty($data['email']) && !filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(["status" => "Failed", "message" => "The provided email address is invalid."]);
    exit;
}

if (isset($data['currency']) && !in_array($data['currency'], ['NGN', 'USD'])) {
    http_response_code(422);
    echo json_encode(["status" => "Failed", "message" => "Invalid currency. Must be 'NGN' or 'USD'."]);
    exit;
}

if (isset($data['payment_terms']) && !in_array($data['payment_terms'], ['due_on_receipt', 'net_7'])) {
    http_response_code(422);
    echo json_encode(["status" => "Failed", "message" => "Invalid payment terms. Must be 'due_on_receipt' or 'net_7'."]);
    exit;
}

// -------------------------------------------------------
// Prepare Data & Defaults
// -------------------------------------------------------
 $companyName    = trim($data['company_name']);
 $billingAddress = trim($data['billing_address']);
 $shippingAddr   = !empty($data['shipping_address']) ? trim($data['shipping_address']) : NULL;
 $city           = trim($data['city']);
 $state          = trim($data['state']);
 $country        = !empty($data['country']) ? trim($data['country']) : 'Nigeria';
 $email          = !empty($data['email']) ? trim($data['email']) : NULL;
 $phone          = trim($data['phone']);
 $taxId          = !empty($data['tax_id']) ? trim($data['tax_id']) : NULL;
 $currency       = $data['currency'] ?? 'NGN';
 $paymentTerms   = $data['payment_terms'] ?? 'due_on_receipt';

try {
    // -------------------------------------------------------
    // FAST-FAIL UI CHECKS (For better User Experience)
    // -------------------------------------------------------
    if (!empty($email)) {
        $emailCheck = $conn->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
        $emailCheck->bind_param("s", $email);
        $emailCheck->execute();
        if ($emailCheck->get_result()->num_rows > 0) {
            http_response_code(409);
            echo json_encode(["status" => "Failed", "message" => "A client with this email address already exists."]);
            exit;
        }
        $emailCheck->close();
    }

    $companyCheck = $conn->prepare("SELECT id FROM clients WHERE company_name = ? AND phone = ? LIMIT 1");
    $companyCheck->bind_param("ss", $companyName, $phone);
    $companyCheck->execute();
    if ($companyCheck->get_result()->num_rows > 0) {
        http_response_code(409);
        echo json_encode(["status" => "Failed", "message" => "A client with this exact Company Name and Phone Number already exists."]);
        exit;
    }
    $companyCheck->close();


    // -------------------------------------------------------
    // Insert New Client (With Database Safety Net)
    // -------------------------------------------------------
    $query = "INSERT INTO clients (
                company_name, billing_address, shipping_address, city, state, country,
                email, phone, tax_id, currency, payment_terms, created_by
              ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("DB Prepare Error (Create Client): " . $conn->error); 
        throw new Exception("An error occurred while preparing to save the client.", 500);
    }

    $stmt->bind_param("sssssssssssi",
        $companyName, $billingAddress, $shippingAddr, $city, $state, $country,
        $email, $phone, $taxId, $currency, $paymentTerms, $loggedInUserId
    );

    if (!$stmt->execute()) {
        // -------------------------------------------------------
        // THE BULLETPROOF SAFETY NET
        // -------------------------------------------------------
        // 1062 is the MySQL error code for "Duplicate Entry"
        if ($stmt->errno === 1062) {
            http_response_code(409);
            echo json_encode([
                "status" => "Failed", 
                "message" => "This client already exists. A matching email, or identical Company Name & Phone number was found."
            ]);
            $stmt->close();
            exit;
        }
        
        error_log("DB Execute Error (Create Client): " . $stmt->error); 
        throw new Exception("Failed to save client to the database.", 500);
    }

    $newClientId = $stmt->insert_id;
    $stmt->close();

    // Fetch the newly created client to return it
    $result = $conn->query("SELECT * FROM clients WHERE id = $newClientId");
    $newClient = $result->fetch_assoc();

    http_response_code(201); // 201 Created
    echo json_encode([
        "status" => "Success",
        "message" => "Client created successfully.",
        "data" => $newClient
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
    exit;
}
?>