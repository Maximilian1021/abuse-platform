<?php
// =============================================================================
//  Agent Config Endpoint
//  GET /api/agent-config.php?token=SERVER_TOKEN
//  Gibt bash-sourceable Konfiguration zurück + updated last_contact
// =============================================================================
require_once __DIR__ . '/../../app/db/auth_db.php';

$token = $_GET['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? '';

if (empty($token)) {
    http_response_code(401);
    exit;
}

$server = getServerByToken($token);
if (!$server) {
    http_response_code(403);
    exit;
}

touchServerContact($token);

$cfg = require __DIR__ . '/../../app/config/agent_config.php';

// Bash-sourceable Format ausgeben
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

foreach ($cfg as $key => $value) {
    $KEY = strtoupper($key);
    $val = addslashes((string)$value);
    echo "{$KEY}=\"{$val}\"\n";
}

// Server-spezifische Werte
echo 'AUTO_BLOCK="' . ($server['auto_block'] ? 'true' : 'false') . '"' . "\n";
echo 'PLATFORM_URL="https://abuse.max1021.de/api"' . "\n";
