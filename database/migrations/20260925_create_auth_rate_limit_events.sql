-- Security Batch 5: generic authentication/security endpoint rate-limit events.

CREATE TABLE IF NOT EXISTS `auth_rate_limit_events` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` varchar(64) NOT NULL,
  `key_hash` char(64) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_auth_rate_limit_window` (`action`,`key_hash`,`attempted_at`),
  KEY `idx_auth_rate_limit_cleanup` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Support account-wide and IP-wide failed-login checks without full table scans.
ALTER TABLE `auth_login_attempts`
  ADD KEY `idx_login_account_window` (`identifier_hash`,`success`,`attempted_at`),
  ADD KEY `idx_login_ip_window` (`ip_address`,`success`,`attempted_at`);
