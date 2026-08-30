<?php
if (defined('TEMPLATES_DB_LOADED')) return;
define('TEMPLATES_DB_LOADED', true);

require_once __DIR__ . '/mysql_db.php';

/**
 * Speicher für die Abuse-Mail-Vorlagen (GUI-editierbar unter Admin -> Vorlagen).
 * Die Code-Defaults in app/mail/templates.php dienen als Seed + Fallback.
 */
function mailTemplatesBootstrap(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    getMySQL()->exec(
        "CREATE TABLE IF NOT EXISTS abuse_mail_templates (
            `key`      VARCHAR(40)  NOT NULL PRIMARY KEY,
            label      VARCHAR(120) NOT NULL,
            subject    VARCHAR(255) NOT NULL,
            body       MEDIUMTEXT   NOT NULL,
            sort       INT          NOT NULL DEFAULT 0,
            updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT          NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** Alle Vorlagen als [key => ['key','label','subject','body','sort',...]], sortiert. */
function getMailTemplatesRaw(): array {
    mailTemplatesBootstrap();
    $rows = getMySQL()
        ->query("SELECT * FROM abuse_mail_templates ORDER BY sort ASC, `key` ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[$r['key']] = $r;
    return $out;
}

/** Seedet die Tabelle einmalig aus den Code-Defaults, solange sie leer ist. */
function seedMailTemplatesIfEmpty(array $defaults): void {
    mailTemplatesBootstrap();
    $count = (int) getMySQL()->query("SELECT COUNT(*) FROM abuse_mail_templates")->fetchColumn();
    if ($count > 0) return;
    $i = 0;
    foreach ($defaults as $key => $t) {
        saveMailTemplate((string) $key, $t['label'] ?? $key, $t['subject'] ?? '', $t['body'] ?? '', $i++, null);
    }
}

function saveMailTemplate(string $key, string $label, string $subject, string $body, ?int $sort, ?int $updatedBy): void {
    mailTemplatesBootstrap();
    $body = str_replace("\r\n", "\n", $body);
    if ($sort === null) {
        $sort = (int) getMySQL()->query("SELECT COALESCE(MAX(sort), -1) + 1 FROM abuse_mail_templates")->fetchColumn();
    }
    getMySQL()->prepare(
        "INSERT INTO abuse_mail_templates (`key`, label, subject, body, sort, updated_by)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE label = VALUES(label), subject = VALUES(subject),
                                 body = VALUES(body), updated_by = VALUES(updated_by)"
    )->execute([$key, $label, $subject, $body, $sort, $updatedBy]);
}

function deleteMailTemplate(string $key): void {
    mailTemplatesBootstrap();
    getMySQL()->prepare("DELETE FROM abuse_mail_templates WHERE `key` = ?")->execute([$key]);
}

/** Verwirft alle Vorlagen und seedet die Code-Defaults neu. */
function resetMailTemplates(array $defaults, ?int $updatedBy): void {
    mailTemplatesBootstrap();
    getMySQL()->exec("DELETE FROM abuse_mail_templates");
    $i = 0;
    foreach ($defaults as $key => $t) {
        saveMailTemplate((string) $key, $t['label'] ?? $key, $t['subject'] ?? '', $t['body'] ?? '', $i++, $updatedBy);
    }
}
