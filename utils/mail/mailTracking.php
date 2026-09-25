<?php
// utils/mail/mailTracking.php
// Provider-neutral outbound email submission and delivery tracking.

declare(strict_types=1);

function mailTrackingConnection(): ?mysqli
{
    global $conn;
    return isset($conn) && $conn instanceof mysqli ? $conn : null;
}

function ensureMailTrackingTables(mysqli $connection): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $connection->query(
        "CREATE TABLE IF NOT EXISTS mail_dispatches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tracking_id CHAR(32) NOT NULL,
            recipient_email VARCHAR(190) NOT NULL,
            recipient_name VARCHAR(190) DEFAULT NULL,
            subject VARCHAR(255) NOT NULL,
            requested_provider VARCHAR(20) NOT NULL DEFAULT 'system',
            provider VARCHAR(20) DEFAULT NULL,
            fallback_used TINYINT(1) NOT NULL DEFAULT 0,
            provider_message_id VARCHAR(255) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            has_attachment TINYINT(1) NOT NULL DEFAULT 0,
            attempts_json LONGTEXT DEFAULT NULL,
            failure_code VARCHAR(80) DEFAULT NULL,
            failure_reason VARCHAR(255) DEFAULT NULL,
            submitted_at DATETIME DEFAULT NULL,
            delivered_at DATETIME DEFAULT NULL,
            last_event_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mail_dispatch_tracking (tracking_id),
            KEY idx_mail_dispatch_provider_message (provider, provider_message_id),
            KEY idx_mail_dispatch_recipient (recipient_email),
            KEY idx_mail_dispatch_status_created (status, created_at),
            KEY idx_mail_dispatch_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $connection->query(
        "CREATE TABLE IF NOT EXISTS mail_delivery_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dispatch_id BIGINT UNSIGNED NOT NULL,
            event_key CHAR(64) NOT NULL,
            provider VARCHAR(20) NOT NULL,
            provider_message_id VARCHAR(255) DEFAULT NULL,
            event_type VARCHAR(50) NOT NULL,
            status VARCHAR(32) DEFAULT NULL,
            recipient_email VARCHAR(190) DEFAULT NULL,
            reason VARCHAR(500) DEFAULT NULL,
            event_at DATETIME DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mail_delivery_event_key (event_key),
            KEY idx_mail_delivery_dispatch (dispatch_id, created_at),
            KEY idx_mail_delivery_provider_message (provider, provider_message_id),
            CONSTRAINT fk_mail_delivery_dispatch
                FOREIGN KEY (dispatch_id) REFERENCES mail_dispatches(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

function newMailTrackingId(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return md5(uniqid('otelex-mail-', true));
    }
}

function normalizeProviderMessageId(?string $messageId): ?string
{
    $messageId = trim((string) $messageId);
    if ($messageId === '') {
        return null;
    }

    $messageId = trim($messageId, "<> \t\r\n");
    return $messageId !== '' ? substr($messageId, 0, 255) : null;
}

/**
 * Begin a tracking record. Tracking is deliberately best-effort: a logging
 * problem must never prevent the actual email from being sent.
 *
 * @return array{id:?int,tracking_id:string}
 */
function beginMailDispatch(array $data): array
{
    $trackingId = newMailTrackingId();
    $connection = mailTrackingConnection();

    if (!$connection) {
        return ['id' => null, 'tracking_id' => $trackingId];
    }

    try {
        ensureMailTrackingTables($connection);

        $recipientEmail = strtolower(trim((string) ($data['recipient_email'] ?? '')));
        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        $subject = substr(trim((string) ($data['subject'] ?? '')), 0, 255);
        $requestedProvider = substr(trim((string) ($data['requested_provider'] ?? 'system')), 0, 20);
        $hasAttachment = !empty($data['has_attachment']) ? 1 : 0;

        $stmt = $connection->prepare(
            "INSERT INTO mail_dispatches
                (tracking_id, recipient_email, recipient_name, subject, requested_provider, has_attachment)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'sssssi',
            $trackingId,
            $recipientEmail,
            $recipientName,
            $subject,
            $requestedProvider,
            $hasAttachment
        );
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return ['id' => $id, 'tracking_id' => $trackingId];
    } catch (Throwable $e) {
        error_log('Mail Tracking Begin Error: ' . $e->getMessage());
        return ['id' => null, 'tracking_id' => $trackingId];
    }
}

function recordMailDeliveryEvent(
    ?int $dispatchId,
    string $provider,
    string $eventType,
    ?string $status = null,
    ?string $providerMessageId = null,
    ?string $recipientEmail = null,
    ?string $reason = null,
    ?string $eventAt = null,
    ?array $payload = null,
    ?string $eventKey = null
): void {
    if (!$dispatchId) {
        return;
    }

    $connection = mailTrackingConnection();
    if (!$connection) {
        return;
    }

    try {
        ensureMailTrackingTables($connection);

        $provider = substr(strtolower(trim($provider)), 0, 20);
        $eventType = substr(strtolower(trim($eventType)), 0, 50);
        $status = $status !== null ? substr(strtolower(trim($status)), 0, 32) : null;
        $providerMessageId = normalizeProviderMessageId($providerMessageId);
        $recipientEmail = $recipientEmail !== null ? substr(strtolower(trim($recipientEmail)), 0, 190) : null;
        $reason = $reason !== null ? substr(trim($reason), 0, 500) : null;
        $eventAt = $eventAt !== null && trim($eventAt) !== '' ? trim($eventAt) : null;
        $payloadJson = $payload !== null
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        if ($eventKey === null || trim($eventKey) === '') {
            $eventKey = hash('sha256', implode('|', [
                (string) $dispatchId,
                $provider,
                $eventType,
                (string) $providerMessageId,
                (string) $eventAt,
                (string) microtime(true),
                bin2hex(random_bytes(4)),
            ]));
        }

        $stmt = $connection->prepare(
            'INSERT IGNORE INTO mail_delivery_events
                (dispatch_id, event_key, provider, provider_message_id, event_type, status,
                 recipient_email, reason, event_at, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'isssssssss',
            $dispatchId,
            $eventKey,
            $provider,
            $providerMessageId,
            $eventType,
            $status,
            $recipientEmail,
            $reason,
            $eventAt,
            $payloadJson
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Mail Tracking Event Error: ' . $e->getMessage());
    }
}

function markMailDispatchSubmitted(
    ?int $dispatchId,
    string $provider,
    ?string $providerMessageId,
    bool $fallbackUsed,
    array $attempts,
    ?string $recipientEmail = null
): void {
    if (!$dispatchId) {
        return;
    }

    $connection = mailTrackingConnection();
    if (!$connection) {
        return;
    }

    try {
        ensureMailTrackingTables($connection);
        $providerMessageId = normalizeProviderMessageId($providerMessageId);
        $fallback = $fallbackUsed ? 1 : 0;
        $attemptsJson = json_encode($attempts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $connection->prepare(
            "UPDATE mail_dispatches
             SET provider = ?, fallback_used = ?, provider_message_id = ?, status = 'submitted',
                 attempts_json = ?, failure_code = NULL, failure_reason = NULL,
                 submitted_at = NOW(), last_event_at = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param('sissi', $provider, $fallback, $providerMessageId, $attemptsJson, $dispatchId);
        $stmt->execute();
        $stmt->close();

        recordMailDeliveryEvent(
            $dispatchId,
            $provider,
            'submitted',
            'submitted',
            $providerMessageId,
            $recipientEmail,
            null,
            date('Y-m-d H:i:s')
        );
    } catch (Throwable $e) {
        error_log('Mail Tracking Submit Error: ' . $e->getMessage());
    }
}

function markMailDispatchFailed(
    ?int $dispatchId,
    array $attempts,
    string $failureCode,
    string $failureReason,
    ?string $provider = null,
    ?string $recipientEmail = null
): void {
    if (!$dispatchId) {
        return;
    }

    $connection = mailTrackingConnection();
    if (!$connection) {
        return;
    }

    try {
        ensureMailTrackingTables($connection);
        $attemptsJson = json_encode($attempts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $failureCode = substr(trim($failureCode), 0, 80);
        $failureReason = substr(trim($failureReason), 0, 255);
        $provider = $provider !== null ? substr(strtolower(trim($provider)), 0, 20) : null;

        $stmt = $connection->prepare(
            "UPDATE mail_dispatches
             SET provider = COALESCE(?, provider), status = 'failed', attempts_json = ?,
                 failure_code = ?, failure_reason = ?, last_event_at = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param('ssssi', $provider, $attemptsJson, $failureCode, $failureReason, $dispatchId);
        $stmt->execute();
        $stmt->close();

        recordMailDeliveryEvent(
            $dispatchId,
            $provider ?: 'system',
            'failed',
            'failed',
            null,
            $recipientEmail,
            $failureReason,
            date('Y-m-d H:i:s')
        );
    } catch (Throwable $e) {
        error_log('Mail Tracking Failure Error: ' . $e->getMessage());
    }
}

function findMailDispatchForProviderEvent(
    string $provider,
    ?string $trackingId,
    ?string $providerMessageId
): ?array {
    $connection = mailTrackingConnection();
    if (!$connection) {
        return null;
    }

    try {
        ensureMailTrackingTables($connection);

        if ($trackingId !== null && preg_match('/^[a-f0-9]{32}$/i', $trackingId)) {
            $stmt = $connection->prepare('SELECT * FROM mail_dispatches WHERE tracking_id = ? LIMIT 1');
            $stmt->bind_param('s', $trackingId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }

        $providerMessageId = normalizeProviderMessageId($providerMessageId);
        if ($providerMessageId !== null) {
            $stmt = $connection->prepare(
                'SELECT * FROM mail_dispatches WHERE provider = ? AND provider_message_id = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->bind_param('ss', $provider, $providerMessageId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    } catch (Throwable $e) {
        error_log('Mail Tracking Lookup Error: ' . $e->getMessage());
    }

    return null;
}

function mailEventDateTime(array $event): ?string
{
    $epoch = $event['ts_event'] ?? $event['ts'] ?? null;
    if (is_numeric($epoch)) {
        return gmdate('Y-m-d H:i:s', (int) $epoch);
    }

    $date = trim((string) ($event['date'] ?? ''));
    if ($date !== '') {
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return gmdate('Y-m-d H:i:s', $timestamp);
        }
    }

    return null;
}

function extractBrevoTrackingId(array $event): ?string
{
    $custom = (string) ($event['X-Mailin-custom'] ?? $event['x-mailin-custom'] ?? '');
    if ($custom !== '' && preg_match('/otelex_tracking_id\s*[:=]\s*([a-f0-9]{32})/i', $custom, $match)) {
        return strtolower($match[1]);
    }

    $tags = $event['tags'] ?? [];
    if (is_string($tags)) {
        $decoded = json_decode($tags, true);
        $tags = is_array($decoded) ? $decoded : [$tags];
    }

    if (is_array($tags)) {
        foreach ($tags as $tag) {
            if (preg_match('/^otelex_([a-f0-9]{32})$/i', (string) $tag, $match)) {
                return strtolower($match[1]);
            }
        }
    }

    return null;
}

/**
 * Convert Brevo webhook event names into the delivery states shown in Otelex.
 * Engagement-only events are stored in the event ledger but do not replace the
 * delivery state.
 */
function brevoDeliveryStatusForEvent(string $eventType): ?string
{
    $eventType = strtolower(trim($eventType));
    $eventType = str_replace(['-', ' '], '_', $eventType);

    return match ($eventType) {
        'request', 'sent' => 'submitted',
        'delivered' => 'delivered',
        'deferred' => 'deferred',
        'soft_bounce', 'softbounce' => 'soft_bounced',
        'hard_bounce', 'hardbounce' => 'bounced',
        'blocked' => 'blocked',
        'spam' => 'spam',
        'invalid', 'invalid_email' => 'invalid',
        'error' => 'failed',
        default => null,
    };
}

/**
 * Apply one Brevo webhook event to an existing Otelex dispatch.
 * Returns false when the event does not belong to an Otelex-tracked message.
 */
function applyBrevoDeliveryEvent(array $event): bool
{
    $eventType = strtolower(trim((string) ($event['event'] ?? '')));
    $messageId = normalizeProviderMessageId((string) ($event['message-id'] ?? $event['message_id'] ?? ''));
    $trackingId = extractBrevoTrackingId($event);
    $dispatch = findMailDispatchForProviderEvent('brevo', $trackingId, $messageId);

    if (!$dispatch) {
        return false;
    }

    $dispatchId = (int) $dispatch['id'];
    $recipient = strtolower(trim((string) ($event['email'] ?? $dispatch['recipient_email'] ?? '')));
    $reason = trim((string) ($event['reason'] ?? $event['message'] ?? ''));
    $eventAt = mailEventDateTime($event) ?? gmdate('Y-m-d H:i:s');
    $status = brevoDeliveryStatusForEvent($eventType);

    // Webhook retries or network delays can arrive out of order. Do not let a
    // late "sent"/"deferred" event overwrite an already terminal state.
    if ($status !== null) {
        $rank = [
            'pending' => 0,
            'submitted' => 10,
            'deferred' => 20,
            'soft_bounced' => 25,
            'delivered' => 100,
            'bounced' => 100,
            'blocked' => 100,
            'spam' => 100,
            'invalid' => 100,
            'failed' => 100,
        ];
        $currentStatus = strtolower((string) ($dispatch['status'] ?? 'pending'));
        $currentRank = $rank[$currentStatus] ?? 0;
        $incomingRank = $rank[$status] ?? 0;
        if ($currentRank >= 100 && $incomingRank < 100) {
            $status = null;
        }
    }
    $eventIdentifier = (string) ($event['id'] ?? '');
    $eventTimestamp = (string) ($event['ts_event'] ?? $event['ts_epoch'] ?? $event['ts'] ?? $eventAt);
    $eventKey = hash('sha256', implode('|', [
        'brevo',
        (string) $dispatchId,
        $eventIdentifier,
        $eventType,
        (string) $messageId,
        $recipient,
        $eventTimestamp,
    ]));

    recordMailDeliveryEvent(
        $dispatchId,
        'brevo',
        $eventType !== '' ? $eventType : 'unknown',
        $status,
        $messageId,
        $recipient,
        $reason !== '' ? $reason : null,
        $eventAt,
        $event,
        $eventKey
    );

    $connection = mailTrackingConnection();
    if (!$connection) {
        return true;
    }

    try {
        $messageIdToSave = $messageId ?: normalizeProviderMessageId((string) ($dispatch['provider_message_id'] ?? ''));

        if ($status !== null) {
            $failureStatuses = ['soft_bounced', 'bounced', 'blocked', 'spam', 'invalid', 'failed'];
            $failureCode = in_array($status, $failureStatuses, true) ? $eventType : null;
            $failureReason = in_array($status, $failureStatuses, true) && $reason !== '' ? substr($reason, 0, 255) : null;
            $deliveredAt = $status === 'delivered' ? $eventAt : null;

            $stmt = $connection->prepare(
                'UPDATE mail_dispatches
                 SET provider_message_id = COALESCE(?, provider_message_id), status = ?,
                     failure_code = ?, failure_reason = ?,
                     delivered_at = CASE WHEN ? IS NOT NULL THEN ? ELSE delivered_at END,
                     last_event_at = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'sssssssi',
                $messageIdToSave,
                $status,
                $failureCode,
                $failureReason,
                $deliveredAt,
                $deliveredAt,
                $eventAt,
                $dispatchId
            );
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $connection->prepare(
                'UPDATE mail_dispatches
                 SET provider_message_id = COALESCE(?, provider_message_id), last_event_at = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('ssi', $messageIdToSave, $eventAt, $dispatchId);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('Brevo Delivery Tracking Update Error: ' . $e->getMessage());
    }

    return true;
}
