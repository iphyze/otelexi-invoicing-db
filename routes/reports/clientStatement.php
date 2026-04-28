<?php
// routes/reports/clientStatement.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /reports/client-statement
 * Generates a full account statement for a specific client.
 * Lists all invoices and payments in date order with a running balance.
 * Roles allowed: Admin, Accountant
 *
 * Query params:
 *   ?client_id=3         (required)
 *   &from=2026-01-01     (defaults to start of current year)
 *   &to=2026-04-30       (defaults to today)
 *   &currency=NGN|USD
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData         = authenticateUser();
    $loggedInUserRole = $userData['role'];

    if (!in_array($loggedInUserRole, ['admin', 'accountant'])) {
        throw new Exception("Unauthorized: Only Admins or Accountants can access reports.", 403);
    }

    // -------------------------------------------------------
    // 1. Parameters
    // -------------------------------------------------------
    if (!isset($_GET['client_id']) || !is_numeric($_GET['client_id'])) {
        throw new Exception("A valid 'client_id' is required.", 422);
    }

    $clientId = (int)$_GET['client_id'];
    $from     = isset($_GET['from']) && DateTime::createFromFormat('Y-m-d', trim($_GET['from']))
                ? trim($_GET['from']) : date('Y-01-01');
    $to       = isset($_GET['to']) && DateTime::createFromFormat('Y-m-d', trim($_GET['to']))
                ? trim($_GET['to']) : date('Y-m-d');
    $currency = isset($_GET['currency']) && in_array(strtoupper(trim($_GET['currency'])), ['NGN','USD'])
                ? strtoupper(trim($_GET['currency'])) : 'NGN';

    if ($from > $to) throw new Exception("'from' date cannot be after 'to' date.", 422);

    // -------------------------------------------------------
    // 2. Fetch client details
    // -------------------------------------------------------
    $clientStmt = $conn->prepare("
        SELECT id, company_name, email, phone, billing_address,
               city, state, country, currency, payment_terms, is_active
        FROM clients
        WHERE id = ?
        LIMIT 1
    ");
    $clientStmt->bind_param("i", $clientId);
    $clientStmt->execute();
    $client = $clientStmt->get_result()->fetch_assoc();
    $clientStmt->close();

    if (!$client) throw new Exception("Client not found.", 404);

    // -------------------------------------------------------
    // 3. Fetch invoices for this client in range
    // -------------------------------------------------------
    $invoicesStmt = $conn->prepare("
        SELECT
            i.id, i.invoice_number, i.issue_date, i.due_date,
            i.total_amount, i.amount_paid, i.balance_due,
            i.status, i.currency, i.payment_terms
        FROM invoices i
        WHERE i.client_id = ?
          AND i.currency = ?
          AND i.issue_date BETWEEN ? AND ?
          AND i.status NOT IN ('draft', 'cancelled')
        ORDER BY i.issue_date ASC, i.id ASC
    ");
    $invoicesStmt->bind_param("isss", $clientId, $currency, $from, $to);
    $invoicesStmt->execute();
    $invoiceRows = $invoicesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $invoicesStmt->close();

    // -------------------------------------------------------
    // 4. Fetch all payments for those invoices in range
    // -------------------------------------------------------
    $paymentsStmt = $conn->prepare("
        SELECT
            p.id, p.invoice_id, p.amount, p.payment_date,
            p.payment_method, p.reference, p.notes,
            i.invoice_number
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        WHERE i.client_id = ?
          AND i.currency = ?
          AND p.payment_date BETWEEN ? AND ?
        ORDER BY p.payment_date ASC, p.id ASC
    ");
    $paymentsStmt->bind_param("isss", $clientId, $currency, $from, $to);
    $paymentsStmt->execute();
    $paymentRows = $paymentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $paymentsStmt->close();

    // -------------------------------------------------------
    // 5. Merge into a single chronological ledger with running balance
    // -------------------------------------------------------
    $ledgerEntries = [];

    foreach ($invoiceRows as $inv) {
        $ledgerEntries[] = [
            'date'    => $inv['issue_date'],
            'type'    => 'invoice',
            'sort_id' => (int)$inv['id'],
            'data'    => $inv
        ];
    }
    foreach ($paymentRows as $pay) {
        $ledgerEntries[] = [
            'date'    => $pay['payment_date'],
            'type'    => 'payment',
            'sort_id' => (int)$pay['id'],
            'data'    => $pay
        ];
    }

    // Sort by date ASC, then by type (invoice before payment on same day), then id
    usort($ledgerEntries, function($a, $b) {
        if ($a['date'] !== $b['date']) return strcmp($a['date'], $b['date']);
        if ($a['type'] !== $b['type']) return $a['type'] === 'invoice' ? -1 : 1;
        return $a['sort_id'] <=> $b['sort_id'];
    });

    $runningBalance = 0.00;
    $ledger         = [];

    foreach ($ledgerEntries as $entry) {
        if ($entry['type'] === 'invoice') {
            $inv             = $entry['data'];
            $runningBalance += (float)$inv['total_amount'];
            $ledger[]        = [
                "date"           => $inv['issue_date'],
                "type"           => "invoice",
                "reference"      => $inv['invoice_number'],
                "due_date"       => $inv['due_date'],
                "status"         => $inv['status'],
                "debit"          => (float)$inv['total_amount'],
                "credit"         => 0.00,
                "running_balance"=> round($runningBalance, 2)
            ];
        } else {
            $pay             = $entry['data'];
            $runningBalance -= (float)$pay['amount'];
            $ledger[]        = [
                "date"           => $pay['payment_date'],
                "type"           => "payment",
                "reference"      => $pay['invoice_number'] . ($pay['reference'] ? " – " . $pay['reference'] : ""),
                "payment_method" => $pay['payment_method'],
                "notes"          => $pay['notes'],
                "debit"          => 0.00,
                "credit"         => (float)$pay['amount'],
                "running_balance"=> round($runningBalance, 2)
            ];
        }
    }

    // -------------------------------------------------------
    // 6. Totals
    // -------------------------------------------------------
    $totalInvoiced = array_sum(array_column(
        array_filter($ledger, fn($e) => $e['type'] === 'invoice'), 'debit'
    ));
    $totalPaid = array_sum(array_column(
        array_filter($ledger, fn($e) => $e['type'] === 'payment'), 'credit'
    ));
    $closingBalance = round($runningBalance, 2);

    // Outstanding invoices summary
    $outstandingInvoices = array_values(array_filter($invoiceRows, fn($i) =>
        in_array($i['status'], ['sent', 'partial', 'overdue'])
    ));
    $totalOutstanding = array_sum(array_column($outstandingInvoices, 'balance_due'));

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Client statement fetched successfully.",
        "data"    => [
            "client"  => [
                "id"            => (int)$client['id'],
                "company_name"  => $client['company_name'],
                "email"         => $client['email'],
                "phone"         => $client['phone'],
                "address"       => $client['billing_address'],
                "city"          => $client['city'],
                "state"         => $client['state'],
                "country"       => $client['country'],
                "payment_terms" => $client['payment_terms']
            ],
            "period"  => ["from" => $from, "to" => $to, "currency" => $currency],
            "summary" => [
                "total_invoiced"     => round($totalInvoiced, 2),
                "total_paid"         => round($totalPaid, 2),
                "closing_balance"    => $closingBalance,
                "total_outstanding"  => round($totalOutstanding, 2),
                "outstanding_invoices_count" => count($outstandingInvoices)
            ],
            "ledger" => $ledger
        ]
    ]);

} catch (Exception $e) {
    error_log("Client Statement Report Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
