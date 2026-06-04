<?php
// routes/reports/invoiceAging.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /reports/invoice-aging
 * Returns outstanding invoices grouped by aging buckets.
 *
 * Important:
 * This route intentionally reads directly from invoices + clients instead of
 * relying on the v_outstanding_invoices database view. Some local XAMPP imports
 * can fail when exported views contain a live-server DEFINER user that does not
 * exist locally, which then causes this report to return a 500 error.
 *
 * Roles allowed: Super Admin, Admin, Accounting
 *
 * Query params:
 *   ?currency=NGN|USD
 *   &client_id=1
 *   &bucket=0|1|2|3|4
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    if (!in_array($userData['role'], ['super_admin', 'admin', 'accounting'], true)) {
        throw new Exception("Unauthorized: Admins and Accounting users only.", 403);
    }

    $clientId = isset($_GET['client_id']) && is_numeric($_GET['client_id'])
        ? (int) $_GET['client_id']
        : null;

    $currency = isset($_GET['currency']) && in_array(strtoupper(trim((string) $_GET['currency'])), ['NGN', 'USD'], true)
        ? strtoupper(trim((string) $_GET['currency']))
        : null;

    $bucket = isset($_GET['bucket']) && is_numeric($_GET['bucket'])
        ? (int) $_GET['bucket']
        : null;

    if ($bucket !== null && ($bucket < 0 || $bucket > 4)) {
        throw new Exception("Invalid aging bucket selected.", 422);
    }

    $where = [
        "i.status IN ('sent', 'partial', 'overdue')",
        "i.balance_due > 0",
    ];
    $params = [];
    $types = '';

    if ($clientId !== null) {
        $where[] = "i.client_id = ?";
        $params[] = $clientId;
        $types .= 'i';
    }

    if ($currency !== null) {
        $where[] = "i.currency = ?";
        $params[] = $currency;
        $types .= 's';
    }

    $daysOverdueExpr = "DATEDIFF(CURDATE(), i.due_date)";
    $agingBucketExpr = "CASE
        WHEN {$daysOverdueExpr} <= 0 THEN 0
        WHEN {$daysOverdueExpr} BETWEEN 1 AND 30 THEN 1
        WHEN {$daysOverdueExpr} BETWEEN 31 AND 60 THEN 2
        WHEN {$daysOverdueExpr} BETWEEN 61 AND 90 THEN 3
        ELSE 4
    END";

    $agingLabelExpr = "CASE
        WHEN {$daysOverdueExpr} <= 0 THEN 'Current'
        WHEN {$daysOverdueExpr} BETWEEN 1 AND 30 THEN '1-30 days'
        WHEN {$daysOverdueExpr} BETWEEN 31 AND 60 THEN '31-60 days'
        WHEN {$daysOverdueExpr} BETWEEN 61 AND 90 THEN '61-90 days'
        ELSE '90+ days'
    END";

    if ($bucket !== null) {
        $where[] = "({$agingBucketExpr}) = ?";
        $params[] = $bucket;
        $types .= 'i';
    }

    $sql = "
        SELECT
            i.id AS invoice_id,
            i.invoice_number,
            i.client_id,
            c.company_name,
            i.issue_date,
            i.due_date,
            i.total_amount,
            i.amount_paid,
            i.balance_due,
            i.currency,
            i.status,
            i.payment_terms,
            {$daysOverdueExpr} AS days_overdue,
            {$agingBucketExpr} AS aging_bucket,
            {$agingLabelExpr} AS aging_label
        FROM invoices i
        INNER JOIN clients c ON c.id = i.client_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY aging_bucket DESC, days_overdue DESC, i.due_date ASC, i.id DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Unable to prepare invoice aging report.", 500);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $invoices = [];
    while ($row = $result->fetch_assoc()) {
        $invoices[] = [
            'invoice_id'     => (int) $row['invoice_id'],
            'invoice_number' => $row['invoice_number'],
            'client_id'      => (int) $row['client_id'],
            'company_name'   => $row['company_name'],
            'issue_date'     => $row['issue_date'],
            'due_date'       => $row['due_date'],
            'total_amount'   => (float) $row['total_amount'],
            'amount_paid'    => (float) $row['amount_paid'],
            'balance_due'    => (float) $row['balance_due'],
            'currency'       => $row['currency'],
            'status'         => $row['status'],
            'payment_terms'  => $row['payment_terms'],
            'days_overdue'   => (int) $row['days_overdue'],
            'aging_bucket'   => (int) $row['aging_bucket'],
            'aging_label'    => $row['aging_label'],
        ];
    }
    $stmt->close();

    $buckets = [
        0 => ['label' => 'Current',    'count' => 0, 'total_balance' => 0.00],
        1 => ['label' => '1-30 days',  'count' => 0, 'total_balance' => 0.00],
        2 => ['label' => '31-60 days', 'count' => 0, 'total_balance' => 0.00],
        3 => ['label' => '61-90 days', 'count' => 0, 'total_balance' => 0.00],
        4 => ['label' => '90+ days',   'count' => 0, 'total_balance' => 0.00],
    ];

    $grandTotal = 0.00;
    foreach ($invoices as $inv) {
        $agingBucket = (int) $inv['aging_bucket'];
        if (isset($buckets[$agingBucket])) {
            $buckets[$agingBucket]['count']++;
            $buckets[$agingBucket]['total_balance'] += (float) $inv['balance_due'];
        }
        $grandTotal += (float) $inv['balance_due'];
    }

    foreach ($buckets as &$bucketRow) {
        $bucketRow['total_balance'] = round((float) $bucketRow['total_balance'], 2);
    }
    unset($bucketRow);

    http_response_code(200);
    echo json_encode([
        'status'  => 'success',
        'message' => 'Invoice aging report fetched successfully.',
        'data'    => $invoices,
        'summary' => [
            'total_outstanding' => round($grandTotal, 2),
            'total_invoices'    => count($invoices),
            'buckets'           => array_values($buckets),
        ],
        'meta' => [
            'client_id' => $clientId,
            'currency'  => $currency,
            'bucket'    => $bucket,
        ],
    ]);
} catch (Exception $e) {
    error_log("Invoice Aging Report Error: " . $e->getMessage());
    $code = $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    http_response_code($code);
    echo json_encode([
        'status'  => 'failed',
        'message' => $code >= 500 ? 'Unable to load invoice aging report right now.' : $e->getMessage(),
    ]);
}
?>
