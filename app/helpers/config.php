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

/** Einzelwert mit Default. */
function cfg(string $key, $default = null) {
    $c = appConfig();
    return $c[$key] ?? $default;
}

// ── Branding (alles über app/config/config.php anpassbar) ─────────────────────

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
 * Footer-Inhalt als HTML. 'footer_html' in der Config überschreibt alles
 * (HTML erlaubt, gilt als vertrauenswürdig – nur Admin editiert die Config).
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
