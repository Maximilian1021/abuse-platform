<?php
if (defined('MYSQL_DB_LOADED')) return;
define('MYSQL_DB_LOADED', true);

function getMySQL(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    static $cfg = null;
    if (!$cfg) $cfg = require __DIR__ . '/../config/agent_config.php';

    $dsn = "mysql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$cfg['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
