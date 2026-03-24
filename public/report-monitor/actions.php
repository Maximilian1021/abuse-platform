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

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'create_report':
            $uid = currentUser()['id'] ?? null;
            $id  = createReport(
                trim($_POST['ip'] ?? ''),
                trim($_POST['reported_to'] ?? ''),
                trim($_POST['reason'] ?? ''),
                trim($_POST['status'] ?? 'Gemeldet'),
                trim($_POST['note'] ?? ''),
                $uid
            );
            echo json_encode(['ok' => true, 'id' => $id]);
            break;

        case 'update_report':
            $id  = (int)($_POST['id'] ?? 0);
            $uid = currentUser()['id'] ?? null;
            if (!$id) throw new Exception('Keine ID');
            updateReport(
                $id,
                trim($_POST['ip'] ?? ''),
                trim($_POST['reported_to'] ?? ''),
                trim($_POST['reason'] ?? ''),
                trim($_POST['status'] ?? 'Gemeldet'),
                trim($_POST['note'] ?? ''),
                $uid
            );
            echo json_encode(['ok' => true]);
            break;

        case 'delete_report':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Keine ID');
            deleteReport($id);
            echo json_encode(['ok' => true]);
            break;

        case 'add_log':
            $id      = (int)($_POST['id'] ?? 0);
            $type    = trim($_POST['type'] ?? 'Notiz');
            $content = trim($_POST['content'] ?? '');
            $uid     = currentUser()['id'] ?? null;
            if (!$id || !$content) throw new Exception('Fehlende Daten');
            addLog($id, $type, $content, $uid);
            echo json_encode(['ok' => true]);
            break;

        case 'delete_log':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Keine ID');
            deleteLog($id);
            echo json_encode(['ok' => true]);
            break;

        case 'get_report':
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $report = getReport($id);
            if (!$report) throw new Exception('Nicht gefunden');
            $logs = getLogs($id);
            echo json_encode(['ok' => true, 'report' => $report, 'logs' => $logs]);
            break;

        case 'get_all':
            echo json_encode(['ok' => true, 'reports' => getAllReports()]);
            break;

        default:
            throw new Exception('Unbekannte Aktion');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
