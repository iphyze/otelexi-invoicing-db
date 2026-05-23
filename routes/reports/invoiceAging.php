<?php
// routes/reports/invoiceAging.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /reports/invoice-aging
 * Returns outstanding invoices grouped by aging buckets.
 * Uses the v_outstanding_invoices view from schema.
 * Roles allowed: Admin, Accounting
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    if (!in_array($userData['role'], ['super_admin', 'admin', 'accounting'])) {
        throw new Exception("Unauthorized: Admins and Accounting users only.", 403);
    }

    // ── Optional filters ─────────────────────────────────────
    $clientId = isset($_GET['client_id']) && is_numeric($_GET['client_id'])
                ? (int)$_GET['client_id'] : null;
    $currency = isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['NGN', 'USD'])
                ? strtoupper($_GET['currency']) : null;
    $bucket   = isset($_GET['bucket']) && is_numeric($_GET['bucket'])
                ? (int)$_GET['bucket'] : null;   // 0=Current,1=1-30,2=31-60,3=61-90,4=90+

    // ── Build query from view ────────────────────────────────
    // v_outstanding_invoices columns:
    //   invoice_id, invoice_number, company_name, issue_date, due_date,
    //   total_amount, amount_paid, balance_due, currency, status,
    //   payment_terms, days_overdue, aging_bucket, aging_label

    $sql    = "SELECT * FROM v_outstanding_invoices WHERE 1=1";
    $params = [];
    $types  = '';

    if ($clientId !== null) {
        // The view doesn't expose client_id directly, so join to invoices
        // Use a subquery approach instead
        $sql    = "
            SELECT v.*
            FROM v_outstanding_invoices v
            JOIN invoices i ON i.invoice_number = v.invoice_number
            WHERE i.client_id = ?
        ";
        $params[] = $clientId;
        $types   .= 'i';

        if ($currency !== null) {
            $sql     .= ' AND v.currency = ?';
            $params[] = $currency;
            $types   .= 's';
        }
        if ($bucket !== null) {
            $sql     .= ' AND v.aging_bucket = ?';
            $params[] = $bucket;
            $types   .= 'i';
        }
    } else {
        if ($currency !== null) {
            $sql     .= ' AND currency = ?';
            $params[] = $currency;
            $types   .= 's';
        }
        if ($bucket !== null) {
            $sql     .= ' AND aging_bucket = ?';
            $params[] = $bucket;
            $types   .= 'i';
        }
    }

    $sql .= ' ORDER BY aging_bucket DESC, days_overdue DESC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("DB prepare error: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $invoices = [];
    while ($row = $result->fetch_assoc()) {
        $invoices[] = [
            'invoice_id'     => (int)$row['invoice_id'],
            'invoice_number' => $row['invoice_number'],
            'company_name'   => $row['company_name'],
            'issue_date'     => $row['issue_date'],
            'due_date'       => $row['due_date'],
            'total_amount'   => (float)$row['total_amount'],
            'amount_paid'    => (float)$row['amount_paid'],
            'balance_due'    => (float)$row['balance_due'],
            'currency'       => $row['currency'],
            'status'         => $row['status'],
            'payment_terms'  => $row['payment_terms'],
            'days_overdue'   => (int)$row['days_overdue'],
            'aging_bucket'   => (int)$row['aging_bucket'],
            'aging_label'    => $row['aging_label'],
        ];
    }
    $stmt->close();

    // ── Bucket summary ───────────────────────────────────────
    $buckets = [
        0 => ['label' => 'Current',    'count' => 0, 'total_balance' => 0.00],
        1 => ['label' => '1-30 days',  'count' => 0, 'total_balance' => 0.00],
        2 => ['label' => '31-60 days', 'count' => 0, 'total_balance' => 0.00],
        3 => ['label' => '61-90 days', 'count' => 0, 'total_balance' => 0.00],
        4 => ['label' => '90+ days',   'count' => 0, 'total_balance' => 0.00],
    ];

    $grandTotal = 0.00;
    foreach ($invoices as $inv) {
        $b = (int)$inv['aging_bucket'];
        if (isset($buckets[$b])) {
            $buckets[$b]['count']++;
            $buckets[$b]['total_balance'] += $inv['balance_due'];
        }
        $grandTotal += $inv['balance_due'];
    }

    // Round bucket totals
    foreach ($buckets as &$bk) {
        $bk['total_balance'] = round($bk['total_balance'], 2);
    }
    unset($bk);

    http_response_code(200);
    echo json_encode([
        'status'  => 'success',
        'message' => 'Invoice aging report fetched successfully.',
        'data'    => $invoices,
        'summary' => [
            'total_outstanding'  => round($grandTotal, 2),
            'total_invoices'     => count($invoices),
            'buckets'            => array_values($buckets),
        ],
        'meta' => [
            'client_id' => $clientId,
            'currency'  => $currency,
            'bucket'    => $bucket,
        ],
    ]);

} catch (Exception $e) {
    error_log("Invoice Aging Report Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'failed', 'message' => $e->getMessage()]);
}
?>