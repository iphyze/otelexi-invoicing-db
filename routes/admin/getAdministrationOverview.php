<?php
// routes/admin/getAdministrationOverview.php
// GET /admin/overview - security and administration control snapshot.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = authenticateUser();
    requireRole($user, [ROLE_SUPER_ADMIN], 'Only the Super Admin can access administration controls.');

    $roles = $conn->query(
        "SELECT role, COUNT(*) AS total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active
         FROM users GROUP BY role ORDER BY FIELD(role, 'super_admin','admin','sales','accounting')"
    );
    $roleCounts = [];
    while ($row = $roles->fetch_assoc()) {
        $roleCounts[] = [
            'role' => $row['role'],
            'total' => (int) $row['total'],
            'active' => (int) $row['active'],
        ];
    }

    $security = $conn->query(
        "SELECT
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_users,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_users,
            SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS active_last_30_days
         FROM users"
    )->fetch_assoc();

    $recent = [];
    $recentResult = $conn->query(
        "SELECT al.action, al.description, al.created_at, COALESCE(u.name, 'System') AS actor
         FROM activity_log al LEFT JOIN users u ON u.id = al.user_id
         WHERE al.action LIKE 'user.%'
            OR al.action LIKE 'inventory.%'
            OR al.action LIKE 'credit_note.%'
            OR al.action LIKE 'refund.%'
            OR al.action LIKE 'invoice.reversed%'
         ORDER BY al.created_at DESC, al.id DESC LIMIT 8"
    );
    while ($row = $recentResult->fetch_assoc()) {
        $recent[] = $row;
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'roles' => $roleCounts,
            'security' => [
                'active_users' => (int) ($security['active_users'] ?? 0),
                'inactive_users' => (int) ($security['inactive_users'] ?? 0),
                'active_last_30_days' => (int) ($security['active_last_30_days'] ?? 0),
            ],
            'recent_privileged_activity' => $recent,
            'role_policy' => [
                'super_admin' => 'Full control including users, settings, audits, credit notes, refunds and reversals.',
                'admin' => 'Daily operations, documents, inventory adjustments and payments; no ownership-level controls.',
                'sales' => 'Clients, quotations, proformas and sales document preparation.',
                'accounting' => 'Payments, receipts, stock history and reporting visibility.',
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Administration Overview Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500 ? 'Administration overview could not be loaded right now.' : $e->getMessage(),
    ]);
}
