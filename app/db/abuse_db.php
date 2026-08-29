<?php
if (defined('ABUSE_DB_LOADED')) return;
define('ABUSE_DB_LOADED', true);

require_once __DIR__ . '/mysql_db.php';
require_once __DIR__ . '/hoster_db.php'; // stellt hoster_contacts sicher (JOINs + Snapshot)

// ── Schema bootstrap (idempotent) ─────────────────────────────────────────────

(function () {
    $db = getMySQL();

    $db->exec("CREATE TABLE IF NOT EXISTS abuse_reports (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        ref             VARCHAR(32)  DEFAULT NULL,
        ip              VARCHAR(45)  NOT NULL DEFAULT '',
        hoster_id       INT          DEFAULT NULL,
        reported_to     VARCHAR(255) DEFAULT '',
        subject         VARCHAR(255) DEFAULT '',
        reason          TEXT,
        status          VARCHAR(50)  DEFAULT 'Entwurf',
        note            TEXT,
        source          VARCHAR(30)  NOT NULL DEFAULT 'manual',
        source_ref      VARCHAR(255) DEFAULT '',
        created_by      INT          DEFAULT NULL,
        updated_by      INT          DEFAULT NULL,
        sent_at         DATETIME     DEFAULT NULL,
        last_inbound_at DATETIME     DEFAULT NULL,
        created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ref (ref),
        INDEX idx_ip     (ip),
        INDEX idx_status (status),
        INDEX idx_hoster (hoster_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Nachrüsten für bestehende Installationen
    ensureColumn($db, 'abuse_reports', 'ref',             "ref VARCHAR(32) DEFAULT NULL");
    ensureColumn($db, 'abuse_reports', 'hoster_id',       "hoster_id INT DEFAULT NULL");
    ensureColumn($db, 'abuse_reports', 'subject',         "subject VARCHAR(255) DEFAULT ''");
    ensureColumn($db, 'abuse_reports', 'sent_at',         "sent_at DATETIME DEFAULT NULL");
    ensureColumn($db, 'abuse_reports', 'last_inbound_at', "last_inbound_at DATETIME DEFAULT NULL");

    // Sicherheitsnetz: Reports ohne Nummer bekommen eine (saubere Durchnummerierung
    // macht scripts/renumber_reports.php). Format PREFIX-YY-NNNN, Nummer = id.
    $prefix = trim((string) (cfg('report_ref_prefix', 'R') ?: 'R'));
    $sep    = $prefix !== '' ? "CONCAT(?, '-', DATE_FORMAT(created_at, '%y'), '-', LPAD(id, 4, '0'))"
                             : "CONCAT(DATE_FORMAT(created_at, '%y'), '-', LPAD(id, 4, '0'))";
    $stmt = $db->prepare("UPDATE abuse_reports SET ref = {$sep} WHERE ref IS NULL OR ref = ''");
    $stmt->execute($prefix !== '' ? [$prefix] : []);

    $db->exec("CREATE TABLE IF NOT EXISTS abuse_log_entries (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        report_id  INT          NOT NULL,
        type       VARCHAR(50)  DEFAULT 'Notiz',
        content    TEXT,
        created_by INT          DEFAULT NULL,
        created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (report_id) REFERENCES abuse_reports(id) ON DELETE CASCADE,
        INDEX idx_report (report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS abuse_messages (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        report_id   INT              NOT NULL,
        direction   ENUM('out','in') NOT NULL,
        from_addr   VARCHAR(320) DEFAULT '',
        to_addr     VARCHAR(320) DEFAULT '',
        subject     VARCHAR(255) DEFAULT '',
        body_text   MEDIUMTEXT,
        message_id  VARCHAR(255) DEFAULT '',
        in_reply_to VARCHAR(255) DEFAULT '',
        imap_uid    INT          DEFAULT NULL,
        sent_by     INT          DEFAULT NULL,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (report_id) REFERENCES abuse_reports(id) ON DELETE CASCADE,
        INDEX idx_report  (report_id),
        INDEX idx_msgid   (message_id),
        UNIQUE KEY uq_in_uid (direction, imap_uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})();

// ── Statuswerte (zentral) ─────────────────────────────────────────────────────

const ABUSE_STATUSES = [
    'Entwurf', 'Gesendet', 'Wartet auf Antwort', 'Antwort erhalten',
    'Erfolgreich', 'Ignoriert', 'Abgeschlossen',
];
const ABUSE_CLOSED_STATUSES = ['Erfolgreich', 'Ignoriert', 'Abgeschlossen'];

function abuseRefPrefix(): string {
    return trim((string) (cfg('report_ref_prefix', 'R') ?: 'R'));
}

/**
 * Baut eine Reportnummer im Format  PREFIX-YY-NNNN  (z.B. R-26-0042).
 * $seq = laufende Nummer innerhalb des Jahres, $when = Bezugsdatum (Default: jetzt).
 */
function makeReportRef(int $seq, ?string $when = null): string {
    $prefix = abuseRefPrefix();
    $yy     = date('y', $when ? strtotime($when) : time());
    $num    = str_pad((string) max(1, $seq), 4, '0', STR_PAD_LEFT);
    return ($prefix !== '' ? "{$prefix}-" : '') . "{$yy}-{$num}";
}

/** Laufende Nummer für einen Report im Kalenderjahr von $when (1-basiert). */
function nextReportSeq(string $when = 'now'): int {
    $year = (int) date('Y', strtotime($when));
    $st = getMySQL()->prepare("SELECT COUNT(*) FROM abuse_reports WHERE YEAR(created_at) = ?");
    $st->execute([$year]);
    return (int) $st->fetchColumn() + 1;
}

// ── Reports ───────────────────────────────────────────────────────────────────

function getAllReports(): array {
    return getMySQL()->query("
        SELECT
            r.*,
            h.name      AS hoster_name,
            u.username   AS created_by_name,
            u2.username  AS updated_by_name,
            (SELECT COUNT(*) FROM abuse_log_entries WHERE report_id = r.id) AS log_count,
            (SELECT COUNT(*) FROM abuse_messages    WHERE report_id = r.id) AS msg_count
        FROM abuse_reports r
        LEFT JOIN hoster_contacts h ON h.id = r.hoster_id
        LEFT JOIN platform_users  u  ON u.id  = r.created_by
        LEFT JOIN platform_users  u2 ON u2.id = r.updated_by
        ORDER BY r.updated_at DESC, r.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function getReport(int $id): ?array {
    $stmt = getMySQL()->prepare("
        SELECT
            r.*,
            h.name        AS hoster_name,
            h.abuse_email AS hoster_email,
            h.abuse_url   AS hoster_url,
            h.method      AS hoster_method,
            u.username     AS created_by_name,
            u2.username    AS updated_by_name
        FROM abuse_reports r
        LEFT JOIN hoster_contacts h ON h.id = r.hoster_id
        LEFT JOIN platform_users  u  ON u.id  = r.created_by
        LEFT JOIN platform_users  u2 ON u2.id = r.updated_by
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getReportByRef(string $ref): ?array {
    $stmt = getMySQL()->prepare("SELECT * FROM abuse_reports WHERE ref = ?");
    $stmt->execute([$ref]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Legt einen Report an und vergibt eine Reportnummer im Format PREFIX-YY-NNNN.
 * @return array{id:int, ref:string}
 */
function createReport(string $ip, ?int $hosterId, string $reason, string $note, ?int $createdBy): array {
    $db = getMySQL();

    $reportedTo = '';
    if ($hosterId) {
        $h = getHosterSnapshot($hosterId);
        $reportedTo = $h['abuse_email'] ?: ($h['name'] ?? '');
    }

    $db->prepare("
        INSERT INTO abuse_reports (ip, hoster_id, reported_to, reason, status, note, created_by, source)
        VALUES (?, ?, ?, ?, 'Entwurf', ?, ?, 'manual')
    ")->execute([$ip, $hosterId ?: null, $reportedTo, $reason, $note, $createdBy]);

    $id      = (int) $db->lastInsertId();
    $created = (string) $db->query("SELECT created_at FROM abuse_reports WHERE id = {$id}")->fetchColumn();

    // laufende Nummer im Jahr = Position dieses Reports nach Erstellreihenfolge
    $seqStmt = $db->prepare("SELECT COUNT(*) FROM abuse_reports WHERE YEAR(created_at) = YEAR(?)");
    $seqStmt->execute([$created]);
    $seq = (int) $seqStmt->fetchColumn();

    $upd = $db->prepare("UPDATE abuse_reports SET ref = ? WHERE id = ?");
    $ref = '';
    for ($try = 0; $try < 25; $try++) {
        $ref = makeReportRef($seq + $try, $created);
        try {
            $upd->execute([$ref, $id]);
            break;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e; // nur bei Ref-Kollision weiterprobieren
        }
    }

    _addLogInternal($id, 'System', "Report {$ref} angelegt.", $createdBy);
    return ['id' => $id, 'ref' => $ref];
}

function updateReport(
    int $id, string $ip, ?int $hosterId, string $reason, string $status, string $note, ?int $updatedBy
): void {
    $old = getReport($id);

    $reportedTo = $old['reported_to'] ?? '';
    if ($hosterId && (int)($old['hoster_id'] ?? 0) !== $hosterId) {
        $h = getHosterSnapshot($hosterId);
        $reportedTo = $h['abuse_email'] ?: ($h['name'] ?? '');
    } elseif (!$hosterId) {
        $reportedTo = $old['reported_to'] ?? '';
    }

    getMySQL()->prepare("
        UPDATE abuse_reports
        SET ip = ?, hoster_id = ?, reported_to = ?, reason = ?, status = ?, note = ?, updated_by = ?
        WHERE id = ?
    ")->execute([$ip, $hosterId ?: null, $reportedTo, $reason, $status, $note, $updatedBy, $id]);

    if ($old && $old['status'] !== $status) {
        _addLogInternal($id, 'System', "Status geändert: {$old['status']} → {$status}", $updatedBy);
    }
}

function setReportStatus(int $id, string $status, ?int $updatedBy = null): void {
    $old = getReport($id);
    if ($old && $old['status'] === $status) return;
    getMySQL()->prepare("UPDATE abuse_reports SET status = ?, updated_by = ? WHERE id = ?")
        ->execute([$status, $updatedBy, $id]);
    if ($old) {
        _addLogInternal($id, 'System', "Status geändert: {$old['status']} → {$status}", $updatedBy);
    }
}

function markReportSent(int $id, string $subject, ?int $updatedBy = null): void {
    getMySQL()->prepare("
        UPDATE abuse_reports
        SET subject = ?, sent_at = COALESCE(sent_at, NOW()), status = 'Gesendet', updated_by = ?
        WHERE id = ?
    ")->execute([$subject, $updatedBy, $id]);
}

function touchReportInbound(int $id): void {
    getMySQL()->prepare("UPDATE abuse_reports SET last_inbound_at = NOW() WHERE id = ?")->execute([$id]);
}

function deleteReport(int $id): void {
    getMySQL()->prepare("DELETE FROM abuse_reports WHERE id = ?")->execute([$id]);
}

// ── Log entries ───────────────────────────────────────────────────────────────

function getLogs(int $reportId): array {
    $stmt = getMySQL()->prepare("
        SELECT l.*, u.username AS created_by_name
        FROM abuse_log_entries l
        LEFT JOIN platform_users u ON u.id = l.created_by
        WHERE l.report_id = ?
        ORDER BY l.created_at ASC, l.id ASC
    ");
    $stmt->execute([$reportId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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

// ── E-Mail-Nachrichten ────────────────────────────────────────────────────────

/**
 * @param array $data keys: from_addr,to_addr,subject,body_text,message_id,in_reply_to,imap_uid,sent_by
 */
function addMessage(int $reportId, string $direction, array $data): int {
    $db = getMySQL();
    $db->prepare("
        INSERT INTO abuse_messages
            (report_id, direction, from_addr, to_addr, subject, body_text, message_id, in_reply_to, imap_uid, sent_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $reportId, $direction,
        $data['from_addr']   ?? '',
        $data['to_addr']     ?? '',
        $data['subject']     ?? '',
        $data['body_text']   ?? '',
        $data['message_id']  ?? '',
        $data['in_reply_to'] ?? '',
        $data['imap_uid']    ?? null,
        $data['sent_by']     ?? null,
    ]);
    return (int) $db->lastInsertId();
}

function getMessages(int $reportId): array {
    $stmt = getMySQL()->prepare("
        SELECT m.*, u.username AS sent_by_name
        FROM abuse_messages m
        LEFT JOIN platform_users u ON u.id = m.sent_by
        WHERE m.report_id = ?
        ORDER BY m.created_at ASC, m.id ASC
    ");
    $stmt->execute([$reportId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Message-ID der jüngsten eingehenden Nachricht (für In-Reply-To beim Antworten). */
function getLatestInboundMessageId(int $reportId): ?string {
    $stmt = getMySQL()->prepare("
        SELECT message_id FROM abuse_messages
        WHERE report_id = ? AND direction = 'in' AND message_id <> ''
        ORDER BY created_at DESC, id DESC LIMIT 1
    ");
    $stmt->execute([$reportId]);
    return $stmt->fetchColumn() ?: null;
}

/** Message-ID irgendeiner (aus-/eingehenden) Nachricht des Reports — für Threading-Fallback. */
function getAnyMessageIds(int $reportId): array {
    $stmt = getMySQL()->prepare("SELECT message_id FROM abuse_messages WHERE report_id = ? AND message_id <> ''");
    $stmt->execute([$reportId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function inboundMessageExists(?int $uid, string $messageId): bool {
    $db = getMySQL();
    if ($uid !== null) {
        $st = $db->prepare("SELECT 1 FROM abuse_messages WHERE direction='in' AND imap_uid = ? LIMIT 1");
        $st->execute([$uid]);
        if ($st->fetchColumn()) return true;
    }
    if ($messageId !== '') {
        $st = $db->prepare("SELECT 1 FROM abuse_messages WHERE message_id = ? LIMIT 1");
        $st->execute([$messageId]);
        if ($st->fetchColumn()) return true;
    }
    return false;
}

/** Findet report_id anhand einer Liste von Message-IDs (aus In-Reply-To / References). */
function findReportIdByMessageIds(array $messageIds): ?int {
    $messageIds = array_values(array_filter(array_map('trim', $messageIds)));
    if (!$messageIds) return null;
    $in  = implode(',', array_fill(0, count($messageIds), '?'));
    $st  = getMySQL()->prepare("SELECT report_id FROM abuse_messages WHERE message_id IN ($in) ORDER BY id DESC LIMIT 1");
    $st->execute($messageIds);
    $v = $st->fetchColumn();
    return $v ? (int) $v : null;
}

/** Genau ein offener Report zu dieser Hoster-Abuse-Adresse? Sonst null. */
function singleOpenReportIdForHosterEmail(string $email): ?int {
    if ($email === '') return null;
    $closed = "'" . implode("','", ABUSE_CLOSED_STATUSES) . "'";
    $st = getMySQL()->prepare("
        SELECT r.id FROM abuse_reports r
        JOIN hoster_contacts h ON h.id = r.hoster_id
        WHERE h.abuse_email = ? AND r.status NOT IN ($closed)
    ");
    $st->execute([$email]);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    return count($rows) === 1 ? (int) $rows[0] : null;
}

// ── Gemischte Timeline (Nachrichten + Logeinträge) ────────────────────────────

function getTimeline(int $reportId): array {
    $items = [];
    foreach (getMessages($reportId) as $m) {
        $items[] = [
            'kind'       => 'message',
            'id'         => (int) $m['id'],
            'direction'  => $m['direction'],
            'from_addr'  => $m['from_addr'],
            'to_addr'    => $m['to_addr'],
            'subject'    => $m['subject'],
            'content'    => $m['body_text'],
            'sent_by_name' => $m['sent_by_name'] ?? null,
            'created_at' => $m['created_at'],
        ];
    }
    foreach (getLogs($reportId) as $l) {
        $items[] = [
            'kind'       => 'log',
            'id'         => (int) $l['id'],
            'type'       => $l['type'],
            'content'    => $l['content'],
            'created_by_name' => $l['created_by_name'] ?? null,
            'created_at' => $l['created_at'],
        ];
    }
    usort($items, fn($a, $b) => [$a['created_at'], $a['kind'], $a['id']] <=> [$b['created_at'], $b['kind'], $b['id']]);
    return $items;
}

// ── Hoster-Snapshot (ohne hoster_db.php hart zu koppeln) ──────────────────────

function getHosterSnapshot(int $hosterId): array {
    $st = getMySQL()->prepare("SELECT id, name, abuse_email, abuse_url, method FROM hoster_contacts WHERE id = ?");
    $st->execute([$hosterId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => 0, 'name' => '', 'abuse_email' => '', 'abuse_url' => '', 'method' => ''];
}
