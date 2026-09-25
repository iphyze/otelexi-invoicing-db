<?php
// cron/notificationHelper.php
// ------------------------------------------------------------------
// Call createNotification() INSIDE a transaction after the main write
// succeeds, or OUTSIDE if the event has already committed.
//
// Usage:
//   require_once __DIR__ . '/notificationHelper.php';
//   createNotification($conn, [
//       'user_id'    => 4,           // specific recipient (required unless role set)
//       'role'       => 'admin',     // OR broadcast to a role (optional)
//       'type'       => 'invoice.finalized',
//       'title'      => 'Invoice Finalized',
//       'message'    => 'INV/2026/001 has been finalized for Acme Ltd.',
//       'model_type' => 'Invoice',
//       'model_id'   => 7
//   ]);
//
// If both user_id and role are provided, user_id wins.
// ------------------------------------------------------------------

if (!function_exists('createNotification')) {

    function createNotification(mysqli $conn, array $data): bool
    {
        $userId    = isset($data['user_id']) ? (int)$data['user_id'] : null;
        $role      = isset($data['role']) ? strtolower(trim($data['role'])) : null;
        $type      = trim($data['type'] ?? '');
        $title     = trim($data['title'] ?? '');
        $message   = trim($data['message'] ?? '');
        $modelType = isset($data['model_type']) ? trim($data['model_type']) : null;
        $modelId   = isset($data['model_id']) ? (int)$data['model_id'] : null;

        if (empty($type) || empty($title) || empty($message)) {
            error_log("createNotification: type, title, and message are required.");
            return false;
        }

        // ------------------------------------------------------------------
        // If a role is given (and no specific user), fan out to all users
        // of that role so each gets their own row (easier to track read state)
        // ------------------------------------------------------------------
        if ($role && !$userId) {
            $userStmt = $conn->prepare(
                "SELECT id FROM users WHERE role = ? AND is_active = 1"
            );
            $userStmt->bind_param("s", $role);
            $userStmt->execute();
            $recipients = $userStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $userStmt->close();

            if (empty($recipients)) return true; // No active users for role

            $ok = true;
            foreach ($recipients as $recipient) {
                $recipientId = (int)$recipient['id'];
                $ok = $ok && _insertNotification(
                    $conn, $recipientId, $role, $type, $title, $message, $modelType, $modelId
                );
            }
            return $ok;
        }

        // Single user notification
        if (!$userId) {
            error_log("createNotification: user_id or role is required.");
            return false;
        }

        return _insertNotification(
            $conn, $userId, $role, $type, $title, $message, $modelType, $modelId
        );
    }

    function _insertNotification(
        mysqli $conn,
        int    $userId,
        ?string $role,
        string $type,
        string $title,
        string $message,
        ?string $modelType,
        ?int   $modelId
    ): bool {
        $stmt = $conn->prepare("
            INSERT INTO notifications
                (user_id, role, type, title, message, model_type, model_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        // i s s s s s i  → 7 params
        $stmt->bind_param("isssssi",
            $userId, $role, $type, $title, $message, $modelType, $modelId
        );
        $result = $stmt->execute();
        if (!$result) {
            error_log("createNotification insert failed: " . $stmt->error);
        }
        $stmt->close();
        return $result;
    }
}
?>
