<?php
require_once __DIR__ . '/../../app/helpers/auth.php';

// AJAX endpoint — return JSON 401 instead of redirect
if (!currentUser()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet']);
    exit;
}

require_once __DIR__ . '/../../app/db/abuse_db.php';
require_once __DIR__ . '/../../app/db/hoster_db.php';
require_once __DIR__ . '/../../app/mail/templates.php';
require_once __DIR__ . '/../../app/mail/mailer.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid    = currentUser()['id'] ?? null;

// Schreibende Aktionen brauchen Schreibrechte
$writeActions = [
    'create_report', 'update_report', 'delete_report', 'add_log', 'delete_log',
    'send_report', 'reply', 'mark_manually_sent',
];
if (in_array($action, $writeActions, true) && !canWrite()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung (nur Lesezugriff).']);
    exit;
}

$templateList = [];
foreach (abuseTemplates() as $key => $t) {
    $templateList[] = ['key' => $key, 'label' => $t['label']];
}

try {
    switch ($action) {

        case 'get_all':
            echo json_encode(['ok' => true, 'reports' => getAllReports()]);
            break;

        case 'get_report': {
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $report = getReport($id);
            if (!$report) throw new Exception('Nicht gefunden');
            echo json_encode([
                'ok'        => true,
                'report'    => $report,
                'timeline'  => getTimeline($id),
                'hosters'   => getAllHosters(),
                'templates' => $templateList,
                'statuses'  => ABUSE_STATUSES,
            ]);
            break;
        }

        case 'create_report': {
            $res = createReport(
                trim($_POST['ip'] ?? ''),
                (int)($_POST['hoster_id'] ?? 0) ?: null,
                trim($_POST['reason'] ?? ''),
                trim($_POST['note'] ?? ''),
                $uid
            );
            echo json_encode(['ok' => true, 'id' => $res['id'], 'ref' => $res['ref']]);
            break;
        }

        case 'update_report': {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Keine ID');
            updateReport(
                $id,
                trim($_POST['ip'] ?? ''),
                (int)($_POST['hoster_id'] ?? 0) ?: null,
                trim($_POST['reason'] ?? ''),
                trim($_POST['status'] ?? 'Entwurf'),
                trim($_POST['note'] ?? ''),
                $uid
            );
            echo json_encode(['ok' => true]);
            break;
        }

        case 'delete_report': {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Keine ID');
            deleteReport($id);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'add_log': {
            $id      = (int)($_POST['id'] ?? 0);
            $type    = trim($_POST['type'] ?? 'Notiz');
            $content = trim($_POST['content'] ?? '');
            if (!$id || $content === '') throw new Exception('Fehlende Daten');
            addLog($id, $type, $content, $uid);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'delete_log': {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Keine ID');
            deleteLog($id);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'build_draft': {
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            $report = getReport($id);
            if (!$report) throw new Exception('Nicht gefunden');
            $tplKey = trim($_POST['template'] ?? $_GET['template'] ?? 'generic');
            $draft  = buildDraft($report, $tplKey);
            echo json_encode([
                'ok'       => true,
                'subject'  => $draft['subject'],
                'body'     => $draft['body'],
                'to'       => $report['hoster_email'] ?: '',
                'can_send' => (bool)($report['hoster_email'] ?? ''),
                'hoster_url' => $report['hoster_url'] ?? '',
            ]);
            break;
        }

        case 'send_report': {
            $id = (int)($_POST['id'] ?? 0);
            $report = getReport($id);
            if (!$report) throw new Exception('Nicht gefunden');
            $to = trim($report['hoster_email'] ?? '');
            if ($to === '') throw new Exception('Kein Hoster mit Abuse-E-Mail zugeordnet.');

            $subject = trim($_POST['subject'] ?? '') ?: ('Abuse Report ' . $report['ref']);
            $body    = trim($_POST['body'] ?? '');
            if ($body === '') throw new Exception('Leerer Nachrichtentext.');

            $r = sendAbuseMail($to, $subject, $body, $report['ref'] ?? '');
            if (!$r['ok']) {
                echo json_encode(['ok' => false, 'error' => 'Versand fehlgeschlagen: ' . $r['error']]);
                break;
            }

            addMessage($id, 'out', [
                'from_addr'  => (string) cfg('abuse_from_email', ''),
                'to_addr'    => $to,
                'subject'    => $r['subject'],
                'body_text'  => $body,
                'message_id' => $r['message_id'],
                'sent_by'    => $uid,
            ]);
            markReportSent($id, $r['subject'], $uid);
            addLog($id, 'System', "Report an {$to} gesendet.", $uid);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'reply': {
            $id = (int)($_POST['id'] ?? 0);
            $report = getReport($id);
            if (!$report) throw new Exception('Nicht gefunden');
            $to = trim($report['hoster_email'] ?? '');
            if ($to === '') throw new Exception('Kein Hoster mit Abuse-E-Mail zugeordnet.');
            $body = trim($_POST['body'] ?? '');
            if ($body === '') throw new Exception('Leerer Nachrichtentext.');

            $baseSubject = $report['subject'] ?: ('Abuse Report ' . $report['ref']);
            if (!preg_match('/^\s*re:/i', $baseSubject)) $baseSubject = 'Re: ' . $baseSubject;

            $r = sendAbuseMail(
                $to, $baseSubject, $body, $report['ref'] ?? '',
                getLatestInboundMessageId($id),
                getAnyMessageIds($id)
            );
            if (!$r['ok']) {
                echo json_encode(['ok' => false, 'error' => 'Versand fehlgeschlagen: ' . $r['error']]);
                break;
            }

            addMessage($id, 'out', [
                'from_addr'   => (string) cfg('abuse_from_email', ''),
                'to_addr'     => $to,
                'subject'     => $r['subject'],
                'body_text'   => $body,
                'message_id'  => $r['message_id'],
                'in_reply_to' => (string) getLatestInboundMessageId($id),
                'sent_by'     => $uid,
            ]);
            if (!in_array($report['status'], ABUSE_CLOSED_STATUSES, true)) {
                setReportStatus($id, 'Wartet auf Antwort', $uid);
            }
            addLog($id, 'System', "Antwort an {$to} gesendet.", $uid);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'mark_manually_sent': {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Keine ID');
            setReportStatus($id, 'Gesendet', $uid);
            addLog($id, 'System', 'Manuell beim Hoster gemeldet (Webformular / kein E-Mail-Versand).', $uid);
            echo json_encode(['ok' => true]);
            break;
        }

        default:
            throw new Exception('Unbekannte Aktion');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
