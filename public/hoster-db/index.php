<?php
require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/db/hoster_db.php';
requireAuth();

$navActive = 'hoster-db';

// ── Aktionen ──────────────────────────────────────────────────────────────────

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!canWrite()) {
        http_response_code(403);
        die('<div style="color:#f87171;padding:40px;font-family:monospace;background:#080b10;min-height:100vh">403 — Keine Berechtigung.</div>');
    }
    $action   = $_POST['action'] ?? '';
    $name     = trim($_POST['name']     ?? '');
    $url      = trim($_POST['url']      ?? '');
    $email    = trim($_POST['email']    ?? '');
    $method   = in_array($_POST['method'] ?? '', ['web','email','both']) ? $_POST['method'] : 'email';
    $responds = in_array($_POST['responds'] ?? '', ['ja','nein','manchmal']) ? $_POST['responds'] : 'manchmal';
    $notes    = trim($_POST['notes']    ?? '');
    $id       = (int)($_POST['id']      ?? 0);

    if ($action === 'create' && $name !== '') {
        createHoster($name, $url, $email, $method, $responds, $notes);
        header('Location: index.php');
        exit;
    } elseif ($action === 'update' && $id && $name !== '') {
        updateHoster($id, $name, $url, $email, $method, $responds, $notes);
        header('Location: index.php');
        exit;
    } elseif ($action === 'delete' && $id) {
        deleteHoster($id);
        header('Location: index.php');
        exit;
    }
}

$hosters = getAllHosters();
$search  = trim($_GET['q'] ?? '');
if ($search !== '') {
    $hosters = array_filter($hosters, fn($h) =>
        stripos($h['name'], $search) !== false ||
        stripos($h['notes'], $search) !== false ||
        stripos($h['abuse_email'], $search) !== false
    );
}

// Für Inline-Edit
$edit_id = (int)($_GET['edit'] ?? 0);
$edit    = $edit_id ? getHoster($edit_id) : null;

$responds_labels = ['ja' => 'Ja', 'nein' => 'Nein', 'manchmal' => 'Manchmal'];
$responds_colors = ['ja' => 'tag-green', 'nein' => 'tag-red', 'manchmal' => 'tag-orange'];
$method_labels   = ['web' => 'Webformular', 'email' => 'E-Mail', 'both' => 'Beides'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<title><?= htmlspecialchars(pageTitle('Hoster-Datenbank')) ?></title>
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
    position: fixed; inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.015'%3E%3Crect x='0' y='0' width='1' height='1'/%3E%3Crect x='20' y='20' width='1' height='1'/%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none; z-index: 0;
}
.blob { position: fixed; border-radius: 50%; filter: blur(120px); opacity: .15; pointer-events: none; z-index: 0; }
.blob-blue  { width: 500px; height: 500px; background: #1d4ed8; top: -200px; left: -150px; }
.blob-green { width: 350px; height: 350px; background: #065f46; bottom: -100px; right: -100px; }

.wrap { position: relative; z-index: 1; max-width: 1200px; margin: 0 auto; padding: 28px 24px; }

.page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
.page-title  { font-size: 20px; font-weight: 700; letter-spacing: -.02em; color: #f1f5f9; }
.page-meta   { font-size: 13px; color: var(--muted); margin-top: 2px; }

/* ── Toolbar ── */
.toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
.search-box {
    flex: 1; min-width: 200px; max-width: 340px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: 7px; padding: 7px 12px; color: var(--text); font-size: 13px; outline: none;
}
.search-box:focus { border-color: var(--border-hi); }
.search-box::placeholder { color: var(--muted); }

/* ── Buttons ── */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 16px; border-radius: 7px; border: none; cursor: pointer;
    font-size: 13px; font-weight: 500; text-decoration: none;
    transition: opacity .15s, transform .1s;
}
.btn:hover { opacity: .88; transform: translateY(-1px); }
.btn-primary { background: rgba(34,197,94,.15); color: #86efac; border: 1px solid rgba(34,197,94,.25); }
.btn-ghost   { background: var(--card); color: var(--muted); border: 1px solid var(--border); }
.btn-ghost:hover { color: var(--text); }
.btn-danger  { background: rgba(239,68,68,.12); color: #f87171; border: 1px solid rgba(239,68,68,.2); }

/* ── Table ── */
.section { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { color: var(--muted); font-weight: 500; text-align: left; padding: 9px 14px; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid var(--border); white-space: nowrap; }
td { padding: 9px 14px; border-bottom: 1px solid rgba(30,39,64,.6); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: rgba(46,63,96,.1); }

.tag { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; border: 1px solid transparent; white-space: nowrap; }
.tag-green  { background: rgba(34,197,94,.1);   color: #86efac; border-color: rgba(34,197,94,.2); }
.tag-red    { background: rgba(239,68,68,.1);   color: #fca5a5; border-color: rgba(239,68,68,.2); }
.tag-orange { background: rgba(249,115,22,.1);  color: #fdba74; border-color: rgba(249,115,22,.2); }
.tag-blue   { background: rgba(59,130,246,.1);  color: #93c5fd; border-color: rgba(59,130,246,.2); }
.tag-gray   { background: rgba(100,116,139,.1); color: #94a3b8; border-color: rgba(100,116,139,.2); }

a.link { color: #60a5fa; text-decoration: none; font-size: 12px; }
a.link:hover { text-decoration: underline; }
code { background: rgba(59,130,246,.08); border: 1px solid rgba(59,130,246,.15); padding: 1px 6px; border-radius: 4px; font-family: 'SF Mono','Fira Code',monospace; color: #93c5fd; font-size: 12px; }

/* ── Modal ── */
.modal-bg {
    position: fixed; inset: 0; background: rgba(0,0,0,.6);
    backdrop-filter: blur(4px); z-index: 200;
    display: flex; align-items: center; justify-content: center; padding: 20px;
}
.modal-bg.hidden { display: none; }
.modal {
    background: var(--card); border: 1px solid var(--border-hi); border-radius: 14px;
    padding: 28px; width: 100%; max-width: 540px;
}
.modal-title { font-size: 16px; font-weight: 600; color: #f1f5f9; margin-bottom: 20px; }

.field { margin-bottom: 14px; }
.field label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 5px; font-weight: 500; text-transform: uppercase; letter-spacing: .04em; }
.field input, .field select, .field textarea {
    width: 100%; background: var(--surface); border: 1px solid var(--border);
    border-radius: 7px; padding: 8px 11px; color: var(--text); font-size: 13px; outline: none;
    font-family: inherit;
}
.field input:focus, .field select:focus, .field textarea:focus { border-color: var(--border-hi); }
.field textarea { resize: vertical; min-height: 80px; }
.field select { cursor: pointer; }

.modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; }

.empty { padding: 48px; text-align: center; color: var(--muted); font-size: 13px; }

/* Delete confirm */
.del-form { display: inline; }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../app/helpers/nav.php'; ?>

<div class="blob blob-blue"></div>
<div class="blob blob-green"></div>

<div class="wrap">

  <div class="page-header">
    <div>
      <div class="page-title">Hoster-Report-Datenbank</div>
      <div class="page-meta"><?= count($hosters) ?> Einträge — wo und wie man bei welchem Hoster Abuse meldet</div>
    </div>
    <?php if (canWrite()): ?>
    <button class="btn btn-primary" onclick="openModal()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
      Hoster hinzufügen
    </button>
    <?php endif; ?>
  </div>

  <!-- Toolbar -->
  <form method="get" class="toolbar">
    <input type="text" name="q" class="search-box" placeholder="Suche nach Name, E-Mail, Notiz …" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
    <?php if ($search): ?>
    <a href="index.php" class="btn btn-ghost">× Zurücksetzen</a>
    <?php endif; ?>
  </form>

  <!-- Table -->
  <div class="section">
    <?php if (empty($hosters)): ?>
    <div class="empty">
      Noch keine Einträge.<?= $search ? ' Keine Treffer für "<strong>'.htmlspecialchars($search).'</strong>".' : ' Klicke auf "Hoster hinzufügen" um loszulegen.' ?>
    </div>
    <?php else: ?>
    <table>
      <thead><tr>
        <th>Hoster</th>
        <th>Kontakt / URL</th>
        <th>Methode</th>
        <th>Reagiert?</th>
        <th>Notizen</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($hosters as $h): ?>
      <tr>
        <td style="font-weight:600;color:#f1f5f9"><?= htmlspecialchars($h['name']) ?></td>
        <td style="max-width:220px">
          <?php if ($h['abuse_url']): ?>
          <a class="link" href="<?= htmlspecialchars($h['abuse_url']) ?>" target="_blank" rel="noopener">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>Formular
          </a>
          <?php endif; ?>
          <?php if ($h['abuse_email']): ?>
          <div style="margin-top:3px"><code><?= htmlspecialchars($h['abuse_email']) ?></code></div>
          <?php endif; ?>
          <?php if (!$h['abuse_url'] && !$h['abuse_email']): ?>
          <span style="color:var(--muted)">—</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="tag tag-blue"><?= htmlspecialchars($method_labels[$h['method']] ?? $h['method']) ?></span>
        </td>
        <td>
          <span class="tag <?= $responds_colors[$h['responds']] ?? 'tag-gray' ?>">
            <?= htmlspecialchars($responds_labels[$h['responds']] ?? $h['responds']) ?>
          </span>
        </td>
        <td style="max-width:260px;color:var(--muted);font-size:12px">
          <?= htmlspecialchars(mb_substr($h['notes'] ?? '', 0, 100)) ?><?= mb_strlen($h['notes'] ?? '') > 100 ? ' …' : '' ?>
        </td>
        <td style="white-space:nowrap">
          <?php if (canWrite()): ?>
          <button class="btn btn-ghost" style="padding:4px 10px;font-size:12px"
            onclick="openModal(<?= htmlspecialchars(json_encode($h)) ?>)">Bearbeiten</button>
          <form method="post" class="del-form" onsubmit="return confirm('<?= htmlspecialchars($h['name']) ?> wirklich löschen?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $h['id'] ?>">
            <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:12px">Löschen</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<!-- Modal -->
<div class="modal-bg hidden" id="modal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <div class="modal-title" id="modal-title">Hoster hinzufügen</div>
    <form method="post" id="modal-form">
      <input type="hidden" name="action" id="f-action" value="create">
      <input type="hidden" name="id"     id="f-id"     value="">

      <div class="field">
        <label>Name *</label>
        <input type="text" name="name" id="f-name" placeholder="z.B. Hetzner, OVH, Contabo …" required autocomplete="off">
      </div>
      <div class="field">
        <label>Abuse-URL (Webformular)</label>
        <input type="url" name="url" id="f-url" placeholder="https://…">
      </div>
      <div class="field">
        <label>Abuse-E-Mail</label>
        <input type="email" name="email" id="f-email" placeholder="abuse@example.com">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="field">
          <label>Methode</label>
          <select name="method" id="f-method">
            <option value="email">E-Mail</option>
            <option value="web">Webformular</option>
            <option value="both">Beides</option>
          </select>
        </div>
        <div class="field">
          <label>Reagiert?</label>
          <select name="responds" id="f-responds">
            <option value="ja">Ja</option>
            <option value="manchmal">Manchmal</option>
            <option value="nein">Nein</option>
          </select>
        </div>
      </div>
      <div class="field">
        <label>Notizen</label>
        <textarea name="notes" id="f-notes" placeholder="Erfahrungen, Hinweise, typische Antwortzeiten …"></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal()">Abbrechen</button>
        <button type="submit" class="btn btn-primary" id="modal-submit-btn">Hinzufügen</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(data) {
    const modal = document.getElementById('modal');
    if (data) {
        document.getElementById('modal-title').textContent = 'Hoster bearbeiten';
        document.getElementById('modal-submit-btn').textContent = 'Speichern';
        document.getElementById('f-action').value  = 'update';
        document.getElementById('f-id').value      = data.id;
        document.getElementById('f-name').value    = data.name    || '';
        document.getElementById('f-url').value     = data.abuse_url   || '';
        document.getElementById('f-email').value   = data.abuse_email || '';
        document.getElementById('f-method').value  = data.method   || 'email';
        document.getElementById('f-responds').value= data.responds || 'manchmal';
        document.getElementById('f-notes').value   = data.notes   || '';
    } else {
        document.getElementById('modal-title').textContent = 'Hoster hinzufügen';
        document.getElementById('modal-submit-btn').textContent = 'Hinzufügen';
        document.getElementById('f-action').value  = 'create';
        document.getElementById('f-id').value      = '';
        document.getElementById('modal-form').reset();
    }
    modal.classList.remove('hidden');
}
function closeModal() {
    document.getElementById('modal').classList.add('hidden');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>

</body>
</html>
