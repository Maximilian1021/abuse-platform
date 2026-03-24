<?php
if (defined('ABUSE_DB_LOADED')) return;
define('ABUSE_DB_LOADED', true);

require_once __DIR__ . '/mysql_db.php';

// ── Schema bootstrap (idempotent) ─────────────────────────────────────────────

(function () {
    $db = getMySQL();
    foreach ([
        "CREATE TABLE IF NOT EXISTS abuse_reports (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            ip           VARCHAR(45)  NOT NULL DEFAULT '',
            reported_to  VARCHAR(255) DEFAULT '',
            reason       TEXT,
            status       VARCHAR(50)  DEFAULT 'Gemeldet',
            note         TEXT,
            source       VARCHAR(30)  NOT NULL DEFAULT 'manual',
            source_ref   VARCHAR(255) DEFAULT '',
            created_by   INT          DEFAULT NULL,
            updated_by   INT          DEFAULT NULL,
            created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ip     (ip),
            INDEX idx_status (status),
            INDEX idx_source (source)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS abuse_log_entries (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            report_id  INT          NOT NULL,
            type       VARCHAR(50)  DEFAULT 'Notiz',
            content    TEXT,
            created_by INT          DEFAULT NULL,
            created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id) REFERENCES abuse_reports(id) ON DELETE CASCADE,
            INDEX idx_report (report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $sql) {
        $db->exec($sql);
    }
})();

// ── Reports ───────────────────────────────────────────────────────────────────

function getAllReports(): array {
    return getMySQL()->query("
        SELECT
            r.*,
            u.username  AS created_by_name,
            u2.username AS updated_by_name,
            (SELECT COUNT(*) FROM abuse_log_entries WHERE report_id = r.id) AS log_count
        FROM abuse_reports r
        LEFT JOIN platform_users u  ON u.id  = r.created_by
        LEFT JOIN platform_users u2 ON u2.id = r.updated_by
        ORDER BY r.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function getReport(int $id): ?array {
    $stmt = getMySQL()->prepare("
        SELECT
            r.*,
            u.username  AS created_by_name,
            u2.username AS updated_by_name
        FROM abuse_reports r
        LEFT JOIN platform_users u  ON u.id  = r.created_by
        LEFT JOIN platform_users u2 ON u2.id = r.updated_by
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getLogs(int $reportId): array {
    $stmt = getMySQL()->prepare("
        SELECT l.*, u.username AS created_by_name
        FROM abuse_log_entries l
        LEFT JOIN platform_users u ON u.id = l.created_by
        WHERE l.report_id = ?
        ORDER BY l.created_at ASC
    ");
    $stmt->execute([$reportId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function createReport(
    string $ip,
    string $reportedTo,
    string $reason,
    string $status,
    string $note,
    ?int   $createdBy = null,
    string $source    = 'manual',
    string $sourceRef = ''
): int {
    $db   = getMySQL();
    $stmt = $db->prepare("
        INSERT INTO abuse_reports (ip, reported_to, reason, status, note, created_by, source, source_ref)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$ip, $reportedTo, $reason, $status, $note, $createdBy, $source, $sourceRef]);
    $id = (int) $db->lastInsertId();

    $who = $createdBy ? null : null; // resolved via join in getLogs
    _addLogInternal($id, 'System', "Report erstellt. Status: $status", $createdBy);
    return $id;
}

function updateReport(
    int    $id,
    string $ip,
    string $reportedTo,
    string $reason,
    string $status,
    string $note,
    ?int   $updatedBy = null
): void {
    $old  = getReport($id);
    $stmt = getMySQL()->prepare("
        UPDATE abuse_reports
        SET ip=?, reported_to=?, reason=?, status=?, note=?, updated_by=?
        WHERE id=?
    ");
    $stmt->execute([$ip, $reportedTo, $reason, $status, $note, $updatedBy, $id]);

    if ($old && $old['status'] !== $status) {
        _addLogInternal($id, 'System', "Status geändert: {$old['status']} → $status", $updatedBy);
    }
}

function deleteReport(int $id): void {
    getMySQL()->prepare("DELETE FROM abuse_reports WHERE id = ?")->execute([$id]);
}

// ── Log entries ───────────────────────────────────────────────────────────────

function addLog(int $reportId, string $type, string $content, ?int $createdBy = null): void {
    _addLogInternal($reportId, $type, $content, $createdBy);
}

function _addLogInternal(int $reportId, string $type, string $content, ?int $createdBy): void {
    getMySQL()
        ->prepare("INSERT INTO abuse_log_entries (report_id, type, content, created_by) VALUES (?, ?, ?, ?)")
        ->execute([$reportId, $type, $content, $createdBy]);
}

function deleteLog(int $id): void {
    getMySQL()->prepare("DELETE FROM abuse_log_entries WHERE id = ?")->execute([$id]);
}
