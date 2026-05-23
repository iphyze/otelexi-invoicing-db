<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/security.php';

loadEnvironment();
applyApiSecurityHeaders();

require_once __DIR__ . '/includes/connection.php';

// Normalize request URI
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = apiBasePath();
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
    'GET /auth/csrf'      => 'routes/auth/csrf.php',
    'GET /auth/session'   => 'routes/auth/session.php',
    'POST /auth/login'    => 'routes/auth/login.php',
    'POST /auth/refresh'  => 'routes/auth/refresh.php',
    'POST /auth/logout'   => 'routes/auth/logout.php',
    'POST /auth/forgot-password' => 'routes/auth/forgotPassword.php',
    'POST /auth/reset-password'  => 'routes/auth/resetPassword.php',

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
    'PUT /clients/reactivate'   => 'routes/clients/reactivateClient.php',
    'GET /clients/{id}/contacts' => 'routes/clients/getClientContacts.php',

    // --------------------------------------------------------
    // CLIENT CONTACTS (Dynamic routing)
    // {id} = client_id, {cid} = contact_id
    // --------------------------------------------------------
    'POST /clients/contacts/create'    => 'routes/clients/contacts/createContact.php',
    'PUT /clients/contacts/edit/{id}'  => 'routes/clients/contacts/editContact.php',
    'DELETE /clients/contacts/delete'  => 'routes/clients/contacts/deleteContact.php',
    'GET /clients/contacts'            => 'routes/clients/contacts/getFilteredRequest.php',
    'GET /clients/contact/{id}'        => 'routes/clients/contacts/getSingleContact.php',
    'GET /clients/contacts/search'     => 'routes/clients/contacts/search.php',


    // PRODUCTS  
    'GET /products'  => 'routes/products/getFilteredRequest.php',
    'GET /products/{id}'  => 'routes/products/getSingleProduct.php',
    'POST /products/create'  => 'routes/products/createProduct.php',
    'PUT /products/edit/{id}'  => 'routes/products/editProduct.php',
    'PUT /products/deactivate'  => 'routes/products/deactivateProduct.php',
    'DELETE /products/delete'  => 'routes/products/deleteProduct.php',
    'GET /products/low-stock'  => 'routes/products/lowStockProducts.php',
    'GET /products/search'  => 'routes/products/search.php',


    // PRODUCT CATEGORIES
    'GET /products/categories'  => 'routes/products/categories/getFilteredRequest.php',
    'GET /products/categories/{id}'  => 'routes/products/categories/getSingleCategory.php',
    'POST /products/categories/create'  => 'routes/products/categories/createCategory.php',
    'PUT /products/categories/edit/{id}'  => 'routes/products/categories/editCategory.php',

    // Note for the delete route is shown below
    // "This product is referenced in 4 active invoices. The line items will be preserved but unlinked from this product. Continue?"

    'DELETE /products/categories/delete'  => 'routes/products/categories/deleteCategories.php',
    'GET /products/categories/search'  => 'routes/products/categories/search.php',


    // QUOTATIONS
    'GET /quotations'  => 'routes/quotations/getFilteredRequest.php',
    'GET /quotations/{id}'  => 'routes/quotations/getSingleQuotation.php',
    'POST /quotations/create'  => 'routes/quotations/createQuotation.php',
    'PUT /quotations/edit/{id}'  => 'routes/quotations/editQuotation.php',
    'DELETE /quotations/delete'  => 'routes/quotations/deleteQuotation.php',
    'GET /quotations/search'  => 'routes/quotations/search.php',
    'PUT /quotations/update-status'  => 'routes/quotations/updateStatus.php',
    'POST /quotation/{id}/send'  => 'routes/quotations/sendQuotation.php',
    'POST /quotation/{id}/accept'  => 'routes/quotations/acceptQuotation.php',
    'POST /quotation/{id}/reject'  => 'routes/quotations/rejectQuotation.php',
    'POST /quotation/expire'  => 'routes/quotations/expireQuotation.php',
    'POST /quotation/{id}/reopen'  => 'routes/quotations/reopenQuotation.php',
    'POST /quotation/{id}/convert-proforma'  => 'routes/quotations/convertToProforma.php',
    'POST /quotation/{id}/convert-invoice'  => 'routes/quotations/convertToInvoice.php',


    // PROFORMAS
    'GET /proformas'                          => 'routes/proformas/getProformas.php',
    'GET /proformas/{id}'                     => 'routes/proformas/getSingleProforma.php',
    'POST /proformas/create'                  => 'routes/proformas/createProforma.php',
    'PUT /proformas/edit/{id}'               => 'routes/proformas/updateProforma.php',
    'DELETE /proformas/delete'               => 'routes/proformas/deleteProforma.php',
    'POST /proforma/{id}/send'               => 'routes/proformas/sendProforma.php',
    'POST /proforma/{id}/approve'            => 'routes/proformas/approveProforma.php',
    'POST /proforma/{id}/reject'             => 'routes/proformas/rejectProforma.php',
    'POST /proforma/{id}/convert-invoice'    => 'routes/proformas/convertProformaToInvoice.php',
    'POST /proformas/expire'                 => 'routes/proformas/expireProformas.php',


    // Invoices
    'GET /invoices'                       => 'routes/invoices/getInvoices.php',
    'GET /invoices/{id}'                  => 'routes/invoices/getSingleInvoice.php',
    'POST /invoices/create'               => 'routes/invoices/createInvoice.php',
    'PUT /invoices/edit/{id}'            => 'routes/invoices/updateInvoice.php',
    'DELETE /invoices/delete'            => 'routes/invoices/deleteInvoice.php',
    'POST /invoices/{id}/finalize'       => 'routes/invoices/finalizeInvoice.php',
    'POST /invoices/{id}/cancel'         => 'routes/invoices/cancelInvoice.php',
    'POST /invoices/mark-overdue'        => 'routes/invoices/markOverdue.php',
    'POST /invoices/{id}/send'            => 'routes/invoices/sendInvoice.php',
    'POST /invoices/{id}/send-reminder'   => 'routes/invoices/sendOverdueReminder.php',
    'POST /invoices/{id}/credit-notes'     => 'routes/invoices/createCreditNote.php',
    'POST /invoices/{id}/reverse'          => 'routes/invoices/reverseInvoice.php',
    'POST /credit-notes/{id}/refunds'      => 'routes/creditNotes/processRefund.php',
    'POST /credit-notes/{id}/send'         => 'routes/creditNotes/sendCreditNote.php',

    // Document email history
    'GET /documents/{id}/email-history' => 'routes/documents/getEmailHistory.php',

    // Inventory control and stock movement history
    'GET /inventory/movements'       => 'routes/inventory/getStockMovements.php',
    'POST /inventory/adjustments'    => 'routes/inventory/adjustStock.php',

    // Administration and audit controls (Super Admin only)
    'GET /admin/audit-logs'          => 'routes/admin/getAuditLogs.php',
    'GET /admin/overview'            => 'routes/admin/getAdministrationOverview.php',

    // Payments
    'GET /payments'               => 'routes/payments/getPayments.php',
    'GET /payments/{id}'          => 'routes/payments/getSinglePayment.php',
    'POST /payments/record'       => 'routes/payments/recordPayment.php',
    'POST /payments/{id}/receipt' => 'routes/receipts/issueReceipt.php',
    'DELETE /payments/{id}/delete' => 'routes/payments/deletePayment.php',

    // Payment receipts
    'GET /receipts/{id}'          => 'routes/receipts/getReceipt.php',
    'POST /receipts/{id}/send'    => 'routes/receipts/sendReceipt.php',

    // Reports
    'GET /reports/sales-summary'        => 'routes/reports/salesSummary.php',
    'GET /reports/top-products'         => 'routes/reports/topProducts.php',
    'GET /reports/stock-levels'         => 'routes/reports/stockLevels.php',
    'GET /reports/vat'                  => 'routes/reports/vatReport.php',
    'GET /reports/sales-by-staff'       => 'routes/reports/salesByStaff.php',
    'GET /reports/invoice-aging'        => 'routes/reports/invoiceAging.php',
    'GET /reports/client-statement'     => 'routes/reports/clientStatement.php',
    'GET /reports/revenue-by-category'  => 'routes/reports/revenueByCategory.php',
    'GET /reports/document-flow'        => 'routes/reports/documentFlow.php',

    // Dashboard, document maintenance and notifications
    'GET /dashboard'                             => 'routes/dashboard/dashboard.php',
    'GET /automation/document-maintenance/status' => 'routes/automation/getDocumentMaintenanceStatus.php',
    'POST /automation/document-maintenance/run'   => 'routes/automation/runDocumentMaintenance.php',
    'GET /notifications'                         => 'routes/notifications/getNotifications.php',
    'POST /notifications/mark-read'        => 'routes/notifications/markNotificationsRead.php',

];


// ============================================================
// ROUTER LOGIC
// ============================================================

$routeKey = "{$method} {$relativePath}";
$matched = false;
$handlerFile = null;

// 1. Try exact match with method prefix
if (isset($routes[$routeKey])) {
    $handlerFile = $routes[$routeKey];
}

// 2. Try dynamic route matching (for future use if needed)
if (!$handlerFile) {
    foreach ($routes as $pattern => $handler) {
        if (strpos($pattern, "{$method} ") !== 0) {
            continue;
        }

        $regex = preg_replace('/\{[a-zA-Z_]+\}/', '(\d+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $routeKey, $matches)) {
            preg_match_all('/\{([a-zA-Z_]+)\}/', $pattern, $paramNames);
            $paramNames = $paramNames[1];
            array_shift($matches);

            foreach ($paramNames as $index => $name) {
                $_GET[$name] = $matches[$index];
            }

            $handlerFile = $handler;
            break;
        }
    }
}

// 3. If no route matched
if (!$handlerFile) {
    http_response_code(404);
    echo json_encode([
        "status"  => "failed",
        "message" => "Route not found"
    ]);
    exit;
}

// ============================================================
// EXECUTE HANDLER WITH SAFETY NETS
// ============================================================

// Safety Net 1: Check if file exists BEFORE including
$fullPath = __DIR__ . '/' . $handlerFile;

if (!file_exists($fullPath)) {
    error_log("Router Error: Handler file not found: {$fullPath}");
    http_response_code(500);
    echo json_encode([
        "status"  => "failed",
        "message" => "Internal server error. Route handler file is missing."
    ]);
    exit;
}

// Safety Net 2: Start output buffering to catch unexpected errors
ob_start();

try {
    include_once($fullPath);
} catch (Throwable $e) {
    // Catch any fatal errors or exceptions from the included file
    ob_end_clean();
    error_log("Handler Error ({$routeKey}): " . $e->getMessage());

    $code = $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }

    http_response_code($code);
    echo json_encode([
        "status"  => "failed",
        "message" => $code >= 500 ? "Internal server error." : $e->getMessage()
    ]);
    exit;
}

// Safety Net 3: Check if the included file outputted JSON properly
$output = ob_get_clean();

// Do not leak SQL paths, stack messages or infrastructure details from route-level errors.
if (http_response_code() >= 500) {
    error_log("Handler returned server error ({$routeKey}): " . substr($output, 0, 500));
    echo json_encode([
        "status"  => "failed",
        "message" => "Internal server error."
    ]);
    exit;
}

// If output is empty, the route file likely handled its own response (normal case)
if (empty($output)) {
    exit;
}

// If output contains PHP warnings/errors but no valid JSON was sent yet
// This catches cases where include_once fails silently but prints warnings
$jsonStart = strpos($output, '{');
$jsonEnd = strrpos($output, '}');

if ($jsonStart === false || $jsonEnd === false) {
    // No valid JSON found in output — likely a PHP error/warning
    error_log("Router Warning: Non-JSON output from handler: " . substr($output, 0, 500));
    http_response_code(500);
    echo json_encode([
        "status"  => "failed",
        "message" => "Internal server error. An unexpected error occurred."
    ]);
    exit;
}

// Valid JSON found — output it (the route file likely handled status code already)
echo $output;

exit;
