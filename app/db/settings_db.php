<?php
if (defined('SETTINGS_DB_LOADED')) return;
define('SETTINGS_DB_LOADED', true);

require_once __DIR__ . '/mysql_db.php';

/**
 * Key/Value-Speicher für GUI-editierbare Einstellungen (Branding etc.).
 * Überschreibt die Defaults aus app/config/config.php — siehe cfg() / CONFIG_DB_KEYS.
 * Secrets (DB/SMTP/IMAP) landen hier bewusst NICHT.
 */
function settingsBootstrap(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    getMySQL()->exec(
        "CREATE TABLE IF NOT EXISTS platform_settings (
            `key`      VARCHAR(64) NOT NULL PRIMARY KEY,
            `value`    TEXT        NULL,
            updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT         NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** Alle gespeicherten Overrides als key=>value. */
function getAllSettings(): array {
    settingsBootstrap();
    return getMySQL()
        ->query("SELECT `key`, `value` FROM platform_settings")
        ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
}

/** Upsert für die übergebenen key=>value-Paare. */
function saveSettings(array $kv, ?int $updatedBy = null): void {
    settingsBootstrap();
    $st = getMySQL()->prepare(
        "INSERT INTO platform_settings (`key`, `value`, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)"
    );
    foreach ($kv as $k => $v) {
        $st->execute([$k, $v, $updatedBy]);
    }
}
