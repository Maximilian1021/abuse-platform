<?php
require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/db/auth_db.php';
requireAuth();

$me        = currentUser();
$navActive = 'auth-monitor';

try {
    $pdo = getMySQL();
} catch (Exception $e) {
    die('<div style="color:#f87171;padding:40px;font-family:monospace;background:#080b10;min-height:100vh">DB-Fehler: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

// ── Filter ────────────────────────────────────────────────────────────────────
$selected_date = $_GET['date']   ?? date('Y-m-d');
$view          = $_GET['view']   ?? 'day';
$server_filter = trim($_GET['server'] ?? '');

// Datumsbereich
if ($view === 'week') {
    $date_from = date('Y-m-d', strtotime('monday this week', strtotime($selected_date)));
    $date_to   = date('Y-m-d', strtotime('sunday this week', strtotime($selected_date)));
} elseif ($view === 'all') {
    $date_from = '2000-01-01';
    $date_to   = date('Y-m-d');
} else {
    $date_from = $date_to = $selected_date;
}

$today = date('Y-m-d');

// ── Server-Liste ──────────────────────────────────────────────────────────────
$available_servers = [];
$server_overview   = [];
$no_data           = false;

try {
    $available_servers = $pdo->query(
        "SELECT DISTINCT server FROM daily_stats ORDER BY server"
    )->fetchAll(PDO::FETCH_COLUMN);

    // Alle Server mit je ihren Gesamt-Stats (für Picker-Karten)
    foreach ($available_servers as $srv) {
        $s = $pdo->prepare("SELECT
            COALESCE(SUM(fail_count),0)    as total_fails,
            COALESCE(SUM(success_count),0) as total_success,
            COUNT(DISTINCT ip)             as unique_ips,
            MAX(stat_date)                 as last_day
            FROM daily_stats WHERE server = ?");
        $s->execute([$srv]);
        $row = $s->fetch(PDO::FETCH_ASSOC);

        $l = $pdo->prepare("SELECT
            COALESCE(SUM(event_type IN ('FAILED','INVALID_USER')),0) as live_fails
            FROM auth_events WHERE log_date = CURDATE() AND server = ?");
        $l->execute([$srv]);
        $lrow = $l->fetch(PDO::FETCH_ASSOC);

        $server_overview[$srv] = array_merge($row, ['live_fails' => (int)$lrow['live_fails']]);
    }
} catch (Exception $e) {
    $no_data = true;
}

// Kein Server gewählt → nur Picker anzeigen
$show_picker = ($server_filter === '' && count($available_servers) > 1);

// ── Defaults (wenn keine Tabellen existieren) ─────────────────────────────────
$stats        = ['total_fails' => 0, 'total_success' => 0, 'unique_ips' => 0];
$live_fails   = $live_success = 0;
$top_ips      = $top_hosters = $daily_data = $recent = $available_dates = [];
$sort         = $_GET['sort']    ?? 'fails';
$country_filter = $_GET['country'] ?? '';

// ── Server-Filter SQL ─────────────────────────────────────────────────────────
$server_where        = '';
$server_event_where  = '';
$server_params       = [];
$server_event_params = [];
if ($server_filter !== '') {
    $server_where          = 'AND ds.server = ?';
    $server_event_where    = 'AND server = ?';
    $server_params[]       = $server_filter;
    $server_event_params[] = $server_filter;
}

if (!$no_data) {
    $valid_sorts = ['fails','successes','country','city','hoster','last_seen'];
    if (!in_array($sort, $valid_sorts)) $sort = 'fails';

    $country_where = '';
    $country_param = [];
    if ($country_filter === 'DE') {
        $country_where = "AND ii.country = 'DE'";
    } elseif ($country_filter === 'non-DE') {
        $country_where = "AND (ii.country != 'DE' OR ii.country IS NULL)";
    }

    // Statistiken (daily_stats — gestern und älter)
    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM(fail_count),0)    as total_fails,
        COALESCE(SUM(success_count),0) as total_success,
        COUNT(DISTINCT ip)             as unique_ips
        FROM daily_stats ds WHERE stat_date BETWEEN ? AND ? $server_where");
    $stmt->execute(array_merge([$date_from, $date_to], $server_params));
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Heutige Live-Daten (auth_events)
    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM(event_type IN ('FAILED','INVALID_USER')),0) as fails,
        COALESCE(SUM(event_type = 'SUCCESS'),0)                  as successes
        FROM auth_events WHERE log_date = CURDATE() $server_event_where");
    $stmt->execute($server_event_params);
    $live         = $stmt->fetch(PDO::FETCH_ASSOC);
    $live_fails   = (int)($live['fails']     ?? 0);
    $live_success = (int)($live['successes'] ?? 0);

    if ($date_to >= $today) {
        $stats['total_fails']   += $live_fails;
        $stats['total_success'] += $live_success;
    }

    // Top IPs (daily_stats)
    $stmt = $pdo->prepare("
        SELECT ds.ip,
               SUM(ds.fail_count)                                        as fails,
               SUM(ds.success_count)                                     as successes,
               COALESCE(ii.country,'?')                                  as country,
               COALESCE(ii.city,'?')                                     as city,
               COALESCE(ii.hoster,'unbekannt')                           as hoster,
               COALESCE(ii.asn,'')                                       as asn,
               MAX(ds.last_seen)                                         as last_seen,
               MAX(ds.usernames_tried)                                   as usernames,
               GROUP_CONCAT(DISTINCT ds.server ORDER BY ds.server SEPARATOR ',') as servers
        FROM daily_stats ds
        LEFT JOIN ip_info ii ON ds.ip = ii.ip
        WHERE ds.stat_date BETWEEN ? AND ? $country_where $server_where
        GROUP BY ds.ip, ii.country, ii.city, ii.hoster, ii.asn
        ORDER BY fails DESC LIMIT 50");
    $stmt->execute(array_merge([$date_from, $date_to], $country_param, $server_params));
    $top_ips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Heutige IPs aus auth_events einmergen
    if ($date_to >= $today) {
        $stmt = $pdo->prepare("
            SELECT ip,
                   SUM(event_type IN ('FAILED','INVALID_USER'))                  as live_fails,
                   SUM(event_type = 'SUCCESS')                                   as live_success,
                   GROUP_CONCAT(DISTINCT server ORDER BY server SEPARATOR ',')   as live_servers
            FROM auth_events WHERE log_date = CURDATE() $server_event_where
            GROUP BY ip ORDER BY live_fails DESC LIMIT 50");
        $stmt->execute($server_event_params);
        $ip_index = array_column($top_ips, null, 'ip');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
            if (isset($ip_index[$lr['ip']])) {
                foreach ($top_ips as &$tr) {
                    if ($tr['ip'] === $lr['ip']) {
                        $tr['fails']     += (int)$lr['live_fails'];
                        $tr['successes'] += (int)$lr['live_success'];
                        // Merge server names
                        $existing = array_filter(explode(',', $tr['servers'] ?? ''));
                        $new      = array_filter(explode(',', $lr['live_servers'] ?? ''));
                        $merged   = array_unique(array_merge($existing, $new));
                        sort($merged);
                        $tr['servers'] = implode(',', $merged);
                        break;
                    }
                }
                unset($tr);
            } else {
                $top_ips[] = ['ip' => $lr['ip'], 'fails' => (int)$lr['live_fails'],
                    'successes' => (int)$lr['live_success'], 'country' => '?', 'city' => '?',
                    'hoster' => 'unbekannt', 'asn' => '', 'last_seen' => date('Y-m-d H:i:s'),
                    'usernames' => '', 'servers' => $lr['live_servers'] ?? ''];
            }
        }
        usort($top_ips, fn($a, $b) => (int)$b['fails'] - (int)$a['fails']);
        $top_ips = array_slice($top_ips, 0, 50);
    }

    // Chart-Daten
    $stmt = $pdo->prepare("SELECT stat_date, SUM(fail_count) as fails, SUM(success_count) as successes
        FROM daily_stats ds WHERE stat_date BETWEEN ? AND ? $server_where GROUP BY stat_date ORDER BY stat_date");
    $stmt->execute(array_merge([$date_from, $date_to], $server_params));
    $daily_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($date_to >= $today && ($live_fails > 0 || $live_success > 0)) {
        $found = false;
        foreach ($daily_data as &$dd) {
            if ($dd['stat_date'] === $today) { $dd['fails'] += $live_fails; $dd['successes'] += $live_success; $found = true; break; }
        }
        unset($dd);
        if (!$found) $daily_data[] = ['stat_date' => $today, 'fails' => $live_fails, 'successes' => $live_success];
        usort($daily_data, fn($a, $b) => strcmp($a['stat_date'], $b['stat_date']));
    }

    // Live Events (letzte Stunde)
    $stmt = $pdo->prepare("SELECT timestamp, ip, username, event_type
        FROM auth_events WHERE timestamp >= NOW() - INTERVAL 1 HOUR $server_event_where
        ORDER BY timestamp DESC LIMIT 30");
    $stmt->execute($server_event_params);
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top Hoster
    $stmt = $pdo->prepare("
        SELECT COALESCE(ii.hoster,'unbekannt') as hoster,
               COUNT(DISTINCT ds.ip) as ips, SUM(ds.fail_count) as fails
        FROM daily_stats ds LEFT JOIN ip_info ii ON ds.ip = ii.ip
        WHERE ds.stat_date BETWEEN ? AND ? $server_where
        GROUP BY hoster ORDER BY fails DESC LIMIT 10");
    $stmt->execute(array_merge([$date_from, $date_to], $server_params));
    $top_hosters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Datums-Picker (respektiert Server-Filter)
    $stmt = $pdo->prepare("SELECT DISTINCT stat_date FROM daily_stats WHERE 1=1 $server_where ORDER BY stat_date DESC LIMIT 90");
    $stmt->execute($server_params);
    $available_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$chart_labels  = json_encode(array_column($daily_data, 'stat_date'));
$chart_fails   = json_encode(array_column($daily_data, 'fails'));
$chart_success = json_encode(array_column($daily_data, 'successes'));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<meta http-equiv="refresh" content="60">
<title>Auth Monitor<?= $server_filter ? ' — '.htmlspecialchars($server_filter) : '' ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
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
    --accent:    #3b82f6;
    --red:       #ef4444;
    --green:     #22c55e;
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
    pointer-events: none; z-index: 0;
}
.blob { position: fixed; border-radius: 50%; filter: blur(120px); opacity: .18; pointer-events: none; z-index: 0; }
.blob-blue { width: 500px; height: 500px; background: #1d4ed8; top: -200px; left: -150px; }
.blob-red  { width: 350px; height: 350px; background: #9f1239; bottom: -100px; right: -100px; }

.wrap { position: relative; z-index: 1; max-width: 1440px; margin: 0 auto; padding: 24px; }

/* ── Server picker ── */
.picker-title { font-size: 20px; font-weight: 700; letter-spacing: -.02em; color: #f1f5f9; margin-bottom: 6px; }
.picker-sub   { font-size: 13px; color: var(--muted); margin-bottom: 28px; }
.server-grid  { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
.server-card  {
    background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
    padding: 22px; text-decoration: none; color: inherit;
    display: flex; flex-direction: column; gap: 12px;
    transition: border-color .2s, transform .15s;
}
.server-card:hover { border-color: rgba(59,130,246,.4); transform: translateY(-2px); }
.server-card-name  { font-size: 15px; font-weight: 600; color: #f1f5f9; }
.server-card-stats { display: flex; gap: 16px; }
.server-card-stat  { display: flex; flex-direction: column; gap: 2px; }
.server-card-num   { font-size: 22px; font-weight: 700; line-height: 1; }
.server-card-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
.server-card-foot  { font-size: 11px; color: var(--muted); padding-top: 8px; border-top: 1px solid var(--border); }

/* ── Server header ── */
.server-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
    background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
    padding: 16px 20px; margin-bottom: 20px;
}
.server-header-left { display: flex; align-items: center; gap: 12px; }
.server-icon {
    width: 38px; height: 38px; border-radius: 9px;
    background: rgba(59,130,246,.12); border: 1px solid rgba(59,130,246,.2);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.server-icon svg { color: #60a5fa; }
.server-name { font-size: 16px; font-weight: 600; color: #f1f5f9; }
.server-change { font-size: 12px; color: var(--muted); text-decoration: none; padding: 5px 10px; border-radius: 5px; border: 1px solid var(--border); background: var(--surface); }
.server-change:hover { color: var(--text); border-color: var(--border-hi); }

/* ── Filter bar ── */
.filter-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
.filter-btn {
    padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none;
    border: 1px solid var(--border); color: var(--muted); background: var(--card);
    transition: color .15s, border-color .15s;
}
.filter-btn:hover { color: var(--text); border-color: var(--border-hi); }
.filter-btn.active { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.4); color: #60a5fa; }
.filter-select {
    background: var(--card); border: 1px solid var(--border); color: var(--text);
    padding: 6px 10px; border-radius: 6px; font-size: 13px; cursor: pointer; outline: none;
}
.filter-select:focus { border-color: var(--border-hi); }
.filter-sep { color: var(--border); font-size: 18px; margin: 0 2px; }

/* ── Stat cards ── */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 20px; }
.stat-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 22px; }
.stat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 8px; }
.stat-num   { font-size: 36px; font-weight: 700; line-height: 1; letter-spacing: -.02em; }
.stat-sub   { font-size: 12px; color: var(--muted); margin-top: 5px; }
.live-pulse {
    display: inline-block; width: 7px; height: 7px;
    background: var(--green); border-radius: 50%; margin-right: 5px;
    animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.7)} }

/* ── Sections ── */
.section { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 18px; overflow: hidden; }
.section-head {
    padding: 13px 18px; border-bottom: 1px solid var(--border);
    font-size: 12px; font-weight: 600; color: var(--muted);
    text-transform: uppercase; letter-spacing: .06em;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;
}

/* ── Tables ── */
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { color: var(--muted); font-weight: 500; text-align: left; padding: 9px 16px; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid var(--border); }
td { padding: 8px 16px; border-bottom: 1px solid rgba(30,39,64,.6); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: rgba(46,63,96,.15); }
code { background: rgba(59,130,246,.08); border: 1px solid rgba(59,130,246,.15); padding: 2px 7px; border-radius: 4px; font-family: 'SF Mono','Fira Code',monospace; color: #93c5fd; font-size: 12px; }

/* ── Badges ── */
.tag { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; border: 1px solid transparent; }
.tag-red    { background: rgba(239,68,68,.1);   color: #fca5a5; border-color: rgba(239,68,68,.2); }
.tag-green  { background: rgba(34,197,94,.1);   color: #86efac; border-color: rgba(34,197,94,.2); }
.tag-blue   { background: rgba(59,130,246,.1);  color: #93c5fd; border-color: rgba(59,130,246,.2); }
.tag-gray   { background: rgba(100,116,139,.1); color: #94a3b8; border-color: rgba(100,116,139,.2); }
.tag-orange { background: rgba(249,115,22,.1);  color: #fdba74; border-color: rgba(249,115,22,.2); }
.country-pill { display: inline-block; background: rgba(59,130,246,.08); border: 1px solid rgba(59,130,246,.15); color: #93c5fd; padding: 1px 6px; border-radius: 4px; font-size: 11px; margin-right: 4px; }

/* ── Chart ── */
.chart-wrap { padding: 16px 18px; height: 210px; }

/* ── Two-col ── */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
@media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

/* ── Buttons ── */
.btn-report { font-size: 12px; padding: 4px 10px; background: rgba(29,78,216,.3); border: 1px solid rgba(59,130,246,.3); color: #93c5fd; border-radius: 5px; text-decoration: none; transition: background .15s; white-space: nowrap; }
.btn-report:hover { background: rgba(29,78,216,.5); }
.head-controls { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.head-select { background: var(--surface); border: 1px solid var(--border); color: var(--text); padding: 4px 8px; border-radius: 5px; font-size: 12px; cursor: pointer; }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../app/helpers/nav.php'; ?>

<div class="blob blob-blue"></div>
<div class="blob blob-red"></div>

<!-- Sub-Nav -->
<div style="background:rgba(8,11,16,.7);border-bottom:1px solid #1e2740;padding:0 20px;display:flex;align-items:center;justify-content:space-between;height:38px">
  <div style="display:flex;gap:2px">
    <a href="index.php" style="font-size:12px;padding:4px 10px;border-radius:5px;text-decoration:none;color:#60a5fa;background:rgba(59,130,246,.1)">Dashboard</a>
    <a href="servers.php" style="font-size:12px;padding:4px 10px;border-radius:5px;text-decoration:none;color:#64748b;transition:color .15s" onmouseover="this.style.color='#e2e8f0'" onmouseout="this.style.color='#64748b'">Server</a>
  </div>
  <span style="font-size:11px;color:#475569"><?= date('H:i:s') ?> · Auto-Refresh 60s</span>
</div>

<div class="wrap">

<?php if ($no_data): ?>
  <div style="text-align:center;padding:80px 24px;color:var(--muted)">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:16px;opacity:.4">
      <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
      <line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
    </svg>
    <div style="font-size:16px;font-weight:600;color:#94a3b8;margin-bottom:8px">Noch keine Daten vorhanden</div>
    <div style="font-size:13px;max-width:400px;margin:0 auto;line-height:1.7">
      Registriere einen Server und starte den Agenten — sobald erste Events ankommen, erscheint hier das Dashboard.
    </div>
    <a href="servers.php" style="display:inline-block;margin-top:20px;padding:8px 18px;background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);color:#60a5fa;border-radius:7px;text-decoration:none;font-size:13px">Server registrieren →</a>
  </div>

<?php elseif ($show_picker): ?>
  <!-- ── Server-Picker ── -->
  <div class="picker-title">Server auswählen</div>
  <div class="picker-sub">Wähle einen Server für die detaillierte Ansicht oder <a href="?server=&view=day" style="color:#60a5fa">zeige alle zusammen</a>.</div>
  <div class="server-grid">
    <?php foreach ($available_servers as $srv):
        $ov = $server_overview[$srv];
        $total = (int)$ov['total_fails'] + (int)$ov['live_fails'];
    ?>
    <a class="server-card" href="?server=<?= urlencode($srv) ?>&view=day">
      <div class="server-card-name"><?= htmlspecialchars($srv) ?></div>
      <div class="server-card-stats">
        <div class="server-card-stat">
          <span class="server-card-num" style="color:#f87171"><?= number_format($total) ?></span>
          <span class="server-card-label">Fehlversuche</span>
        </div>
        <div class="server-card-stat">
          <span class="server-card-num" style="color:#4ade80"><?= number_format($ov['total_success']) ?></span>
          <span class="server-card-label">Erfolge</span>
        </div>
        <div class="server-card-stat">
          <span class="server-card-num" style="color:#60a5fa"><?= number_format($ov['unique_ips']) ?></span>
          <span class="server-card-label">IPs</span>
        </div>
      </div>
      <?php if ($ov['live_fails'] > 0): ?>
      <div class="server-card-foot">
        <span class="live-pulse"></span><?= $ov['live_fails'] ?> Fehlversuche heute
      </div>
      <?php else: ?>
      <div class="server-card-foot">Letzter Eintrag: <?= htmlspecialchars($ov['last_day'] ?? '—') ?></div>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

<?php else: ?>
  <!-- ── Dashboard ── -->

  <?php if ($server_filter !== ''): ?>
  <!-- Server-Header -->
  <div class="server-header">
    <div class="server-header-left">
      <div class="server-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
          <line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
        </svg>
      </div>
      <div>
        <div class="server-name"><?= htmlspecialchars($server_filter) ?></div>
        <div style="font-size:12px;color:var(--muted)">Auth Monitor — Server-Ansicht</div>
      </div>
    </div>
    <a href="index.php" class="server-change">← Server wechseln</a>
  </div>
  <?php endif; ?>

  <!-- Filter-Bar -->
  <div class="filter-bar">
    <?php $sp = urlencode($server_filter); ?>
    <a href="?view=day&date=<?= $today ?>&server=<?= $sp ?>" class="filter-btn <?= $view==='day' ? 'active' : '' ?>">Heute</a>
    <a href="?view=week&date=<?= $today ?>&server=<?= $sp ?>" class="filter-btn <?= $view==='week' ? 'active' : '' ?>">Diese Woche</a>
    <a href="?view=all&server=<?= $sp ?>" class="filter-btn <?= $view==='all' ? 'active' : '' ?>">Gesamt</a>
    <span class="filter-sep">|</span>
    <select class="filter-select" onchange="location='?view=day&date='+this.value+'&server=<?= $sp ?>'">
      <option value="">Datum wählen</option>
      <?php foreach ($available_dates as $d): ?>
      <option value="<?= $d ?>" <?= $d===$selected_date && $view==='day' ? 'selected' : '' ?>><?= $d ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!$server_filter && count($available_servers) > 1): ?>
    <select class="filter-select" onchange="location=updateParam('server',this.value)">
      <option value="">Alle Server</option>
      <?php foreach ($available_servers as $srv): ?>
      <option value="<?= htmlspecialchars($srv) ?>" <?= $srv===$server_filter ? 'selected' : '' ?>><?= htmlspecialchars($srv) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </div>

  <!-- Stat-Karten -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-label">Fehlgeschlagen</div>
      <div class="stat-num" style="color:#f87171"><?= number_format($stats['total_fails']) ?></div>
      <?php if ($live_fails > 0 && $date_to >= $today): ?>
      <div class="stat-sub"><span class="live-pulse"></span><?= number_format($live_fails) ?> heute live</div>
      <?php endif; ?>
    </div>
    <div class="stat-card">
      <div class="stat-label">Erfolgreich</div>
      <div class="stat-num" style="color:#4ade80"><?= number_format($stats['total_success']) ?></div>
      <?php if ($live_success > 0 && $date_to >= $today): ?>
      <div class="stat-sub"><span class="live-pulse"></span><?= number_format($live_success) ?> heute live</div>
      <?php endif; ?>
    </div>
    <div class="stat-card">
      <div class="stat-label">Einzigartige IPs</div>
      <div class="stat-num" style="color:#60a5fa"><?= number_format($stats['unique_ips']) ?></div>
    </div>
    <?php if (count($recent) > 0): ?>
    <div class="stat-card">
      <div class="stat-label">Live (letzte Stunde)</div>
      <div class="stat-num" style="color:#fb923c"><?= count($recent) ?></div>
      <div class="stat-sub">Events</div>
    </div>
    <?php endif; ?>
  </div>

  <div class="two-col">
    <?php if (count($daily_data) > 1): ?>
    <div class="section">
      <div class="section-head">Angriffsverteilung</div>
      <div class="chart-wrap"><canvas id="chart"></canvas></div>
    </div>
    <?php endif; ?>
    <div class="section">
      <div class="section-head">Top Hoster</div>
      <table>
        <thead><tr><th>Hoster</th><th>IPs</th><th>Versuche</th></tr></thead>
        <tbody>
        <?php foreach ($top_hosters as $h): ?>
        <tr>
          <td style="color:var(--text)"><?= htmlspecialchars($h['hoster']) ?></td>
          <td><span class="tag tag-gray"><?= $h['ips'] ?></span></td>
          <td><span class="tag tag-red"><?= number_format($h['fails']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Top IPs -->
  <div class="section">
    <div class="section-head">
      Top IPs &mdash; <?= $date_from === $date_to ? $date_from : "$date_from – $date_to" ?>
      <?php if ($server_filter): ?>
      <span class="tag tag-blue"><?= htmlspecialchars($server_filter) ?></span>
      <?php endif; ?>
      <div class="head-controls">
        <select class="head-select" onchange="location=updateParam('country',this.value)">
          <option value=""      <?= $country_filter===''       ? 'selected':'' ?>>Alle Länder</option>
          <option value="DE"    <?= $country_filter==='DE'     ? 'selected':'' ?>>DE</option>
          <option value="non-DE"<?= $country_filter==='non-DE' ? 'selected':'' ?>>Nicht-DE</option>
        </select>
        <select class="head-select" onchange="location=updateParam('sort',this.value)">
          <option value="fails"     <?= $sort==='fails'     ? 'selected':'' ?>>Versuche</option>
          <option value="country"   <?= $sort==='country'   ? 'selected':'' ?>>Land</option>
          <option value="hoster"    <?= $sort==='hoster'    ? 'selected':'' ?>>Hoster</option>
          <option value="last_seen" <?= $sort==='last_seen' ? 'selected':'' ?>>Zuletzt</option>
        </select>
        <a class="btn-report" href="report.php?view=<?= $view ?>&date=<?= $selected_date ?><?= $server_filter ? '&server='.urlencode($server_filter) : '' ?>">
          Zeitraum reporten
        </a>
      </div>
    </div>
    <?php if (empty($top_ips)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted);font-size:13px">Keine Daten für diesen Zeitraum.</div>
    <?php else: ?>
    <table>
      <thead><tr>
        <th>IP</th><th>Versuche</th><th>Erfolge</th><th>Land / Stadt</th><th>Hoster</th><th>Server</th><th>Zuletzt</th><th>Nutzer</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($top_ips as $row):
          $srv_list  = array_filter(array_map('trim', explode(',', $row['servers'] ?? '')));
          $srv_param = count($srv_list) === 1 ? '&server='.urlencode($srv_list[array_key_first($srv_list)]) : '';
      ?>
      <tr>
        <td><code><?= htmlspecialchars($row['ip']) ?></code></td>
        <td><span class="tag <?= $row['fails'] >= 20 ? 'tag-red' : 'tag-gray' ?>"><?= number_format($row['fails']) ?></span></td>
        <td><?= $row['successes'] > 0
            ? '<span class="tag tag-green">'.$row['successes'].'</span>'
            : '<span style="color:var(--muted)">0</span>' ?></td>
        <td>
          <span class="country-pill"><?= htmlspecialchars($row['country']) ?></span>
          <span style="color:var(--muted);font-size:12px"><?= htmlspecialchars($row['city']) ?></span>
        </td>
        <td style="font-size:12px;color:var(--muted)" title="<?= htmlspecialchars($row['asn']) ?>"><?= htmlspecialchars($row['hoster']) ?></td>
        <td style="white-space:nowrap">
          <?php foreach ($srv_list as $srv): ?>
          <span class="tag tag-blue" style="margin:1px 2px"><?= htmlspecialchars($srv) ?></span>
          <?php endforeach; ?>
        </td>
        <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($row['last_seen']) ?></td>
        <td style="font-size:11px;color:#475569"><?= htmlspecialchars(substr($row['usernames'] ?? '', 0, 40)) ?></td>
        <td>
          <a class="btn-report" href="report.php?ip=<?= urlencode($row['ip']) ?>&from=<?= $date_from ?>&to=<?= $date_to ?><?= $server_filter ? '&server='.urlencode($server_filter) : $srv_param ?>">
            Report
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Live Events -->
  <?php if (count($recent) > 0): ?>
  <div class="section">
    <div class="section-head"><span><span class="live-pulse"></span>Live Events (letzte Stunde)</span></div>
    <table>
      <thead><tr><th>Zeit</th><th>IP</th><th>Nutzer</th><th>Typ</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $e): ?>
      <tr>
        <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($e['timestamp']) ?></td>
        <td><code><?= htmlspecialchars($e['ip']) ?></code></td>
        <td style="font-size:12px"><?= htmlspecialchars($e['username']) ?></td>
        <td><?= $e['event_type'] === 'SUCCESS'
            ? '<span class="tag tag-green">SUCCESS</span>'
            : '<span class="tag tag-red">'.htmlspecialchars($e['event_type']).'</span>' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

<?php endif; // end dashboard ?>

</div>

<script>
<?php if (!$show_picker && count($daily_data) > 1): ?>
new Chart(document.getElementById('chart'), {
  type: 'bar',
  data: {
    labels: <?= $chart_labels ?>,
    datasets: [
      { label: 'Fehlgeschlagen', data: <?= $chart_fails ?>,   backgroundColor: 'rgba(239,68,68,.7)',  borderRadius: 3 },
      { label: 'Erfolgreich',    data: <?= $chart_success ?>, backgroundColor: 'rgba(34,197,94,.6)', borderRadius: 3 }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { labels: { color: '#64748b', font: { size: 11 } } } },
    scales: {
      x: { ticks: { color: '#475569' }, grid: { color: 'rgba(30,39,64,.4)' } },
      y: { ticks: { color: '#475569' }, grid: { color: 'rgba(30,39,64,.8)' } }
    }
  }
});
<?php endif; ?>
function updateParam(key, value) {
  const url = new URL(window.location.href);
  url.searchParams.set(key, value);
  return url.toString();
}
</script>
</body>
</html>
