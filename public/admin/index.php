<?php
require_once __DIR__ . '/../../app/helpers/auth.php';
require_once __DIR__ . '/../../app/db/settings_db.php';
require_once __DIR__ . '/../../app/db/templates_db.php';
require_once __DIR__ . '/../../app/mail/templates.php';
requireRole('admin');

seedMailTemplatesIfEmpty(abuseTemplateDefaults());

$me        = currentUser();
$navActive = 'admin';
$message   = '';
$msgType   = '';

// GUI-editierbare Branding-Keys (Defaults kommen aus app/config/config.php)
$BRAND_FIELDS = [
    'site_name'       => ['label' => 'Produktname',                    'ph' => 'Abuse Platform',        'max' => 80,   'type' => 'text',
                          'help'  => 'Marke in der Navigation und im Seitentitel.'],
    'site_domain'     => ['label' => 'Domain / Organisation',          'ph' => 'leer = weglassen',      'max' => 120,  'type' => 'text',
                          'help'  => 'Erscheint im Footer und im Seitentitel.'],
    'mail_org'        => ['label' => 'Org-Name in Mail-Vorlagen',      'ph' => 'leer = Domain',         'max' => 120,  'type' => 'text',
                          'help'  => 'Wird in Abuse-Vorlagen als "<Org> infrastructure" / "<Org> Abuse Team" eingesetzt.'],
    'abuse_from_name' => ['label' => 'Absender-Anzeigename (Mail)',    'ph' => 'z.B. example.com Abuse', 'max' => 120,  'type' => 'text',
                          'help'  => 'From-Name der versendeten Abuse-Mails. Die Absenderadresse bleibt in der config.php.'],
    'reporter_name'   => ['label' => 'Melder-Name (Signatur-Fallback)','ph' => 'Vorname Nachname',      'max' => 120,  'type' => 'text',
                          'help'  => 'Fällt in der Mail-Signatur ein, wenn kein Ersteller-Name verfügbar ist.'],
    'login_note'      => ['label' => 'Login-Fußzeile',                 'ph' => 'Internal Use Only',     'max' => 120,  'type' => 'text',
                          'help'  => 'Kleiner Hinweistext auf der Anmeldeseite.'],
    'footer_html'     => ['label' => 'Footer (HTML erlaubt)',          'ph' => 'leer = automatisch aus Produktname + Domain', 'max' => 2000, 'type' => 'textarea',
                          'help'  => 'Überschreibt den automatischen Footer. HTML wird ungefiltert ausgegeben – nur Admins bearbeiten dieses Feld.'],
];

// ── Actions ───────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($action === 'save_settings') {
    $kv = [];
    foreach ($BRAND_FIELDS as $key => $f) {
        $val = trim((string) ($_POST[$key] ?? ''));
        if ($key !== 'footer_html') $val = strip_tags($val);
        if (mb_strlen($val) > $f['max']) $val = mb_substr($val, 0, $f['max']);
        $kv[$key] = $val;
    }
    saveSettings($kv, $me['id']);
    $message = 'Branding-Einstellungen gespeichert.'; $msgType = 'ok';
}

if ($action === 'save_template' || $action === 'create_template') {
    $key     = trim($_POST['key'] ?? '');
    $label   = trim($_POST['label'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body'] ?? '');

    if ($action === 'create_template') {
        $key = strtolower(preg_replace('/[^a-z0-9_\-]/', '', str_replace(' ', '-', strtolower($key))));
    }

    if ($action === 'create_template' && (strlen($key) < 2 || strlen($key) > 40)) {
        $message = 'Ungültiger Schlüssel (2–40 Zeichen: a–z, 0–9, _ oder -).'; $msgType = 'error';
    } elseif ($action === 'create_template' && array_key_exists($key, getMailTemplatesRaw())) {
        $message = "Schlüssel '$key' existiert bereits."; $msgType = 'error';
    } elseif ($key === '' || $label === '' || $subject === '' || $body === '') {
        $message = 'Schlüssel, Bezeichnung, Betreff und Text dürfen nicht leer sein.'; $msgType = 'error';
    } else {
        saveMailTemplate(
            $key, mb_substr($label, 0, 120), mb_substr($subject, 0, 255), $body,
            $action === 'create_template' ? null : (int) ($_POST['sort'] ?? 0),
            $me['id']
        );
        $message = $action === 'create_template' ? "Vorlage '$key' angelegt." : "Vorlage '$key' gespeichert.";
        $msgType = 'ok';
    }
}

if ($action === 'delete_template') {
    $key = trim($_POST['key'] ?? '');
    if ($key === 'generic') {
        $message = "'generic' ist der Fallback und kann nicht gelöscht werden."; $msgType = 'error';
    } elseif ($key !== '') {
        deleteMailTemplate($key);
        $message = "Vorlage '$key' gelöscht."; $msgType = 'ok';
    }
}

if ($action === 'reset_templates') {
    resetMailTemplates(abuseTemplateDefaults(), $me['id']);
    $message = 'Vorlagen auf Standard zurückgesetzt.'; $msgType = 'ok';
}

if ($action === 'create_user') {
    $username = trim($_POST['username'] ?? '');
    $pw       = $_POST['password'] ?? '';
    $role     = in_array($_POST['role'] ?? '', ['admin', 'viewer']) ? $_POST['role'] : 'viewer';
    if (strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
        $message = 'Ungültiger Benutzername.'; $msgType = 'error';
    } elseif (strlen($pw) < 8) {
        $message = 'Passwort muss mindestens 8 Zeichen lang sein.'; $msgType = 'error';
    } else {
        try {
            createUser($username, $pw, $role);
            $message = "Benutzer '$username' erstellt."; $msgType = 'ok';
        } catch (Exception $e) {
            $message = 'Benutzername bereits vergeben.'; $msgType = 'error';
        }
    }
}

if ($action === 'toggle_user') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id && $id !== $me['id']) {
        toggleUserActive($id);
        $message = 'Status geändert.'; $msgType = 'ok';
    } else {
        $message = 'Eigener Account kann nicht deaktiviert werden.'; $msgType = 'error';
    }
}

if ($action === 'change_password') {
    $id  = (int)($_POST['id'] ?? 0);
    $pw  = $_POST['new_password'] ?? '';
    $pw2 = $_POST['new_password2'] ?? '';
    if (!$id) {
        $message = 'Ungültige ID.'; $msgType = 'error';
    } elseif (strlen($pw) < 8) {
        $message = 'Passwort muss mindestens 8 Zeichen lang sein.'; $msgType = 'error';
    } elseif ($pw !== $pw2) {
        $message = 'Passwörter stimmen nicht überein.'; $msgType = 'error';
    } else {
        updateUserPassword($id, $pw);
        deleteAllUserTokens($id); // Invalidate all remember-tokens
        $message = 'Passwort geändert. Alle "Angemeldet bleiben"-Sessions wurden beendet.'; $msgType = 'ok';
    }
}

if ($action === 'delete_user') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id && $id !== $me['id']) {
        deleteAllUserTokens($id);
        deleteUser($id);
        $message = 'Benutzer gelöscht.'; $msgType = 'ok';
    } else {
        $message = 'Eigener Account kann nicht gelöscht werden.'; $msgType = 'error';
    }
}

$users        = getAllUsers();
$loginLog     = getLoginLog(100);
$mailTemplates = getMailTemplatesRaw();
$tab          = in_array($_GET['tab'] ?? '', ['users', 'log', 'branding', 'templates'], true) ? $_GET['tab'] : 'users';

// Branding-Formular vorbelegen: gespeicherter Wert, sonst config.php-Default
$cfgFile = appConfig();
$stored  = getAllSettings();
$brand   = [];
foreach ($BRAND_FIELDS as $key => $f) {
    $brand[$key] = array_key_exists($key, $stored)
        ? (string) $stored[$key]
        : (string) ($cfgFile[$key] ?? '');
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmtDate(?string $dt): string {
    if (!$dt) return '—';
    return date('d.m.Y H:i', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<title><?= htmlspecialchars(pageTitle('Admin')) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:      #080b10;
    --surface: #0f1420;
    --card:    #141926;
    --border:  #1e2740;
    --border-hi:#2e3f60;
    --text:    #e2e8f0;
    --muted:   #64748b;
    --accent:  #3b82f6;
    --green:   #22c55e;
    --red:     #ef4444;
    --radius:  8px;
}

body { background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif; font-size: 14px; min-height: 100vh; }

a { color: inherit; text-decoration: none; }


/* Layout */
.wrap { max-width: 1100px; margin: 0 auto; padding: 32px 24px; }

/* Page title */
.page-head { margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.page-title { font-size: 22px; font-weight: 700; color: #f1f5f9; letter-spacing: -.02em; }
.page-subtitle { font-size: 13px; color: var(--muted); margin-top: 3px; }

/* Alert */
.alert { border-radius: var(--radius); padding: 12px 16px; font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
.alert-ok    { background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.2); color: #86efac; }
.alert-error { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25); color: #fca5a5; }

/* Tabs */
.tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--border); margin-bottom: 24px; }
.tab-btn { padding: 10px 18px; font-size: 13px; font-weight: 500; color: var(--muted); border: none; background: none; cursor: pointer; font-family: inherit; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color .15s; }
.tab-btn:hover { color: var(--text); }
.tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }

/* Buttons */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: var(--radius); border: none; cursor: pointer; font-size: 13px; font-family: inherit; font-weight: 500; transition: opacity .15s; }
.btn:hover { opacity: .85; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-ghost   { background: transparent; color: var(--muted); border: 1px solid var(--border); }
.btn-ghost:hover { color: var(--text); }
.btn-danger  { background: rgba(239,68,68,.15); color: #fca5a5; border: 1px solid rgba(239,68,68,.2); }
.btn-warning { background: rgba(245,158,11,.12); color: #fcd34d; border: 1px solid rgba(245,158,11,.2); }
.btn-sm { padding: 4px 10px; font-size: 12px; }

/* Table */
.section { background: var(--card); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.section-head { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.section-title { font-size: 13px; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { color: var(--muted); font-weight: 500; text-align: left; padding: 10px 16px; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid var(--border); }
td { padding: 10px 16px; border-bottom: 1px solid rgba(30,39,64,.7); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: rgba(255,255,255,.02); }

/* Badges */
.badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-admin  { background: rgba(139,92,246,.15); color: #c4b5fd; border: 1px solid rgba(139,92,246,.25); }
.badge-viewer { background: rgba(59,130,246,.1);  color: #93c5fd; border: 1px solid rgba(59,130,246,.2); }
.badge-active   { background: rgba(34,197,94,.1);  color: #86efac; border: 1px solid rgba(34,197,94,.2); }
.badge-inactive { background: rgba(100,116,139,.1); color: #64748b; border: 1px solid rgba(100,116,139,.2); }
.badge-ok   { background: rgba(34,197,94,.1);  color: #86efac; }
.badge-fail { background: rgba(239,68,68,.1);  color: #fca5a5; }

/* Modal */
.overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.7); z-index: 100; align-items: center; justify-content: center; padding: 24px; }
.overlay.open { display: flex; }
.modal { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 28px; width: 100%; max-width: 440px; max-height: 90vh; overflow-y: auto; }
.modal h2 { font-size: 17px; font-weight: 600; color: #f1f5f9; margin-bottom: 20px; letter-spacing: -.01em; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; font-weight: 500; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .03em; }
.form-group input, .form-group select, .form-group textarea {
    width: 100%; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
    padding: 9px 12px; color: var(--text); font-size: 13px; font-family: inherit; outline: none;
    transition: border-color .15s;
}
.form-group textarea { min-height: 90px; resize: vertical; line-height: 1.5; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--accent); }
.form-group .hint { font-size: 11px; color: var(--muted); margin-top: 5px; text-transform: none; letter-spacing: 0; font-weight: 400; }
select option { background: var(--card); }
.modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; }

/* Branding-Formular */
.settings-form { padding: 20px; max-width: 620px; }
.settings-form .form-group label { text-transform: none; letter-spacing: 0; font-size: 12px; font-weight: 600; color: var(--text); }
.settings-form .modal-actions { justify-content: flex-start; }

/* Vorlagen-Editor */
.tpl-wrap { padding: 20px; }
.tpl-legend { font-size: 12px; color: var(--muted); margin-bottom: 16px; line-height: 1.7; }
.tpl-legend code { margin-right: 4px; }
.tpl-item { border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 10px; background: var(--surface); }
.tpl-item > summary { padding: 12px 16px; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--text); list-style: none; display: flex; align-items: center; gap: 8px; }
.tpl-item > summary::-webkit-details-marker { display: none; }
.tpl-item > summary::before { content: '▸'; color: var(--muted); font-size: 11px; }
.tpl-item[open] > summary::before { content: '▾'; }
.tpl-item > summary .tpl-key { font-family: monospace; font-size: 11px; color: var(--muted); font-weight: 400; }
.tpl-body { padding: 4px 16px 16px; }
.tpl-body .form-group input, .tpl-body .form-group textarea { background: var(--bg); }
.tpl-body textarea { min-height: 230px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; white-space: pre; overflow-wrap: normal; overflow-x: auto; }
.tpl-actions { display: flex; gap: 8px; align-items: center; margin-top: 12px; }
.tpl-item-new { border-color: var(--border-hi); }

/* Log */
code { background: var(--bg); padding: 2px 7px; border-radius: 4px; font-family: monospace; color: #93c5fd; font-size: 12px; }

/* ── Mobil ── */
@media (max-width: 640px) {
    .wrap { padding: 20px 14px; }
    .section { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { min-width: 680px; }
    th, td { padding: 8px 12px; }
    .tabs { overflow-x: auto; scrollbar-width: none; }
    .tabs::-webkit-scrollbar { display: none; }
    .tab-btn { white-space: nowrap; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../app/helpers/nav.php'; ?>

<div class="wrap">

    <div class="page-head">
        <div>
            <div class="page-title">Administration</div>
            <div class="page-subtitle">Benutzerverwaltung und Login-Log</div>
        </div>
        <button class="btn btn-primary" onclick="document.getElementById('createModal').classList.add('open')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Benutzer anlegen
        </button>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>">
        <?= h($message) ?>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn <?= $tab === 'users' ? 'active' : '' ?>" data-tab="users" onclick="switchTab('users')">
            Benutzer (<?= count($users) ?>)
        </button>
        <button class="tab-btn <?= $tab === 'log' ? 'active' : '' ?>" data-tab="log" onclick="switchTab('log')">
            Login-Log
        </button>
        <button class="tab-btn <?= $tab === 'branding' ? 'active' : '' ?>" data-tab="branding" onclick="switchTab('branding')">
            Branding
        </button>
        <button class="tab-btn <?= $tab === 'templates' ? 'active' : '' ?>" data-tab="templates" onclick="switchTab('templates')">
            Vorlagen (<?= count($mailTemplates) ?>)
        </button>
    </div>

    <!-- Users Tab -->
    <div id="tab-users" <?= $tab !== 'users' ? 'style="display:none"' : '' ?>>
        <div class="section">
            <div class="section-head">
                <span class="section-title">Alle Benutzer</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Benutzername</th>
                        <th>Rolle</th>
                        <th>Status</th>
                        <th>Erstellt</th>
                        <th>Letzter Login</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <strong style="font-family:monospace"><?= h($u['username']) ?></strong>
                        <?php if ($u['id'] == $me['id']): ?>
                        <span style="font-size:11px;color:var(--muted);margin-left:6px">(Du)</span>
                        <?php endif; ?>
                        <?php if (!empty($u['full_name'])): ?>
                        <div style="font-size:12px;color:var(--muted);margin-top:2px"><?= h($u['full_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $u['role'] ?>"><?= h($u['role']) ?></span>
                    </td>
                    <td>
                        <span class="badge badge-<?= $u['active'] ? 'active' : 'inactive' ?>">
                            <?= $u['active'] ? 'Aktiv' : 'Inaktiv' ?>
                        </span>
                    </td>
                    <td style="color:var(--muted);font-size:12px"><?= fmtDate($u['created_at']) ?></td>
                    <td style="color:var(--muted);font-size:12px"><?= fmtDate($u['last_login']) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;justify-content:flex-end">
                            <button class="btn btn-ghost btn-sm"
                                onclick="openPwModal(<?= $u['id'] ?>, '<?= h($u['username']) ?>')">
                                Passwort
                            </button>
                            <?php if ($u['id'] != $me['id']): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Status ändern?')">
                                <input type="hidden" name="action" value="toggle_user">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-warning btn-sm">
                                    <?= $u['active'] ? 'Deaktivieren' : 'Aktivieren' ?>
                                </button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Benutzer wirklich löschen?')">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Löschen</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Log Tab -->
    <div id="tab-log" <?= $tab !== 'log' ? 'style="display:none"' : '' ?>>
        <div class="section">
            <div class="section-head">
                <span class="section-title">Login-Versuche (letzte 100)</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Zeit</th>
                        <th>IP</th>
                        <th>Benutzername</th>
                        <th>Ergebnis</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($loginLog as $entry): ?>
                <tr>
                    <td style="color:var(--muted);font-size:12px"><?= fmtDate($entry['attempted_at']) ?></td>
                    <td><code><?= h($entry['ip']) ?></code></td>
                    <td style="font-size:13px"><?= $entry['username'] ? h($entry['username']) : '<span style="color:var(--muted)">—</span>' ?></td>
                    <td>
                        <?php if ($entry['success']): ?>
                        <span class="badge badge-ok">Erfolg</span>
                        <?php else: ?>
                        <span class="badge badge-fail">Fehlgeschlagen</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($loginLog)): ?>
                <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:24px">Noch keine Einträge.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Branding Tab -->
    <div id="tab-branding" <?= $tab !== 'branding' ? 'style="display:none"' : '' ?>>
        <div class="section">
            <div class="section-head">
                <span class="section-title">Branding &amp; Individualisierung</span>
            </div>
            <form method="POST" action="?tab=branding" class="settings-form">
                <input type="hidden" name="action" value="save_settings">
                <p style="font-size:12px;color:var(--muted);margin-bottom:18px;line-height:1.6">
                    Diese Werte überschreiben die Defaults aus <code>app/config/config.php</code>.
                    Zugangsdaten (Datenbank, SMTP, IMAP), die Absenderadresse und das Reportnummer-Prefix
                    bleiben bewusst nur in der Datei.
                </p>
                <?php foreach ($BRAND_FIELDS as $key => $f): ?>
                <div class="form-group">
                    <label for="f_<?= h($key) ?>"><?= h($f['label']) ?></label>
                    <?php if ($f['type'] === 'textarea'): ?>
                    <textarea id="f_<?= h($key) ?>" name="<?= h($key) ?>" maxlength="<?= (int)$f['max'] ?>" placeholder="<?= h($f['ph']) ?>"><?= h($brand[$key]) ?></textarea>
                    <?php else: ?>
                    <input type="text" id="f_<?= h($key) ?>" name="<?= h($key) ?>" maxlength="<?= (int)$f['max'] ?>" autocomplete="off" placeholder="<?= h($f['ph']) ?>" value="<?= h($brand[$key]) ?>">
                    <?php endif; ?>
                    <?php if (!empty($f['help'])): ?>
                    <div class="hint"><?= h($f['help']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Vorlagen Tab -->
    <div id="tab-templates" <?= $tab !== 'templates' ? 'style="display:none"' : '' ?>>
        <div class="section">
            <div class="section-head">
                <span class="section-title">Mail-Vorlagen</span>
                <form method="POST" action="?tab=templates" onsubmit="return confirm('Alle Vorlagen auf den eingebauten Standard zurücksetzen? Eigene Änderungen und neue Vorlagen gehen verloren.')">
                    <input type="hidden" name="action" value="reset_templates">
                    <button type="submit" class="btn btn-warning btn-sm">Auf Standard zurücksetzen</button>
                </form>
            </div>
            <div class="tpl-wrap">
                <div class="tpl-legend">
                    Platzhalter (werden beim Entwurf ersetzt):
                    <?php foreach (ABUSE_TEMPLATE_PLACEHOLDERS as $ph): ?><code>{<?= h($ph) ?>}</code><?php endforeach; ?><br>
                    <code>{reporter}</code> = Name + Rolle des Report-Erstellers ·
                    <code>{evidence}</code> = Belege/Notiz aus dem Report ·
                    <code>{org}</code> aus dem Branding-Tab.
                </div>

                <?php foreach ($mailTemplates as $key => $t): ?>
                <details class="tpl-item">
                    <summary>
                        <?= h($t['label']) ?>
                        <span class="tpl-key"><?= h($key) ?><?= $key === 'generic' ? ' · Fallback' : '' ?></span>
                    </summary>
                    <div class="tpl-body">
                        <form method="POST" action="?tab=templates">
                            <input type="hidden" name="action" value="save_template">
                            <input type="hidden" name="key" value="<?= h($key) ?>">
                            <input type="hidden" name="sort" value="<?= (int) $t['sort'] ?>">
                            <div class="form-group">
                                <label>Bezeichnung</label>
                                <input type="text" name="label" maxlength="120" value="<?= h($t['label']) ?>">
                            </div>
                            <div class="form-group">
                                <label>Betreff</label>
                                <input type="text" name="subject" maxlength="255" value="<?= h($t['subject']) ?>">
                            </div>
                            <div class="form-group">
                                <label>Text</label>
                                <textarea name="body"><?= h($t['body']) ?></textarea>
                            </div>
                            <div class="tpl-actions">
                                <button type="submit" class="btn btn-primary btn-sm">Speichern</button>
                                <span class="hint" style="margin:0 0 0 auto">zuletzt geändert <?= fmtDate($t['updated_at']) ?></span>
                            </div>
                        </form>
                        <?php if ($key !== 'generic'): ?>
                        <form method="POST" action="?tab=templates" style="margin-top:8px"
                              onsubmit="return confirm('Vorlage &quot;<?= h($key) ?>&quot; löschen?')">
                            <input type="hidden" name="action" value="delete_template">
                            <input type="hidden" name="key" value="<?= h($key) ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Vorlage löschen</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endforeach; ?>

                <details class="tpl-item tpl-item-new">
                    <summary>Neue Vorlage&nbsp;<span class="tpl-key">+</span></summary>
                    <div class="tpl-body">
                        <form method="POST" action="?tab=templates">
                            <input type="hidden" name="action" value="create_template">
                            <div class="form-group">
                                <label>Schlüssel</label>
                                <input type="text" name="key" maxlength="40" placeholder="z.B. ddos" autocomplete="off">
                                <div class="hint">Klein, ohne Leerzeichen: a–z, 0–9, _ oder -. Nicht mehr änderbar.</div>
                            </div>
                            <div class="form-group">
                                <label>Bezeichnung</label>
                                <input type="text" name="label" maxlength="120" placeholder="z.B. DDoS-Angriff">
                            </div>
                            <div class="form-group">
                                <label>Betreff</label>
                                <input type="text" name="subject" maxlength="255" placeholder="Abuse Report {ref} — {ip}">
                            </div>
                            <div class="form-group">
                                <label>Text</label>
                                <textarea name="body" placeholder="Hello,&#10;&#10;..."></textarea>
                            </div>
                            <div class="tpl-actions">
                                <button type="submit" class="btn btn-primary btn-sm">Anlegen</button>
                            </div>
                        </form>
                    </div>
                </details>
            </div>
        </div>
    </div>

</div><!-- /wrap -->

<!-- Create User Modal -->
<div class="overlay" id="createModal">
    <div class="modal">
        <h2>Neuen Benutzer anlegen</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create_user">
            <div class="form-group">
                <label>Benutzername</label>
                <input type="text" name="username" autocomplete="off" placeholder="z.B. max" autofocus>
            </div>
            <div class="form-group">
                <label>Rolle</label>
                <select name="role">
                    <option value="viewer">Viewer — Nur lesen</option>
                    <option value="admin">Admin — Voller Zugriff</option>
                </select>
            </div>
            <div class="form-group">
                <label>Passwort</label>
                <input type="password" name="password" autocomplete="new-password" placeholder="Mindestens 8 Zeichen">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('createModal').classList.remove('open')">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Anlegen</button>
            </div>
        </form>
    </div>
</div>

<!-- Change Password Modal -->
<div class="overlay" id="pwModal">
    <div class="modal">
        <h2>Passwort ändern: <span id="pwModalUser" style="color:var(--accent)"></span></h2>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="id" id="pwModalId">
            <div class="form-group">
                <label>Neues Passwort</label>
                <input type="password" name="new_password" autocomplete="new-password" placeholder="Mindestens 8 Zeichen">
            </div>
            <div class="form-group">
                <label>Passwort wiederholen</label>
                <input type="password" name="new_password2" autocomplete="new-password" placeholder="Passwort bestätigen">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('pwModal').classList.remove('open')">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(tab) {
    ['users', 'log', 'branding', 'templates'].forEach(t => {
        document.getElementById('tab-' + t).style.display = t === tab ? '' : 'none';
    });
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    history.replaceState(null, '', '?tab=' + tab);
}
function openPwModal(id, username) {
    document.getElementById('pwModalId').value   = id;
    document.getElementById('pwModalUser').textContent = username;
    document.getElementById('pwModal').classList.add('open');
}
document.querySelectorAll('.overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.overlay.open').forEach(o => o.classList.remove('open'));
});
</script>
</body>
</html>
