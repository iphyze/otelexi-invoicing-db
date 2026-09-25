-- Security Batch 4: optional per-user email multi-factor authentication.
-- Apply after the existing authentication/session migrations.

CREATE TABLE IF NOT EXISTS `user_security_settings` (
  `user_id` int(11) NOT NULL,
  `email_mfa_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `email_mfa_enabled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_security_settings_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auth_mfa_challenges` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `purpose` varchar(32) NOT NULL,
  `challenge_hash` char(64) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `device_hash` char(64) DEFAULT NULL,
  `auth_version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `attempts` tinyint UNSIGNED NOT NULL DEFAULT 0,
  `send_count` tinyint UNSIGNED NOT NULL DEFAULT 1,
  `last_sent_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_mfa_challenge_hash` (`challenge_hash`),
  KEY `idx_auth_mfa_user_purpose` (`user_id`,`purpose`,`used_at`,`expires_at`),
  KEY `idx_auth_mfa_cleanup` (`expires_at`,`used_at`),
  CONSTRAINT `fk_auth_mfa_challenges_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
