<?php
if (defined('APP_CONFIG_LOADED')) return;
define('APP_CONFIG_LOADED', true);

/**
 * Zentrale App-Konfiguration (DB + Mail + Meta).
 * Liest app/config/config.php einmalig und cached das Array.
 */
function appConfig(): array {
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/../config/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('app/config/config.php fehlt — aus config.example.php erstellen.');
        }
        $cfg = require $path;
    }
    return $cfg;
}

/**
 * Keys, die ein Admin über die GUI (Tabelle platform_settings) überschreiben darf.
 * Alles andere (DB-/SMTP-/IMAP-Zugang, Absender-Adresse, Reportnummer-Prefix)
 * bleibt bewusst datei-only.
 */
const CONFIG_DB_KEYS = [
    'site_name', 'site_domain', 'footer_html',
    'login_note', 'mail_org', 'reporter_name', 'abuse_from_name',
];

/**
 * Overlay aus platform_settings — einmalig pro Request, best effort.
 * Fehlt die Tabelle oder ist die DB nicht erreichbar, greift lautlos die config.php.
 */
function configOverlay(): array {
    static $overlay = null;
    if ($overlay !== null) return $overlay;
    $overlay = [];
    try {
        if (function_exists('getMySQL')) {
            $rows = getMySQL()
                ->query("SELECT `key`, `value` FROM platform_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $k => $v) {
                if (in_array($k, CONFIG_DB_KEYS, true)) $overlay[$k] = $v;
            }
        }
    } catch (\Throwable $e) {
        $overlay = [];
    }
    return $overlay;
}

/** Einzelwert mit Default. GUI-Einstellung (platform_settings) schlägt config.php. */
function cfg(string $key, $default = null) {
    if (in_array($key, CONFIG_DB_KEYS, true)) {
        $ov = configOverlay();
        if (array_key_exists($key, $ov)) return $ov[$key];
    }
    $c = appConfig();
    return $c[$key] ?? $default;
}

// ── Branding (Defaults in config.php, live editierbar unter Admin → Branding) ──

/** Produktname (Nav-Marke, Seitentitel). */
function siteName(): string {
    return trim((string) (cfg('site_name', 'Abuse Platform') ?: 'Abuse Platform'));
}

/** Domain / Organisation für Footer + Titel (leer erlaubt). */
function siteDomain(): string {
    return trim((string) cfg('site_domain', ''));
}

/** Kurze Fußzeile auf der Login-Seite. */
function loginNote(): string {
    return trim((string) cfg('login_note', 'Internal Use Only'));
}

/** Label für die Mail-Vorlagen ("<org> infrastructure" / "<org> Abuse Team"). */
function mailOrg(): string {
    return trim((string) (cfg('mail_org', '') ?: (siteDomain() ?: siteName())));
}

/**
 * <title>-Text. Ohne Abschnitt: "Name — Domain" (bzw. nur Name).
 * Mit Abschnitt: "Abschnitt — Name".
 */
function pageTitle(string $section = ''): string {
    if ($section !== '') return $section . ' — ' . siteName();
    $d = siteDomain();
    return $d !== '' ? siteName() . ' — ' . $d : siteName();
}

/**
 * Footer-Inhalt als HTML. 'footer_html' überschreibt alles
 * (HTML erlaubt, gilt als vertrauenswürdig – nur Admin editiert es).
 * Sonst automatisch aus site_name + site_domain.
 */
function footerHtml(): string {
    $custom = trim((string) cfg('footer_html', ''));
    if ($custom !== '') return $custom;
    $name = htmlspecialchars(siteName(), ENT_QUOTES, 'UTF-8');
    $d    = siteDomain();
    return $d !== ''
        ? $name . ' &mdash; <strong>' . htmlspecialchars($d, ENT_QUOTES, 'UTF-8') . '</strong>'
        : $name;
}
