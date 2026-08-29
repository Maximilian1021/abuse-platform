<?php
if (defined('ABUSE_MAILER_LOADED')) return;
define('ABUSE_MAILER_LOADED', true);

require_once __DIR__ . '/../helpers/config.php';
require_once __DIR__ . '/templates.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/** Domain-Teil der Absenderadresse (für Message-ID). */
function _mailDomain(): string {
    $from = (string) cfg('abuse_from_email', 'abuse@localhost');
    $at   = strrchr($from, '@');
    return $at ? substr($at, 1) : 'localhost';
}

/** Erzeugt eine RFC-konforme Message-ID inkl. Reportnummer. */
function makeMessageId(string $ref): string {
    $rand = bin2hex(random_bytes(6));
    $slug = $ref !== '' ? preg_replace('/[^A-Za-z0-9._-]/', '', $ref) : 'msg';
    return sprintf('<%s.%s.%s@%s>', $slug, time(), $rand, _mailDomain());
}

/**
 * Versendet eine Abuse-Mail über SMTP.
 *
 * @param string      $toEmail     Empfänger (Hoster-Abuse-Adresse)
 * @param string      $subject     Betreff (Reportnummer wird bei Bedarf angehängt)
 * @param string      $bodyText    Klartext-Body
 * @param string      $ref         Reportnummer (für Message-ID + Betreff)
 * @param string|null $inReplyTo   Message-ID, auf die geantwortet wird (Threading)
 * @param array       $references  Bekannte Message-IDs des Threads
 * @return array{ok:bool, message_id:string, subject:string, error:string}
 */
function sendAbuseMail(
    string $toEmail,
    string $subject,
    string $bodyText,
    string $ref = '',
    ?string $inReplyTo = null,
    array $references = []
): array {
    $subject   = subjectWithRef($subject, $ref);
    $messageId = makeMessageId($ref);

    $host = (string) cfg('smtp_host', '');
    if ($host === '' || $toEmail === '') {
        return ['ok' => false, 'message_id' => '', 'subject' => $subject,
                'error' => $host === '' ? 'SMTP nicht konfiguriert (app/config/config.php).' : 'Keine Empfängeradresse.'];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = (int) cfg('smtp_port', 587);
        $mail->SMTPAuth   = true;
        $mail->Username   = (string) cfg('smtp_user', '');
        $mail->Password   = (string) cfg('smtp_pass', '');
        $secure           = strtolower((string) cfg('smtp_secure', 'tls'));
        $mail->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = PHPMailer::CHARSET_UTF8;
        $mail->Timeout    = 20;

        $mail->setFrom((string) cfg('abuse_from_email', ''), (string) cfg('abuse_from_name', ''));
        $mail->addReplyTo((string) cfg('abuse_from_email', ''), (string) cfg('abuse_from_name', ''));
        $mail->addAddress($toEmail);

        $mail->Subject   = $subject;
        $mail->Body      = $bodyText;
        $mail->isHTML(false);
        $mail->MessageID = $messageId;

        $refs = array_values(array_filter(array_map('trim', $references)));
        if ($inReplyTo) {
            $mail->addCustomHeader('In-Reply-To', $inReplyTo);
            $refs[] = $inReplyTo;
        }
        if ($refs) {
            $mail->addCustomHeader('References', implode(' ', array_unique($refs)));
        }

        $mail->send();
        return ['ok' => true, 'message_id' => $messageId, 'subject' => $subject, 'error' => ''];
    } catch (PHPMailerException $e) {
        return ['ok' => false, 'message_id' => '', 'subject' => $subject,
                'error' => $mail->ErrorInfo ?: $e->getMessage()];
    }
}
