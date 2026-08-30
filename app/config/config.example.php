<?php
// =============================================================================
//  Zentrale Konfiguration — Abuse Platform
//  Kopiere diese Datei nach config.php und trage deine Werte ein.
// =============================================================================
return [
    // ── MySQL-Datenbank ──────────────────────────────────────────────────────
    'db_host' => 'your-db-host',
    'db_port' => '3306',
    'db_user' => 'db-user',
    'db_pass' => 'db-password',
    'db_name' => 'db-name',

    // ── Abuse-Postfach: SMTP (Versand) ───────────────────────────────────────
    'smtp_host'   => 'mail.example.com',
    'smtp_port'   => 587,
    'smtp_secure' => 'tls',          // 'tls' (STARTTLS, Port 587) oder 'ssl' (Port 465)
    'smtp_user'   => 'abuse@example.com',
    'smtp_pass'   => 'mailbox-password',

    // ── Abuse-Postfach: IMAP (Antworten abholen) ─────────────────────────────
    'imap_host'            => 'mail.example.com',
    'imap_port'            => 993,
    'imap_secure'          => 'ssl',   // 'ssl' (Port 993) | 'starttls' (Port 143) | 'none'
    'imap_user'            => 'abuse@example.com',
    'imap_pass'            => 'mailbox-password',
    'imap_folder'          => 'INBOX',
    'imap_processed_folder' => '',    // optional: verarbeitete Mails hierhin verschieben (z.B. 'Processed')

    // ── Absender / Meta ──────────────────────────────────────────────────────
    'abuse_from_email'  => 'abuse@example.com',
    'abuse_from_name'   => 'example.com Abuse',
    'reporter_name'     => 'Vorname Nachname',   // Fallback für die Mail-Signatur, falls der Report-Ersteller keinen Profil-Anzeigenamen hat
    'report_ref_prefix' => 'R',   // Reportnummer: PREFIX-JAHR-NUMMER  ->  R-26-0042  (leer = 26-0042)

    // ── Branding (Defaults; live editierbar unter Admin → Branding) ──────────
    //    Die Werte hier greifen, solange sie in der GUI nicht überschrieben wurden.
    'site_name'   => 'Abuse Platform',      // Nav-Marke + Seitentitel
    'site_domain' => 'example.com',         // Footer + Titel (leer = weglassen)
    'footer_html' => '',                    // optional: eigener Footer (HTML erlaubt); leer = auto aus site_name + site_domain
    'login_note'  => 'Internal Use Only',   // Fußzeile auf der Login-Seite
    'mail_org'    => 'example.com',          // in Mail-Vorlagen: "<mail_org> infrastructure" / "<mail_org> Abuse Team" (leer = site_domain)
];
