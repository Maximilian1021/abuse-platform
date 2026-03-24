<?php
require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/db/abuse_db.php';
requireAuth();

$navActive = 'auth-monitor';
$cfg       = require __DIR__ . '/../../app/config/agent_config.php';

try {
    $pdo = getMySQL();
} catch (Exception $e) {
    die('<div style="color:#f87171;padding:40px;font-family:monospace;background:#080b10;min-height:100vh">DB-Fehler: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

$ip            = $_GET['ip']     ?? '';
$view          = $_GET['view']   ?? 'day';
$date          = $_GET['date']   ?? date('Y-m-d');
$server_param  = trim($_GET['server'] ?? '');

if (!empty($_GET['from']) && !empty($_GET['to'])) {
    $date_from = $_GET['from'];
    $date_to   = $_GET['to'];
} elseif ($view === 'week') {
    $date_from = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    $date_to   = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
} else {
    $date_from = $date_to = $date;
}

$ip_filter   = $ip;
$report_type = !empty($ip) ? 'day' : ($view === 'week' ? 'week' : 'day');

function check_duplicate($pdo, $type, $from, $to, $ip_filter) {
    $stmt = $pdo->prepare("SELECT created_at FROM auth_reports
        WHERE report_type=? AND period_start=? AND period_end=? AND ip_filter=? LIMIT 1");
    $stmt->execute([$type, $from, $to, $ip_filter]);
    return $stmt->fetchColumn();
}
$existing = check_duplicate($pdo, $report_type, $date_from, $date_to, $ip_filter);

$action       = $_POST['action'] ?? '';
$message      = '';
$message_type = '';

$webhook_url   = $cfg['webhook_url'];
$webhook_token = $cfg['webhook_token'];

if (($action === 'generate' || $action === 'force') && !canWrite()) {
    $message      = 'Keine Berechtigung. Viewer dürfen keine Reports auslösen.';
    $message_type = 'error';
    $action       = '';
}

if ($action === 'generate' || $action === 'force') {
    $params = ['type' => $report_type, 'force' => $action === 'force' ? '1' : '0'];
    if (!empty($ip))       { $params['ip'] = $ip; $params['from'] = $date_from; $params['to'] = $date_to; }
    elseif (!empty($date)) { $params['date'] = $date_from; }

    $url = $webhook_url . '/report?' . http_build_query(array_merge($params, ['token' => $webhook_token]));
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 130,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ["X-Token: $webhook_token"],
    ]);
    $response = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curl_err) {
        $message      = "Webhook nicht erreichbar!\nFehler: $curl_err\nLäuft auth-webhook.py auf dem 24fire-Server?";
        $message_type = 'error';
    } else {
        $data         = json_decode($response, true);
        $message      = $data['output'] ?? $response;
        $message_type = ($data['success'] ?? false) ? 'success' : 'error';

        // ── Auto-Abuse-Report anlegen ─────────────────────────────────────────
        if ($message_type === 'success') {
            try {
                $user      = currentUser();
                $period    = $date_from === $date_to ? $date_from : "$date_from – $date_to";
                $sourceRef = ($server_param ? "Server: $server_param / " : '') . "Zeitraum: $period" . (!empty($ip_filter) ? " / IP: $ip_filter" : '');
                $reason    = !empty($ip_filter)
                    ? "SSH Brute Force – Auth Monitor Report (IP: $ip_filter)"
                    : "SSH Brute Force – Auth Monitor Tagesbericht ($period)";

                createReport(
                    $ip_filter,
                    $cfg['email_to'],
                    $reason,
                    'Gemeldet',
                    '',
                    $user['id'] ?? null,
                    'auth-monitor',
                    $sourceRef
                );

                // Merke Report in auth_reports für Duplikat-Erkennung
                $pdo->prepare("INSERT INTO auth_reports (report_type, period_start, period_end, ip_filter) VALUES (?, ?, ?, ?)")
                    ->execute([$report_type, $date_from, $date_to, $ip_filter]);
            } catch (Exception $e) {
                // Nicht kritisch — Haupt-Report wurde bereits gesendet
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<title>Report generieren — Auth Monitor</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:        #080b10;
    --surface:   #0f1420;
    --card:      #141926;
    --border:    #1e2740;
    --border-hi: #2e3f60;
    --text:      #e2e8f0;
    --muted:     #64748b;
    --radius:    10px;
}

body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    font-size: 14px;
    min-height: 100vh;
    line-height: 1.5;
}

body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.015'%3E%3Crect x='0' y='0' width='1' height='1'/%3E%3Crect x='20' y='20' width='1' height='1'/%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none;
    z-index: 0;
}

.blob {
    position: fixed; border-radius: 50%; filter: blur(120px);
    opacity: .15; pointer-events: none; z-index: 0;
}
.blob-blue { width: 400px; height: 400px; background: #1d4ed8; top: -150px; left: -100px; }
.blob-red  { width: 300px; height: 300px; background: #9f1239; bottom: -80px; right: -80px; }


.wrap { position: relative; z-index: 1; max-width: 720px; margin: 0 auto; padding: 40px 24px; }

.page-title {
    font-size: 22px; font-weight: 700; letter-spacing: -.02em; margin-bottom: 6px; color: #f1f5f9;
}
.page-meta { font-size: 13px; color: var(--muted); margin-bottom: 28px; display: flex; gap: 12px; flex-wrap: wrap; }
.page-meta strong { color: #94a3b8; }

.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 16px;
}

.alert {
    border-radius: var(--radius);
    padding: 16px 18px;
    margin-bottom: 16px;
    font-size: 13px;
}
.alert-success {
    background: rgba(34,197,94,.08);
    border: 1px solid rgba(34,197,94,.2);
    color: #86efac;
}
.alert-error {
    background: rgba(239,68,68,.08);
    border: 1px solid rgba(239,68,68,.2);
    color: #fca5a5;
}
.alert-warn {
    background: rgba(249,115,22,.08);
    border: 1px solid rgba(249,115,22,.2);
    color: #fdba74;
}

.alert-label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .06em; margin-bottom: 8px; opacity: .7;
}

pre {
    background: rgba(0,0,0,.3);
    padding: 12px;
    border-radius: 6px;
    font-size: 12px;
    overflow-x: auto;
    white-space: pre-wrap;
    margin-top: 8px;
    font-family: 'SF Mono', 'Fira Code', monospace;
    color: inherit;
    opacity: .9;
}

.desc { font-size: 13px; color: var(--muted); margin-bottom: 18px; line-height: 1.6; }
.desc strong { color: #94a3b8; }
code {
    background: rgba(59,130,246,.08);
    border: 1px solid rgba(59,130,246,.15);
    padding: 1px 6px; border-radius: 4px;
    font-family: 'SF Mono', 'Fira Code', monospace;
    color: #93c5fd; font-size: 12px;
}

.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: 7px; border: none; cursor: pointer;
    font-size: 13px; font-weight: 500; text-decoration: none;
    transition: opacity .15s, transform .1s;
}
.btn:hover { opacity: .88; transform: translateY(-1px); }
.btn-primary { background: #1d4ed8; color: #fff; }
.btn-warn    { background: rgba(249,115,22,.2); color: #fdba74; border: 1px solid rgba(249,115,22,.3); }
.btn-ghost   { background: var(--card); color: var(--muted); border: 1px solid var(--border); }
.btn-ghost:hover { color: var(--text); }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../app/helpers/nav.php'; ?>

<div class="blob blob-blue"></div>
<div class="blob blob-red"></div>

<!-- Sub-Nav Auth Monitor -->
<div style="background:rgba(8,11,16,.7);border-bottom:1px solid #1e2740;padding:0 20px;display:flex;align-items:center;height:38px">
  <div style="display:flex;gap:2px">
    <a href="index.php" style="font-size:12px;padding:4px 10px;border-radius:5px;text-decoration:none;color:#64748b;transition:color .15s" onmouseover="this.style.color='#e2e8f0'" onmouseout="this.style.color='#64748b'">Dashboard</a>
    <a href="servers.php" style="font-size:12px;padding:4px 10px;border-radius:5px;text-decoration:none;color:#64748b;transition:color .15s" onmouseover="this.style.color='#e2e8f0'" onmouseout="this.style.color='#64748b'">Server</a>
  </div>
</div>

<div class="wrap">

  <div class="page-title">Report generieren</div>
  <div class="page-meta">
    <?php if (!empty($ip)): ?>
    <span>IP: <strong><?= htmlspecialchars($ip) ?></strong></span>
    <span>·</span>
    <?php endif; ?>
    <span>Zeitraum: <strong><?= htmlspecialchars($date_from) ?><?= $date_from !== $date_to ? ' – '.htmlspecialchars($date_to) : '' ?></strong></span>
    <span>·</span>
    <span>Typ: <strong><?= $report_type ?></strong></span>
  </div>

  <?php if ($message): ?>
  <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>">
    <div class="alert-label"><?= $message_type === 'success' ? 'Ergebnis' : 'Fehler' ?></div>
    <pre><?= htmlspecialchars($message) ?></pre>
  </div>
  <?php endif; ?>

  <?php if ($existing && !$message): ?>
  <div class="alert alert-warn">
    <div class="alert-label">Duplikat</div>
    Dieser Report wurde bereits erstellt am <strong><?= htmlspecialchars($existing) ?></strong>.
    <?php if (canWrite()): ?>
    <form method="post" style="margin-top:14px">
      <input type="hidden" name="action" value="force">
      <button type="submit" class="btn btn-warn">Trotzdem neu erstellen</button>
    </form>
    <?php endif; ?>
  </div>
  <?php elseif (!$message): ?>
  <div class="card">
    <p class="desc">
      Der Report wird als HTML-E-Mail an <strong><?= htmlspecialchars($cfg['email_to']) ?></strong> gesendet
      und auf dem Report-Server gespeichert.
    </p>
    <?php if (canWrite()): ?>
    <form method="post">
      <input type="hidden" name="action" value="generate">
      <button type="submit" class="btn btn-primary">Report generieren &amp; senden</button>
    </form>
    <?php else: ?>
    <p style="color:var(--muted);font-size:13px">Viewer dürfen keine Reports auslösen.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <a href="index.php" class="btn btn-ghost">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
    Zurück zum Dashboard
  </a>

</div>
</body>
</html>
