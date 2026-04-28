<?php
// routes/reports/documentFlow.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /reports/document-flow
 * Tracks the full sales pipeline from quotation through to paid invoice.
 * Shows conversion rates and drop-offs at each stage.
 *
 * Pipeline stages:
 *   Quotations created → Accepted → Converted (to proforma or invoice)
 *   Proformas created  → Approved → Converted (to invoice)
 *   Invoices created   → Sent     → Paid
 *
 * Roles allowed: Admin, Accountant
 *
 * Query params:
 *   ?from=2026-01-01  &to=2026-04-30   (defaults to current month)
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
    $from     = isset($_GET['from']) && DateTime::createFromFormat('Y-m-d', trim($_GET['from']))
                ? trim($_GET['from']) : date('Y-m-01');
    $to       = isset($_GET['to']) && DateTime::createFromFormat('Y-m-d', trim($_GET['to']))
                ? trim($_GET['to']) : date('Y-m-t');
    $currency = isset($_GET['currency']) && in_array(strtoupper(trim($_GET['currency'])), ['NGN','USD'])
                ? strtoupper(trim($_GET['currency'])) : 'NGN';

    if ($from > $to) throw new Exception("'from' date cannot be after 'to' date.", 422);

    // -------------------------------------------------------
    // 2. Quotation funnel
    // -------------------------------------------------------
    $quoteStmt = $conn->prepare("
        SELECT
            COUNT(*)                                                    AS total_created,
            COUNT(CASE WHEN status = 'sent'      THEN 1 END)           AS sent,
            COUNT(CASE WHEN status = 'accepted'  THEN 1 END)           AS accepted,
            COUNT(CASE WHEN status = 'rejected'  THEN 1 END)           AS rejected,
            COUNT(CASE WHEN status = 'expired'   THEN 1 END)           AS expired,
            COUNT(CASE WHEN status = 'converted' THEN 1 END)           AS converted,
            COALESCE(SUM(total_amount), 0)                              AS total_value,
            COALESCE(SUM(CASE WHEN status = 'converted'
                              THEN total_amount ELSE 0 END), 0)         AS converted_value,
            COALESCE(AVG(DATEDIFF(
                CASE WHEN status IN ('accepted','converted')
                     THEN updated_at ELSE NULL END,
                created_at)), 0)                                        AS avg_days_to_accept
        FROM quotations
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $quoteStmt->bind_param("ss", $from, $to);
    $quoteStmt->execute();
    $quoteData = $quoteStmt->get_result()->fetch_assoc();
    $quoteStmt->close();

    $qTotal     = (int)$quoteData['total_created'];
    $qConverted = (int)$quoteData['converted'];
    $qValue     = (float)$quoteData['total_value'];
    $qConvVal   = (float)$quoteData['converted_value'];

    $quotationFunnel = [
        "total_created"       => $qTotal,
        "sent"                => (int)$quoteData['sent'],
        "accepted"            => (int)$quoteData['accepted'],
        "rejected"            => (int)$quoteData['rejected'],
        "expired"             => (int)$quoteData['expired'],
        "converted"           => $qConverted,
        "total_value"         => $qValue,
        "converted_value"     => $qConvVal,
        "conversion_rate"     => $qTotal > 0 ? round(($qConverted / $qTotal) * 100, 2) : 0,
        "value_conversion_rate"=> $qValue > 0 ? round(($qConvVal / $qValue) * 100, 2) : 0,
        "avg_days_to_accept"  => round((float)$quoteData['avg_days_to_accept'], 1)
    ];

    // -------------------------------------------------------
    // 3. Proforma funnel
    // -------------------------------------------------------
    $proformaStmt = $conn->prepare("
        SELECT
            COUNT(*)                                                    AS total_created,
            COUNT(CASE WHEN status = 'sent'      THEN 1 END)           AS sent,
            COUNT(CASE WHEN status = 'approved'  THEN 1 END)           AS approved,
            COUNT(CASE WHEN status = 'rejected'  THEN 1 END)           AS rejected,
            COUNT(CASE WHEN status = 'expired'   THEN 1 END)           AS expired,
            COUNT(CASE WHEN status = 'converted' THEN 1 END)           AS converted,
            COALESCE(SUM(total_amount), 0)                              AS total_value,
            COALESCE(SUM(CASE WHEN status = 'converted'
                              THEN total_amount ELSE 0 END), 0)         AS converted_value,
            COUNT(CASE WHEN quotation_id IS NULL THEN 1 END)            AS standalone,
            COUNT(CASE WHEN quotation_id IS NOT NULL THEN 1 END)        AS from_quotation
        FROM proforma_invoices
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $proformaStmt->bind_param("ss", $from, $to);
    $proformaStmt->execute();
    $proformaData = $proformaStmt->get_result()->fetch_assoc();
    $proformaStmt->close();

    $pTotal     = (int)$proformaData['total_created'];
    $pConverted = (int)$proformaData['converted'];
    $pValue     = (float)$proformaData['total_value'];
    $pConvVal   = (float)$proformaData['converted_value'];

    $proformaFunnel = [
        "total_created"        => $pTotal,
        "standalone"           => (int)$proformaData['standalone'],
        "from_quotation"       => (int)$proformaData['from_quotation'],
        "sent"                 => (int)$proformaData['sent'],
        "approved"             => (int)$proformaData['approved'],
        "rejected"             => (int)$proformaData['rejected'],
        "expired"              => (int)$proformaData['expired'],
        "converted"            => $pConverted,
        "total_value"          => $pValue,
        "converted_value"      => $pConvVal,
        "conversion_rate"      => $pTotal > 0 ? round(($pConverted / $pTotal) * 100, 2) : 0,
        "value_conversion_rate"=> $pValue > 0 ? round(($pConvVal / $pValue) * 100, 2) : 0
    ];

    // -------------------------------------------------------
    // 4. Invoice funnel
    // -------------------------------------------------------
    $invoiceStmt = $conn->prepare("
        SELECT
            COUNT(*)                                                     AS total_created,
            COUNT(CASE WHEN status = 'sent'     THEN 1 END)             AS sent,
            COUNT(CASE WHEN status = 'partial'  THEN 1 END)             AS partial,
            COUNT(CASE WHEN status = 'paid'     THEN 1 END)             AS paid,
            COUNT(CASE WHEN status = 'overdue'  THEN 1 END)             AS overdue,
            COUNT(CASE WHEN status = 'cancelled'THEN 1 END)             AS cancelled,
            COUNT(CASE WHEN proforma_id IS NOT NULL THEN 1 END)         AS from_proforma,
            COUNT(CASE WHEN quotation_id IS NOT NULL AND
                            proforma_id IS NULL THEN 1 END)             AS from_quotation_direct,
            COUNT(CASE WHEN proforma_id IS NULL AND
                            quotation_id IS NULL THEN 1 END)            AS standalone,
            COALESCE(SUM(total_amount), 0)                              AS total_invoiced,
            COALESCE(SUM(amount_paid), 0)                               AS total_collected,
            COALESCE(SUM(CASE WHEN status = 'paid'
                              THEN total_amount ELSE 0 END), 0)         AS paid_value,
            COALESCE(AVG(CASE WHEN status = 'paid'
                THEN DATEDIFF(updated_at, issue_date) END), 0)          AS avg_days_to_payment
        FROM invoices
        WHERE issue_date BETWEEN ? AND ?
          AND currency = ?
    ");
    $invoiceStmt->bind_param("sss", $from, $to, $currency);
    $invoiceStmt->execute();
    $invoiceData = $invoiceStmt->get_result()->fetch_assoc();
    $invoiceStmt->close();

    $iTotal       = (int)$invoiceData['total_created'];
    $iPaid        = (int)$invoiceData['paid'];
    $iTotalValue  = (float)$invoiceData['total_invoiced'];
    $iPaidValue   = (float)$invoiceData['paid_value'];

    $invoiceFunnel = [
        "total_created"         => $iTotal,
        "standalone"            => (int)$invoiceData['standalone'],
        "from_quotation_direct" => (int)$invoiceData['from_quotation_direct'],
        "from_proforma"         => (int)$invoiceData['from_proforma'],
        "sent"                  => (int)$invoiceData['sent'],
        "partial"               => (int)$invoiceData['partial'],
        "paid"                  => $iPaid,
        "overdue"               => (int)$invoiceData['overdue'],
        "cancelled"             => (int)$invoiceData['cancelled'],
        "total_invoiced"        => $iTotalValue,
        "total_collected"       => (float)$invoiceData['total_collected'],
        "paid_value"            => $iPaidValue,
        "payment_rate"          => $iTotal > 0 ? round(($iPaid / $iTotal) * 100, 2) : 0,
        "value_payment_rate"    => $iTotalValue > 0 ? round(($iPaidValue / $iTotalValue) * 100, 2) : 0,
        "avg_days_to_payment"   => round((float)$invoiceData['avg_days_to_payment'], 1)
    ];

    // -------------------------------------------------------
    // 5. End-to-end pipeline (quote → paid invoice)
    // -------------------------------------------------------
    $endToEndRate = $qTotal > 0 ? round(($iPaid / $qTotal) * 100, 2) : 0;

    // -------------------------------------------------------
    // 6. Monthly pipeline trend
    // -------------------------------------------------------
    $trendStmt = $conn->prepare("
        SELECT
            DATE_FORMAT(DATE(created_at), '%Y-%m') AS month,
            'quotation'                            AS doc_type,
            COUNT(*)                               AS total,
            COUNT(CASE WHEN status = 'converted'  THEN 1 END) AS converted
        FROM quotations
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY month

        UNION ALL

        SELECT
            DATE_FORMAT(DATE(created_at), '%Y-%m') AS month,
            'proforma'                             AS doc_type,
            COUNT(*)                               AS total,
            COUNT(CASE WHEN status = 'converted'  THEN 1 END) AS converted
        FROM proforma_invoices
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY month

        UNION ALL

        SELECT
            DATE_FORMAT(issue_date, '%Y-%m')       AS month,
            'invoice'                              AS doc_type,
            COUNT(*)                               AS total,
            COUNT(CASE WHEN status = 'paid'       THEN 1 END) AS converted
        FROM invoices
        WHERE issue_date BETWEEN ? AND ?
          AND currency = ?
          AND status != 'draft'
        GROUP BY month

        ORDER BY month ASC, doc_type ASC
    ");
    $trendStmt->bind_param("sssssss", $from, $to, $from, $to, $from, $to, $currency);
    $trendStmt->execute();
    $trendRows = $trendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $trendStmt->close();

    // Pivot trend into monthly objects
    $trendPivot = [];
    foreach ($trendRows as $row) {
        $month = $row['month'];
        if (!isset($trendPivot[$month])) {
            $trendPivot[$month] = ["month" => $month];
        }
        $trendPivot[$month][$row['doc_type'] . '_total']     = (int)$row['total'];
        $trendPivot[$month][$row['doc_type'] . '_converted']  = (int)$row['converted'];
    }
    $trend = array_values($trendPivot);

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Document flow report fetched successfully.",
        "data"    => [
            "period"        => ["from" => $from, "to" => $to, "currency" => $currency],
            "pipeline"      => [
                "end_to_end_rate" => $endToEndRate,
                "description"     => "Percentage of quotations that resulted in a paid invoice."
            ],
            "quotations"    => $quotationFunnel,
            "proformas"     => $proformaFunnel,
            "invoices"      => $invoiceFunnel,
            "trend"         => $trend
        ]
    ]);

} catch (Exception $e) {
    error_log("Document Flow Report Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
