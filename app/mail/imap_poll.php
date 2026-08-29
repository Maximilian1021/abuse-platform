<?php
/**
 * imap_poll.php — holt Antworten aus dem Abuse-Postfach und ordnet sie Reports zu.
 *
 * Aufruf (per Cron, z.B. alle 5 Minuten):
 *   php /pfad/zu/app/mail/imap_poll.php
 *
 * Zuordnung in dieser Reihenfolge:
 *   1. In-Reply-To / References enthält eine bekannte Message-ID
 *   2. Betreff enthält [PREFIX-1234]  (Reportnummer)
 *   3. Absender == hoster_contacts.abuse_email und genau EIN offener Report dazu
 *   4. kein Treffer -> Mail bleibt ungelesen, wird geloggt
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../helpers/config.php';
require_once __DIR__ . '/../db/abuse_db.php';
require_once __DIR__ . '/templates.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Webklex\PHPIMAP\ClientManager;

const LOG_FILE = __DIR__ . '/../../logs/imap-poll.log';

function plog(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

// ── Single-Instance-Lock ─────────────────────────────────────────────────────
@mkdir(dirname(LOG_FILE), 0775, true);
$lockFile = __DIR__ . '/../../logs/.imap-poll.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    plog('Läuft bereits — abgebrochen.');
    exit(0);
}

$cfg = appConfig();
if (empty($cfg['imap_host'])) {
    plog('IMAP nicht konfiguriert (app/config/config.php) — nichts zu tun.');
    exit(0);
}

// ── Verschlüsselung bestimmen ───────────────────────────────────────────────
//  imap_secure: 'ssl' | 'starttls' | 'none'  (Vorrang)
//  Fallback imap_ssl (bool): true -> je nach Port (993=ssl, sonst starttls), false -> none
$port = (int) ($cfg['imap_port'] ?? 993);
$secure = strtolower(trim((string) ($cfg['imap_secure'] ?? '')));
if ($secure === '') {
    if (!empty($cfg['imap_ssl'])) {
        $secure = $port === 993 ? 'ssl' : 'starttls';
    } else {
        $secure = 'none';
    }
}
$encryption = match ($secure) {
    'ssl', 'tls'         => 'ssl',
    'starttls'           => 'starttls',
    'none', 'false', '0' => false,
    default              => 'starttls',
};

// ── Verbinden ────────────────────────────────────────────────────────────────
try {
    $cm = new ClientManager();
    $client = $cm->make([
        'host'          => $cfg['imap_host'],
        'port'          => $port,
        'encryption'    => $encryption,
        'validate_cert' => true,
        'username'      => $cfg['imap_user'] ?? '',
        'password'      => $cfg['imap_pass'] ?? '',
        'protocol'      => 'imap',
    ]);
    $client->connect();
} catch (Throwable $e) {
    plog('VERBINDUNG FEHLGESCHLAGEN: ' . $e->getMessage());
    exit(1);
}

$inboxName     = $cfg['imap_folder'] ?? 'INBOX';
$processedName = trim((string) ($cfg['imap_processed_folder'] ?? ''));
$prefix        = abuseRefPrefix();
$refRegex      = $prefix !== ''
    ? '/\[(' . preg_quote($prefix, '/') . '-\d{2}-\d+)\]/i'
    : '/\[(\d{2}-\d{3,})\]/';

try {
    $folder   = $client->getFolder($inboxName);
    $messages = $folder->query()->unseen()->leaveUnread()->get();
} catch (Throwable $e) {
    plog('ORDNER-FEHLER: ' . $e->getMessage());
    exit(1);
}

plog("Postfach {$inboxName}: " . $messages->count() . ' ungelesene Nachricht(en).');

$matched = 0; $skipped = 0; $unmatched = 0;

foreach ($messages as $message) {
    try {
        $uid      = (int) $message->getUid();
        $msgId    = trim((string) $message->getMessageId());
        $subject  = trim((string) $message->getSubject());
        $fromObj  = $message->getFrom();
        $fromAddr = strtolower(trim((string) (($fromObj[0]->mail ?? '') ?: '')));

        $inReplyTo  = trim((string) $message->getInReplyTo());
        $references = (string) $message->getReferences();
        // rohe Header als Fallback
        $hdr = $message->getHeader();
        if ($inReplyTo === '')  $inReplyTo  = trim((string) $hdr->get('in-reply-to'));
        if ($references === '') $references = trim((string) $hdr->get('references'));

        if (inboundMessageExists($uid, $msgId)) {
            $message->setFlag('Seen');
            $skipped++;
            continue;
        }

        // ── Zuordnung ───────────────────────────────────────────────────────
        $reportId = null;
        $how = '';

        $threadIds = preg_split('/\s+/', trim($inReplyTo . ' ' . $references), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($threadIds) {
            $reportId = findReportIdByMessageIds($threadIds);
            if ($reportId) $how = 'Threading-Header';
        }

        if (!$reportId && preg_match($refRegex, $subject, $m)) {
            $r = getReportByRef(strtoupper($m[1]));
            if ($r) { $reportId = (int) $r['id']; $how = 'Reportnummer im Betreff'; }
        }

        $weakMatch = false;
        if (!$reportId && $fromAddr !== '') {
            $reportId = singleOpenReportIdForHosterEmail($fromAddr);
            if ($reportId) { $how = 'Absender = Hoster-Abuse-Adresse'; $weakMatch = true; }
        }

        if (!$reportId) {
            $unmatched++;
            plog("  UID {$uid}: keine Zuordnung — \"" . mb_substr($subject, 0, 70) . "\" von {$fromAddr} (bleibt ungelesen)");
            continue;
        }

        // ── Body ────────────────────────────────────────────────────────────
        $body = trim((string) $message->getTextBody());
        if ($body === '') {
            $html = (string) $message->getHTMLBody();
            $body = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (mb_strlen($body) > 60000) $body = mb_substr($body, 0, 60000) . "\n…[gekürzt]";

        addMessage($reportId, 'in', [
            'from_addr'   => $fromAddr,
            'to_addr'     => strtolower((string) cfg('abuse_from_email', '')),
            'subject'     => $subject,
            'body_text'   => $body,
            'message_id'  => $msgId,
            'in_reply_to' => $inReplyTo,
            'imap_uid'    => $uid,
        ]);

        touchReportInbound($reportId);

        $report = getReport($reportId);
        if ($report && !in_array($report['status'], ABUSE_CLOSED_STATUSES, true)) {
            setReportStatus($reportId, 'Antwort erhalten');
        }
        $note = "Antwort von {$fromAddr} eingegangen (Zuordnung: {$how}).";
        if ($weakMatch) $note .= ' ⚠ Bitte Zuordnung prüfen.';
        addLog($reportId, 'System', $note, null);

        $message->setFlag('Seen');
        if ($processedName !== '') {
            try { $message->move($processedName); } catch (Throwable $e) { /* Ordner fehlt -> egal */ }
        }

        $refLabel = $report['ref'] ?? ('#' . $reportId);
        plog("  UID {$uid}: -> {$refLabel} ({$how})");
        $matched++;
    } catch (Throwable $e) {
        plog('  FEHLER bei einer Nachricht: ' . $e->getMessage());
    }
}

plog("Fertig: {$matched} zugeordnet, {$skipped} übersprungen, {$unmatched} ohne Zuordnung.");

try { $client->disconnect(); } catch (Throwable $e) {}
flock($lock, LOCK_UN);
fclose($lock);
