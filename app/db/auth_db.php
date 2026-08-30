<?php
if (defined('AUTH_DB_LOADED')) return;
define('AUTH_DB_LOADED', true);

require_once __DIR__ . '/mysql_db.php';

// ── Schema bootstrap (idempotent) ─────────────────────────────────────────────

(function () {
    $db = getMySQL();
    foreach ([
        "CREATE TABLE IF NOT EXISTS platform_users (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            username      VARCHAR(80)  UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role          VARCHAR(20)  NOT NULL DEFAULT 'viewer',
            active        TINYINT(1)   NOT NULL DEFAULT 1,
            created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
            last_login    DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS platform_login_attempts (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            ip           VARCHAR(45) NOT NULL,
            username     VARCHAR(80),
            success      TINYINT(1)  NOT NULL DEFAULT 0,
            attempted_at DATETIME    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS platform_remember_tokens (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT         NOT NULL,
            token_hash VARCHAR(64) UNIQUE NOT NULL,
            expires_at DATETIME    NOT NULL,
            created_at DATETIME    DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES platform_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS platform_settings (
            `key`      VARCHAR(64) NOT NULL PRIMARY KEY,
            `value`    TEXT        NULL,
            updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT         NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $sql) {
        $db->exec($sql);
    }

    // Profil-Anzeigename (für Mail-Signatur etc.)
    ensureColumn($db, 'platform_users', 'full_name', 'full_name VARCHAR(120) NULL AFTER username');

    // Cleanup expired remember tokens
    $db->exec("DELETE FROM platform_remember_tokens WHERE expires_at < NOW()");
})();

// ── Users ─────────────────────────────────────────────────────────────────────

function userCount(): int {
    return (int) getMySQL()->query("SELECT COUNT(*) FROM platform_users")->fetchColumn();
}

function getAllUsers(): array {
    return getMySQL()
        ->query("SELECT id, username, full_name, role, active, created_at, last_login FROM platform_users ORDER BY created_at ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function updateUserFullName(int $id, string $fullName): void {
    $fullName = trim($fullName);
    getMySQL()
        ->prepare("UPDATE platform_users SET full_name = ? WHERE id = ?")
        ->execute([$fullName !== '' ? $fullName : null, $id]);
}

function getUserById(int $id): ?array {
    $stmt = getMySQL()->prepare("SELECT * FROM platform_users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getUserByUsername(string $username): ?array {
    $stmt = getMySQL()->prepare("SELECT * FROM platform_users WHERE username = ? AND active = 1");
    $stmt->execute([$username]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function createUser(string $username, string $password, string $role = 'viewer'): int {
    $db   = getMySQL();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO platform_users (username, password_hash, role) VALUES (?, ?, ?)");
    $stmt->execute([$username, $hash, $role]);
    return (int) $db->lastInsertId();
}

function updateUserPassword(int $id, string $password): void {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    getMySQL()->prepare("UPDATE platform_users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
}

function toggleUserActive(int $id): void {
    getMySQL()->prepare("UPDATE platform_users SET active = 1 - active WHERE id = ?")->execute([$id]);
}

function deleteUser(int $id): void {
    getMySQL()->prepare("DELETE FROM platform_users WHERE id = ?")->execute([$id]);
}

function updateLastLogin(int $id): void {
    getMySQL()->prepare("UPDATE platform_users SET last_login = NOW() WHERE id = ?")->execute([$id]);
}

// ── Login attempts ────────────────────────────────────────────────────────────

function logAttempt(string $ip, ?string $username, bool $success): void {
    getMySQL()
        ->prepare("INSERT INTO platform_login_attempts (ip, username, success) VALUES (?, ?, ?)")
        ->execute([$ip, $username, $success ? 1 : 0]);
}

function getFailedAttempts(string $ip, int $minutes = 15): int {
    $stmt = getMySQL()->prepare(
        "SELECT COUNT(*) FROM platform_login_attempts
         WHERE ip = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([$ip, $minutes]);
    return (int) $stmt->fetchColumn();
}

function getLoginLog(int $limit = 100): array {
    return getMySQL()
        ->query("SELECT * FROM platform_login_attempts ORDER BY attempted_at DESC LIMIT $limit")
        ->fetchAll(PDO::FETCH_ASSOC);
}

// ── Remember tokens ───────────────────────────────────────────────────────────

function createRememberToken(int $userId): string {
    $token   = bin2hex(random_bytes(32));
    $hash    = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    getMySQL()
        ->prepare("INSERT INTO platform_remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)")
        ->execute([$userId, $hash, $expires]);
    return $token;
}

function getUserByRememberToken(string $token): ?array {
    $hash = hash('sha256', $token);
    $stmt = getMySQL()->prepare("
        SELECT u.* FROM platform_users u
        JOIN platform_remember_tokens rt ON rt.user_id = u.id
        WHERE rt.token_hash = ? AND rt.expires_at > NOW() AND u.active = 1
    ");
    $stmt->execute([$hash]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function deleteRememberToken(string $token): void {
    $hash = hash('sha256', $token);
    getMySQL()->prepare("DELETE FROM platform_remember_tokens WHERE token_hash = ?")->execute([$hash]);
}

function deleteAllUserTokens(int $userId): void {
    getMySQL()->prepare("DELETE FROM platform_remember_tokens WHERE user_id = ?")->execute([$userId]);
}
