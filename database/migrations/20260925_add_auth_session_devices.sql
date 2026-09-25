-- Security Batch 2: bind logical sessions to stable browser/device identities
-- and support short-lived, single-use login challenges when the device limit is reached.
-- Apply after 20260925_create_auth_sessions.sql.

ALTER TABLE `auth_sessions`
  ADD COLUMN `device_hash` char(64) DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `device_label` varchar(120) DEFAULT NULL AFTER `device_hash`,
  ADD KEY `idx_auth_sessions_user_device` (`user_id`, `device_hash`, `revoked_at`);

CREATE TABLE `auth_login_challenges` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `purpose` varchar(32) NOT NULL,
  `challenge_hash` char(64) NOT NULL,
  `device_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_login_challenge_hash` (`challenge_hash`),
  KEY `idx_auth_login_challenges_lookup` (`user_id`, `purpose`, `used_at`, `expires_at`),
  CONSTRAINT `fk_auth_login_challenges_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
