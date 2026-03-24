# Abuse Platform

Zentrales Security-Dashboard für SSH-Angriffserkennung, Abuse-Meldungen und Hoster-Kontaktdaten.

## Module

| Modul | Beschreibung |
|---|---|
| **Auth Monitor** | Echtzeit-Dashboard für SSH-Angriffe, Top-IPs, Hoster-Analyse, Live Events |
| **Report Monitor** | Verwaltung von Abuse-Meldungen mit Verlauf, Status-Tracking, Audit-Trail |
| **Hoster-Datenbank** | Kontaktdaten & Erfahrungen für Abuse-Meldungen je Hoster |

---

## Voraussetzungen

- PHP 8.x mit PDO, cURL, mbstring
- MySQL 8.x
- Webserver (Apache/Nginx) mit `public/` als Document Root
- `msmtp` oder `mail` auf den überwachten Servern (für E-Mail-Alerts)

---

## Installation

### 1. PHP-Konfiguration anlegen

```bash
cp app/config/agent_config.example.php app/config/agent_config.php
cp app/config/config.example.php app/config/config.php
```

**`app/config/agent_config.php`** — Datenbankzugang, API-Token, Webhook:

```php
'db_host'       => 'dein-mysql-host',
'db_port'       => '3306',
'db_user'       => 'db-benutzer',
'db_pass'       => 'db-passwort',
'db_name'       => 'db-name',
'ipinfo_token'  => 'dein-ipinfo.io-token',   // https://ipinfo.io/account
'email_to'      => 'abuse@deine-domain.de',
'email_from'    => 'no-reply@deine-domain.de',
'webhook_url'   => 'http://dein-server:8765',
'webhook_token' => 'dein-webhook-token',
```

**`app/config/config.php`** — App-Passwort-Hash generieren:

```bash
php -r "echo password_hash('dein-passwort', PASSWORD_BCRYPT);"
```

Den Hash in `config.php` eintragen:

```php
define('APP_PASSWORD_HASH', '$2y$10$...');
```

### 2. MySQL-Tabellen

Die Tabellen werden beim ersten Seitenaufruf automatisch angelegt (idempotent). Für die Agenten müssen drei Tabellen manuell erstellt werden:

```sql
CREATE TABLE IF NOT EXISTS auth_events (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    timestamp  DATETIME NOT NULL,
    log_date   DATE NOT NULL,
    ip         VARCHAR(45) NOT NULL,
    username   VARCHAR(100) DEFAULT '',
    event_type VARCHAR(20) NOT NULL,
    server     VARCHAR(100) DEFAULT '',
    INDEX idx_date   (log_date),
    INDEX idx_ip     (ip),
    INDEX idx_server (server)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS daily_stats (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    stat_date     DATE NOT NULL,
    ip            VARCHAR(45) NOT NULL,
    server        VARCHAR(100) DEFAULT '',
    fail_count    INT DEFAULT 0,
    success_count INT DEFAULT 0,
    last_seen     DATETIME,
    usernames_tried TEXT,
    UNIQUE KEY uq_date_ip_server (stat_date, ip, server),
    INDEX idx_date   (stat_date),
    INDEX idx_ip     (ip),
    INDEX idx_server (server)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ip_info (
    ip          VARCHAR(45) PRIMARY KEY,
    country     VARCHAR(10) DEFAULT '',
    city        VARCHAR(100) DEFAULT '',
    region      VARCHAR(100) DEFAULT '',
    org         VARCHAR(255) DEFAULT '',
    hostname    VARCHAR(255) DEFAULT '',
    timezone    VARCHAR(100) DEFAULT '',
    hoster      VARCHAR(255) DEFAULT '',
    asn         VARCHAR(50)  DEFAULT '',
    cached_at   DATETIME,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3. Agenten-Konfiguration anlegen

```bash
cp agents/auth-monitor/auth-monitor.conf.example agents/auth-monitor/auth-monitor.conf
```

**`agents/auth-monitor/auth-monitor.conf`** ausfüllen:

```bash
DB_HOST="dein-mysql-host"
DB_PORT="3306"
DB_USER="db-benutzer"
DB_PASS="db-passwort"
DB_NAME="db-name"

IPINFO_TOKEN="dein-ipinfo.io-token"
WEBHOOK_TOKEN="dein-webhook-token"

EMAIL_TO="abuse@deine-domain.de"
EMAIL_FROM="no-reply@deine-domain.de"

DYNDNS="dein-dyndns-hostname"   # wird nie automatisch geblockt
```

### 4. Agenten auf überwachten Servern installieren

```bash
curl -s https://deine-domain.de/api/install.sh | bash
```

Das Skript registriert den Server, lädt alle Agenten herunter und richtet die Cronjobs ein.

### 5. Webhook-Server starten (auth-webhook.py)

Auf dem Server der die Reports erstellt:

```bash
cp agents/auth-monitor/auth-monitor.conf /opt/
cp agents/auth-monitor/auth-webhook.py /opt/
cp agents/auth-monitor/auth-report.sh /opt/
python3 /opt/auth-webhook.py
```

Für Autostart als systemd-Service:

```ini
[Unit]
Description=Auth Monitor Webhook
After=network.target

[Service]
ExecStart=/usr/bin/python3 /opt/auth-webhook.py
Restart=always
User=root

[Install]
WantedBy=multi-user.target
```

---

## Gitignored — nie committen

| Datei | Inhalt |
|---|---|
| `app/config/agent_config.php` | DB-Credentials, API-Token, Webhook |
| `app/config/config.php` | App-Passwort-Hash |
| `agents/auth-monitor/auth-monitor.conf` | DB-Credentials, Token, E-Mail, DynDNS |
| `data/` | SQLite-Altdatenbanken |
| `logs/` | Zugriffs- und Fehlerlogdateien |
