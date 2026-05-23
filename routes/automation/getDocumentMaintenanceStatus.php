<?php
// routes/automation/getDocumentMaintenanceStatus.php
// GET /automation/document-maintenance/status
// Admin-only summary used by the dashboard automation panel.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();

    if (($user['role'] ?? '') !== 'super_admin') {
        throw new Exception('Only an administrator can view document-check history.', 403);
    }

    $runsResult = $conn->query(
        'SELECT id, trigger_source, started_at, completed_at, run_status,
                invoices_marked_overdue, quotation_expired_count, proforma_expired_count,
                reminders_sent, reminders_failed
         FROM document_maintenance_runs
         ORDER BY id DESC
         LIMIT 5'
    );
    $recentRuns = $runsResult ? $runsResult->fetch_all(MYSQLI_ASSOC) : [];

    $pendingStmt = $conn->prepare(
        "SELECT
            (SELECT COUNT(*) FROM invoices
             WHERE status IN ('sent', 'partial') AND balance_due > 0 AND due_date < CURDATE()) AS invoices_due_for_overdue,
            (SELECT COUNT(*) FROM quotations
             WHERE status = 'sent' AND expiry_date < CURDATE()) AS quotations_due_for_expiry,
            (SELECT COUNT(*) FROM proforma_invoices
             WHERE status = 'sent' AND expiry_date < CURDATE()) AS proformas_due_for_expiry"
    );
    $pendingStmt->execute();
    $pending = $pendingStmt->get_result()->fetch_assoc() ?: [];
    $pendingStmt->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'data' => [
            'last_run' => $recentRuns[0] ?? null,
            'recent_runs' => $recentRuns,
            'pending' => [
                'invoices_due_for_overdue' => (int) ($pending['invoices_due_for_overdue'] ?? 0),
                'quotations_due_for_expiry' => (int) ($pending['quotations_due_for_expiry'] ?? 0),
                'proformas_due_for_expiry' => (int) ($pending['proformas_due_for_expiry'] ?? 0),
            ],
        ],
    ]);
} catch (Throwable $error) {
    error_log('Get Document Maintenance Status Error: ' . $error->getMessage());

    $code = (int) $error->getCode();
    $isClientError = in_array($code, [400, 403, 405], true);

    http_response_code($isClientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $isClientError
            ? $error->getMessage()
            : 'Unable to load document-check history.',
    ]);
}
