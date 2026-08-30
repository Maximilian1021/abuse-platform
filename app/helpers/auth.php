<?php
if (defined('AUTH_HELPER_LOADED')) return;
define('AUTH_HELPER_LOADED', true);

require_once __DIR__ . '/../db/auth_db.php';

// ── Constants ─────────────────────────────────────────────────────────────────
const AUTH_SESSION_KEY     = 'auth_user';
const AUTH_COOKIE_NAME     = 'remember_token';
const AUTH_COOKIE_DAYS     = 30;
const AUTH_MAX_ATTEMPTS    = 5;
const AUTH_LOCKOUT_MINUTES = 15;
const AUTH_SESSION_TIMEOUT = 28800; // 8 hours

// Adjust if public/ is not your webserver root
const AUTH_LOGIN_URL = '/login.php';
const AUTH_SETUP_URL = '/setup.php';

// ── Session ───────────────────────────────────────────────────────────────────

function startSecureSession(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── Current user ──────────────────────────────────────────────────────────────

function currentUser(): ?array {
    startSecureSession();

    // Active session
    if (!empty($_SESSION[AUTH_SESSION_KEY])) {
        if (isset($_SESSION['auth_last_activity'])
            && (time() - $_SESSION['auth_last_activity']) > AUTH_SESSION_TIMEOUT
        ) {
            session_unset();
            session_destroy();
            return null;
        }
        $_SESSION['auth_last_activity'] = time();
        return $_SESSION[AUTH_SESSION_KEY];
    }

    // Remember-me cookie
    if (!empty($_COOKIE[AUTH_COOKIE_NAME])) {
        $user = getUserByRememberToken($_COOKIE[AUTH_COOKIE_NAME]);
        if ($user) {
            $_SESSION[AUTH_SESSION_KEY] = [
                'id'        => $user['id'],
                'username'  => $user['username'],
                'full_name' => $user['full_name'] ?? '',
                'role'      => $user['role'],
            ];
            $_SESSION['auth_last_activity'] = time();
            updateLastLogin($user['id']);
            return $_SESSION[AUTH_SESSION_KEY];
        }
        // Invalid / expired cookie → clear
        _clearRememberCookie();
    }

    return null;
}

// ── Guards ────────────────────────────────────────────────────────────────────

function requireAuth(): void {
    if (userCount() === 0) {
        header('Location: ' . AUTH_SETUP_URL);
        exit;
    }
    if (!currentUser()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . AUTH_LOGIN_URL . '?redirect=' . $redirect);
        exit;
    }
}

function requireRole(string $role): void {
    requireAuth();
    if ((currentUser()['role'] ?? '') !== $role) {
        http_response_code(403);
        die(_authErrorPage('403 — Zugriff verweigert.'));
    }
}

// Viewer darf nur lesen — alle Schreibaktionen benötigen diese Prüfung
function canWrite(): bool {
    $role = currentUser()['role'] ?? 'viewer';
    return $role !== 'viewer';
}

// Anzeigename einer Rolle (UI-Badges, Mail-Signatur)
function roleLabel(string $role): string {
    return [
        'admin'     => 'Administrator',
        'moderator' => 'Moderator',
        'viewer'    => 'Viewer',
    ][$role] ?? ($role !== '' ? ucfirst($role) : '');
}

// Anzeigename des aktuellen Users (Profil-Name, sonst Login-Name)
function currentUserDisplayName(): string {
    $u = currentUser() ?? [];
    return trim((string)($u['full_name'] ?? '')) ?: (string)($u['username'] ?? '');
}

// ── Login / Logout ────────────────────────────────────────────────────────────

function authLogin(string $username, string $password, bool $remember, string $ip): array {
    $fails = getFailedAttempts($ip, AUTH_LOCKOUT_MINUTES);
    if ($fails >= AUTH_MAX_ATTEMPTS) {
        return ['ok' => false, 'error' => 'Zu viele Fehlversuche. Bitte ' . AUTH_LOCKOUT_MINUTES . ' Minuten warten.'];
    }

    $user = getUserByUsername($username);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        logAttempt($ip, $username, false);
        $remaining = max(0, AUTH_MAX_ATTEMPTS - $fails - 1);
        $msg = $remaining > 0
            ? "Falscher Benutzername oder Passwort. ($remaining Versuch" . ($remaining === 1 ? '' : 'e') . " verbleibend)"
            : 'Zu viele Fehlversuche. Bitte ' . AUTH_LOCKOUT_MINUTES . ' Minuten warten.';
        return ['ok' => false, 'error' => $msg];
    }

    logAttempt($ip, $username, true);
    updateLastLogin($user['id']);

    startSecureSession();
    session_regenerate_id(true);
    $_SESSION[AUTH_SESSION_KEY] = [
        'id'        => $user['id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'] ?? '',
        'role'      => $user['role'],
    ];
    $_SESSION['auth_last_activity'] = time();

    if ($remember) {
        $token = createRememberToken($user['id']);
        setcookie(AUTH_COOKIE_NAME, $token, [
            'expires'  => time() + AUTH_COOKIE_DAYS * 86400,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    return ['ok' => true];
}

function authLogout(): void {
    startSecureSession();
    if (!empty($_COOKIE[AUTH_COOKIE_NAME])) {
        deleteRememberToken($_COOKIE[AUTH_COOKIE_NAME]);
        _clearRememberCookie();
    }
    session_unset();
    session_destroy();
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function _clearRememberCookie(): void {
    setcookie(AUTH_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function _authErrorPage(string $msg): string {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fehler</title>'
         . '<style>body{background:#080b10;color:#ef4444;font-family:monospace;'
         . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
         . '</style></head><body>' . htmlspecialchars($msg) . '</body></html>';
}

function clientIp(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}
