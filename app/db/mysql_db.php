<?php
if (defined('MYSQL_DB_LOADED')) return;
define('MYSQL_DB_LOADED', true);

require_once __DIR__ . '/../helpers/config.php';

function getMySQL(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = appConfig();

    $dsn = "mysql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$cfg['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/**
 * Idempotenter Spalten-Zusatz (MySQL 8 kennt kein ADD COLUMN IF NOT EXISTS).
 * $ddl ist alles nach "ADD COLUMN ", z.B. "ref VARCHAR(32) NULL".
 */
function ensureColumn(PDO $db, string $table, string $column, string $ddl): void {
    $st = $db->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $column]);
    if (!$st->fetchColumn()) {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    }
}
