<?php
// routes/notifications/getNotifications.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * GET /notifications
 * Fetch notifications for the authenticated user.
 * Returns both paginated notifications and an unread count badge value.
 * Roles allowed: All authenticated users
 *
 * Query params:
 *   ?filter=all|unread          (default: all)
 *   &page=1  &limit=20
 */

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData       = authenticateUser();
    $loggedInUserId = (int)$userData['id'];

    $filter = isset($_GET['filter']) && $_GET['filter'] === 'unread' ? 'unread' : 'all';
    $limit  = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 20;
    $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    // -------------------------------------------------------
    // 1. Unread count (always returned regardless of filter)
    // -------------------------------------------------------
    $unreadStmt = $conn->prepare("
        SELECT COUNT(*) AS unread_count
        FROM notifications
        WHERE user_id = ? AND is_read = 0
    ");
    $unreadStmt->bind_param("i", $loggedInUserId);
    $unreadStmt->execute();
    $unreadCount = (int)$unreadStmt->get_result()->fetch_assoc()['unread_count'];
    $unreadStmt->close();

    // -------------------------------------------------------
    // 2. Notification list
    // -------------------------------------------------------
    $whereClause = "WHERE user_id = ?";
    $params      = [$loggedInUserId];
    $types       = "i";

    if ($filter === 'unread') {
        $whereClause .= " AND is_read = 0";
    }

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications {$whereClause}");
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $listParams   = array_merge($params, [$limit, $offset]);
    $listTypes    = $types . "ii";

    $listStmt = $conn->prepare("
        SELECT id, type, title, message, model_type, model_id,
               is_read, read_at, created_at
        FROM notifications
        {$whereClause}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $listStmt->bind_param($listTypes, ...$listParams);
    $listStmt->execute();
    $rows = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $listStmt->close();

    $notifications = array_map(fn($r) => [
        "id"         => (int)$r['id'],
        "type"       => $r['type'],
        "title"      => $r['title'],
        "message"    => $r['message'],
        "model_type" => $r['model_type'],
        "model_id"   => $r['model_id'] ? (int)$r['model_id'] : null,
        "is_read"    => (bool)$r['is_read'],
        "read_at"    => $r['read_at'],
        "created_at" => $r['created_at']
    ], $rows);

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Notifications fetched successfully.",
        "data"    => $notifications,
        "meta"    => [
            "unread_count" => $unreadCount,
            "total"        => $total,
            "total_pages"  => $total > 0 ? (int)ceil($total / $limit) : 0,
            "page"         => $page,
            "limit"        => $limit,
            "filter"       => $filter
        ]
    ]);

} catch (Exception $e) {
    error_log("Get Notifications Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
