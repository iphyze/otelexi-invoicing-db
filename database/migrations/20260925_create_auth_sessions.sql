-- Logical authenticated sessions used for inactivity timeout and future device/session controls.
-- Apply this migration once before deploying the corresponding authentication code.

CREATE TABLE `auth_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_key` char(64) NOT NULL COMMENT 'Random public session identifier embedded in access JWTs',
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) NOT NULL,
  `last_activity_at` datetime NOT NULL,
  `absolute_expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revocation_reason` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_sessions_session_key` (`session_key`),
  KEY `idx_auth_sessions_user_active` (`user_id`, `revoked_at`, `last_activity_at`),
  KEY `idx_auth_sessions_expiry` (`absolute_expires_at`),
  CONSTRAINT `fk_auth_sessions_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `auth_refresh_tokens`
  ADD COLUMN `session_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `user_id`,
  ADD KEY `idx_auth_refresh_session` (`session_id`),
  ADD CONSTRAINT `fk_auth_refresh_session`
    FOREIGN KEY (`session_id`) REFERENCES `auth_sessions` (`id`) ON DELETE CASCADE;
