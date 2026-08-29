# Abuse Platform

Internes Tool zum Erstellen, Versenden und Nachverfolgen von Abuse-Meldungen.

## Module

| Modul | Beschreibung |
|---|---|
| **Report Monitor** | Abuse-Report als Entwurf erstellen → aus Vorlage + Hoster-Kontakt eine E-Mail bauen → an die Abuse-Adresse des Hosters senden → eingehende Antworten werden per Reportnummer / Threading automatisch dem Fall zugeordnet. Volle 2-Wege-Konversation im Verlauf. |
| **Hoster-Datenbank** | Kontaktdaten & Erfahrungen je Hoster (Abuse-URL, Abuse-E-Mail, Methode, ob der Hoster reagiert). Liefert den Empfänger für den Report-Versand. |

---

## Ablauf eines Reports

1. **Neu** im Report Monitor: IP, Hoster (aus der Hoster-DB), Grund, Belege/Log-Auszug. Der Report bekommt eine Nummer im Format `PREFIX-JAHR-NUMMER` (z.B. `R-26-0042`), Status `Entwurf`. Das `PREFIX` ist frei über `report_ref_prefix` in `app/config/config.php` einstellbar (leer erlaubt → `26-0042`); Jahr und laufende Nummer (pro Kalenderjahr) vergibt das System automatisch.
2. **Entwurf erzeugen**: Vorlage wählen → Betreff + Text werden vorbefüllt (Platzhalter: IP, Reportnummer, Belege, Datum …). Frei editierbar.
3. **Report senden**: Mail geht über das eigene Abuse-Postfach (SMTP) an die Abuse-Adresse des Hosters. Die Reportnummer steht im Betreff (`… [R-26-0042]`), eine eigene `Message-ID` wird gesetzt.
4. **Antworten abholen**: Ein Cron-Job (`app/mail/imap_poll.php`) prüft das Postfach per IMAP. Eingehende Mails werden zugeordnet über
   1. `In-Reply-To` / `References` (bekannte Message-ID),
   2. Reportnummer im Betreff,
   3. Absender = Abuse-Adresse des Hosters (nur wenn genau ein offener Report dazu existiert).
   Zugeordnete Antworten landen im Verlauf, der Status wechselt auf *Antwort erhalten*.
5. **Antworten** direkt aus dem Report-Thread — Betreff, Reportnummer und Threading-Header werden übernommen.

Hoster ohne Abuse-E-Mail (nur Webformular): „Als manuell gemeldet markieren" statt Versand.

---

## Voraussetzungen

- PHP 8.1+ mit `pdo_mysql`, `mbstring`, `openssl`, `curl`
- MySQL 8.x
- [Composer](https://getcomposer.org/)
- Webserver (Apache/Nginx) mit `public/` als Document Root
- Ein IMAP/SMTP-Postfach für die Abuse-Adresse (Passwort-Login)

---

## Installation

### 1. Abhängigkeiten

```bash
composer install --no-dev
```

### 2. Konfiguration

```bash
cp app/config/config.example.php app/config/config.php
```

`app/config/config.php` ausfüllen — **eine** Datei für alles:

```php
// MySQL
'db_host' => '…', 'db_port' => '3306', 'db_user' => '…', 'db_pass' => '…', 'db_name' => '…',

// Abuse-Postfach — SMTP (Versand)
'smtp_host' => 'mail.example.com', 'smtp_port' => 587, 'smtp_secure' => 'tls',  // oder 'ssl' / Port 465
'smtp_user' => 'abuse@example.com', 'smtp_pass' => '…',

// Abuse-Postfach — IMAP (Antworten)
'imap_host' => 'mail.example.com', 'imap_port' => 993, 'imap_secure' => 'ssl',  // 'ssl' (993) | 'starttls' (143) | 'none'
'imap_user' => 'abuse@example.com', 'imap_pass' => '…',
'imap_folder' => 'INBOX', 'imap_processed_folder' => '',   // optional: verarbeitete Mails verschieben

// Absender / Meta
'abuse_from_email' => 'abuse@example.com', 'abuse_from_name' => 'example.com Abuse',
'reporter_name' => 'Vorname Nachname',
'report_ref_prefix' => 'R',   // Reportnummer PREFIX-JAHR-NUMMER  ->  R-26-0042  (leer -> 26-0042)
```

Die MySQL-Tabellen (`platform_users`, `abuse_reports`, `abuse_messages`, `abuse_log_entries`,
`hoster_contacts` …) werden beim ersten Seitenaufruf idempotent angelegt.

### 3. Ersteinrichtung

`https://abuse.example.com/setup.php` aufrufen und den ersten **Admin**-Account anlegen.
Weitere Benutzer (`admin` / `viewer`) danach im Admin-Panel.

### 4. IMAP-Poller als Cron

```bash
sudo cp scripts/imap-poll.cron.example /etc/cron.d/abuse-imap-poll
# Pfad + Benutzer in der Datei anpassen, Datei braucht eine Leerzeile am Ende
```

Standard: alle 5 Minuten `php app/mail/imap_poll.php`. Log: `logs/imap-poll.log`.
Manueller Testlauf: `php app/mail/imap_poll.php`.

---

## Webserver-Konfiguration

Document Root ist `public/`. `vendor/`, `app/` und `logs/` liegen darüber und sind nicht
öffentlich erreichbar.

### Nginx

```nginx
server {
    listen 80;
    server_name abuse.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name abuse.example.com;

    ssl_certificate     /etc/letsencrypt/live/abuse.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/abuse.example.com/privkey.pem;

    root /var/www/abuse-platform/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\. { deny all; }
}
```

> Zertifikat: `certbot --nginx -d abuse.example.com`

### Apache

```apache
<VirtualHost *:443>
    ServerName abuse.example.com
    DocumentRoot /var/www/abuse-platform/public

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/abuse.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/abuse.example.com/privkey.pem

    <Directory /var/www/abuse-platform/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

> `mod_rewrite` aktivieren: `a2enmod rewrite && systemctl restart apache2`

---

## Nicht committen (gitignored)

| Pfad | Inhalt |
|---|---|
| `app/config/config.php` | DB-, SMTP-, IMAP-Zugangsdaten |
| `vendor/` | Composer-Abhängigkeiten |
| `logs/` | Log-Dateien (u.a. `imap-poll.log`) |
| `data/` | (Altbestand) |
