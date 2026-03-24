<?php
require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/db/auth_db.php';
requireAuth();

$user      = currentUser();
$isAdmin   = $user && $user['role'] === 'admin';
$navActive = 'auth-monitor';

// MySQL-Verbindung für Server-Statistiken
$cfg = require __DIR__ . '/../../app/config/agent_config.php';
$mysql = null;
try {
    $mysql = new PDO(
        "mysql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$cfg['db_name']};charset=utf8mb4",
        $cfg['db_user'], $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
} catch (Exception $e) {
    // Dashboard funktioniert auch ohne MySQL (nur registrierte Server anzeigen)
}

// Registrierungs-Token generieren (nur Admin)
$new_token = null;
$token_error = null;
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        if ($action === 'gen_token') {
            $new_token = createRegistrationToken();
        } elseif ($action === 'toggle' && isset($_POST['id'])) {
            toggleServerActive((int)$_POST['id']);
            header('Location: servers.php');
            exit;
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            deleteServer((int)$_POST['id']);
            header('Location: servers.php');
            exit;
        }
    }
}

// Server aus SQLite laden
$servers = getAllServers();

// Server-Statistiken aus MySQL holen
$server_stats = [];
if ($mysql) {
    try {
        $stmt = $mysql->query("
            SELECT
                server,
                SUM(fail_count)    AS total_fails,
                SUM(success_count) AS total_success,
                COUNT(DISTINCT ip) AS unique_ips,
                MAX(last_seen)     AS last_event
            FROM daily_stats
            GROUP BY server
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $server_stats[$row['server']] = $row;
        }

        // Heutige Live-Daten aus auth_events
        $stmt = $mysql->query("
            SELECT
                server,
                SUM(event_type IN ('FAILED','INVALID_USER')) AS live_fails,
                SUM(event_type = 'SUCCESS')                  AS live_success
            FROM auth_events
            WHERE log_date = CURDATE()
            GROUP BY server
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $s = $row['server'];
            $server_stats[$s]['live_fails']   = $row['live_fails'];
            $server_stats[$s]['live_success']  = $row['live_success'];
        }
    } catch (Exception $e) {
        // ignore
    }
}

// Online-Status: last_contact innerhalb 2 Stunden = online, innerhalb 48h = warn, sonst offline
function serverStatus(?string $last_contact): string {
    if (!$last_contact) return 'never';
    $diff = time() - strtotime($last_contact);
    if ($diff < 7200)   return 'online';   // < 2h
    if ($diff < 172800) return 'warn';     // < 48h
    return 'offline';
}

function statusBadge(string $status): string {
    return match($status) {
        'online'  => '<span class="badge badge-online">Online</span>',
        'warn'    => '<span class="badge badge-warn">Inaktiv</span>',
        'offline' => '<span class="badge badge-offline">Offline</span>',
        default   => '<span class="badge badge-gray">Nie gesehen</span>',
    };
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
<title>Server Übersicht — Auth Monitor</title>
<style>
*, *::before, *::after{box-sizing:border-box;margin:0;padding:0}

:root{
    --bg:#080b10;--surface:#0f1420;--card:#141926;--border:#1e2740;--border-hi:#2e3f60;
    --text:#e2e8f0;--muted:#64748b;--radius:10px;
}

body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px}

body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.015'%3E%3Crect x='0' y='0' width='1' height='1'/%3E%3Crect x='20' y='20' width='1' height='1'/%3E%3C/g%3E%3C/svg%3E");pointer-events:none;z-index:0}

.blob{position:fixed;border-radius:50%;filter:blur(120px);opacity:.15;pointer-events:none;z-index:0}
.blob-blue{width:400px;height:400px;background:#1d4ed8;top:-150px;left:-100px}
.blob-red{width:300px;height:300px;background:#9f1239;bottom:-80px;right:-80px}

.wrap{position:relative;z-index:1;max-width:1400px;margin:0 auto;padding:24px}

.grid-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px}
.card{background:var(--card);border-radius:var(--radius);padding:20px;border:1px solid var(--border)}
.card-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}
.card-num{font-size:32px;font-weight:700;line-height:1}

.red{color:#f87171}.green{color:#4ade80}.blue{color:#60a5fa}.yellow{color:#fbbf24}

.section{background:var(--card);border-radius:var(--radius);border:1px solid var(--border);margin-bottom:24px;overflow:hidden}
.section-head{padding:14px 20px;border-bottom:1px solid var(--border);font-size:13px;font-weight:500;color:#94a3b8;display:flex;align-items:center;justify-content:space-between}

table{width:100%;border-collapse:collapse;font-size:13px}
th{color:var(--muted);font-weight:500;text-align:left;padding:10px 16px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border)}
td{padding:10px 16px;border-bottom:1px solid rgba(30,39,64,.6);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(30,39,64,.5)}

code{background:var(--surface);padding:2px 8px;border-radius:4px;font-family:monospace;color:#93c5fd;font-size:12px}

.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:.03em}
.badge-online{background:rgba(34,197,94,.1);color:#4ade80;border:1px solid rgba(34,197,94,.25)}
.badge-warn{background:rgba(251,191,36,.1);color:#fbbf24;border:1px solid rgba(251,191,36,.25)}
.badge-offline{background:rgba(248,113,113,.1);color:#f87171;border:1px solid rgba(248,113,113,.25)}
.badge-gray{background:rgba(100,116,139,.1);color:var(--muted);border:1px solid var(--border)}

.btn{padding:6px 14px;border-radius:6px;font-size:12px;cursor:pointer;border:none;text-decoration:none;display:inline-block;font-weight:500;transition:opacity .15s}
.btn-primary{background:#1d4ed8;color:#fff}.btn-primary:hover{background:#2563eb}
.btn-danger{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.25)}.btn-danger:hover{background:rgba(239,68,68,.25)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{color:var(--text);border-color:var(--border-hi)}

.token-box{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:16px;margin-top:16px}
.token-box code{font-size:13px;word-break:break-all;display:block;padding:10px;background:var(--bg);border-radius:6px;margin:8px 0;color:#4ade80}
.token-box .cmd{font-size:12px;word-break:break-all;display:block;padding:10px;background:var(--bg);border-radius:6px;margin:8px 0;color:#93c5fd;font-family:monospace}
.token-label{font-size:12px;color:var(--muted);margin-bottom:4px}

.online-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px}
.dot-online{background:#4ade80;box-shadow:0 0 6px #4ade80}
.dot-warn{background:#fbbf24}
.dot-offline{background:#f87171}
.dot-never{background:#475569}

.stat-mini{font-size:11px;color:var(--muted)}
.actions{display:flex;gap:8px;align-items:center}
.no-data{padding:32px;text-align:center;color:#475569;font-size:13px}
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
    <a href="servers.php" style="font-size:12px;padding:4px 10px;border-radius:5px;text-decoration:none;color:#60a5fa;background:rgba(59,130,246,.1)">Server</a>
  </div>
</div>

<div class="wrap">

  <!-- Zusammenfassung -->
  <?php
  $total_servers  = count($servers);
  $online_servers = 0;
  $offline_servers = 0;
  foreach ($servers as $s) {
      $st = serverStatus($s['last_contact']);
      if ($st === 'online') $online_servers++;
      elseif ($st === 'offline' || $st === 'never') $offline_servers++;
  }
  $all_fails = array_sum(array_column($server_stats, 'total_fails'));
  ?>
  <div class="grid-summary">
    <div class="card">
      <div class="card-label">Server gesamt</div>
      <div class="card-num blue"><?= $total_servers ?></div>
    </div>
    <div class="card">
      <div class="card-label">Online</div>
      <div class="card-num green"><?= $online_servers ?></div>
    </div>
    <div class="card">
      <div class="card-label">Offline / Nie</div>
      <div class="card-num red"><?= $offline_servers ?></div>
    </div>
    <div class="card">
      <div class="card-label">Angriffe gesamt</div>
      <div class="card-num red"><?= number_format($all_fails) ?></div>
    </div>
  </div>

  <!-- Server-Tabelle -->
  <div class="section">
    <div class="section-head">
      Registrierte Server
      <?php if ($isAdmin): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="gen_token">
        <button type="submit" class="btn btn-primary">+ Neuer Token</button>
      </form>
      <?php endif; ?>
    </div>

    <?php if (empty($servers)): ?>
      <div class="no-data">Noch keine Server registriert. Erstelle einen Registrierungs-Token und führe den Install-Befehl auf deinem Server aus.</div>
    <?php else: ?>
    <table>
      <thead><tr>
        <th>Status</th>
        <th>Server</th>
        <th>Hostname / IP</th>
        <th>Registriert</th>
        <th>Letzter Kontakt</th>
        <th>Angriffe (gesamt)</th>
        <th>Heute</th>
        <th>Auto-Block</th>
        <?php if ($isAdmin): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($servers as $s):
          $status   = serverStatus($s['last_contact']);
          $dotClass = "dot-{$status}";
          $sname    = $s['name'];
          $smysql   = $server_stats[$sname] ?? [];
          $live_fails = $smysql['live_fails'] ?? 0;
      ?>
      <tr>
        <td>
          <span class="online-dot <?= $dotClass ?>"></span>
          <?= statusBadge($status) ?>
        </td>
        <td>
          <strong><?= htmlspecialchars($sname) ?></strong>
          <?php if (!$s['active']): ?><span class="badge badge-gray" style="margin-left:6px">Deaktiviert</span><?php endif; ?>
        </td>
        <td>
          <div><?= htmlspecialchars($s['hostname'] ?? '—') ?></div>
          <div class="stat-mini"><?= htmlspecialchars($s['ip'] ?? '—') ?></div>
        </td>
        <td class="stat-mini"><?= htmlspecialchars($s['registered_at'] ?? '—') ?></td>
        <td class="stat-mini"><?= $s['last_contact'] ? htmlspecialchars($s['last_contact']) : '<span style="color:#475569">—</span>' ?></td>
        <td>
          <?php if (!empty($smysql)): ?>
            <span class="red" style="font-weight:600"><?= number_format($smysql['total_fails'] ?? 0) ?></span>
            <span class="stat-mini"> von <?= number_format($smysql['unique_ips'] ?? 0) ?> IPs</span>
          <?php else: ?>
            <span style="color:#475569">keine Daten</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($live_fails > 0): ?>
            <span class="red"><?= $live_fails ?> heute</span>
          <?php else: ?>
            <span style="color:#475569">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?= $s['auto_block'] ? '<span class="badge badge-online">Aktiv</span>' : '<span class="badge badge-gray">Inaktiv</span>' ?>
        </td>
        <?php if ($isAdmin): ?>
        <td>
          <div class="actions">
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-ghost"><?= $s['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Server \'<?= htmlspecialchars(addslashes($sname)) ?>\' wirklich löschen?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-danger">Löschen</button>
            </form>
          </div>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Neuer Registrierungs-Token -->
  <?php if ($new_token): ?>
  <div class="section">
    <div class="section-head">Neuer Registrierungs-Token generiert</div>
    <div class="token-box" style="margin:16px">
      <div class="token-label">Token (einmalig, 1 Stunde gültig):</div>
      <code><?= htmlspecialchars($new_token) ?></code>
      <div class="token-label" style="margin-top:12px">Install-Befehl für den neuen Server:</div>
      <span class="cmd">curl -s https://abuse.max1021.de/api/install.sh | REG_TOKEN=<?= htmlspecialchars($new_token) ?> bash</span>
      <div style="font-size:12px;color:#64748b;margin-top:8px">Als root auf dem Ziel-Server ausführen. Der Token ist nach der Verwendung verbraucht.</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Install-Anleitung -->
  <?php if ($isAdmin && !$new_token): ?>
  <div class="section">
    <div class="section-head">Neuen Server hinzufügen</div>
    <div style="padding:16px 20px;font-size:13px;color:#94a3b8">
      <p style="margin-bottom:12px">Klicke auf <strong>+ Neuer Token</strong>, um einen einmaligen Registrierungs-Token zu generieren. Führe dann folgenden Befehl als root auf dem Ziel-Server aus:</p>
      <span class="cmd" style="display:block;background:#0f172a;padding:10px;border-radius:6px;font-family:monospace;font-size:12px;color:#93c5fd">curl -s https://abuse.max1021.de/api/install.sh | REG_TOKEN=&lt;TOKEN&gt; bash</span>
    </div>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
