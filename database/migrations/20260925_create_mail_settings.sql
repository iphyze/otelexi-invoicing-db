-- Otelex mail-provider routing preferences.
-- Provider credentials remain in the backend .env file and are never stored here.

CREATE TABLE IF NOT EXISTS `mail_settings` (
  `id` tinyint unsigned NOT NULL DEFAULT 1,
  `default_provider` varchar(20) NOT NULL DEFAULT 'zoho',
  `zoho_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `brevo_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `fallback_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `fallback_provider` varchar(20) DEFAULT 'brevo',
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mail_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_mail_settings_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
