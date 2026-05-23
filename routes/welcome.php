<?php

try {

    $req = $_SERVER['REQUEST_METHOD'];

    if ($req !== 'GET' && $req !== 'POST') {
        throw new Exception("Method Not Allowed", 405);
    }

    // -------------------------------------------------------
    // Base URL — update this when you deploy
    // -------------------------------------------------------
    $base = "/api/v1";

    // -------------------------------------------------------
    // Helper to define an endpoint
    // -------------------------------------------------------
    $ep = function ($method, $path, $desc, $auth = true, $roles = [], $params = []) {
        return [
            "method"      => $method,
            "endpoint"    => "{$path}",
            "full_url"    => "{$path}",
            "description" => $desc,
            "auth"        => $auth,
            "roles"       => $roles,
            "params"      => $params
        ];
    };

    // -------------------------------------------------------
    // AUTHENTICATION
    // -------------------------------------------------------
    $auth = [
        $ep("POST", "{$base}/auth/login",           "Login with email & password. Returns JWT token.", false),
        $ep("POST", "{$base}/auth/logout",          "Invalidate current token.", true, ["admin","sales","accounting"]),
        $ep("GET",  "{$base}/auth/me",              "Get current authenticated user profile.", true, ["admin","sales","accounting"]),
        $ep("PUT",  "{$base}/auth/change-password", "Change logged-in user's password.", true, ["admin","sales","accounting"],
            [
                "current_password" => "string (required)",
                "new_password"     => "string (required, min 8 chars)"
            ]
        ),
    ];

    // -------------------------------------------------------
    // USERS (Admin only)
    // -------------------------------------------------------
    $users = [
        $ep("GET",    "{$base}/users",              "List all users. Optional ?role=admin&status=active filters.", true, ["admin"],
            [
                "role"     => "string (optional) — filter by role",
                "status"   => "string (optional) — active|inactive",
                "search"   => "string (optional) — search name/email",
                "page"     => "integer (optional) — pagination",
                "per_page" => "integer (optional) — items per page, default 20"
            ]
        ),
        $ep("POST",   "{$base}/users",              "Create a new user.", true, ["admin"],
            [
                "name"     => "string (required)",
                "email"    => "string (required, unique)",
                "password" => "string (required, min 8 chars)",
                "role"     => "enum: admin|sales|accounting (required)"
            ]
        ),
        $ep("GET",    "{$base}/users/{id}",         "Get single user details.", true, ["admin"]),
        $ep("PUT",    "{$base}/users/{id}",         "Update user profile.", true, ["admin"],
            [
                "name"  => "string (optional)",
                "email" => "string (optional, unique)",
                "role"  => "enum: admin|sales|accounting (optional)",
                "is_active" => "integer: 0|1 (optional)"
            ]
        ),
        $ep("DELETE", "{$base}/users/{id}",         "Soft-delete a user (sets is_active=0).", true, ["admin"]),
    ];

    // -------------------------------------------------------
    // CLIENTS
    // -------------------------------------------------------
    $clients = [
        $ep("GET",    "{$base}/clients",                    "List all clients with optional filters.", true, ["admin","sales","accounting"],
            [
                "search"   => "string (optional) — search company name, email, phone",
                "status"   => "string (optional) — active|inactive",
                "currency" => "string (optional) — NGN|USD",
                "page"     => "integer (optional)",
                "per_page" => "integer (optional)"
            ]
        ),
        $ep("POST",   "{$base}/clients",                    "Create a new client.", true, ["admin","sales"],
            [
                "company_name"    => "string (required)",
                "billing_address" => "string (required)",
                "shipping_address"=> "string (optional, null = same as billing)",
                "city"            => "string (required)",
                "state"           => "string (required)",
                "country"         => "string (optional, default: Nigeria)",
                "email"           => "string (optional)",
                "phone"           => "string (required)",
                "tax_id"          => "string (optional — client VAT/TIN)",
                "currency"        => "enum: NGN|USD (optional, default: NGN)",
                "payment_terms"   => "enum: due_on_receipt|net_7 (optional, default: due_on_receipt)"
            ]
        ),
        $ep("GET",    "{$base}/clients/{id}",               "Get single client with contacts.", true, ["admin","sales","accounting"]),
        $ep("PUT",    "{$base}/clients/{id}",               "Update client details.", true, ["admin","sales"]),
        $ep("DELETE", "{$base}/clients/{id}",               "Soft-delete a client.", true, ["admin"]),

        // Client Contacts (nested)
        $ep("GET",    "{$base}/clients/{id}/contacts",      "List all contacts for a client.", true, ["admin","sales","accounting"]),
        $ep("POST",   "{$base}/clients/{id}/contacts",      "Add a contact person to a client.", true, ["admin","sales"],
            [
                "name"       => "string (required)",
                "email"      => "string (optional)",
                "phone"      => "string (optional)",
                "position"   => "string (optional) — e.g. Procurement Manager",
                "is_primary" => "integer: 0|1 (optional, default: 0)"
            ]
        ),
        $ep("PUT",    "{$base}/clients/{id}/contacts/{cid}", "Update a contact person.", true, ["admin","sales"]),
        $ep("DELETE", "{$base}/clients/{id}/contacts/{cid}", "Remove a contact person.", true, ["admin","sales"]),

        // Client Statement
        $ep("GET",    "{$base}/clients/{id}/statement",     "Get all invoices & payments for a client. Optional ?from=&to= date filters.", true, ["admin","accounting"],
            [
                "from" => "date (optional, YYYY-MM-DD)",
                "to"   => "date (optional, YYYY-MM-DD)"
            ]
        ),
    ];

    // -------------------------------------------------------
    // PRODUCT CATEGORIES
    // -------------------------------------------------------
    $categories = [
        $ep("GET",    "{$base}/categories",        "List all product categories.", true, ["admin","sales","accounting"]),
        $ep("POST",   "{$base}/categories",        "Create a new category.", true, ["admin"],
            [
                "name"        => "string (required, unique)",
                "description" => "string (optional)"
            ]
        ),
        $ep("GET",    "{$base}/categories/{id}",   "Get single category.", true, ["admin","sales","accounting"]),
        $ep("PUT",    "{$base}/categories/{id}",   "Update category.", true, ["admin"]),
        $ep("DELETE", "{$base}/categories/{id}",   "Delete category (only if no products linked).", true, ["admin"]),
    ];

    // -------------------------------------------------------
    // PRODUCTS
    // -------------------------------------------------------
    $products = [
        $ep("GET",    "{$base}/products",           "List all products with optional filters.", true, ["admin","sales","accounting"],
            [
                "category_id" => "integer (optional)",
                "search"      => "string (optional) — search name or SKU",
                "tax_type"    => "string (optional) — vat|exempt",
                "status"      => "string (optional) — active|inactive",
                "low_stock"   => "integer: 1 (optional) — show only low/out-of-stock",
                "page"        => "integer (optional)",
                "per_page"    => "integer (optional)"
            ]
        ),
        $ep("POST",   "{$base}/products",           "Create a new product.", true, ["admin"],
            [
                "category_id"     => "integer (required)",
                "name"            => "string (required)",
                "sku"             => "string (required, unique)",
                "description"     => "string (optional)",
                "unit_price"      => "decimal (required) — e.g. 2500.00",
                "unit_of_measure" => "enum: single|set|carton|dozen (required)",
                "tax_type"        => "enum: vat|exempt (required, default: vat)",
                "tax_rate"        => "decimal (optional, default: 7.50)",
                "stock_quantity"  => "decimal (optional, default: 0)",
                "reorder_level"   => "decimal (optional, default: 0)"
            ]
        ),
        $ep("GET",    "{$base}/products/{id}",      "Get single product details.", true, ["admin","sales","accounting"]),
        $ep("PUT",    "{$base}/products/{id}",      "Update product.", true, ["admin"]),
        $ep("DELETE", "{$base}/products/{id}",      "Soft-delete product.", true, ["admin"]),
        $ep("GET",    "{$base}/products/low-stock",  "Get all products at or below reorder level. Uses v_low_stock view.", true, ["admin"]),
    ];

    // -------------------------------------------------------
    // QUOTATIONS
    // -------------------------------------------------------
    $quotations = [
        $ep("GET",    "{$base}/quotations",                          "List quotations. Sales sees own only; Admin sees all.", true, ["admin","sales"],
            [
                "status" => "string (optional) — draft|sent|accepted|rejected|expired|converted",
                "client_id" => "integer (optional)",
                "from"  => "date (optional, YYYY-MM-DD)",
                "to"    => "date (optional, YYYY-MM-DD)",
                "search"=> "string (optional) — quote number or client name",
                "page"  => "integer (optional)",
                "per_page" => "integer (optional)"
            ]
        ),
        $ep("POST",   "{$base}/quotations",                          "Create a new quotation with line items.", true, ["admin","sales"],
            [
                "client_id"      => "integer (required)",
                "currency"       => "enum: NGN|USD (optional, default: client currency)",
                "exchange_rate"  => "decimal (required if currency=USD)",
                "discount_type"  => "enum: percentage|none (optional, default: none)",
                "discount_value" => "decimal (required if discount_type=percentage)",
                "notes"          => "string (optional)",
                "items"          => "array (required) — each item:",
                "  items[].product_id"      => "integer (optional — if null, provide description)",
                "  items[].description"     => "string (required)",
                "  items[].quantity"        => "decimal (required)",
                "  items[].unit_price"      => "decimal (required — snapshotted)",
                "  items[].tax_rate"        => "decimal (optional — defaults from product)",
                "  items[].discount_type"   => "enum: fixed|none (optional)",
                "  items[].discount_value"  => "decimal (optional — fixed amount off this line)"
            ]
        ),
        $ep("GET",    "{$base}/quotations/{id}",                     "Get quotation with all line items.", true, ["admin","sales"]),
        $ep("PUT",    "{$base}/quotations/{id}",                     "Update draft quotation and items.", true, ["admin","sales"],
            [
                "Same body as create — only draft status can be edited"
            ]
        ),
        $ep("DELETE", "{$base}/quotations/{id}",                     "Delete a draft quotation.", true, ["admin","sales"]),
        $ep("POST",   "{$base}/quotations/{id}/send",                "Mark quotation as 'sent'. Sets expiry = issue_date + 14 days.", true, ["admin","sales"]),
        $ep("POST",   "{$base}/quotations/{id}/accept",              "Mark quotation as 'accepted'.", true, ["admin","sales"]),
        $ep("POST",   "{$base}/quotations/{id}/reject",              "Mark quotation as 'rejected'.", true, ["admin","sales"]),
        $ep("POST",   "{$base}/quotations/{id}/convert-proforma",    "Convert quotation to proforma invoice. Items are copyable & editable.", true, ["admin","sales"],
            [
                "items" => "array (optional) — if provided, overrides original items (edit during conversion)"
            ]
        ),
        $ep("POST",   "{$base}/quotations/{id}/convert-invoice",     "Convert quotation directly to invoice (skip proforma). Items editable.", true, ["admin","sales"],
            [
                "items"         => "array (optional) — editable items for conversion",
                "payment_terms" => "enum: due_on_receipt|net_7 (optional, default: client setting)"
            ]
        ),
        $ep("GET",    "{$base}/quotations/{id}/pdf",                 "Download quotation as PDF.", true, ["admin","sales"]),
    ];

    // -------------------------------------------------------
    // PROFORMA INVOICES
    // -------------------------------------------------------
    $proformas = [
        $ep("GET",    "{$base}/proformas",                           "List proforma invoices. Sales sees own only.", true, ["admin","sales"],
            [
                "status"    => "string (optional) — draft|sent|approved|rejected|converted|expired",
                "client_id" => "integer (optional)",
                "from"      => "date (optional)",
                "to"        => "date (optional)",
                "search"    => "string (optional)",
                "page"      => "integer (optional)",
                "per_page"  => "integer (optional)"
            ]
        ),
        $ep("POST",   "{$base}/proformas",                           "Create proforma (standalone or from quotation).", true, ["admin","sales"],
            [
                "quotation_id"   => "integer (optional — if from quote conversion)",
                "client_id"      => "integer (required if no quotation_id)",
                "currency"       => "enum: NGN|USD (optional)",
                "exchange_rate"  => "decimal (required if USD)",
                "discount_type"  => "enum: percentage|none (optional)",
                "discount_value" => "decimal (optional)",
                "notes"          => "string (optional)",
                "items"          => "array (required if no quotation_id — same structure as quotation items)"
            ]
        ),
        $ep("GET",    "{$base}/proformas/{id}",                      "Get proforma with line items.", true, ["admin","sales"]),
        $ep("PUT",    "{$base}/proformas/{id}",                      "Update draft proforma.", true, ["admin","sales"]),
        $ep("DELETE", "{$base}/proformas/{id}",                      "Delete draft proforma.", true, ["admin","sales"]),
        $ep("POST",   "{$base}/proformas/{id}/send",                 "Mark proforma as 'sent'.", true, ["admin","sales"]),
        $ep("POST",   "{$base}/proformas/{id}/approve",              "Mark proforma as 'approved' (client approved).", true, ["admin","sales"]),
        $ep("POST",   "{$base}/proformas/{id}/reject",               "Mark proforma as 'rejected'.", true, ["admin","sales"]),
        $ep("POST",   "{$base}/proformas/{id}/convert-invoice",      "Convert approved proforma to final invoice. Items editable.", true, ["admin","sales"],
            [
                "items"         => "array (optional — editable items)",
                "payment_terms" => "enum: due_on_receipt|net_7 (optional)"
            ]
        ),
        $ep("GET",    "{$base}/proformas/{id}/pdf",                  "Download proforma as PDF.", true, ["admin","sales"]),
    ];

    // -------------------------------------------------------
    // INVOICES
    // -------------------------------------------------------
    $invoices = [
        $ep("GET",    "{$base}/invoices",                            "List invoices. Sales sees own; Accounting sees all.", true, ["admin","sales","accounting"],
            [
                "status"    => "string (optional) — draft|sent|partial|paid|overdue|cancelled",
                "client_id" => "integer (optional)",
                "from"      => "date (optional)",
                "to"        => "date (optional)",
                "search"    => "string (optional) — invoice number or client name",
                "page"      => "integer (optional)",
                "per_page"  => "integer (optional)"
            ]
        ),
        $ep("POST",   "{$base}/invoices",                            "Create invoice (usually via conversion, but standalone allowed).", true, ["admin","sales"],
            [
                "proforma_id"    => "integer (optional)",
                "quotation_id"   => "integer (optional)",
                "client_id"      => "integer (required if no proforma/quotation)",
                "currency"       => "enum: NGN|USD (optional)",
                "exchange_rate"  => "decimal (required if USD)",
                "payment_terms"  => "enum: due_on_receipt|net_7 (required)",
                "discount_type"  => "enum: percentage|none (optional)",
                "discount_value" => "decimal (optional)",
                "notes"          => "string (optional)",
                "items"          => "array (required if no proforma/quotation)"
            ]
        ),
        $ep("GET",    "{$base}/invoices/{id}",                       "Get invoice with line items and payment history.", true, ["admin","sales","accounting"]),
        $ep("PUT",    "{$base}/invoices/{id}",                       "Update draft invoice.", true, ["admin","sales"]),
        $ep("DELETE", "{$base}/invoices/{id}",                       "Delete draft invoice.", true, ["admin"]),
        $ep("POST",   "{$base}/invoices/{id}/finalize",              "Finalize invoice: locks it, deducts stock, sets status to 'sent'. Admin only.", true, ["admin"]),
        $ep("POST",   "{$base}/invoices/{id}/send",                  "Mark as sent (without finalizing — if you need a separate send step).", true, ["admin"]),
        $ep("POST",   "{$base}/invoices/{id}/cancel",                "Cancel invoice. Restores stock if already deducted.", true, ["admin"],
            [
                "reason" => "string (optional) — cancellation reason"
            ]
        ),
        $ep("GET",    "{$base}/invoices/{id}/pdf",                   "Download invoice as PDF.", true, ["admin","sales","accounting"]),
    ];

    // -------------------------------------------------------
    // PAYMENTS
    // -------------------------------------------------------
    $payments = [
        $ep("GET",    "{$base}/invoices/{id}/payments",              "List all payments for a specific invoice.", true, ["admin","accounting"]),
        $ep("POST",   "{$base}/invoices/{id}/payments",              "Record a payment against an invoice. Updates amount_paid & balance_due.", true, ["admin","accounting"],
            [
                "amount"         => "decimal (required) — must be > 0 and <= balance_due",
                "payment_date"   => "date (required, YYYY-MM-DD)",
                "payment_method" => "enum: bank_transfer|cash|cheque|pos|other (required)",
                "reference"      => "string (optional) — bank ref or cheque number",
                "notes"          => "string (optional)"
            ]
        ),
        $ep("GET",    "{$base}/payments",                            "List all payments across invoices. Accounting & Admin only.", true, ["admin","accounting"],
            [
                "from"           => "date (optional)",
                "to"             => "date (optional)",
                "payment_method" => "string (optional)",
                "client_id"      => "integer (optional)",
                "page"           => "integer (optional)",
                "per_page"       => "integer (optional)"
            ]
        ),
    ];

    // -------------------------------------------------------
    // REPORTS
    // -------------------------------------------------------
    $reports = [
        $ep("GET", "{$base}/reports/monthly-sales",    "Monthly revenue, VAT collected, payments received. Uses v_monthly_sales view.", true, ["admin","accounting"],
            [
                "year"  => "integer (optional, default: current year)",
                "month" => "integer (optional, 1-12 — filter single month)"
            ]
        ),
        $ep("GET", "{$base}/reports/top-products",     "Products ranked by quantity sold and revenue. Uses v_top_products view.", true, ["admin","accounting"],
            [
                "limit"       => "integer (optional, default: 20)",
                "category_id" => "integer (optional — filter by category)",
                "from"        => "date (optional)",
                "to"          => "date (optional)"
            ]
        ),
        $ep("GET", "{$base}/reports/stock-levels",     "Current stock levels with low-stock alerts. Uses v_low_stock view.", true, ["admin"],
            [
                "category_id" => "integer (optional)",
                "status"      => "string (optional) — OUT OF STOCK|LOW STOCK|OK"
            ]
        ),
        $ep("GET", "{$base}/reports/vat-collected",    "VAT breakdown: standard vs exempt, grouped by month.", true, ["admin","accounting"],
            [
                "year"  => "integer (optional)",
                "month" => "integer (optional)"
            ]
        ),
        $ep("GET", "{$base}/reports/staff-sales",      "Sales performance per staff member. Uses v_sales_per_staff view. Sales sees own only.", true, ["admin","sales","accounting"],
            [
                "year"  => "integer (optional)",
                "month" => "integer (optional)"
            ]
        ),
        $ep("GET", "{$base}/reports/outstanding",      "Outstanding invoices with aging buckets. Uses v_outstanding_invoices view.", true, ["admin","accounting"],
            [
                "client_id"   => "integer (optional)",
                "aging_bucket"=> "integer (optional) — 0=Current, 1=1-30d, 2=31-60d, 3=61-90d, 4=90+d"
            ]
        ),
        $ep("GET", "{$base}/reports/quotation-conversion", "Quotation conversion rate: created vs converted to invoice.", true, ["admin"],
            [
                "from" => "date (optional)",
                "to"   => "date (optional)"
            ]
        ),
        $ep("GET", "{$base}/reports/overdue",          "All overdue invoices with days overdue count.", true, ["admin","accounting"],
            [
                "client_id" => "integer (optional)"
            ]
        ),
        $ep("GET", "{$base}/reports/payment-history",  "All payments within a date range, grouped by method.", true, ["admin","accounting"],
            [
                "from"           => "date (optional)",
                "to"             => "date (optional)",
                "payment_method" => "string (optional)"
            ]
        ),
    ];

    // -------------------------------------------------------
    // COMPANY SETTINGS
    // -------------------------------------------------------
    $settings = [
        $ep("GET", "{$base}/settings",  "Get company settings (branding, bank details, legal footer).", true, ["admin"]),
        $ep("PUT", "{$base}/settings",  "Update company settings.", true, ["admin"],
            [
                "company_name"   => "string (optional)",
                "address"        => "string (optional)",
                "city"           => "string (optional)",
                "state"          => "string (optional)",
                "country"        => "string (optional)",
                "phone"          => "string (optional)",
                "email"          => "string (optional)",
                "website"        => "string (optional)",
                "logo"           => "file (optional) — multipart/form-data upload",
                "bank_name"      => "string (optional)",
                "account_name"   => "string (optional)",
                "account_number" => "string (optional)",
                "bank_branch"    => "string (optional)",
                "vat_number"     => "string (optional)",
                "legal_footer"   => "string (optional)"
            ]
        ),
        $ep("POST", "{$base}/settings/upload-logo", "Upload company logo image. Returns logo_path.", true, ["admin"],
            [
                "logo" => "file (required) — jpg/png, max 2MB"
            ]
        ),
    ];

    // -------------------------------------------------------
    // DASHBOARD
    // -------------------------------------------------------
    $dashboard = [
        $ep("GET", "{$base}/dashboard", "Summary cards: total revenue, unpaid invoices, low stock count, quotes pending, recent activity.", true, ["admin","sales","accounting"]),
    ];

    // -------------------------------------------------------
    // ACTIVITY LOG
    // -------------------------------------------------------
    $activity = [
        $ep("GET", "{$base}/activity-log", "System audit trail. Admin sees all; others see own actions.", true, ["admin","sales","accounting"],
            [
                "user_id"    => "integer (optional)",
                "action"     => "string (optional) — e.g. invoice.created",
                "model_type" => "string (optional) — e.g. Invoice, Quotation",
                "from"       => "date (optional)",
                "to"         => "date (optional)",
                "page"       => "integer (optional)",
                "per_page"   => "integer (optional)"
            ]
        ),
    ];

    // -------------------------------------------------------
    // Assemble final response
    // -------------------------------------------------------
    $documentation = [
        "system"         => "Otelex Invoicing System",
        "version"        => "1.0.0",
        "base_url"       => $base,
        "authentication" => "Secure HttpOnly cookie session; include X-CSRF-Token on state-changing requests.",
        "date_format"    => "YYYY-MM-DD",
        "currency"       => "NGN (primary), USD (optional with exchange rate)",
        "document_flow"  => [
            "1. Quotation (QUO/YYYY/NNN) → client accepts or rejects",
            "2. Proforma Invoice (PRO/YYYY/NNN) → formal approval before delivery",
            "3. Tax Invoice (INV/YYYY/NNN) → final billable document, triggers stock deduction",
            "4. Payment → one or more payments recorded against invoice"
        ],
        "status_codes"   => [
            "quotation" => ["draft", "sent", "accepted", "rejected", "expired", "converted"],
            "proforma"  => ["draft", "sent", "approved", "rejected", "converted", "expired"],
            "invoice"   => ["draft", "sent", "partial", "paid", "overdue", "cancelled"]
        ],
        "user_roles"     => [
            "admin"      => "Full access — manage users, products, settings, approve invoices",
            "sales"      => "Create quotations, proformas, invoices (own only). Cannot finalize or manage products.",
            "accounting" => "Record payments, view all reports, send reminders. Cannot create documents or manage products."
        ],
        "discount_rules" => [
            "document_level" => "Percentage discount on subtotal (stored on parent document)",
            "item_level"     => "Fixed amount discount per line item (stored on _items table)",
            "calculation"    => "Item discounts applied first → subtotal → document percentage discount → taxable_amount → tax → total"
        ],
        "stock_rules"    => [
            "deduction_trigger" => "Stock deducted ONLY when invoice is finalized (not on quotation or proforma)",
            "double_deduction_guard" => "invoices.stock_deducted flag prevents duplicate deduction",
            "negative_stock"    => "System warns if stock would go negative (configurable: block or allow)"
        ],
        "reminder_rules" => [
            "trigger"   => "Daily cron job checks next_reminder_at",
            "schedule"  => "On due date → +3 days → +7 days → +14 days (4 max)",
            "after_max" => "next_reminder_at set to NULL, no further automated reminders"
        ],
        "pdf_generation" => "Server-side via PHP (DOMPDF/mPDF). All documents include logo, company details, bank info, legal footer.",
        "endpoints"      => [
            "Authentication"        => $auth,
            "Users"                 => $users,
            "Clients"               => $clients,
            "Product Categories"    => $categories,
            "Products"              => $products,
            "Quotations"            => $quotations,
            "Proforma Invoices"     => $proformas,
            "Invoices"              => $invoices,
            "Payments"              => $payments,
            "Reports"               => $reports,
            "Company Settings"      => $settings,
            "Dashboard"             => $dashboard,
            "Activity Log"          => $activity,
        ],
        "total_endpoints" => (
            count($auth) + count($users) + count($clients) + count($categories) +
            count($products) + count($quotations) + count($proformas) +
            count($invoices) + count($payments) + count($reports) +
            count($settings) + count($dashboard) + count($activity)
        ),
        "notes" => [
            "All monetary values use DECIMAL(15,2) — never FLOAT.",
            "Prices and tax rates are snapshotted at document creation. Historical documents are immutable.",
            "Document numbers format: TYPE/YYYY/NNN (e.g. INV/2026/001). Resets every January.",
            "Quotation validity is always 14 days from issue_date.",
            "Payment terms: due_on_receipt (due_date = issue_date) or net_7 (due_date = issue_date + 7 days).",
            "Sales staff can only see/edit their own documents. Admin sees everything.",
            "Soft-delete pattern: is_active=0 on clients/products/users. Never hard-delete.",
            "Pagination: all list endpoints support ?page=1&per_page=20. Response includes meta: {current_page, per_page, total, last_page}.",
            "Standard error response: { status: 'failed', message: '...' } with appropriate HTTP status code."
        ]
    ];

    http_response_code(200);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($documentation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "status"  => "failed",
        "message" => $e->getMessage()
    ], JSON_PRETTY_PRINT);
    exit;
}
?>