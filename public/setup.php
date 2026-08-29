<?php
require_once __DIR__ . '/../app/helpers/auth.php';

// Only accessible on first run
if (userCount() > 0) {
    header('Location: /');
    exit;
}

$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $pw       = $_POST['password']  ?? '';
    $pw2      = $_POST['password2'] ?? '';

    if (strlen($username) < 3) {
        $error = 'Benutzername muss mindestens 3 Zeichen lang sein.';
    } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
        $error = 'Benutzername darf nur Buchstaben, Zahlen, _ und - enthalten.';
    } elseif (strlen($pw) < 8) {
        $error = 'Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($pw !== $pw2) {
        $error = 'Passwörter stimmen nicht überein.';
    } else {
        createUser($username, $pw, 'admin');
        $done = true;
    }
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
<title><?= htmlspecialchars(pageTitle('Ersteinrichtung')) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg: #080b10; --card: #141926; --border: #1e2740;
    --text: #e2e8f0; --muted: #64748b; --accent: #3b82f6;
    --green: #22c55e; --red: #ef4444;
}
body {
    background: var(--bg); color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    font-size: 14px; min-height: 100vh;
    display: flex; align-items: center; justify-content: center; padding: 24px;
}
.wrap { width: 100%; max-width: 400px; }
.badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.2);
    color: #86efac; border-radius: 20px; padding: 4px 14px;
    font-size: 12px; font-weight: 500; letter-spacing: .04em;
    text-transform: uppercase; margin-bottom: 24px;
}
.badge-dot { width: 6px; height: 6px; background: #22c55e; border-radius: 50%; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 32px; }
h1 { font-size: 20px; font-weight: 600; color: #f1f5f9; margin-bottom: 6px; letter-spacing: -.02em; }
.subtitle { font-size: 13px; color: var(--muted); margin-bottom: 28px; line-height: 1.6; }
.alert-error {
    background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25);
    color: #fca5a5; border-radius: 8px; padding: 12px 14px; font-size: 13px; margin-bottom: 20px;
}
.alert-success {
    background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.2);
    color: #86efac; border-radius: 8px; padding: 16px; font-size: 14px; text-align: center; line-height: 1.7;
}
.alert-success a { color: #4ade80; font-weight: 600; text-decoration: none; }
.alert-success a:hover { text-decoration: underline; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; font-weight: 500; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .03em; }
.form-group input {
    width: 100%; background: #0f1420; border: 1px solid var(--border);
    border-radius: 8px; padding: 10px 13px; color: var(--text);
    font-size: 14px; font-family: inherit; outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.form-group input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.hint { font-size: 12px; color: #334155; margin-top: 5px; }
.btn {
    width: 100%; padding: 11px; background: var(--accent); color: #fff;
    border: none; border-radius: 8px; font-size: 14px; font-weight: 600;
    font-family: inherit; cursor: pointer; transition: opacity .15s;
}
.btn:hover { opacity: .9; }
.divider { border: none; border-top: 1px solid var(--border); margin: 24px 0; }
.info-list { list-style: none; display: flex; flex-direction: column; gap: 8px; }
.info-list li {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; color: var(--muted);
}
.info-list li svg { color: var(--accent); flex-shrink: 0; }
</style>
</head>
<body>
<div class="wrap">

    <div style="text-align:center; margin-bottom: 8px;">
        <div class="badge"><span class="badge-dot"></span>Ersteinrichtung</div>
    </div>

    <div class="card">
        <h1>Admin-Account anlegen</h1>
        <p class="subtitle">Erstelle den ersten Administrator-Account um die Plattform zu nutzen. Weitere Benutzer können danach im Admin-Panel hinzugefügt werden.</p>

        <?php if ($done): ?>
        <div class="alert-success">
            Account erstellt.<br>
            <a href="/login.php">Jetzt anmelden &rarr;</a>
        </div>
        <?php else: ?>

        <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="username">Benutzername</label>
                <input type="text" id="username" name="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username" autofocus placeholder="admin">
                <div class="hint">Buchstaben, Zahlen, _ und - erlaubt</div>
            </div>
            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" placeholder="Mindestens 8 Zeichen">
            </div>
            <div class="form-group">
                <label for="password2">Passwort wiederholen</label>
                <input type="password" id="password2" name="password2"
                       autocomplete="new-password" placeholder="Passwort bestätigen">
            </div>
            <button type="submit" class="btn">Admin-Account erstellen</button>
        </form>

        <hr class="divider">
        <ul class="info-list">
            <li>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Rolle: Administrator (voller Zugriff)
            </li>
            <li>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Weitere Nutzer im Admin-Panel verwaltbar
            </li>
            <li>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Diese Seite ist danach nicht mehr erreichbar
            </li>
        </ul>

        <?php endif; ?>
    </div>
</div>
</body>
</html>
