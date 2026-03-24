<?php
session_start();

define('CONFIG_FILE', __DIR__ . '/../../app/config/config.php');

// Already configured → redirect
if (file_exists(CONFIG_FILE)) {
    header('Location: index.php');
    exit;
}


$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = $_POST['password']  ?? '';
    $pw2 = $_POST['password2'] ?? '';

    if (strlen($pw) < 6) {
        $error = 'Passwort muss mindestens 6 Zeichen lang sein.';
    } elseif ($pw !== $pw2) {
        $error = 'Passwörter stimmen nicht überein.';
    } else {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $content = "<?php\ndefine('APP_PASSWORD_HASH', " . var_export($hash, true) . ");\n";
        file_put_contents(CONFIG_FILE, $content);
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
<title>Setup — Abuse Report Tool</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: #0d0d0d;
    color: #e0e0e0;
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-size: 14px;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}
.card {
    background: #1a1a1a;
    border: 1px solid #2a2a2a;
    border-radius: 10px;
    padding: 32px;
    width: 380px;
    max-width: 95vw;
}
.logo { font-size: 36px; text-align: center; margin-bottom: 8px; }
h1 { font-size: 18px; text-align: center; margin-bottom: 4px; }
.sub { text-align: center; color: #888; font-size: 13px; margin-bottom: 24px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12px; color: #888; margin-bottom: 5px; }
.form-group input {
    width: 100%;
    background: #111;
    border: 1px solid #2a2a2a;
    border-radius: 8px;
    padding: 9px 12px;
    color: #e0e0e0;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    transition: border-color .15s;
}
.form-group input:focus { border-color: #e74c3c; }
.btn {
    width: 100%;
    padding: 10px;
    background: #e74c3c;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    margin-top: 4px;
}
.btn:hover { opacity: .88; }
.error {
    background: #c0392b22;
    border: 1px solid #c0392b55;
    color: #e74c3c;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    margin-bottom: 16px;
}
.success {
    background: #27ae6022;
    border: 1px solid #27ae6055;
    color: #2ecc71;
    border-radius: 8px;
    padding: 14px;
    text-align: center;
    font-size: 14px;
}
.success a {
    color: #2ecc71;
    font-weight: 600;
    text-decoration: underline;
}
</style>
</head>
<body>
<div class="card">
    <div class="logo">🚨</div>
    <h1>Abuse Report Tool</h1>
    <div class="sub">Ersteinrichtung — Passwort festlegen</div>

    <?php if ($done): ?>
    <div class="success">
        Passwort gesetzt! <a href="index.php">Zur App →</a>
    </div>
    <?php else: ?>
    <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label>Passwort</label>
            <input type="password" name="password" autofocus placeholder="Mindestens 6 Zeichen">
        </div>
        <div class="form-group">
            <label>Passwort wiederholen</label>
            <input type="password" name="password2" placeholder="Passwort bestätigen">
        </div>
        <button type="submit" class="btn">Passwort setzen</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
