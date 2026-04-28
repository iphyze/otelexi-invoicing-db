<?php
// routes/invoices/markOverdue.php
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../cron/notificationHelper.php';

/**
 * POST /invoices/mark-overdue
 * Mark all 'sent' or 'partial' invoices past their due_date as 'overdue'.
 * Also increments reminder_count and updates last_reminder_at + next_reminder_at
 * for invoices whose next_reminder_at is today or earlier.
 *
 * Per client spec: automated reminders for overdue invoices.
 * Intended to be called by a daily cron job OR manually by an Admin.
 * Roles allowed: Admin only
 *
 * No request body needed.
 */

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    $userData          = authenticateUser();
    $loggedInUserId    = (int)$userData['id'];
    $loggedInUserRole  = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    if ($loggedInUserRole !== 'admin') {
        throw new Exception("Unauthorized: Only Admins can run overdue marking.", 403);
    }

    $today    = date('Y-m-d');
    $now      = date('Y-m-d H:i:s');
    $conn->begin_transaction();

    try {
        // -------------------------------------------------------
        // 1. Fetch all invoices that need to be marked overdue
        //    (sent or partial, past due_date, not already overdue)
        // -------------------------------------------------------
        $fetchOverdueStmt = $conn->prepare("
            SELECT i.id, i.invoice_number, i.due_date, i.status,
                   i.total_amount, i.balance_due, i.currency,
                   c.company_name AS client_name
            FROM invoices i
            JOIN clients c ON c.id = i.client_id
            WHERE i.status IN ('sent', 'partial')
              AND i.due_date < ?
        ");
        $fetchOverdueStmt->bind_param("s", $today);
        $fetchOverdueStmt->execute();
        $overdueInvoices = $fetchOverdueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fetchOverdueStmt->close();

        $markedOverdueCount = 0;

        if (!empty($overdueInvoices)) {
            $overdueIds = array_column($overdueInvoices, 'id');
            $overduePlaceholders = implode(',', array_fill(0, count($overdueIds), '?'));

            $markStmt = $conn->prepare("
                UPDATE invoices
                SET status = 'overdue'
                WHERE id IN ($overduePlaceholders)
                  AND status IN ('sent', 'partial')
            ");
            $markStmt->bind_param(str_repeat('i', count($overdueIds)), ...$overdueIds);
            $markStmt->execute();
            $markedOverdueCount = $markStmt->affected_rows;
            $markStmt->close();
        }

        // -------------------------------------------------------
        // 2. Process reminders for overdue invoices whose
        //    next_reminder_at is today or in the past
        //    (covers both freshly marked and previously overdue)
        // -------------------------------------------------------
        $fetchReminderStmt = $conn->prepare("
            SELECT i.id, i.invoice_number, i.reminder_count,
                   i.total_amount, i.balance_due, i.currency, i.due_date,
                   c.company_name AS client_name, c.email AS client_email
            FROM invoices i
            JOIN clients c ON c.id = i.client_id
            WHERE i.status = 'overdue'
              AND (i.next_reminder_at IS NULL OR i.next_reminder_at <= ?)
        ");
        $fetchReminderStmt->bind_param("s", $today);
        $fetchReminderStmt->execute();
        $reminderDue = $fetchReminderStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fetchReminderStmt->close();

        $remindedCount    = 0;
        $reminderDetails  = [];

        foreach ($reminderDue as $inv) {
            $newReminderCount = (int)$inv['reminder_count'] + 1;

            // Reminder schedule: every 3 days up to reminder 3, then weekly
            $daysUntilNext = $newReminderCount <= 3 ? 3 : 7;
            $nextReminder  = date('Y-m-d', strtotime($today . " + {$daysUntilNext} days"));

            $reminderStmt = $conn->prepare("
                UPDATE invoices
                SET reminder_count   = ?,
                    last_reminder_at = ?,
                    next_reminder_at = ?
                WHERE id = ?
            ");
            $reminderStmt->bind_param("issi", $newReminderCount, $now, $nextReminder, $inv['id']);
            $reminderStmt->execute();
            $reminderStmt->close();

            $remindedCount++;
            $reminderDetails[] = [
                "invoice_number"   => $inv['invoice_number'],
                "client_name"      => $inv['client_name'],
                "client_email"     => $inv['client_email'],
                "balance_due"      => (float)$inv['balance_due'],
                "currency"         => $inv['currency'],
                "due_date"         => $inv['due_date'],
                "reminder_number"  => $newReminderCount,
                "next_reminder_at" => $nextReminder
            ];
        }

        // -------------------------------------------------------
        // 3. Activity log
        // -------------------------------------------------------
        $logStmt     = $conn->prepare("
            INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $action      = "invoice.overdue_run";
        $modelType   = "Invoice";
        $modelId     = null;
        $description = "{$loggedInUserEmail} ran overdue marking. "
                     . "{$markedOverdueCount} invoice(s) marked overdue. "
                     . "{$remindedCount} reminder(s) processed.";
        $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $logStmt->bind_param("ississ", $loggedInUserId, $action, $modelType, $modelId, $description, $ipAddress);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();


        // ============================================================
        // 4. markOverdue.php
        //    Add after: $conn->commit();
        //    Only fires if invoices were actually marked overdue
        // ============================================================
        
        if ($markedOverdueCount > 0) {
            // Notify all accountants of the overdue batch
            createNotification($conn, [
                'role'       => 'accountant',
                'type'       => 'invoice.overdue_batch',
                'title'      => "{$markedOverdueCount} Invoice(s) Now Overdue",
                'message'    => "{$markedOverdueCount} invoice(s) have passed their due date and are now marked overdue. "
                            . "{$remindedCount} reminder(s) are scheduled.",
                'model_type' => 'Invoice',
                'model_id'   => null
            ]);

            // Notify all admins too
            createNotification($conn, [
                'role'       => 'admin',
                'type'       => 'invoice.overdue_batch',
                'title'      => "{$markedOverdueCount} Invoice(s) Overdue",
                'message'    => "{$markedOverdueCount} invoice(s) marked overdue as of {$today}. "
                            . "Check invoice aging for details.",
                'model_type' => 'Invoice',
                'model_id'   => null
            ]);
        }


        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Overdue run completed.",
            "data"    => [
                "run_date"            => $today,
                "marked_overdue"      => $markedOverdueCount,
                "reminders_processed" => $remindedCount,
                "reminders"           => $reminderDetails
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Mark Overdue Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "failed", "message" => $e->getMessage()]);
}
?>
