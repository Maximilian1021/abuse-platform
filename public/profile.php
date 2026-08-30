<?php
require_once __DIR__ . '/../app/helpers/auth.php';
requireAuth();

$me        = currentUser();
$navActive = '';
$message   = '';
$msgType   = '';

if (($_POST['action'] ?? '') === 'save_profile') {
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $fullName = strip_tags($fullName);
    if (mb_strlen($fullName) > 120) $fullName = mb_substr($fullName, 0, 120);

    updateUserFullName((int) $me['id'], $fullName);

    // Session-Kopie aktualisieren, damit Nav / Signatur sofort stimmen
    startSecureSession();
    $_SESSION[AUTH_SESSION_KEY]['full_name'] = $fullName;
    $me['full_name'] = $fullName;

    $message = 'Profil gespeichert.'; $msgType = 'ok';
}

// Frischen Stand laden (falls die Session-Kopie veraltet ist)
$fresh    = getUserById((int) $me['id']) ?? $me;
$fullName = (string) ($fresh['full_name'] ?? '');
$display  = trim($fullName) ?: (string) $fresh['username'];
$roleTxt  = roleLabel((string) ($fresh['role'] ?? ''));
$sigLine  = $roleTxt !== '' ? "{$display} ({$roleTxt})" : $display;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<title><?= h(pageTitle('Profil')) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg:#080b10; --surface:#0f1420; --card:#141926; --border:#1e2740;
    --text:#e2e8f0; --muted:#64748b; --accent:#3b82f6; --radius:8px;
}
body { background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif; font-size: 14px; min-height: 100vh; }
a { color: inherit; text-decoration: none; }
.wrap { max-width: 620px; margin: 0 auto; padding: 32px 24px; }
.page-title { font-size: 22px; font-weight: 700; color: #f1f5f9; letter-spacing: -.02em; margin-bottom: 4px; }
.page-subtitle { font-size: 13px; color: var(--muted); margin-bottom: 24px; }
.alert { border-radius: var(--radius); padding: 12px 16px; font-size: 13px; margin-bottom: 20px; }
.alert-ok    { background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.2); color: #86efac; }
.alert-error { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25); color: #fca5a5; }
.section { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 24px; }
.form-group { margin-bottom: 18px; }
.form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--text); margin-bottom: 6px; }
.form-group input {
    width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
    padding: 9px 12px; color: var(--text); font-size: 13px; font-family: inherit; outline: none; transition: border-color .15s;
}
.form-group input:focus { border-color: var(--accent); }
.form-group input[readonly] { color: var(--muted); cursor: not-allowed; }
.hint { font-size: 11px; color: var(--muted); margin-top: 5px; }
.preview { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 14px; margin-bottom: 18px; }
.preview .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin-bottom: 6px; }
.preview .val { font-family: monospace; font-size: 13px; color: #93c5fd; }
.btn { display: inline-flex; align-items: center; padding: 8px 16px; border-radius: var(--radius); border: none; cursor: pointer; font-size: 13px; font-family: inherit; font-weight: 500; background: var(--accent); color: #fff; }
.btn:hover { opacity: .9; }
.role-badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; background: rgba(139,92,246,.15); color: #c4b5fd; border: 1px solid rgba(139,92,246,.25); }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../app/helpers/nav.php'; ?>

<div class="wrap">
    <div class="page-title">Mein Profil</div>
    <div class="page-subtitle">Anzeigename für die Abuse-Mail-Signatur</div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <div class="section">
        <div class="preview">
            <div class="lbl">So erscheinst du in der Signatur</div>
            <div class="val"><?= h($sigLine) ?></div>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="save_profile">

            <div class="form-group">
                <label>Login-Name</label>
                <input type="text" value="<?= h($fresh['username']) ?>" readonly>
            </div>

            <div class="form-group">
                <label>Rolle</label>
                <div><span class="role-badge"><?= h($roleTxt ?: $fresh['role']) ?></span></div>
                <div class="hint">Die Rolle vergibt ein Administrator.</div>
            </div>

            <div class="form-group">
                <label for="full_name">Anzeigename</label>
                <input type="text" id="full_name" name="full_name" maxlength="120" autocomplete="name"
                       placeholder="z.B. Maximilian P." value="<?= h($fullName) ?>">
                <div class="hint">Erscheint als Absender in der Signatur der Abuse-Mails deiner Reports.
                    Leer = dein Login-Name wird verwendet.</div>
            </div>

            <button type="submit" class="btn">Speichern</button>
        </form>
    </div>
</div>

</body>
</html>
