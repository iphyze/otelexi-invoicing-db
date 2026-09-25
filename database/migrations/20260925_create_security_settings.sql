-- Security Batch 3: administrator-managed application security settings.
-- Apply after the authentication/session migrations.

CREATE TABLE IF NOT EXISTS `security_settings` (
  `id` tinyint unsigned NOT NULL DEFAULT 1,
  `max_active_sessions` tinyint unsigned NOT NULL DEFAULT 2,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_security_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_security_settings_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
