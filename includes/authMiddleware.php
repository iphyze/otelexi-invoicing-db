<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function authenticateUser() {

    $headers = getallheaders();

    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode([
            "status"  => "Failed",
            "message" => "Unauthorized: No token provided"
        ]);
        exit;
    }

    $token = str_replace('Bearer ', '', $headers['Authorization']);

    if (empty($token)) {
        http_response_code(401);
        echo json_encode([
            "status"  => "Failed",
            "message" => "Unauthorized: Empty token"
        ]);
        exit;
    }

    $secretKey = $_ENV["JWT_SECRET"] ?? "otelex_secret_key";

    try {
        $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
        return (array) $decoded;
    } catch (\Firebase\JWT\ExpiredException $e) {
        http_response_code(401);
        echo json_encode([
            "status"  => "Failed",
            "message" => "Token has expired"
        ]);
        exit;
    } catch (\Firebase\JWT\SignatureInvalidException $e) {
        http_response_code(401);
        echo json_encode([
            "status"  => "Failed",
            "message" => "Invalid token signature"
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode([
            "status"  => "Failed",
            "message" => "Invalid or malformed token"
        ]);
        exit;
    }
}

?>