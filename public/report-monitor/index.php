<?php
require_once __DIR__ . '/../../app/helpers/auth.php';
requireAuth();

$navActive = 'report-monitor';
require_once __DIR__ . '/../../app/db/abuse_db.php';
require_once __DIR__ . '/../../app/mail/templates.php';

$reports  = getAllReports();
$hosters  = getAllHosters();
$statuses = ABUSE_STATUSES;
$canWrite = canWrite();

$templates = [];
foreach (abuseTemplates() as $k => $t) $templates[] = ['key' => $k, 'label' => $t['label']];

$statusColors = [
    'Entwurf'            => ['bg' => 'rgba(100,116,139,.12)', 'border' => 'rgba(100,116,139,.25)', 'text' => '#94a3b8'],
    'Gesendet'           => ['bg' => 'rgba(59,130,246,.1)',   'border' => 'rgba(59,130,246,.25)', 'text' => '#93c5fd'],
    'Wartet auf Antwort' => ['bg' => 'rgba(234,179,8,.1)',    'border' => 'rgba(234,179,8,.25)',  'text' => '#fde047'],
    'Antwort erhalten'   => ['bg' => 'rgba(139,92,246,.1)',   'border' => 'rgba(139,92,246,.25)', 'text' => '#c4b5fd'],
    'Erfolgreich'        => ['bg' => 'rgba(34,197,94,.1)',    'border' => 'rgba(34,197,94,.25)',  'text' => '#86efac'],
    'Ignoriert'          => ['bg' => 'rgba(100,116,139,.1)',  'border' => 'rgba(100,116,139,.2)', 'text' => '#94a3b8'],
    'Abgeschlossen'      => ['bg' => 'rgba(30,39,64,.5)',     'border' => 'rgba(46,63,96,.5)',    'text' => '#64748b'],
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<title>Report Monitor — Abuse Platform</title>
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
    --subtle:    #334155;
    --accent:    #ef4444;
    --radius:    8px;
    --font:      -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
}

html, body { height: 100%; }
body { background: var(--bg); color: var(--text); font-family: var(--font); font-size: 14px; min-height: 100vh; overflow: hidden; }
a { color: inherit; text-decoration: none; }

body::before {
    content: ''; position: fixed; inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.012'%3E%3Crect x='0' y='0' width='1' height='1'/%3E%3Crect x='20' y='20' width='1' height='1'/%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none; z-index: 0;
}

.app { display: flex; flex-direction: column; height: 100vh; position: relative; z-index: 1; }
.body { display: flex; flex: 1; overflow: hidden; }

.sidebar { width: 340px; min-width: 280px; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; overflow: hidden; flex-shrink: 0; }
.sidebar-head { padding: 12px 14px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 8px; }
.sidebar-title { font-size: 13px; font-weight: 600; color: var(--text); flex: 1; }
.sidebar-count { font-size: 11px; color: var(--muted); background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1px 7px; }

.search-wrap { padding: 10px 14px; border-bottom: 1px solid var(--border); }
.search-input { width: 100%; background: var(--card); border: 1px solid var(--border); border-radius: 6px; padding: 7px 10px; color: var(--text); font-size: 13px; font-family: var(--font); outline: none; transition: border-color .15s; }
.search-input:focus { border-color: var(--border-hi); }
.search-input::placeholder { color: var(--muted); }

.report-list { overflow-y: auto; flex: 1; }
.report-item { padding: 11px 14px; border-bottom: 1px solid rgba(30,39,64,.5); cursor: pointer; transition: background .1s; display: flex; flex-direction: column; gap: 5px; }
.report-item:hover { background: rgba(20,25,38,.8); }
.report-item.active { background: var(--card); border-left: 2px solid var(--accent); padding-left: 12px; }
.report-ref { font-size: 11px; font-weight: 600; letter-spacing: .04em; color: #93c5fd; font-family: 'SF Mono','Fira Code',monospace; }
.report-ip { font-size: 14px; font-weight: 600; font-family: 'SF Mono', 'Fira Code', monospace; color: #f1f5f9; }
.report-meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.report-sub { font-size: 12px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.report-date { font-size: 11px; color: var(--subtle); margin-left: auto; white-space: nowrap; }
.report-logcount { font-size: 11px; color: var(--subtle); }
.no-reports { padding: 32px 14px; text-align: center; color: var(--muted); font-size: 13px; line-height: 1.7; }

.sbadge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; white-space: nowrap; border: 1px solid transparent; }

.detail { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: var(--bg); }
.empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--muted); gap: 10px; }
.empty-icon { width: 48px; height: 48px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
.empty-icon svg { width: 22px; height: 22px; stroke: var(--muted); }
.empty-label { font-size: 13px; }

.detail-head { padding: 14px 18px; border-bottom: 1px solid var(--border); background: var(--surface); display: flex; align-items: center; gap: 10px; flex-wrap: wrap; flex-shrink: 0; }
.detail-ref { font-size: 12px; font-weight: 700; letter-spacing: .05em; color: #93c5fd; font-family: 'SF Mono','Fira Code',monospace; background: rgba(59,130,246,.08); border: 1px solid rgba(59,130,246,.2); border-radius: 6px; padding: 3px 8px; }
.detail-ip { font-size: 18px; font-weight: 700; font-family: 'SF Mono', 'Fira Code', monospace; color: #f1f5f9; }
.detail-actions { margin-left: auto; display: flex; gap: 7px; }

.detail-body { flex: 1; overflow-y: auto; display: flex; flex-direction: column; }
.dsection { padding: 16px 18px; border-bottom: 1px solid var(--border); }
.dsection-title { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 12px; }

.info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
.info-field label { display: block; font-size: 11px; color: var(--muted); margin-bottom: 3px; }
.info-field .val { font-size: 13px; font-weight: 500; color: var(--text); word-break: break-word; }
.info-field .val.mono { font-family: 'SF Mono', 'Fira Code', monospace; color: #93c5fd; }
.info-note { margin-top: 14px; }
.info-note .note-body { font-size: 13px; white-space: pre-wrap; line-height: 1.6; color: var(--muted); margin-top: 4px; }

/* ── Draft / send ── */
.draft-box { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px; }
.draft-row { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; flex-wrap: wrap; }
.draft-box input, .draft-box textarea, .draft-box select {
    width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;
    padding: 8px 10px; color: var(--text); font-size: 13px; font-family: var(--font); outline: none;
}
.draft-box textarea { resize: vertical; min-height: 220px; font-family: 'SF Mono','Fira Code',monospace; line-height: 1.5; }
.draft-box input:focus, .draft-box textarea:focus, .draft-box select:focus { border-color: var(--border-hi); }
.draft-hint { font-size: 12px; color: var(--muted); margin-bottom: 10px; line-height: 1.5; }

/* ── Timeline ── */
.log-section { flex: 1; padding: 16px 18px; }
.log-list { display: flex; flex-direction: column; gap: 8px; }
.log-entry { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 13px; position: relative; }
.log-entry:hover { border-color: var(--border-hi); }
.log-entry.msg-out { border-left: 3px solid #3b82f6; }
.log-entry.msg-in  { border-left: 3px solid #22c55e; }
.log-entry-head { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.log-type-icon { width: 22px; height: 22px; border-radius: 5px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.log-type-note { background: rgba(100,116,139,.15); }
.log-type-out  { background: rgba(59,130,246,.14); }
.log-type-in   { background: rgba(34,197,94,.12); }
.log-type-sys  { background: rgba(139,92,246,.12); }
.log-type-icon svg { width: 12px; height: 12px; }
.log-type-note svg { stroke: var(--muted); }
.log-type-out  svg { stroke: #60a5fa; }
.log-type-in   svg { stroke: #4ade80; }
.log-type-sys  svg { stroke: #a78bfa; }
.log-entry-type { font-size: 12px; font-weight: 600; color: var(--text); }
.log-entry-sub  { font-size: 11px; color: var(--muted); }
.log-entry-date { font-size: 11px; color: var(--muted); margin-left: auto; }
.log-entry-content { font-size: 13px; color: var(--text); white-space: pre-wrap; word-break: break-word; line-height: 1.55; }
.log-entry.is-sys .log-entry-content { color: var(--muted); }
.msg-body { max-height: 220px; overflow: hidden; position: relative; }
.msg-body.expanded { max-height: none; }
.msg-toggle { font-size: 11px; color: #60a5fa; cursor: pointer; margin-top: 6px; display: inline-block; }
.log-del { position: absolute; top: 8px; right: 8px; background: none; border: none; color: var(--subtle); cursor: pointer; padding: 3px 6px; border-radius: 4px; font-size: 13px; line-height: 1; opacity: 0; transition: opacity .15s; }
.log-entry:hover .log-del { opacity: 1; }
.log-del:hover { background: rgba(239,68,68,.15); color: #f87171; }

.log-add, .reply-box { margin-top: 14px; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 13px; display: flex; flex-direction: column; gap: 8px; }
.log-add-head { display: flex; gap: 8px; align-items: center; }
.reply-box textarea, .log-add textarea {
    background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px;
    color: var(--text); font-size: 13px; font-family: var(--font); outline: none; resize: vertical; width: 100%;
}
.reply-box textarea:focus, .log-add textarea:focus { border-color: var(--border-hi); }

.overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); z-index: 100; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
.overlay.open { display: flex; }
.modal { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 24px; width: 480px; max-width: 95vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 60px rgba(0,0,0,.6); }
.modal-title { font-size: 16px; font-weight: 600; margin-bottom: 18px; color: #f1f5f9; }

.fg { margin-bottom: 13px; }
.fg label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 5px; font-weight: 500; }
.fg input, .fg select, .fg textarea { width: 100%; background: var(--card); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px; color: var(--text); font-size: 13px; font-family: var(--font); outline: none; transition: border-color .15s; }
.fg input:focus, .fg select:focus, .fg textarea:focus { border-color: var(--border-hi); }
.fg textarea { resize: vertical; min-height: 80px; }
.form-row { display: flex; gap: 10px; }
.form-row .fg { flex: 1; }
.modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 18px; }
.edit-mode input, .edit-mode select, .edit-mode textarea { background: var(--card); border: 1px solid var(--border); border-radius: 6px; padding: 7px 10px; color: var(--text); font-size: 13px; font-family: var(--font); outline: none; width: 100%; transition: border-color .15s; }
.edit-mode input:focus, .edit-mode select:focus, .edit-mode textarea:focus { border-color: var(--border-hi); }
select option { background: var(--card); }

.btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-family: var(--font); font-weight: 500; transition: opacity .15s; text-decoration: none; }
.btn:hover { opacity: .85; }
.btn:disabled { opacity: .4; cursor: not-allowed; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-blue    { background: rgba(59,130,246,.2); color: #93c5fd; border: 1px solid rgba(59,130,246,.25); }
.btn-blue:hover { background: rgba(59,130,246,.3); opacity: 1; }
.btn-green   { background: rgba(34,197,94,.2); color: #86efac; border: 1px solid rgba(34,197,94,.25); }
.btn-green:hover { background: rgba(34,197,94,.3); opacity: 1; }
.btn-danger  { background: rgba(239,68,68,.15); color: #fca5a5; border: 1px solid rgba(239,68,68,.2); }
.btn-danger:hover { background: rgba(239,68,68,.25); opacity: 1; }
.btn-ghost   { background: transparent; color: var(--muted); border: 1px solid var(--border); }
.btn-ghost:hover { color: var(--text); border-color: var(--border-hi); opacity: 1; }
.btn-sm { padding: 4px 10px; font-size: 12px; }

.toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: var(--card); border: 1px solid var(--border-hi); border-radius: 8px; padding: 10px 16px; font-size: 13px; z-index: 300; box-shadow: 0 10px 30px rgba(0,0,0,.5); display: none; }
.toast.err { border-color: rgba(239,68,68,.4); color: #fca5a5; }
.toast.ok  { border-color: rgba(34,197,94,.4); color: #86efac; }

::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--border-hi); }

@media (max-width: 800px) {
    .body { flex-direction: column; }
    .sidebar { width: 100%; height: 240px; min-width: unset; }
}
</style>
</head>
<body>

<div class="app">

  <?php require_once __DIR__ . '/../../app/helpers/nav.php'; ?>

  <div class="body">

    <!-- Sidebar -->
    <div class="sidebar">
      <div class="sidebar-head">
        <span class="sidebar-title">Abuse Reports</span>
        <span class="sidebar-count"><?= count($reports) ?></span>
        <?php if ($canWrite): ?><button class="btn btn-primary btn-sm" onclick="openNewModal()">+ Neu</button><?php endif; ?>
      </div>
      <div class="search-wrap">
        <input type="text" class="search-input" id="searchInput" placeholder="Nr., IP, Hoster oder Grund suchen…" oninput="filterList()">
      </div>
      <div class="report-list" id="reportList">
        <?php if (empty($reports)): ?>
        <div class="no-reports">Noch keine Reports.<?php if ($canWrite): ?><br>Klicke auf <strong>+ Neu</strong> um anzufangen.<?php endif; ?></div>
        <?php else: foreach ($reports as $r):
            $sc = $statusColors[$r['status']] ?? $statusColors['Ignoriert'];
        ?>
        <div class="report-item" id="item-<?= $r['id'] ?>" onclick="loadReport(<?= $r['id'] ?>)">
          <div class="report-ref"><?= h($r['ref'] ?: ('#' . $r['id'])) ?></div>
          <div class="report-ip"><?= h($r['ip'] ?: '—') ?></div>
          <div class="report-meta">
            <span class="sbadge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;border-color:<?= $sc['border'] ?>"><?= h($r['status']) ?></span>
            <?php if ($r['hoster_name']): ?><span class="report-sub"><?= h($r['hoster_name']) ?></span><?php endif; ?>
            <?php $cnt = (int)$r['log_count'] + (int)$r['msg_count']; if ($cnt > 0): ?><span class="report-logcount"><?= $cnt ?> Einträge</span><?php endif; ?>
          </div>
          <?php if ($r['reason']): ?><div class="report-sub"><?= h($r['reason']) ?></div><?php endif; ?>
          <div class="report-date"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Detail -->
    <div class="detail" id="detailPane">
      <div class="empty-state" id="emptyState">
        <div class="empty-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        </div>
        <div class="empty-label">Report auswählen<?= $canWrite ? ' oder neuen anlegen' : '' ?></div>
      </div>
      <div id="detailContent" style="display:none;flex:1;overflow-y:auto;flex-direction:column"></div>
    </div>

  </div>
</div>

<?php if ($canWrite): ?>
<!-- New Report Modal -->
<div class="overlay" id="newModal">
  <div class="modal">
    <div class="modal-title">Neuer Abuse Report</div>
    <div class="form-row">
      <div class="fg"><label>IP-Adresse *</label><input type="text" id="newIp" placeholder="1.2.3.4" autocomplete="off"></div>
      <div class="fg">
        <label>Hoster</label>
        <select id="newHoster">
          <option value="">— kein Hoster —</option>
          <?php foreach ($hosters as $ho): ?>
          <option value="<?= (int)$ho['id'] ?>"><?= h($ho['name']) ?><?= $ho['abuse_email'] ? '' : ' (keine E-Mail)' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="fg"><label>Grund</label><input type="text" id="newReason" placeholder="SSH Brute Force, Spam, Portscan…" autocomplete="off"></div>
    <div class="fg"><label>Belege / Log-Auszug (kommt später in die Mail)</label><textarea id="newNote" placeholder="Logzeilen, Zeitstempel (UTC), betroffene Dienste…"></textarea></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeNewModal()">Abbrechen</button>
      <button class="btn btn-primary" onclick="createReport()">Anlegen</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="toast" id="toast"></div>

<script>
const CAN_WRITE     = <?= $canWrite ? 'true' : 'false' ?>;
const STATUS_COLORS = <?= json_encode($statusColors) ?>;
const STATUSES      = <?= json_encode($statuses) ?>;
const HOSTERS       = <?= json_encode(array_map(fn($h) => ['id'=>(int)$h['id'],'name'=>$h['name'],'email'=>$h['abuse_email'],'url'=>$h['abuse_url']], $hosters)) ?>;
const TEMPLATES     = <?= json_encode($templates) ?>;
const LOG_TYPES     = ['Notiz', 'Ausgehend', 'Eingehend', 'System'];

const ICONS = {
    note: `<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`,
    out:  `<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>`,
    in:   `<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>`,
    sys:  `<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M19.07 19.07l-1.41-1.41M4.93 19.07l1.41-1.41M12 2v2M12 20v2M2 12h2M20 12h2"/></svg>`,
};

let currentId = null, current = null;

function toast(msg, kind) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast ' + (kind || '');
    t.style.display = 'block';
    clearTimeout(toast._t); toast._t = setTimeout(() => t.style.display = 'none', 4200);
}
function fmt(dt) {
    if (!dt) return '—';
    const d = new Date(dt.replace(' ', 'T') + 'Z');
    return d.toLocaleDateString('de-DE') + ' ' + d.toLocaleTimeString('de-DE', {hour:'2-digit', minute:'2-digit'});
}
function esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function badgeHtml(status) {
    const c = STATUS_COLORS[status] || STATUS_COLORS['Ignoriert'];
    return `<span class="sbadge" style="background:${c.bg};color:${c.text};border-color:${c.border}">${esc(status)}</span>`;
}
async function post(data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v ?? '');
    const r = await fetch('actions.php', {method: 'POST', body: fd});
    let j; try { j = await r.json(); } catch { j = {ok: false, error: 'Ungültige Antwort'}; }
    if (!j.ok && j.error) toast(j.error, 'err');
    return j;
}

function filterList() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.report-item').forEach(el => {
        el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

/* ── New report modal ── */
function openNewModal() {
    ['newIp','newReason','newNote'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('newHoster').value = '';
    document.getElementById('newModal').classList.add('open');
    setTimeout(() => document.getElementById('newIp').focus(), 50);
}
function closeNewModal() { document.getElementById('newModal')?.classList.remove('open'); }
document.getElementById('newModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeNewModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNewModal(); });

async function createReport() {
    const ip = document.getElementById('newIp').value.trim();
    if (!ip) { document.getElementById('newIp').focus(); return; }
    const res = await post({
        action: 'create_report', ip,
        hoster_id: document.getElementById('newHoster').value,
        reason:    document.getElementById('newReason').value.trim(),
        note:      document.getElementById('newNote').value.trim(),
    });
    if (res.ok) { closeNewModal(); location.reload(); }
}

/* ── Load + render report ── */
async function loadReport(id) {
    currentId = id;
    document.querySelectorAll('.report-item').forEach(el => el.classList.remove('active'));
    document.getElementById('item-' + id)?.classList.add('active');
    const res = await post({action: 'get_report', id});
    if (!res.ok) return;
    current = res;
    renderDetail();
}

function hosterOptions(sel) {
    return `<option value="">— kein Hoster —</option>` + HOSTERS.map(h =>
        `<option value="${h.id}" ${String(h.id)===String(sel)?'selected':''}>${esc(h.name)}${h.email?'':' (keine E-Mail)'}</option>`
    ).join('');
}

function renderDetail() {
    const r = current.report;
    document.getElementById('emptyState').style.display = 'none';
    const pane = document.getElementById('detailContent');
    pane.style.cssText = 'display:flex;flex:1;overflow-y:auto;flex-direction:column';

    const hasEmail = !!(r.hoster_email && r.hoster_email.trim());
    const sent     = current.timeline.some(t => t.kind === 'message' && t.direction === 'out');
    const hasInbound = current.timeline.some(t => t.kind === 'message' && t.direction === 'in');

    pane.innerHTML = `
    <div class="detail-head">
        <span class="detail-ref">${esc(r.ref || ('#' + r.id))}</span>
        <div class="detail-ip">${esc(r.ip || '—')}</div>
        ${badgeHtml(r.status)}
        ${CAN_WRITE ? `<div class="detail-actions">
            <button class="btn btn-blue btn-sm" onclick="toggleEdit()">Bearbeiten</button>
            <button class="btn btn-danger btn-sm" onclick="deleteReport()">Löschen</button>
        </div>` : ''}
    </div>

    <div class="detail-body">
        <div class="dsection" id="viewSection">
            <div class="dsection-title">Details</div>
            <div class="info-grid">
                <div class="info-field"><label>IP-Adresse</label><div class="val mono">${esc(r.ip || '—')}</div></div>
                <div class="info-field"><label>Hoster</label><div class="val">${esc(r.hoster_name || '—')}</div></div>
                <div class="info-field"><label>Abuse-E-Mail</label><div class="val mono">${esc(r.hoster_email || '—')}</div></div>
                <div class="info-field"><label>Grund</label><div class="val">${esc(r.reason || '—')}</div></div>
                <div class="info-field"><label>Betreff</label><div class="val">${esc(r.subject || '—')}</div></div>
                <div class="info-field"><label>Angelegt</label><div class="val">${fmt(r.created_at)}${r.created_by_name ? ' · ' + esc(r.created_by_name) : ''}</div></div>
                <div class="info-field"><label>Gesendet</label><div class="val">${fmt(r.sent_at)}</div></div>
                <div class="info-field"><label>Letzte Antwort</label><div class="val">${fmt(r.last_inbound_at)}</div></div>
            </div>
            ${r.note ? `<div class="info-note"><div class="dsection-title" style="margin-bottom:4px">Belege / Notiz</div><div class="note-body">${esc(r.note)}</div></div>` : ''}
        </div>

        ${CAN_WRITE ? `<div class="dsection edit-mode" id="editSection" style="display:none">
            <div class="dsection-title">Bearbeiten</div>
            <div class="form-row">
                <div class="fg"><label>IP-Adresse</label><input id="eIp" value="${esc(r.ip || '')}"></div>
                <div class="fg"><label>Hoster</label><select id="eHoster">${hosterOptions(r.hoster_id)}</select></div>
            </div>
            <div class="fg"><label>Grund</label><input id="eReason" value="${esc(r.reason || '')}"></div>
            <div class="fg"><label>Status</label><select id="eStatus">${STATUSES.map(s => `<option ${s===r.status?'selected':''}>${esc(s)}</option>`).join('')}</select></div>
            <div class="fg"><label>Belege / Notiz</label><textarea id="eNote">${esc(r.note || '')}</textarea></div>
            <div style="display:flex;gap:8px">
                <button class="btn btn-green btn-sm" onclick="saveEdit()">Speichern</button>
                <button class="btn btn-ghost btn-sm" onclick="toggleEdit()">Abbrechen</button>
            </div>
        </div>` : ''}

        ${CAN_WRITE ? renderComposer(r, hasEmail, sent) : ''}

        <div class="log-section">
            <div class="dsection-title">Verlauf &amp; E-Mail-Konversation</div>
            <div class="log-list" id="logList">${renderTimeline()}</div>
            ${CAN_WRITE ? renderReplyBox(r, hasEmail, hasInbound) : ''}
            ${CAN_WRITE ? `<div class="log-add">
                <div class="log-add-head">
                    <select id="logType" class="btn btn-ghost btn-sm" style="cursor:pointer">${LOG_TYPES.map(t => `<option value="${esc(t)}">${esc(t)}</option>`).join('')}</select>
                    <span style="color:var(--muted);font-size:12px">Interne Notiz / Verlaufseintrag</span>
                </div>
                <textarea id="logContent" placeholder="Nur intern — wird nicht versendet…" rows="3"></textarea>
                <div><button class="btn btn-primary btn-sm" onclick="addLog()">Eintrag speichern</button></div>
            </div>` : ''}
        </div>
    </div>`;
}

function renderComposer(r, hasEmail, sent) {
    if (sent) return ''; // nach dem ersten Versand läuft alles über "Antworten"
    if (!hasEmail) {
        const url = r.hoster_url ? `<a class="msg-toggle" href="${esc(r.hoster_url)}" target="_blank" rel="noopener">Webformular des Hosters öffnen ↗</a>` : '<span style="color:var(--muted)">Kein Hoster mit Abuse-Kontakt zugeordnet.</span>';
        return `<div class="dsection"><div class="dsection-title">Report versenden</div>
            <div class="draft-hint">Dieser Hoster hat keine Abuse-E-Mail hinterlegt — bitte über das Webformular melden und danach als erledigt markieren.</div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">${url}
            <button class="btn btn-green btn-sm" onclick="markManual()">Als manuell gemeldet markieren</button></div></div>`;
    }
    return `<div class="dsection"><div class="dsection-title">Report versenden</div>
        <div class="draft-box">
            <div class="draft-hint">Entwurf aus Vorlage erzeugen, prüfen/anpassen, dann an <strong>${esc(r.hoster_email)}</strong> senden. Die Reportnummer <strong>${esc(r.ref)}</strong> steht automatisch im Betreff.</div>
            <div class="draft-row">
                <select id="tplSel" style="max-width:220px">${TEMPLATES.map(t => `<option value="${esc(t.key)}">${esc(t.label)}</option>`).join('')}</select>
                <button class="btn btn-ghost btn-sm" onclick="buildDraft()">Entwurf erzeugen</button>
            </div>
            <div class="fg" style="margin-bottom:8px"><label style="font-size:11px;color:var(--muted)">Betreff</label><input id="draftSubject" placeholder="wird aus Vorlage befüllt…"></div>
            <div class="fg" style="margin-bottom:8px"><label style="font-size:11px;color:var(--muted)">Nachricht</label><textarea id="draftBody" placeholder="„Entwurf erzeugen“ klicken…"></textarea></div>
            <button class="btn btn-primary btn-sm" id="sendBtn" onclick="sendReport()" disabled>Report senden</button>
        </div></div>`;
}

function renderReplyBox(r, hasEmail, hasInbound) {
    if (!hasEmail) return '';
    if (!hasInbound && !current.timeline.some(t => t.kind === 'message')) return '';
    return `<div class="reply-box">
        <div style="font-size:12px;color:var(--muted)">Antwort an <strong>${esc(r.hoster_email)}</strong> (Betreff + Reportnummer werden übernommen)</div>
        <textarea id="replyBody" rows="4" placeholder="Antwort an den Hoster…"></textarea>
        <div><button class="btn btn-blue btn-sm" onclick="sendReply()">Antworten</button></div>
    </div>`;
}

function renderTimeline() {
    if (!current.timeline.length) return '<div style="color:var(--muted);font-size:13px;padding:4px 0">Noch keine Einträge.</div>';
    return current.timeline.map(t => {
        if (t.kind === 'message') {
            const inbound = t.direction === 'in';
            const cls = inbound ? 'in' : 'out';
            const who = inbound ? ('Von ' + esc(t.from_addr)) : ('An ' + esc(t.to_addr) + (t.sent_by_name ? ' · ' + esc(t.sent_by_name) : ''));
            const bodyId = 'mb-' + t.id;
            return `<div class="log-entry msg-${cls}">
                <div class="log-entry-head">
                    <div class="log-type-icon log-type-${cls}">${ICONS[cls]}</div>
                    <span class="log-entry-type">${inbound ? 'Antwort' : 'Gesendet'}</span>
                    <span class="log-entry-sub">${who}</span>
                    <span class="log-entry-date">${fmt(t.created_at)}</span>
                </div>
                ${t.subject ? `<div class="log-entry-sub" style="margin-bottom:4px">Betreff: ${esc(t.subject)}</div>` : ''}
                <div class="msg-body" id="${bodyId}"><div class="log-entry-content">${esc(t.content)}</div></div>
                <span class="msg-toggle" onclick="document.getElementById('${bodyId}').classList.toggle('expanded');this.textContent=document.getElementById('${bodyId}').classList.contains('expanded')?'▲ weniger':'▼ mehr'">▼ mehr</span>
            </div>`;
        }
        const isSys = t.type === 'System';
        const cls = {'Ausgehend':'out','Eingehend':'in','System':'sys'}[t.type] || 'note';
        return `<div class="log-entry is-${isSys ? 'sys' : 'note'}">
            <div class="log-entry-head">
                <div class="log-type-icon log-type-${cls}">${ICONS[cls] || ICONS.note}</div>
                <span class="log-entry-type">${esc(t.type)}</span>
                ${t.created_by_name ? `<span class="log-entry-sub">${esc(t.created_by_name)}</span>` : ''}
                <span class="log-entry-date">${fmt(t.created_at)}</span>
            </div>
            <div class="log-entry-content">${esc(t.content)}</div>
            ${CAN_WRITE && !isSys ? `<button class="log-del" onclick="deleteLog(${t.id})" title="Löschen">✕</button>` : ''}
        </div>`;
    }).join('');
}

/* ── Actions ── */
function toggleEdit() {
    const v = document.getElementById('viewSection'), e = document.getElementById('editSection');
    if (!e) return;
    const show = e.style.display === 'none';
    e.style.display = show ? 'block' : 'none';
    v.style.display = show ? 'none' : 'block';
}

async function reload() {
    const res = await post({action: 'get_report', id: currentId});
    if (res.ok) { current = res; renderDetail(); }
}

async function saveEdit() {
    const ip = document.getElementById('eIp').value.trim();
    if (!ip) return;
    const res = await post({
        action: 'update_report', id: currentId, ip,
        hoster_id: document.getElementById('eHoster').value,
        reason:    document.getElementById('eReason').value.trim(),
        status:    document.getElementById('eStatus').value,
        note:      document.getElementById('eNote').value.trim(),
    });
    if (res.ok) { await reload(); location.reload(); }
}

async function deleteReport() {
    if (!confirm(`Report ${current.report.ref || ''} wirklich löschen?`)) return;
    const res = await post({action: 'delete_report', id: currentId});
    if (res.ok) location.reload();
}

async function buildDraft() {
    const res = await post({action: 'build_draft', id: currentId, template: document.getElementById('tplSel').value});
    if (!res.ok) return;
    document.getElementById('draftSubject').value = res.subject;
    document.getElementById('draftBody').value = res.body;
    document.getElementById('sendBtn').disabled = !res.can_send;
}

async function sendReport() {
    const subject = document.getElementById('draftSubject').value.trim();
    const body = document.getElementById('draftBody').value.trim();
    if (!body) { toast('Bitte zuerst einen Entwurf erzeugen.', 'err'); return; }
    if (!confirm('Report jetzt an den Hoster senden?')) return;
    const btn = document.getElementById('sendBtn'); btn.disabled = true; btn.textContent = 'Sende…';
    const res = await post({action: 'send_report', id: currentId, subject, body});
    if (res.ok) { toast('Report gesendet.', 'ok'); await reload(); }
    else { btn.disabled = false; btn.textContent = 'Report senden'; }
}

async function sendReply() {
    const body = document.getElementById('replyBody').value.trim();
    if (!body) return;
    const res = await post({action: 'reply', id: currentId, body});
    if (res.ok) { toast('Antwort gesendet.', 'ok'); await reload(); }
}

async function markManual() {
    if (!confirm('Als manuell beim Hoster gemeldet markieren?')) return;
    const res = await post({action: 'mark_manually_sent', id: currentId});
    if (res.ok) { toast('Als gemeldet markiert.', 'ok'); await reload(); }
}

async function addLog() {
    const content = document.getElementById('logContent').value.trim();
    if (!content) return;
    const res = await post({action: 'add_log', id: currentId, type: document.getElementById('logType').value, content});
    if (res.ok) { document.getElementById('logContent').value = ''; await reload(); }
}

async function deleteLog(id) {
    const res = await post({action: 'delete_log', id});
    if (res.ok) await reload();
}
</script>
</body>
</html>
