<?php
if (defined('ABUSE_TEMPLATES_LOADED')) return;
define('ABUSE_TEMPLATES_LOADED', true);

require_once __DIR__ . '/../helpers/config.php';

/**
 * Vorlagen für Abuse-Mails. Platzhalter:
 *   {ref} {ip} {reason} {reporter} {date} {evidence} {hoster}
 * 'subject' und 'body' werden mit fillTemplate() befüllt.
 */
function abuseTemplates(): array {
    return [
        'ssh' => [
            'label'   => 'SSH Brute Force',
            'subject' => 'Abuse Report {ref} — SSH brute-force from {ip}',
            'body'    => <<<TXT
Hello,

we are observing repeated unauthorized SSH login attempts originating from an
IP address in your network:

  Offending IP : {ip}
  Attack type  : SSH brute-force / credential stuffing
  Target       : max1021.de infrastructure (port 22)
  Our reference: {ref}
  Report date  : {date}

Log evidence (timestamps in UTC):

{evidence}

Please take appropriate action to stop this activity and, if possible, let us
know the outcome. Keep the reference {ref} in the subject line when replying so
we can match your answer to this case.

Kind regards,
{reporter}
max1021.de Abuse Team
TXT,
        ],
        'scan' => [
            'label'   => 'Portscan / Spam',
            'subject' => 'Abuse Report {ref} — malicious traffic from {ip}',
            'body'    => <<<TXT
Hello,

an IP address in your network is generating malicious traffic against our
systems:

  Offending IP : {ip}
  Activity     : {reason}
  Target       : max1021.de infrastructure
  Our reference: {ref}
  Report date  : {date}

Evidence:

{evidence}

Please investigate and stop this activity. Please keep {ref} in the subject
line when replying.

Kind regards,
{reporter}
max1021.de Abuse Team
TXT,
        ],
        'generic' => [
            'label'   => 'Allgemein',
            'subject' => 'Abuse Report {ref} — {ip}',
            'body'    => <<<TXT
Hello,

we would like to report abusive activity from an IP address in your network:

  Offending IP : {ip}
  Reason       : {reason}
  Our reference: {ref}
  Report date  : {date}

Evidence:

{evidence}

Please investigate. Keep the reference {ref} in the subject when replying so we
can track your response.

Kind regards,
{reporter}
max1021.de Abuse Team
TXT,
        ],
    ];
}

/** Ersetzt {platzhalter} im String. */
function fillTemplate(string $tpl, array $vars): string {
    return strtr($tpl, array_combine(
        array_map(fn($k) => '{' . $k . '}', array_keys($vars)),
        array_values($vars)
    ));
}

/**
 * Baut Betreff + Body für einen Report-Entwurf.
 * @return array{subject:string, body:string}
 */
function buildDraft(array $report, string $templateKey = 'generic'): array {
    $templates = abuseTemplates();
    $tpl = $templates[$templateKey] ?? $templates['generic'];

    $vars = [
        'ref'      => $report['ref'] ?: '',
        'ip'       => $report['ip'] ?: '(unbekannt)',
        'reason'   => trim($report['reason'] ?? '') ?: 'abusive activity',
        'reporter' => (string) cfg('reporter_name', ''),
        'date'     => date('Y-m-d H:i') . ' UTC',
        'evidence' => trim($report['note'] ?? '') ?: '(bitte Log-Auszug / Belege hier einfügen)',
        'hoster'   => $report['hoster_name'] ?? '',
    ];

    return [
        'subject' => fillTemplate($tpl['subject'], $vars),
        'body'    => fillTemplate($tpl['body'], $vars),
    ];
}

/** Stellt sicher, dass die Reportnummer im Betreff steht ("… [REF]"). */
function subjectWithRef(string $subject, string $ref): string {
    if ($ref === '' || str_contains($subject, "[$ref]")) return $subject;
    return rtrim($subject) . " [$ref]";
}
