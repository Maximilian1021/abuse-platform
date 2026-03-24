<?php
require_once __DIR__ . '/../../app/helpers/auth.php';
requireAuth();

$navActive = 'report-monitor';
require_once __DIR__ . '/../../app/db/abuse_db.php';
$reports = getAllReports();

$statuses = ['Gemeldet', 'Stellung gebeten', 'Antwort erhalten', 'Erfolgreich', 'Ignoriert', 'Abgeschlossen'];
$logTypes = ['Notiz', 'Ausgehend', 'Eingehend', 'System'];

$statusColors = [
    'Gemeldet'         => ['bg' => 'rgba(249,115,22,.1)',  'border' => 'rgba(249,115,22,.25)', 'text' => '#fdba74'],
    'Stellung gebeten' => ['bg' => 'rgba(59,130,246,.1)',  'border' => 'rgba(59,130,246,.25)', 'text' => '#93c5fd'],
    'Antwort erhalten' => ['bg' => 'rgba(139,92,246,.1)',  'border' => 'rgba(139,92,246,.25)', 'text' => '#c4b5fd'],
    'Erfolgreich'      => ['bg' => 'rgba(34,197,94,.1)',   'border' => 'rgba(34,197,94,.25)',  'text' => '#86efac'],
    'Ignoriert'        => ['bg' => 'rgba(100,116,139,.1)', 'border' => 'rgba(100,116,139,.2)', 'text' => '#94a3b8'],
    'Abgeschlossen'    => ['bg' => 'rgba(30,39,64,.5)',    'border' => 'rgba(46,63,96,.5)',    'text' => '#64748b'],
];

$logTypeIcons = [
    'Notiz'     => 'note',
    'Ausgehend' => 'out',
    'Eingehend' => 'in',
    'System'    => 'sys',
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

body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font);
    font-size: 14px;
    min-height: 100vh;
    overflow: hidden;
}

a { color: inherit; text-decoration: none; }

/* ── Noise overlay ── */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.012'%3E%3Crect x='0' y='0' width='1' height='1'/%3E%3Crect x='20' y='20' width='1' height='1'/%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none;
    z-index: 0;
}

/* ── Full-height layout ── */
.app { display: flex; flex-direction: column; height: 100vh; position: relative; z-index: 1; }


/* ── Body: sidebar + detail ── */
.body { display: flex; flex: 1; overflow: hidden; }

/* ── Sidebar ── */
.sidebar {
    width: 320px;
    min-width: 260px;
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex-shrink: 0;
}
.sidebar-head {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 8px;
}
.sidebar-title { font-size: 13px; font-weight: 600; color: var(--text); flex: 1; }
.sidebar-count {
    font-size: 11px; color: var(--muted);
    background: var(--card); border: 1px solid var(--border);
    border-radius: 10px; padding: 1px 7px;
}

.search-wrap { padding: 10px 14px; border-bottom: 1px solid var(--border); }
.search-input {
    width: 100%;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 7px 10px;
    color: var(--text);
    font-size: 13px;
    font-family: var(--font);
    outline: none;
    transition: border-color .15s;
}
.search-input:focus { border-color: var(--border-hi); }
.search-input::placeholder { color: var(--muted); }

.report-list { overflow-y: auto; flex: 1; }
.report-item {
    padding: 11px 14px;
    border-bottom: 1px solid rgba(30,39,64,.5);
    cursor: pointer;
    transition: background .1s;
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.report-item:hover { background: rgba(20,25,38,.8); }
.report-item.active {
    background: var(--card);
    border-left: 2px solid var(--accent);
    padding-left: 12px;
}
.report-ip {
    font-size: 14px; font-weight: 600;
    font-family: 'SF Mono', 'Fira Code', monospace;
    color: #f1f5f9;
}
.report-meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.report-reason { font-size: 12px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.report-date { font-size: 11px; color: var(--subtle); margin-left: auto; white-space: nowrap; }
.report-logcount { font-size: 11px; color: var(--subtle); }

.no-reports { padding: 32px 14px; text-align: center; color: var(--muted); font-size: 13px; line-height: 1.7; }

/* ── Status badge ── */
.sbadge {
    display: inline-flex; align-items: center;
    padding: 2px 8px; border-radius: 10px;
    font-size: 11px; font-weight: 500;
    white-space: nowrap; border: 1px solid transparent;
}

/* ── Detail pane ── */
.detail {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--bg);
}

.empty-state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    gap: 10px;
}
.empty-icon {
    width: 48px; height: 48px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
}
.empty-icon svg { width: 22px; height: 22px; stroke: var(--muted); }
.empty-label { font-size: 13px; }

.detail-head {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.detail-ip {
    font-size: 18px; font-weight: 700;
    font-family: 'SF Mono', 'Fira Code', monospace;
    color: #f1f5f9;
}
.detail-actions { margin-left: auto; display: flex; gap: 7px; }

.detail-body { flex: 1; overflow-y: auto; display: flex; flex-direction: column; }

/* ── Sections ── */
.dsection {
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
}
.dsection-title {
    font-size: 10px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .08em; color: var(--muted); margin-bottom: 12px;
}

.info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
.info-field label { display: block; font-size: 11px; color: var(--muted); margin-bottom: 3px; }
.info-field .val { font-size: 13px; font-weight: 500; color: var(--text); }
.info-field .val.mono { font-family: 'SF Mono', 'Fira Code', monospace; color: #93c5fd; }
.info-note { margin-top: 14px; }
.info-note .note-body { font-size: 13px; white-space: pre-wrap; line-height: 1.6; color: var(--muted); margin-top: 4px; }

/* ── Log timeline ── */
.log-section { flex: 1; padding: 16px 18px; }
.log-list { display: flex; flex-direction: column; gap: 8px; }

.log-entry {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 10px 13px;
    position: relative;
}
.log-entry:hover { border-color: var(--border-hi); }
.log-entry-head { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.log-type-icon {
    width: 22px; height: 22px; border-radius: 5px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.log-type-note { background: rgba(100,116,139,.15); }
.log-type-out  { background: rgba(59,130,246,.12);  }
.log-type-in   { background: rgba(34,197,94,.1);   }
.log-type-sys  { background: rgba(139,92,246,.1);  }
.log-type-icon svg { width: 12px; height: 12px; }
.log-type-note svg { stroke: var(--muted); }
.log-type-out  svg { stroke: #60a5fa; }
.log-type-in   svg { stroke: #4ade80; }
.log-type-sys  svg { stroke: #a78bfa; }

.log-entry-type { font-size: 12px; font-weight: 500; color: var(--text); }
.log-entry-date { font-size: 11px; color: var(--muted); margin-left: auto; }
.log-entry-content { font-size: 13px; color: var(--muted); white-space: pre-wrap; word-break: break-word; line-height: 1.55; }
.log-del {
    position: absolute; top: 8px; right: 8px;
    background: none; border: none; color: var(--subtle);
    cursor: pointer; padding: 3px 6px; border-radius: 4px;
    font-size: 13px; line-height: 1; opacity: 0; transition: opacity .15s;
}
.log-entry:hover .log-del { opacity: 1; }
.log-del:hover { background: rgba(239,68,68,.15); color: #f87171; }

/* ── Log add ── */
.log-add {
    margin-top: 14px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px 13px;
    display: flex; flex-direction: column; gap: 8px;
}
.log-add-head { display: flex; gap: 8px; align-items: center; }

/* ── Modal ── */
.overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.65); z-index: 100;
    align-items: center; justify-content: center;
    backdrop-filter: blur(4px);
}
.overlay.open { display: flex; }
.modal {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    width: 480px; max-width: 95vw; max-height: 90vh; overflow-y: auto;
    box-shadow: 0 24px 60px rgba(0,0,0,.6);
}
.modal-title { font-size: 16px; font-weight: 600; margin-bottom: 18px; color: #f1f5f9; }

/* ── Forms ── */
.fg { margin-bottom: 13px; }
.fg label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 5px; font-weight: 500; }
.fg input, .fg select, .fg textarea {
    width: 100%;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 8px 10px;
    color: var(--text);
    font-size: 13px;
    font-family: var(--font);
    outline: none;
    transition: border-color .15s;
}
.fg input:focus, .fg select:focus, .fg textarea:focus { border-color: var(--border-hi); }
.fg textarea { resize: vertical; min-height: 80px; }
.form-row { display: flex; gap: 10px; }
.form-row .fg { flex: 1; }
.modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 18px; }
select option { background: var(--card); }

/* ── Edit section ── */
.edit-mode input, .edit-mode select, .edit-mode textarea {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 6px; padding: 7px 10px; color: var(--text);
    font-size: 13px; font-family: var(--font); outline: none; width: 100%;
    transition: border-color .15s;
}
.edit-mode input:focus, .edit-mode select:focus, .edit-mode textarea:focus { border-color: var(--border-hi); }

/* ── Buttons ── */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 6px; border: none;
    cursor: pointer; font-size: 13px; font-family: var(--font);
    font-weight: 500; transition: opacity .15s;
    text-decoration: none;
}
.btn:hover { opacity: .85; }
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

/* ── Scrollbar ── */
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--border-hi); }

/* ── Responsive ── */
@media (max-width: 700px) {
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
        <button class="btn btn-primary btn-sm" onclick="openNewModal()">+ Neu</button>
      </div>
      <div class="search-wrap">
        <input type="text" class="search-input" id="searchInput" placeholder="IP oder Grund suchen…" oninput="filterList()">
      </div>
      <div class="report-list" id="reportList">
        <?php if (empty($reports)): ?>
        <div class="no-reports">Noch keine Reports.<br>Klicke auf <strong>+ Neu</strong> um anzufangen.</div>
        <?php else: foreach ($reports as $r):
            $sc = $statusColors[$r['status']] ?? $statusColors['Ignoriert'];
        ?>
        <div class="report-item" id="item-<?= $r['id'] ?>" onclick="loadReport(<?= $r['id'] ?>)">
          <div class="report-ip"><?= h($r['ip']) ?></div>
          <div class="report-meta">
            <span class="sbadge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;border-color:<?= $sc['border'] ?>"><?= h($r['status']) ?></span>
            <?php if ($r['reported_to']): ?>
            <span style="color:var(--muted);font-size:12px"><?= h($r['reported_to']) ?></span>
            <?php endif; ?>
            <?php if ($r['log_count'] > 0): ?>
            <span class="report-logcount"><?= $r['log_count'] ?> Einträge</span>
            <?php endif; ?>
          </div>
          <?php if ($r['reason']): ?>
          <div class="report-reason"><?= h($r['reason']) ?></div>
          <?php endif; ?>
          <div class="report-date"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Detail -->
    <div class="detail" id="detailPane">
      <div class="empty-state" id="emptyState">
        <div class="empty-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
          </svg>
        </div>
        <div class="empty-label">Report auswählen oder neuen anlegen</div>
      </div>
      <div id="detailContent" style="display:none;flex:1;overflow-y:auto;display:flex;flex-direction:column"></div>
    </div>

  </div>
</div>

<!-- New Report Modal -->
<div class="overlay" id="newModal">
  <div class="modal">
    <div class="modal-title">Neuer Abuse Report</div>
    <div class="form-row">
      <div class="fg"><label>IP-Adresse *</label><input type="text" id="newIp" placeholder="1.2.3.4" autocomplete="off"></div>
      <div class="fg"><label>Gemeldet bei</label><input type="text" id="newReportedTo" placeholder="AbuseIPDB, Hetzner…" autocomplete="off"></div>
    </div>
    <div class="fg"><label>Grund</label><input type="text" id="newReason" placeholder="SSH Brute Force, Spam…" autocomplete="off"></div>
    <div class="fg">
      <label>Status</label>
      <select id="newStatus">
        <?php foreach ($statuses as $s): ?><option><?= h($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="fg"><label>Notiz</label><textarea id="newNote" placeholder="Optionale Notizen…"></textarea></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeNewModal()">Abbrechen</button>
      <button class="btn btn-primary" onclick="createReport()">Anlegen</button>
    </div>
  </div>
</div>

<script>
const STATUS_COLORS = <?= json_encode($statusColors) ?>;
const STATUSES      = <?= json_encode($statuses) ?>;
const LOG_TYPES     = <?= json_encode($logTypes) ?>;

// SVG icons per log type
const LOG_ICONS = {
    note: `<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`,
    out:  `<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>`,
    in:   `<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>`,
    sys:  `<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M19.07 19.07l-1.41-1.41M4.93 19.07l1.41-1.41M12 2v2M12 20v2M2 12h2M20 12h2"/></svg>`,
};

let currentId     = null;
let currentReport = null;
let currentLogs   = [];

function fmt(dt) {
    if (!dt) return '—';
    const d = new Date(dt.replace(' ', 'T') + 'Z');
    return d.toLocaleDateString('de-DE') + ' ' + d.toLocaleTimeString('de-DE', {hour:'2-digit', minute:'2-digit'});
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function badgeHtml(status) {
    const c = STATUS_COLORS[status] || STATUS_COLORS['Ignoriert'];
    return `<span class="sbadge" style="background:${c.bg};color:${c.text};border-color:${c.border}">${esc(status)}</span>`;
}

function logTypeClass(type) {
    return {'Notiz':'note','Ausgehend':'out','Eingehend':'in','System':'sys'}[type] || 'note';
}

async function post(data) {
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k, v);
    const r = await fetch('actions.php', {method:'POST', body:fd});
    return r.json();
}

// Sidebar filter
function filterList() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.report-item').forEach(el => {
        el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

// Modal
function openNewModal() {
    ['newIp','newReportedTo','newReason','newNote'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('newStatus').value = 'Gemeldet';
    document.getElementById('newModal').classList.add('open');
    setTimeout(() => document.getElementById('newIp').focus(), 50);
}
function closeNewModal() { document.getElementById('newModal').classList.remove('open'); }
document.getElementById('newModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeNewModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNewModal(); });

async function createReport() {
    const ip = document.getElementById('newIp').value.trim();
    if (!ip) { document.getElementById('newIp').focus(); return; }
    const res = await post({
        action: 'create_report', ip,
        reported_to: document.getElementById('newReportedTo').value.trim(),
        reason:      document.getElementById('newReason').value.trim(),
        status:      document.getElementById('newStatus').value,
        note:        document.getElementById('newNote').value.trim()
    });
    if (res.ok) { closeNewModal(); location.reload(); }
}

// Load report
async function loadReport(id) {
    currentId = id;
    document.querySelectorAll('.report-item').forEach(el => el.classList.remove('active'));
    document.getElementById('item-' + id)?.classList.add('active');
    const res = await post({action: 'get_report', id});
    if (!res.ok) return;
    currentReport = res.report;
    currentLogs   = res.logs;
    renderDetail();
}

function renderDetail() {
    const r = currentReport;
    document.getElementById('emptyState').style.display = 'none';
    const pane = document.getElementById('detailContent');
    pane.style.cssText = 'display:flex;flex:1;overflow-y:auto;flex-direction:column';

    pane.innerHTML = `
    <div class="detail-head">
        <div class="detail-ip">${esc(r.ip)}</div>
        ${badgeHtml(r.status)}
        <div class="detail-actions">
            <button class="btn btn-blue btn-sm" onclick="toggleEdit()">Bearbeiten</button>
            <button class="btn btn-danger btn-sm" onclick="deleteReport()">Löschen</button>
        </div>
    </div>

    <div class="detail-body">
        <div class="dsection" id="viewSection">
            <div class="dsection-title">Details</div>
            <div class="info-grid">
                <div class="info-field"><label>IP-Adresse</label><div class="val mono">${esc(r.ip)}</div></div>
                <div class="info-field"><label>Gemeldet bei</label><div class="val">${esc(r.reported_to || '—')}</div></div>
                <div class="info-field"><label>Grund</label><div class="val">${esc(r.reason || '—')}</div></div>
                <div class="info-field"><label>Erstellt</label><div class="val">${fmt(r.created_at)}</div></div>
                <div class="info-field"><label>Aktualisiert</label><div class="val">${fmt(r.updated_at)}</div></div>
            </div>
            ${r.note ? `<div class="info-note"><div class="dsection-title" style="margin-bottom:4px">Notiz</div><div class="note-body">${esc(r.note)}</div></div>` : ''}
        </div>

        <div class="dsection edit-mode" id="editSection" style="display:none">
            <div class="dsection-title">Bearbeiten</div>
            <div class="form-row">
                <div class="fg"><label>IP-Adresse</label><input id="eIp" value="${esc(r.ip)}"></div>
                <div class="fg"><label>Gemeldet bei</label><input id="eReportedTo" value="${esc(r.reported_to || '')}"></div>
            </div>
            <div class="fg"><label>Grund</label><input id="eReason" value="${esc(r.reason || '')}"></div>
            <div class="fg"><label>Status</label>
                <select id="eStatus">${STATUSES.map(s => `<option ${s===r.status?'selected':''}>${esc(s)}</option>`).join('')}</select>
            </div>
            <div class="fg"><label>Notiz</label><textarea id="eNote">${esc(r.note || '')}</textarea></div>
            <div style="display:flex;gap:8px">
                <button class="btn btn-green btn-sm" onclick="saveEdit()">Speichern</button>
                <button class="btn btn-ghost btn-sm" onclick="toggleEdit()">Abbrechen</button>
            </div>
        </div>

        <div class="log-section" id="logSection">
            <div class="dsection-title">Verlauf &amp; E-Mail-Log</div>
            <div class="log-list" id="logList">${renderLogs()}</div>
            <div class="log-add">
                <div class="log-add-head">
                    <select id="logType" class="btn btn-ghost btn-sm" style="cursor:pointer">
                        ${LOG_TYPES.map(t => `<option value="${esc(t)}">${esc(t)}</option>`).join('')}
                    </select>
                    <span style="color:var(--muted);font-size:12px">Eintrag hinzufügen</span>
                </div>
                <textarea class="fg" id="logContent" placeholder="E-Mail-Text, Notiz oder Verlaufsinfo…" rows="3"
                    style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:8px 10px;color:var(--text);font-size:13px;font-family:var(--font);outline:none;resize:vertical;width:100%;margin-bottom:8px"></textarea>
                <div><button class="btn btn-primary btn-sm" onclick="addLog()">Eintrag speichern</button></div>
            </div>
        </div>
    </div>`;
}

function renderLogs() {
    if (!currentLogs.length) return '<div style="color:var(--muted);font-size:13px;padding:4px 0">Noch keine Einträge.</div>';
    return currentLogs.map(l => {
        const cls = logTypeClass(l.type);
        return `
        <div class="log-entry" id="log-${l.id}">
            <div class="log-entry-head">
                <div class="log-type-icon log-type-${cls}">${LOG_ICONS[cls] || LOG_ICONS.note}</div>
                <span class="log-entry-type">${esc(l.type)}</span>
                <span class="log-entry-date">${fmt(l.created_at)}</span>
            </div>
            <div class="log-entry-content">${esc(l.content)}</div>
            <button class="log-del" onclick="deleteLog(${l.id})" title="Löschen">✕</button>
        </div>`;
    }).join('');
}

function toggleEdit() {
    const v = document.getElementById('viewSection');
    const e = document.getElementById('editSection');
    if (e.style.display === 'none') { v.style.display = 'none'; e.style.display = 'block'; }
    else                            { v.style.display = 'block'; e.style.display = 'none'; }
}

async function saveEdit() {
    const ip = document.getElementById('eIp').value.trim();
    if (!ip) return;
    const res = await post({
        action: 'update_report', id: currentId, ip,
        reported_to: document.getElementById('eReportedTo').value.trim(),
        reason:      document.getElementById('eReason').value.trim(),
        status:      document.getElementById('eStatus').value,
        note:        document.getElementById('eNote').value.trim()
    });
    if (res.ok) {
        const rRes  = await post({action: 'get_report', id: currentId});
        currentReport = rRes.report;
        currentLogs   = rRes.logs;
        renderDetail();
        updateSidebarItem();
    }
}

async function deleteReport() {
    if (!confirm(`Report für ${currentReport.ip} wirklich löschen?`)) return;
    const res = await post({action: 'delete_report', id: currentId});
    if (res.ok) location.reload();
}

async function addLog() {
    const content = document.getElementById('logContent').value.trim();
    if (!content) return;
    const type = document.getElementById('logType').value;
    const res  = await post({action: 'add_log', id: currentId, type, content});
    if (res.ok) {
        const rRes = await post({action: 'get_report', id: currentId});
        currentLogs = rRes.logs;
        document.getElementById('logList').innerHTML = renderLogs();
        document.getElementById('logContent').value = '';
    }
}

async function deleteLog(logId) {
    const res = await post({action: 'delete_log', id: logId});
    if (res.ok) {
        currentLogs = currentLogs.filter(l => l.id !== logId);
        document.getElementById('logList').innerHTML = renderLogs();
    }
}

function updateSidebarItem() {
    const r = currentReport;
    const item = document.getElementById('item-' + r.id);
    if (!item) return;
    const c = STATUS_COLORS[r.status] || STATUS_COLORS['Ignoriert'];
    item.innerHTML = `
        <div class="report-ip">${esc(r.ip)}</div>
        <div class="report-meta">
            <span class="sbadge" style="background:${c.bg};color:${c.text};border-color:${c.border}">${esc(r.status)}</span>
            ${r.reported_to ? `<span style="color:var(--muted);font-size:12px">${esc(r.reported_to)}</span>` : ''}
            ${currentLogs.length ? `<span class="report-logcount">${currentLogs.length} Einträge</span>` : ''}
        </div>
        ${r.reason ? `<div class="report-reason">${esc(r.reason)}</div>` : ''}
        <div class="report-date">${fmt(r.created_at)}</div>
    `;
}
</script>
</body>
</html>
