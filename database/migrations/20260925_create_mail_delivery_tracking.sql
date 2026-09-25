CREATE TABLE IF NOT EXISTS mail_dispatches (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_delivery_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
