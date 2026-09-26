<?php
// cron/notificationHelper.php
// Central notification fan-out helper.
//
// Supports both the original single-recipient API and multi-recipient events:
//
// createNotification($conn, [
//     'user_id'          => 4,                         // optional legacy single user
//     'user_ids'         => [4, 9],                   // optional direct recipients
//     'role'             => 'admin',                  // optional legacy single role
//     'roles'            => ['admin', 'accounting'],  // optional role fan-out
//     'exclude_user_id'  => 2,                        // optional
//     'exclude_user_ids' => [2, 7],                   // optional
//     'type'             => 'invoice.finalized',
//     'title'            => 'Invoice Finalized',
//     'message'          => 'INV/2026/001 has been finalized.',
//     'model_type'       => 'Invoice',
//     'model_id'         => 7,
// ]);
//
// Recipients are de-duplicated, inactive users are ignored, and notification
// failures are logged rather than allowed to break the business action.

if (!function_exists('createNotification')) {
    /**
     * Create one notification row per active recipient.
     *
     * @return bool true when every intended insert succeeded (or no recipients exist)
     */
    function createNotification(mysqli $conn, array $data): bool
    {
        try {
            $type      = trim((string) ($data['type'] ?? ''));
            $title     = trim((string) ($data['title'] ?? ''));
            $message   = trim((string) ($data['message'] ?? ''));
            $modelType = isset($data['model_type']) && $data['model_type'] !== ''
                ? trim((string) $data['model_type'])
                : null;
            $modelId   = isset($data['model_id']) && is_numeric($data['model_id'])
                ? (int) $data['model_id']
                : null;

            if ($type === '' || $title === '' || $message === '') {
                error_log('createNotification: type, title, and message are required.');
                return false;
            }

            $userIds = [];
            if (isset($data['user_id']) && is_numeric($data['user_id'])) {
                $userIds[] = (int) $data['user_id'];
            }
            if (isset($data['user_ids']) && is_array($data['user_ids'])) {
                foreach ($data['user_ids'] as $userId) {
                    if (is_numeric($userId) && (int) $userId > 0) {
                        $userIds[] = (int) $userId;
                    }
                }
            }

            $roles = [];
            if (isset($data['role']) && is_string($data['role'])) {
                $role = strtolower(trim($data['role']));
                if ($role !== '') {
                    $roles[] = $role;
                }
            }
            if (isset($data['roles']) && is_array($data['roles'])) {
                foreach ($data['roles'] as $role) {
                    if (!is_string($role)) {
                        continue;
                    }
                    $role = strtolower(trim($role));
                    if ($role !== '') {
                        $roles[] = $role;
                    }
                }
            }

            $excludeIds = [];
            if (isset($data['exclude_user_id']) && is_numeric($data['exclude_user_id'])) {
                $excludeIds[] = (int) $data['exclude_user_id'];
            }
            if (isset($data['exclude_user_ids']) && is_array($data['exclude_user_ids'])) {
                foreach ($data['exclude_user_ids'] as $userId) {
                    if (is_numeric($userId) && (int) $userId > 0) {
                        $excludeIds[] = (int) $userId;
                    }
                }
            }

            $userIds    = array_values(array_unique(array_filter($userIds, fn ($id) => $id > 0)));
            $roles      = array_values(array_unique($roles));
            $excludeIds = array_values(array_unique(array_filter($excludeIds, fn ($id) => $id > 0)));

            if (empty($userIds) && empty($roles)) {
                error_log('createNotification: at least one user_id/user_ids or role/roles recipient is required.');
                return false;
            }

            // recipient map: user_id => actual current role
            $recipients = [];

            foreach ($userIds as $userId) {
                $stmt = $conn->prepare('SELECT id, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare notification user lookup: ' . $conn->error);
                }
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($row) {
                    $recipients[(int) $row['id']] = (string) $row['role'];
                }
            }

            foreach ($roles as $role) {
                $stmt = $conn->prepare('SELECT id, role FROM users WHERE role = ? AND is_active = 1');
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare notification role lookup: ' . $conn->error);
                }
                $stmt->bind_param('s', $role);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $recipients[(int) $row['id']] = (string) $row['role'];
                }
                $stmt->close();
            }

            foreach ($excludeIds as $excludeId) {
                unset($recipients[$excludeId]);
            }

            if (empty($recipients)) {
                return true;
            }

            $ok = true;
            foreach ($recipients as $recipientId => $recipientRole) {
                if (!_insertNotification(
                    $conn,
                    (int) $recipientId,
                    $recipientRole !== '' ? $recipientRole : null,
                    $type,
                    $title,
                    $message,
                    $modelType,
                    $modelId
                )) {
                    $ok = false;
                }
            }

            return $ok;
        } catch (Throwable $error) {
            error_log('createNotification failed: ' . $error->getMessage());
            return false;
        }
    }

    /**
     * Explicitly named safe alias for new code. Existing routes can continue
     * using createNotification() directly; both are non-throwing.
     */
    function createNotificationSafe(mysqli $conn, array $data): bool
    {
        return createNotification($conn, $data);
    }

    function _insertNotification(
        mysqli $conn,
        int $userId,
        ?string $role,
        string $type,
        string $title,
        string $message,
        ?string $modelType,
        ?int $modelId
    ): bool {
        try {
            $stmt = $conn->prepare(
                'INSERT INTO notifications
                    (user_id, role, type, title, message, model_type, model_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare notification insert: ' . $conn->error);
            }

            $stmt->bind_param(
                'isssssi',
                $userId,
                $role,
                $type,
                $title,
                $message,
                $modelType,
                $modelId
            );

            $result = $stmt->execute();
            if (!$result) {
                error_log('createNotification insert failed: ' . $stmt->error);
            }
            $stmt->close();
            return $result;
        } catch (Throwable $error) {
            error_log('createNotification insert error: ' . $error->getMessage());
            return false;
        }
    }
}
?>
