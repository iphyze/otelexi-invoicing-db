<?php

require_once __DIR__ . '/vendor/autoload.php';

// Set CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

include_once('includes/connection.php');


// Normalize request URI
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = '/otelex-server/api';
$relativePath = str_replace($basePath, '', $requestUri);



$routes = [
    
    '/' => 'routes/welcome.php',
    '/welcome' => 'routes/welcome.php',
    

    // Auth Pages
    '/auth/login' => 'routes/auth/login.php',
    '/auth/register' => 'routes/auth/register.php',

    // User Management Pages
    '/users/create' => 'routes/users/createUsers.php',
    '/users/edit' => 'routes/users/editUsers.php',
    '/users/update' => 'routes/users/updateProfile.php',
    '/users/delete' => 'routes/users/deleteUsers.php',
    '/users/deactivate' => 'routes/users/deactivateUsers.php',
    '/user' => 'routes/users/getSingleUser.php',
    '/users' => 'routes/users/getFilteredRequest.php',
    '/users/search' => 'routes/users/search.php',

    // Company Settings Pages
    '/settings' => 'routes/settings/getSettings.php',
    '/settings/update' => 'routes/settings/updateSettings.php',
    '/settings/upload-logo' => 'routes/settings/uploadLogo.php',


    // Client Management
    '/clients/create' => 'routes/clients/createClient.php',
    '/clients/edit' => 'routes/clients/editClient.php',
    '/clients/delete' => 'routes/clients/deleteClients.php',
    '/clients/deactivate' => 'routes/clients/deactivateClient.php',
    '/client' => 'routes/clients/getSingleClient.php',
    '/clients' => 'routes/clients/getFilteredRequest.php',
    '/clients/search' => 'routes/clients/search.php',
    '/client/contacts' => 'routes/clients/getClientContacts.php',


    // Contact Management
    '/contacts/create' => 'routes/contacts/createContact.php',
    '/contacts/edit' => 'routes/contacts/editContact.php',
    '/contacts/delete' => 'routes/contacts/deleteContacts.php',
    '/contacts/deactivate' => 'routes/contacts/deactivateContact.php',
    '/contact' => 'routes/contacts/getSingleContact.php',
    '/contacts' => 'routes/contacts/getFilteredRequest.php',
    '/contacts/search' => 'routes/contacts/search.php',

];


if (array_key_exists($relativePath, $routes)) {
    if (is_callable($routes[$relativePath])) {
        $routes[$relativePath](); // Execute function
    } else {
        include_once($routes[$relativePath]);
    }
    exit;
}

http_response_code(404);
echo json_encode([
    "status" => "Failed",
    "message" => "Page not found!"
    ]);
exit;

// Close connection
mysqli_close($conn);

?>