<?php
// utils/documentMaintenance.php
// Reusable overdue, reminder and expiry processing for admin-triggered runs and cPanel cron jobs.

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/emailTemplates.php';

/**
 * Normalise actor metadata for audit logs and reminder records.
 *
 * @return array{id:?int,label:string,trigger:string,ip:string}
 */
function documentMaintenanceActor(array $actor = []): array
{
    $trigger = ($actor['trigger'] ?? 'manual') === 'scheduled' ? 'scheduled' : 'manual';
    $label = trim((string) ($actor['label'] ?? ($trigger === 'scheduled' ? 'Scheduled cron job' : 'Administrator')));
    $ip = trim((string) ($actor['ip'] ?? ($trigger === 'scheduled' ? 'cron' : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'))));

    return [
        'id'      => isset($actor['id']) ? (int) $actor['id'] : null,
        'label'   => $label !== '' ? $label : 'System',
        'trigger' => $trigger,
        'ip'      => substr($ip !== '' ? $ip : 'cron', 0, 45),
    ];
}

/**
 * Log a document status/reminder event without making a completed business action fail
 * solely because its audit record could not be inserted.
 */
function logDocumentMaintenanceActivity(
    mysqli $conn,
    array $actor,
    string $action,
    string $modelType,
    ?int $modelId,
    string $description,
    ?array $properties = null
): void {
    try {
        $stmt = $conn->prepare(
            'INSERT INTO activity_log
                (user_id, action, model_type, model_id, description, properties, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $userId = $actor['id'];
        $propertiesJson = $properties === null
            ? null
            : json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ip = $actor['ip'];

        $stmt->bind_param(
            'ississs',
            $userId,
            $action,
            $modelType,
            $modelId,
            $description,
            $propertiesJson,
            $ip
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $logError) {
        error_log('Document Maintenance Activity Log Error: ' . $logError->getMessage());
    }
}

/**
 * Convert configured reminder-day thresholds into ascending unique positive integers.
 * Default sequence: first reminder on day 1 overdue, second on day 7, final on day 14.
 *
 * .env optional: INVOICE_REMINDER_DAYS=1,7,14
 *
 * @return int[]
 */
function invoiceReminderDays(): array
{
    $configured = config('INVOICE_REMINDER_DAYS', '1,7,14') ?? '1,7,14';
    $days = [];

    foreach (explode(',', $configured) as $item) {
        $day = (int) trim($item);
        if ($day > 0) {
            $days[] = $day;
        }
    }

    $days = array_values(array_unique($days));
    sort($days);

    return !empty($days) ? $days : [1, 7, 14];
}

function maintenanceMoney(float $value, string $currency): string
{
    return ($currency === 'USD' ? '$' : '₦') . number_format($value, 2);
}

/**
 * Return the highest reminder stage currently due for the number of days overdue.
 */
function scheduledReminderStage(int $daysOverdue, array $thresholds): ?int
{
    $stage = null;

    foreach ($thresholds as $index => $days) {
        if ($daysOverdue >= $days) {
            $stage = $index + 1;
        }
    }

    return $stage;
}

function nextScheduledReminderAt(string $dueDate, int $sentStage, array $thresholds): ?string
{
    if (!isset($thresholds[$sentStage])) {
        return null;
    }

    return date(
        'Y-m-d H:i:s',
        strtotime($dueDate . ' +' . (int) $thresholds[$sentStage] . ' days 08:00:00')
    );
}

/**
 * Fetch all data necessary to send one invoice reminder.
 */
function fetchInvoiceForReminder(mysqli $conn, int $invoiceId): ?array
{
    $stmt = $conn->prepare(
        "SELECT
            i.id, i.invoice_number, i.due_date, i.currency, i.balance_due,
            i.status, i.reminder_count, i.last_reminder_at, i.next_reminder_at,
            c.company_name AS client_name, c.email AS client_email
         FROM invoices i
         JOIN clients c ON c.id = i.client_id
         WHERE i.id = ?
           AND i.status = 'overdue'
           AND i.balance_due > 0
         LIMIT 1"
    );
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $invoice ?: null;
}

function recordInvoiceReminderAttempt(mysqli $conn, array $data): void
{
    try {
        $stmt = $conn->prepare(
            'INSERT INTO invoice_reminder_logs
                (invoice_id, reminder_stage, days_overdue, recipient_email, email_subject,
                 delivery_status, failure_reason, trigger_source, sent_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $invoiceId = (int) $data['invoice_id'];
        $stage = (int) $data['reminder_stage'];
        $daysOverdue = (int) $data['days_overdue'];
        $recipient = substr((string) $data['recipient_email'], 0, 150);
        $subject = substr((string) $data['email_subject'], 0, 255);
        $status = (string) $data['delivery_status'];
        $failure = isset($data['failure_reason'])
            ? substr((string) $data['failure_reason'], 0, 255)
            : null;
        $trigger = (string) $data['trigger_source'];
        $sentBy = $data['sent_by'] === null ? null : (int) $data['sent_by'];

        $stmt->bind_param(
            'iiisssssi',
            $invoiceId,
            $stage,
            $daysOverdue,
            $recipient,
            $subject,
            $status,
            $failure,
            $trigger,
            $sentBy
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $logError) {
        error_log('Invoice Reminder Log Error: ' . $logError->getMessage());
    }
}

/**
 * Send one overdue reminder.
 *
 * Automatic runs send only the highest scheduled stage that is newly due.
 * Manual sends can issue a follow-up after the scheduled sequence is complete,
 * but never send more than one reminder for an invoice on the same day.
 *
 * @return array{status:string,message:string,invoice_id:int,stage:?int}
 */
function sendInvoiceOverdueReminder(
    mysqli $conn,
    int $invoiceId,
    array $actor,
    bool $manual = false
): array {
    $actor = documentMaintenanceActor($actor);
    $invoice = fetchInvoiceForReminder($conn, $invoiceId);

    if (!$invoice) {
        return [
            'status' => 'skipped',
            'message' => 'This invoice is not currently eligible for an overdue reminder.',
            'invoice_id' => $invoiceId,
            'stage' => null,
        ];
    }

    $today = new DateTimeImmutable('today');
    $dueDate = new DateTimeImmutable((string) $invoice['due_date']);
    $daysOverdue = max(0, (int) $today->diff($dueDate)->days);

    if ($daysOverdue < 1) {
        return [
            'status' => 'skipped',
            'message' => 'This invoice is not overdue yet.',
            'invoice_id' => $invoiceId,
            'stage' => null,
        ];
    }

    $sameDayStmt = $conn->prepare(
        'SELECT COUNT(*) AS attempt_count
         FROM invoice_reminder_logs
         WHERE invoice_id = ?
           AND DATE(attempted_at) = CURDATE()'
    );
    $sameDayStmt->bind_param('i', $invoiceId);
    $sameDayStmt->execute();
    $attemptedToday = (int) ($sameDayStmt->get_result()->fetch_assoc()['attempt_count'] ?? 0);
    $sameDayStmt->close();

    if ($attemptedToday > 0) {
        return [
            'status' => 'skipped',
            'message' => 'A reminder attempt has already been made for this invoice today.',
            'invoice_id' => $invoiceId,
            'stage' => null,
        ];
    }

    $lastStmt = $conn->prepare(
        "SELECT COALESCE(MAX(reminder_stage), 0) AS last_successful_stage
         FROM invoice_reminder_logs
         WHERE invoice_id = ?
           AND delivery_status = 'sent'"
    );
    $lastStmt->bind_param('i', $invoiceId);
    $lastStmt->execute();
    $lastSuccessfulStage = (int) ($lastStmt->get_result()->fetch_assoc()['last_successful_stage'] ?? 0);
    $lastStmt->close();

    $thresholds = invoiceReminderDays();
    $scheduledStage = scheduledReminderStage($daysOverdue, $thresholds);

    if ($manual) {
        if ($scheduledStage === null) {
            $stage = 1;
        } elseif ($scheduledStage > $lastSuccessfulStage) {
            $stage = $scheduledStage;
        } else {
            $stage = $lastSuccessfulStage + 1;
        }
    } else {
        if ($scheduledStage === null || $scheduledStage <= $lastSuccessfulStage) {
            return [
                'status' => 'skipped',
                'message' => 'No reminder is due for this invoice today.',
                'invoice_id' => $invoiceId,
                'stage' => null,
            ];
        }

        $stage = $scheduledStage;
    }

    $recipient = trim((string) ($invoice['client_email'] ?? ''));
    $balance = maintenanceMoney((float) $invoice['balance_due'], (string) $invoice['currency']);
    $displayDueDate = date('d M Y', strtotime((string) $invoice['due_date']));
    $stageLabel = $stage >= count($thresholds) ? 'Final Payment Reminder' : 'Payment Reminder';
    $subject = "{$stageLabel} — Invoice {$invoice['invoice_number']} ({$balance} overdue)";

    if (!isDeliverableEmail($recipient)) {
        recordInvoiceReminderAttempt($conn, [
            'invoice_id' => $invoiceId,
            'reminder_stage' => $stage,
            'days_overdue' => $daysOverdue,
            'recipient_email' => $recipient !== '' ? $recipient : 'not-provided@invalid',
            'email_subject' => $subject,
            'delivery_status' => 'skipped',
            'failure_reason' => 'Client does not have a deliverable email address.',
            'trigger_source' => $actor['trigger'],
            'sent_by' => $actor['id'],
        ]);

        return [
            'status' => 'skipped',
            'message' => 'The client does not have a deliverable email address.',
            'invoice_id' => $invoiceId,
            'stage' => $stage,
        ];
    }

    $settingsResult = $conn->query('SELECT company_name, bank_name, account_name, account_number FROM company_settings LIMIT 1');
    $settings = $settingsResult ? $settingsResult->fetch_assoc() : [];
    $companyName = trim((string) ($settings['company_name'] ?? 'Otelex')) ?: 'Otelex';

    try {
        sendMail(
            to: $recipient,
            toName: (string) $invoice['client_name'],
            subject: $subject,
            body: emailOverdueReminder([
                'invoice_number' => (string) $invoice['invoice_number'],
                'client_name' => (string) $invoice['client_name'],
                'balance_due' => $balance,
                'due_date' => $displayDueDate,
                'days_overdue' => $daysOverdue,
                'reminder_count' => $stage,
                'bank_name' => (string) ($settings['bank_name'] ?? ''),
                'account_name' => (string) ($settings['account_name'] ?? ''),
                'account_number' => (string) ($settings['account_number'] ?? ''),
            ], $companyName)
        );

        $nextReminderAt = $manual && $stage >= count($thresholds)
            ? null
            : nextScheduledReminderAt((string) $invoice['due_date'], $stage, $thresholds);

        $updateStmt = $conn->prepare(
            'UPDATE invoices
             SET reminder_count = reminder_count + 1,
                 last_reminder_at = NOW(),
                 next_reminder_at = ?,
                 updated_at = NOW()
             WHERE id = ?'
        );
        $updateStmt->bind_param('si', $nextReminderAt, $invoiceId);
        $updateStmt->execute();
        $updateStmt->close();

        recordInvoiceReminderAttempt($conn, [
            'invoice_id' => $invoiceId,
            'reminder_stage' => $stage,
            'days_overdue' => $daysOverdue,
            'recipient_email' => $recipient,
            'email_subject' => $subject,
            'delivery_status' => 'sent',
            'failure_reason' => null,
            'trigger_source' => $actor['trigger'],
            'sent_by' => $actor['id'],
        ]);

        logDocumentMaintenanceActivity(
            $conn,
            $actor,
            'invoice.reminder_sent',
            'Invoice',
            $invoiceId,
            "{$actor['label']} sent overdue reminder #{$stage} for invoice {$invoice['invoice_number']} to {$recipient}.",
            [
                'days_overdue' => $daysOverdue,
                'balance_due' => (float) $invoice['balance_due'],
                'currency' => (string) $invoice['currency'],
                'trigger_source' => $actor['trigger'],
            ]
        );

        return [
            'status' => 'sent',
            'message' => "Payment reminder sent for invoice {$invoice['invoice_number']}.",
            'invoice_id' => $invoiceId,
            'stage' => $stage,
        ];
    } catch (Throwable $mailError) {
        recordInvoiceReminderAttempt($conn, [
            'invoice_id' => $invoiceId,
            'reminder_stage' => $stage,
            'days_overdue' => $daysOverdue,
            'recipient_email' => $recipient,
            'email_subject' => $subject,
            'delivery_status' => 'failed',
            'failure_reason' => $mailError->getMessage(),
            'trigger_source' => $actor['trigger'],
            'sent_by' => $actor['id'],
        ]);

        error_log("Overdue Reminder Mail Error for invoice {$invoice['invoice_number']}: " . $mailError->getMessage());

        return [
            'status' => 'failed',
            'message' => 'The overdue reminder email could not be sent right now.',
            'invoice_id' => $invoiceId,
            'stage' => $stage,
        ];
    }
}

/**
 * Mark due invoices as overdue and log each changed invoice.
 */
function markPastDueInvoices(mysqli $conn, array $actor): int
{
    $actor = documentMaintenanceActor($actor);
    $stmt = $conn->prepare(
        "SELECT id, invoice_number, due_date, status, balance_due, currency
         FROM invoices
         WHERE due_date < CURDATE()
           AND status IN ('sent', 'partial')
           AND balance_due > 0"
    );
    $stmt->execute();
    $invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $count = 0;

    foreach ($invoices as $invoice) {
        $id = (int) $invoice['id'];
        $updateStmt = $conn->prepare(
            "UPDATE invoices
             SET status = 'overdue', updated_at = NOW()
             WHERE id = ?
               AND status IN ('sent', 'partial')
               AND balance_due > 0"
        );
        $updateStmt->bind_param('i', $id);
        $updateStmt->execute();
        $changed = $updateStmt->affected_rows === 1;
        $updateStmt->close();

        if (!$changed) {
            continue;
        }

        $count++;
        logDocumentMaintenanceActivity(
            $conn,
            $actor,
            'invoice.marked_overdue',
            'Invoice',
            $id,
            "{$actor['label']} marked invoice {$invoice['invoice_number']} as overdue.",
            [
                'previous_status' => (string) $invoice['status'],
                'due_date' => (string) $invoice['due_date'],
                'balance_due' => (float) $invoice['balance_due'],
                'currency' => (string) $invoice['currency'],
            ]
        );
    }

    return $count;
}

/**
 * Expire sent quotations only. Drafts are intentionally left editable and can be reissued.
 */
function expireSentQuotations(mysqli $conn, array $actor): int
{
    $actor = documentMaintenanceActor($actor);
    $stmt = $conn->prepare(
        "SELECT id, quotation_number, expiry_date
         FROM quotations
         WHERE status = 'sent'
           AND expiry_date < CURDATE()"
    );
    $stmt->execute();
    $quotations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $count = 0;

    foreach ($quotations as $quotation) {
        $id = (int) $quotation['id'];
        $updateStmt = $conn->prepare(
            "UPDATE quotations SET status = 'expired', updated_at = NOW()
             WHERE id = ? AND status = 'sent'"
        );
        $updateStmt->bind_param('i', $id);
        $updateStmt->execute();
        $changed = $updateStmt->affected_rows === 1;
        $updateStmt->close();

        if (!$changed) {
            continue;
        }

        $count++;
        logDocumentMaintenanceActivity(
            $conn,
            $actor,
            'quotation.expired',
            'Quotation',
            $id,
            "{$actor['label']} marked quotation {$quotation['quotation_number']} as expired.",
            ['expiry_date' => (string) $quotation['expiry_date']]
        );
    }

    return $count;
}

/**
 * Expire sent proformas only. Approved/converted documents preserve their business state.
 */
function expireSentProformas(mysqli $conn, array $actor): int
{
    $actor = documentMaintenanceActor($actor);
    $stmt = $conn->prepare(
        "SELECT id, proforma_number, expiry_date
         FROM proforma_invoices
         WHERE status = 'sent'
           AND expiry_date < CURDATE()"
    );
    $stmt->execute();
    $proformas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $count = 0;

    foreach ($proformas as $proforma) {
        $id = (int) $proforma['id'];
        $updateStmt = $conn->prepare(
            "UPDATE proforma_invoices SET status = 'expired', updated_at = NOW()
             WHERE id = ? AND status = 'sent'"
        );
        $updateStmt->bind_param('i', $id);
        $updateStmt->execute();
        $changed = $updateStmt->affected_rows === 1;
        $updateStmt->close();

        if (!$changed) {
            continue;
        }

        $count++;
        logDocumentMaintenanceActivity(
            $conn,
            $actor,
            'proforma.expired',
            'ProformaInvoice',
            $id,
            "{$actor['label']} marked proforma {$proforma['proforma_number']} as expired.",
            ['expiry_date' => (string) $proforma['expiry_date']]
        );
    }

    return $count;
}

/**
 * Send reminders currently due after overdue statuses have been updated.
 *
 * @return array{sent:int,failed:int,skipped:int}
 */
function processScheduledInvoiceReminders(mysqli $conn, array $actor): array
{
    $stmt = $conn->prepare(
        "SELECT id
         FROM invoices
         WHERE status = 'overdue'
           AND balance_due > 0
         ORDER BY due_date ASC, id ASC"
    );
    $stmt->execute();
    $invoiceIds = array_map(
        static fn(array $row): int => (int) $row['id'],
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
    );
    $stmt->close();

    $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

    foreach ($invoiceIds as $invoiceId) {
        $result = sendInvoiceOverdueReminder($conn, $invoiceId, $actor, false);
        $key = in_array($result['status'], ['sent', 'failed', 'skipped'], true)
            ? $result['status']
            : 'skipped';
        $counts[$key]++;
    }

    return $counts;
}

/**
 * Run the complete automation batch, used by both the authenticated admin action and cron.
 *
 * @return array<string,int|string>
 */
function runDocumentMaintenance(mysqli $conn, array $actor = []): array
{
    $actor = documentMaintenanceActor($actor);
    $runId = null;

    try {
        $startStmt = $conn->prepare(
            "INSERT INTO document_maintenance_runs (trigger_source, run_by, run_status)
             VALUES (?, ?, 'running')"
        );
        $startStmt->bind_param('si', $actor['trigger'], $actor['id']);
        $startStmt->execute();
        $runId = (int) $conn->insert_id;
        $startStmt->close();

        $overdueCount = markPastDueInvoices($conn, $actor);
        $quotationCount = expireSentQuotations($conn, $actor);
        $proformaCount = expireSentProformas($conn, $actor);
        $reminders = processScheduledInvoiceReminders($conn, $actor);

        $completeStmt = $conn->prepare(
            "UPDATE document_maintenance_runs
             SET completed_at = NOW(),
                 run_status = 'completed',
                 invoices_marked_overdue = ?,
                 quotation_expired_count = ?,
                 proforma_expired_count = ?,
                 reminders_sent = ?,
                 reminders_failed = ?
             WHERE id = ?"
        );
        $completeStmt->bind_param(
            'iiiiii',
            $overdueCount,
            $quotationCount,
            $proformaCount,
            $reminders['sent'],
            $reminders['failed'],
            $runId
        );
        $completeStmt->execute();
        $completeStmt->close();

        logDocumentMaintenanceActivity(
            $conn,
            $actor,
            'document.maintenance_run',
            'System',
            null,
            "{$actor['label']} ran document checks: {$overdueCount} invoice(s) overdue, {$quotationCount} quotation(s) expired, {$proformaCount} proforma(s) expired, {$reminders['sent']} reminder(s) sent.",
            [
                'run_id' => $runId,
                'trigger_source' => $actor['trigger'],
                'invoices_marked_overdue' => $overdueCount,
                'quotation_expired_count' => $quotationCount,
                'proforma_expired_count' => $proformaCount,
                'reminders_sent' => $reminders['sent'],
                'reminders_failed' => $reminders['failed'],
            ]
        );

        return [
            'run_id' => $runId,
            'trigger_source' => $actor['trigger'],
            'invoices_marked_overdue' => $overdueCount,
            'quotation_expired_count' => $quotationCount,
            'proforma_expired_count' => $proformaCount,
            'reminders_sent' => $reminders['sent'],
            'reminders_failed' => $reminders['failed'],
            'reminders_skipped' => $reminders['skipped'],
        ];
    } catch (Throwable $error) {
        if ($runId !== null) {
            try {
                $failure = substr($error->getMessage(), 0, 255);
                $failedStmt = $conn->prepare(
                    "UPDATE document_maintenance_runs
                     SET completed_at = NOW(), run_status = 'failed', failure_reason = ?
                     WHERE id = ?"
                );
                $failedStmt->bind_param('si', $failure, $runId);
                $failedStmt->execute();
                $failedStmt->close();
            } catch (Throwable $logFailure) {
                error_log('Document Maintenance Run Failure Log Error: ' . $logFailure->getMessage());
            }
        }

        throw $error;
    }
}
