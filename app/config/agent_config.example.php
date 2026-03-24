<?php
// =============================================================================
//  Zentrale Konfiguration für auth-monitor Agenten
//  Kopiere diese Datei nach agent_config.php und trage deine Werte ein.
// =============================================================================
return [
    // MySQL-Datenbank
    'db_host'               => 'your-db-host',
    'db_port'               => '3306',
    'db_user'               => 'db-user',
    'db_pass'               => 'db-password',
    'db_name'               => 'db-name',

    // IP-Info API (ipinfo.io)
    'ipinfo_token'          => 'your-ipinfo-token',
    'ip_cache_days'         => '7',

    // Slack Alerts
    'slack_enabled'         => 'false',
    'slack_webhook_url'     => '',

    // E-Mail Alerts
    'email_enabled'         => 'false',
    'email_to'              => 'abuse@example.com',
    'email_from'            => 'no-reply@example.com',

    // Schwellenwerte
    'brute_force_threshold' => '10',    // Fehlversuche bis zum Alert
    'brute_force_window'    => '300',   // Zeitfenster in Sekunden
    'enum_threshold'        => '5',     // Distinct Usernames bis Enum-Alert
    'alert_cooldown'        => '3600',  // Sekunden zwischen Re-Alerts pro IP

    // Auto-Block
    'min_block_fails'       => '5',     // Mindest-Fehlversuche für iptables-Block
    'block_window_days'     => '30',    // Tage rückwirkend für Block-Abfrage

    // Webhook (auth-report.sh / report.php)
    'webhook_url'           => 'http://your-server:8765',
    'webhook_token'         => 'your-webhook-token',
];
