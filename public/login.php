<?php
require_once __DIR__ . '/../app/helpers/auth.php';

// Already logged in
if (currentUser()) {
    header('Location: /');
    exit;
}

// First run
if (userCount() === 0) {
    header('Location: ' . AUTH_SETUP_URL);
    exit;
}

$error    = '';
$redirect = $_GET['redirect'] ?? '/';
$ip       = clientIp();
$locked   = getFailedAttempts($ip, AUTH_LOCKOUT_MINUTES) >= AUTH_MAX_ATTEMPTS;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    $result = authLogin(
        trim($_POST['username'] ?? ''),
        $_POST['password'] ?? '',
        !empty($_POST['remember']),
        $ip
    );
    if ($result['ok']) {
        $safe = filter_var($redirect, FILTER_VALIDATE_URL) === false ? $redirect : '/';
        header('Location: ' . $safe);
        exit;
    }
    $error  = $result['error'];
    $locked = getFailedAttempts($ip, AUTH_LOCKOUT_MINUTES) >= AUTH_MAX_ATTEMPTS;
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
<title>Login — Abuse Platform</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:      #080b10;
    --surface: #0f1420;
    --card:    #141926;
    --border:  #1e2740;
    --text:    #e2e8f0;
    --muted:   #64748b;
    --accent:  #3b82f6;
    --red:     #ef4444;
    --radius:  10px;
}

body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    font-size: 14px;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}

/* Glow blobs */
body::after {
    content: '';
    position: fixed;
    width: 500px;
    height: 500px;
    background: #1d4ed8;
    border-radius: 50%;
    filter: blur(140px);
    opacity: .12;
    top: -150px;
    left: 50%;
    transform: translateX(-50%);
    pointer-events: none;
    z-index: 0;
}

.wrap {
    width: 100%;
    max-width: 380px;
    position: relative;
    z-index: 1;
}

/* Logo */
.logo {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 32px;
}
.logo-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #1d4ed8, #7c3aed);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.logo-icon svg { width: 22px; height: 22px; }
.logo-text {
    font-size: 17px;
    font-weight: 600;
    color: #f1f5f9;
    letter-spacing: -.01em;
}

/* Card */
.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 32px;
}

h1 {
    font-size: 20px;
    font-weight: 600;
    color: #f1f5f9;
    margin-bottom: 6px;
    letter-spacing: -.02em;
}
.subtitle {
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 28px;
}

/* Alert boxes */
.alert {
    border-radius: 8px;
    padding: 12px 14px;
    font-size: 13px;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    line-height: 1.5;
}
.alert-error {
    background: rgba(239, 68, 68, .08);
    border: 1px solid rgba(239, 68, 68, .25);
    color: #fca5a5;
}
.alert-locked {
    background: rgba(245, 158, 11, .08);
    border: 1px solid rgba(245, 158, 11, .25);
    color: #fcd34d;
}
.alert svg { flex-shrink: 0; margin-top: 1px; }

/* Form */
.form-group {
    margin-bottom: 16px;
}
.form-group label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--muted);
    margin-bottom: 6px;
    letter-spacing: .03em;
    text-transform: uppercase;
}
.form-group input {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 13px;
    color: var(--text);
    font-size: 14px;
    font-family: inherit;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.form-group input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, .12);
}
.form-group input::placeholder { color: #334155; }

/* Remember me */
.remember {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 24px;
    cursor: pointer;
    user-select: none;
}
.remember input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--accent);
    cursor: pointer;
    flex-shrink: 0;
}
.remember span {
    font-size: 13px;
    color: var(--muted);
}

/* Submit */
.btn-submit {
    width: 100%;
    padding: 11px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: opacity .15s, box-shadow .15s;
    letter-spacing: .01em;
}
.btn-submit:hover {
    opacity: .9;
    box-shadow: 0 4px 20px rgba(59, 130, 246, .3);
}
.btn-submit:disabled {
    opacity: .5;
    cursor: not-allowed;
    box-shadow: none;
}

.footer-note {
    text-align: center;
    margin-top: 20px;
    font-size: 12px;
    color: #1e2740;
}
</style>
</head>
<body>
<div class="wrap">

    <div class="logo">
        <div class="logo-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
        </div>
        <div class="logo-text">Abuse Platform</div>
    </div>

    <div class="card">
        <h1>Anmelden</h1>
        <p class="subtitle">Zugang zur internen Monitoring-Plattform</p>

        <?php if ($locked): ?>
        <div class="alert alert-locked">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            Zu viele Fehlversuche. Bitte <?= AUTH_LOCKOUT_MINUTES ?> Minuten warten.
        </div>
        <?php elseif ($error): ?>
        <div class="alert alert-error">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

            <div class="form-group">
                <label for="username">Benutzername</label>
                <input type="text" id="username" name="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username"
                       <?= $locked ? 'disabled' : 'autofocus' ?>>
            </div>

            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password"
                       <?= $locked ? 'disabled' : '' ?>>
            </div>

            <label class="remember">
                <input type="checkbox" name="remember" value="1" <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
                <span>Angemeldet bleiben (30 Tage)</span>
            </label>

            <button type="submit" class="btn-submit" <?= $locked ? 'disabled' : '' ?>>
                Anmelden
            </button>
        </form>
    </div>

    <div class="footer-note">abuse.max1021.de &mdash; Internal Use Only</div>
</div>
</body>
</html>
