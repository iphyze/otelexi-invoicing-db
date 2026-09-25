<?php
// routes/admin/getMailDeliveryHistory.php
// GET /admin/mail-delivery-history
// Super Admin view of provider submissions, fallback attempts and delivery status.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/mail/mailTracking.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can view mail delivery history.');

    global $conn;
    ensureMailTrackingTables($conn);

    $provider = strtolower(trim((string) ($_GET['provider'] ?? '')));
    $status = strtolower(trim((string) ($_GET['status'] ?? '')));
    $search = trim((string) ($_GET['search'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(10, min(100, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $providers = ['', 'zoho', 'brevo'];
    $statuses = [
        '', 'pending', 'submitted', 'delivered', 'deferred', 'soft_bounced',
        'bounced', 'blocked', 'spam', 'invalid', 'failed',
    ];

    if (!in_array($provider, $providers, true)) {
        throw new Exception('Invalid mail provider filter.', 422);
    }
    if (!in_array($status, $statuses, true)) {
        throw new Exception('Invalid delivery status filter.', 422);
    }
    if (strlen($search) > 120) {
        throw new Exception('Search value is too long.', 422);
    }

    $where = ' WHERE 1=1 ';
    if ($provider !== '') {
        $safeProvider = $conn->real_escape_string($provider);
        $where .= " AND provider = '{$safeProvider}' ";
    }
    if ($status !== '') {
        $safeStatus = $conn->real_escape_string($status);
        $where .= " AND status = '{$safeStatus}' ";
    }
    if ($search !== '') {
        $safeSearch = $conn->real_escape_string($search);
        $where .= " AND (recipient_email LIKE '%{$safeSearch}%' OR subject LIKE '%{$safeSearch}%' OR tracking_id LIKE '%{$safeSearch}%') ";
    }

    $countResult = $conn->query('SELECT COUNT(*) AS total FROM mail_dispatches' . $where);
    $total = (int) ($countResult->fetch_assoc()['total'] ?? 0);

    $summaryResult = $conn->query(
        "SELECT
            COUNT(*) AS total,
            SUM(status = 'delivered') AS delivered,
            SUM(status IN ('bounced','blocked','spam','invalid','failed')) AS failed,
            SUM(status IN ('submitted','deferred','soft_bounced','pending')) AS pending,
            SUM(fallback_used = 1) AS fallback_used
         FROM mail_dispatches
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $summaryRow = $summaryResult->fetch_assoc() ?: [];

    $result = $conn->query(
        "SELECT id, tracking_id, recipient_email, recipient_name, subject,
                requested_provider, provider, fallback_used, provider_message_id,
                status, has_attachment, attempts_json, failure_code, failure_reason,
                submitted_at, delivered_at, last_event_at, created_at
         FROM mail_dispatches
         {$where}
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit} OFFSET {$offset}"
    );

    $history = [];
    while ($row = $result->fetch_assoc()) {
        $attempts = json_decode((string) ($row['attempts_json'] ?? ''), true);
        $history[] = [
            'id' => (int) $row['id'],
            'tracking_id' => $row['tracking_id'],
            'recipient_email' => $row['recipient_email'],
            'recipient_name' => $row['recipient_name'],
            'subject' => $row['subject'],
            'requested_provider' => $row['requested_provider'],
            'provider' => $row['provider'],
            'fallback_used' => (bool) $row['fallback_used'],
            'provider_message_id' => $row['provider_message_id'],
            'status' => $row['status'],
            'has_attachment' => (bool) $row['has_attachment'],
            'attempts' => is_array($attempts) ? $attempts : [],
            'failure_code' => $row['failure_code'],
            'failure_reason' => $row['failure_reason'],
            'submitted_at' => $row['submitted_at'],
            'delivered_at' => $row['delivered_at'],
            'last_event_at' => $row['last_event_at'],
            'created_at' => $row['created_at'],
        ];
    }

    $totalPages = max(1, (int) ceil($total / $limit));

    echo json_encode([
        'status' => 'success',
        'message' => 'Mail delivery history loaded successfully.',
        'data' => $history,
        'summary' => [
            'total' => (int) ($summaryRow['total'] ?? 0),
            'delivered' => (int) ($summaryRow['delivered'] ?? 0),
            'failed' => (int) ($summaryRow['failed'] ?? 0),
            'pending' => (int) ($summaryRow['pending'] ?? 0),
            'fallback_used' => (int) ($summaryRow['fallback_used'] ?? 0),
            'period_days' => 30,
        ],
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Get Mail Delivery History Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Mail delivery history could not be loaded right now.' : $e->getMessage(),
    ]);
}
