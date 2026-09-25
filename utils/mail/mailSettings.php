<?php
// utils/mail/mailSettings.php
// Database-backed routing controls for the central mail service.
// Provider secrets remain in .env; only non-sensitive routing preferences are stored here.

declare(strict_types=1);

function environmentMailRoutingSettings(): array
{
    $defaultProvider = strtolower(trim((string) (config('MAIL_DEFAULT_PROVIDER', 'zoho') ?? 'zoho')));
    if (!in_array($defaultProvider, ['zoho', 'brevo'], true)) {
        $defaultProvider = 'zoho';
    }

    $fallbackProvider = strtolower(trim((string) (config('MAIL_FALLBACK_PROVIDER', 'brevo') ?? 'brevo')));
    if (!in_array($fallbackProvider, ['zoho', 'brevo'], true)) {
        $fallbackProvider = $defaultProvider === 'zoho' ? 'brevo' : 'zoho';
    }

    return [
        'default_provider' => $defaultProvider,
        'zoho_enabled' => configBool('ZOHO_MAIL_ENABLED', true),
        'brevo_enabled' => configBool('BREVO_MAIL_ENABLED', false),
        'fallback_enabled' => configBool('MAIL_FALLBACK_ENABLED', false),
        'fallback_provider' => $fallbackProvider,
        'source' => 'environment',
        'updated_at' => null,
    ];
}

function mailSettingsConnection(): ?mysqli
{
    global $conn;
    return isset($conn) && $conn instanceof mysqli ? $conn : null;
}

/**
 * Resolve routing preferences. Database values take precedence when available.
 * If the migration has not yet been applied, the application safely falls back
 * to the existing environment configuration.
 */
function resolvedMailRoutingSettings(bool $refresh = false): array
{
    static $cached = null;

    if (!$refresh && is_array($cached)) {
        return $cached;
    }

    $settings = environmentMailRoutingSettings();
    $connection = mailSettingsConnection();

    if (!$connection) {
        $cached = $settings;
        return $cached;
    }

    try {
        $result = $connection->query(
            'SELECT default_provider, zoho_enabled, brevo_enabled, fallback_enabled, fallback_provider, updated_at
             FROM mail_settings
             WHERE id = 1
             LIMIT 1'
        );
        $row = $result ? $result->fetch_assoc() : null;

        if ($row) {
            $defaultProvider = strtolower(trim((string) $row['default_provider']));
            $fallbackProvider = strtolower(trim((string) ($row['fallback_provider'] ?? '')));

            if (!in_array($defaultProvider, ['zoho', 'brevo'], true)) {
                $defaultProvider = $settings['default_provider'];
            }
            if (!in_array($fallbackProvider, ['zoho', 'brevo'], true)) {
                $fallbackProvider = $defaultProvider === 'zoho' ? 'brevo' : 'zoho';
            }

            $settings = [
                'default_provider' => $defaultProvider,
                'zoho_enabled' => (bool) $row['zoho_enabled'],
                'brevo_enabled' => (bool) $row['brevo_enabled'],
                'fallback_enabled' => (bool) $row['fallback_enabled'],
                'fallback_provider' => $fallbackProvider,
                'source' => 'database',
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }
    } catch (mysqli_sql_exception $e) {
        // Error 1146 = table does not exist. This is expected until the Batch 2
        // migration is applied/saved for the first time; all other errors are logged.
        if ((int) $e->getCode() !== 1146) {
            error_log('Mail Settings Read Error: ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        error_log('Mail Settings Read Error: ' . $e->getMessage());
    }

    $cached = $settings;
    return $cached;
}

function ensureMailSettingsTable(mysqli $connection): void
{
    $connection->query(
        "CREATE TABLE IF NOT EXISTS mail_settings (
            id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            default_provider VARCHAR(20) NOT NULL DEFAULT 'zoho',
            zoho_enabled TINYINT(1) NOT NULL DEFAULT 1,
            brevo_enabled TINYINT(1) NOT NULL DEFAULT 0,
            fallback_enabled TINYINT(1) NOT NULL DEFAULT 0,
            fallback_provider VARCHAR(20) DEFAULT 'brevo',
            updated_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_mail_settings_updated_by
                FOREIGN KEY (updated_by) REFERENCES users(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function saveMailRoutingSettings(mysqli $connection, array $settings, int $updatedBy): array
{
    ensureMailSettingsTable($connection);

    $defaultProvider = (string) $settings['default_provider'];
    $zohoEnabled = !empty($settings['zoho_enabled']) ? 1 : 0;
    $brevoEnabled = !empty($settings['brevo_enabled']) ? 1 : 0;
    $fallbackEnabled = !empty($settings['fallback_enabled']) ? 1 : 0;
    $fallbackProvider = (string) $settings['fallback_provider'];

    $stmt = $connection->prepare(
        'INSERT INTO mail_settings
            (id, default_provider, zoho_enabled, brevo_enabled, fallback_enabled, fallback_provider, updated_by)
         VALUES (1, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            default_provider = VALUES(default_provider),
            zoho_enabled = VALUES(zoho_enabled),
            brevo_enabled = VALUES(brevo_enabled),
            fallback_enabled = VALUES(fallback_enabled),
            fallback_provider = VALUES(fallback_provider),
            updated_by = VALUES(updated_by)'
    );
    $stmt->bind_param(
        'siiisi',
        $defaultProvider,
        $zohoEnabled,
        $brevoEnabled,
        $fallbackEnabled,
        $fallbackProvider,
        $updatedBy
    );
    $stmt->execute();
    $stmt->close();

    return resolvedMailRoutingSettings(true);
}
