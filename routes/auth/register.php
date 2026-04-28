<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';

use Respect\Validation\Validator as v;
use Firebase\JWT\JWT;
use Dotenv\Dotenv;

 $dotenv = Dotenv::createImmutable('./');
 $dotenv->load();

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    $requiredFields = ['name', 'email', 'password', 'role'];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception(ucfirst($field) . " is required", 400);
        }
    }

    $name     = trim($data['name']);
    $email    = strtolower(trim($data['email']));
    $password = trim($data['password']);
    $role     = trim($data['role']);

    // Validation
    if (!v::stringType()->length(2, 150)->validate($name)) {
        throw new Exception("Name must be between 2 and 150 characters", 400);
    }

    if (!v::email()->validate($email)) {
        throw new Exception("Invalid email format", 400);
    }

    if (!v::stringType()->length(8, null)->validate($password)) {
        throw new Exception("Password must be at least 8 characters long", 400);
    }

    // Demand at least one special character
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        throw new Exception("Password must contain at least one special character (e.g. @, _, /, #)", 400);
    }

    $allowedRoles = ['admin', 'sales', 'accountant'];
    if (!in_array($role, $allowedRoles)) {
        throw new Exception("Invalid role. Allowed: admin, sales, accountant", 400);
    }

    // Check if user exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        throw new Exception("User with this email already exists", 400);
    }
    $stmt->close();

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Insert user
    $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $email, $hashedPassword, $role);

    if (!$stmt->execute()) {
        throw new Exception("Failed to create user: " . $stmt->error, 500);
    }

    $userId = $stmt->insert_id;
    $stmt->close();

    http_response_code(201);
    echo json_encode([
        "status"  => "success",
        "message" => "User created successfully",
        "data"    => [
            "id"    => (int)$userId,
            "name"  => $name,
            "email" => $email,
            "role"  => $role
        ]
    ]);

} catch (Exception $e) {
    error_log("Registration Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ]);
}

?>