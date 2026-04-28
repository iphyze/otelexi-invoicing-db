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
 $method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// ROUTE DEFINITIONS
// Format: 'METHOD /path' => 'file.php'
// Use {param} for dynamic segments (extracted as $_GET params)
// 
// Legacy routes (no method prefix) still work for backwards compatibility
// ============================================================

 $routes = [

    // --------------------------------------------------------
    // AUTH
    // --------------------------------------------------------
    'POST /auth/login'    => 'routes/auth/login.php',
    'POST /auth/register' => 'routes/auth/register.php',

    // --------------------------------------------------------
    // USERS
    // --------------------------------------------------------
    'GET /users'          => 'routes/users/getFilteredRequest.php',
    'GET /users/search'   => 'routes/users/search.php',
    'GET /user'           => 'routes/users/getSingleUser.php',
    'POST /users/create'  => 'routes/users/createUsers.php',
    'PUT /users/edit'     => 'routes/users/editUsers.php',
    'PUT /users/update'   => 'routes/users/updateProfile.php',
    'DELETE /users/delete'   => 'routes/users/deleteUsers.php',
    'PUT /users/deactivate'  => 'routes/users/deactivateUsers.php',

    // --------------------------------------------------------
    // COMPANY SETTINGS
    // --------------------------------------------------------
    'GET /settings'            => 'routes/settings/getSettings.php',
    'PUT /settings/update'     => 'routes/settings/updateSettings.php',
    'POST /settings/upload-logo' => 'routes/settings/uploadLogo.php',

    // --------------------------------------------------------
    // CLIENTS
    // --------------------------------------------------------
    'GET /clients'              => 'routes/clients/getFilteredRequest.php',
    'GET /clients/search'       => 'routes/clients/search.php',
    'GET /client'               => 'routes/clients/getSingleClient.php',
    'POST /clients/create'      => 'routes/clients/createClient.php',
    'PUT /clients/edit'         => 'routes/clients/editClient.php',
    'DELETE /clients/delete'    => 'routes/clients/deleteClients.php',
    'PUT /clients/deactivate'   => 'routes/clients/deactivateClient.php',

    // --------------------------------------------------------
    // CLIENT CONTACTS (Dynamic routing)
    // {id} = client_id, {cid} = contact_id
    // --------------------------------------------------------
    'GET /clients/{id}/contacts'          => 'routes/clients/getClientContacts.php',
    'POST /clients/{id}/contacts'         => 'routes/clients/createClientContact.php',
    'PUT /clients/{id}/contacts/{cid}'    => 'routes/clients/updateClientContact.php',
    'DELETE /clients/{id}/contacts/{cid}' => 'routes/clients/deleteClientContact.php',

];


// ============================================================
// ROUTER LOGIC
// ============================================================

 $routeKey = "{$method} {$relativePath}";
 $matched = false;

// 1. Try exact match with method prefix (e.g., "GET /clients")
if (isset($routes[$routeKey])) {
    include_once($routes[$routeKey]);
    $matched = true;
}

// 2. Try dynamic route matching (e.g., "GET /clients/{id}/contacts")
if (!$matched) {
    foreach ($routes as $pattern => $handler) {
        // Only check patterns with the same HTTP method
        if (strpos($pattern, "{$method} ") !== 0) {
            continue;
        }

        // Convert {param} to regex capture group (digits only)
        $regex = preg_replace('/\{[a-zA-Z_]+\}/', '(\d+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $routeKey, $matches)) {
            // Extract parameter names from pattern (e.g., {id}, {cid})
            preg_match_all('/\{([a-zA-Z_]+)\}/', $pattern, $paramNames);
            $paramNames = $paramNames[1];

            // Remove the full match, keep only captured groups
            array_shift($matches);

            // Inject captured values into $_GET so route files can access them
            foreach ($paramNames as $index => $name) {
                $_GET[$name] = $matches[$index];
            }

            include_once($handler);
            $matched = true;
            break;
        }
    }
}

// 3. Fallback: try path-only match (backwards compatibility with legacy routes)
if (!$matched) {
    if (isset($routes[$relativePath])) {
        include_once($routes[$relativePath]);
        $matched = true;
    }
}

// 4. No route found
if (!$matched) {
    http_response_code(404);
    echo json_encode([
        "status"  => "failed",
        "message" => "Route not found"
    ]);
}

exit;