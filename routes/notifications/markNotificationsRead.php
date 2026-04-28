<?php
// routes/notifications/markNotificationsRead.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

/**
 * POST /notifications/mark-read
 * Mark notifications as read for the authenticated user.
 * Roles allowed: All authenticated users
 *
 * Sample payloads:
 *
 * Mark all as read:
 * { "all": true }
 *
 * Mark specific ones:
 * { "ids": [12, 14, 17] }
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    $userData       = authenticateUser();
    $loggedInUserId = (int)$userData['id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid or missing JSON payload.", 400);

    $markAll = isset($data['all']) && $data['all'] === true;
    $ids     = isset($data['ids']) && is_array($data['ids']) ? array_map('intval', $data['ids']) : [];

    if (!$markAll && empty($ids)) {
        throw new Exception("Provide either 'all': true or a non-empty 'ids' array.", 422);
    }

    $now = date('Y-m-d H:i:s');

    if ($markAll) {
        $stmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = ?
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->bind_param("si", $now, $loggedInUserId);
        $stmt->execute();
        $updatedCount = $stmt->affected_rows;
        $stmt->close();
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // Build types: s + i (user_id) + N×i (ids)
        $types  = "si" . str_repeat("i", count($ids));
        $params = array_merge([$now, $loggedInUserId], $ids);

        $stmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = ?
            WHERE user_id = ? AND id IN ($placeholders) AND is_read = 0
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $updatedCount = $stmt->affected_rows;
        $stmt->close();
    }

    // Return fresh unread count for badge update
    $unreadStmt = $conn->prepare("
        SELECT COUNT(*) AS unread_count
        FROM notifications
        WHERE user_id = ? AND is_read = 0
    ");
    $unreadStmt->bind_param("i", $loggedInUserId);
    $unreadStmt->execute();
    $unreadCount = (int)$unreadStmt->get_result()->fetch_assoc()['unread_count'];
    $unreadStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "{$updatedCount} notification(s) marked as read.",
        "data"    => [
            "marked_count"  => $updatedCount,
            "unread_count"  => $unreadCount
        ]
    ]);

} catch (Exception $e) {
    error_log("Mark Notifications Read Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
