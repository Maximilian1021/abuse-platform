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
