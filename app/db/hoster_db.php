<?php
if (defined('HOSTER_DB_LOADED')) return;
define('HOSTER_DB_LOADED', true);

require_once __DIR__ . '/mysql_db.php';

// ── Schema bootstrap (idempotent) ─────────────────────────────────────────────

(function () {
    getMySQL()->exec("CREATE TABLE IF NOT EXISTS hoster_contacts (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(100)  NOT NULL,
        abuse_url    VARCHAR(500)  DEFAULT '',
        abuse_email  VARCHAR(255)  DEFAULT '',
        method       VARCHAR(20)   NOT NULL DEFAULT 'email',
        responds     VARCHAR(20)   NOT NULL DEFAULT 'manchmal',
        notes        TEXT,
        created_at   DATETIME      DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

// ── CRUD ──────────────────────────────────────────────────────────────────────

function getAllHosters(): array {
    return getMySQL()->query("SELECT * FROM hoster_contacts ORDER BY name ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function getHoster(int $id): ?array {
    $stmt = getMySQL()->prepare("SELECT * FROM hoster_contacts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function createHoster(
    string $name,
    string $url,
    string $email,
    string $method,
    string $responds,
    string $notes
): int {
    $db = getMySQL();
    $db->prepare("INSERT INTO hoster_contacts (name, abuse_url, abuse_email, method, responds, notes)
                  VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$name, $url, $email, $method, $responds, $notes]);
    return (int)$db->lastInsertId();
}

function updateHoster(
    int    $id,
    string $name,
    string $url,
    string $email,
    string $method,
    string $responds,
    string $notes
): void {
    getMySQL()->prepare("UPDATE hoster_contacts
        SET name=?, abuse_url=?, abuse_email=?, method=?, responds=?, notes=?
        WHERE id=?")
    ->execute([$name, $url, $email, $method, $responds, $notes, $id]);
}

function deleteHoster(int $id): void {
    getMySQL()->prepare("DELETE FROM hoster_contacts WHERE id = ?")->execute([$id]);
}
