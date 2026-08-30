<?php
if (defined('ABUSE_TEMPLATES_LOADED')) return;
define('ABUSE_TEMPLATES_LOADED', true);

require_once __DIR__ . '/../helpers/config.php';

/** Platzhalter, die in Betreff/Body ersetzt werden (für die UI-Legende). */
const ABUSE_TEMPLATE_PLACEHOLDERS = ['ref', 'ip', 'reason', 'reporter', 'date', 'evidence', 'hoster', 'org'];

/**
 * Aktive Vorlagen für Abuse-Mails: [key => ['label','subject','body']].
 * Quelle ist die Tabelle abuse_mail_templates (editierbar unter Admin -> Vorlagen);
 * fehlt die DB/Tabelle, greifen die Code-Defaults aus abuseTemplateDefaults().
 * Platzhalter:
 *   {ref} {ip} {reason} {reporter} {date} {evidence} {hoster} {org}
 * {reporter} = Profilname + Rolle des Report-Erstellers
 *              (Fallback: Login-Name, dann 'reporter_name' aus der Config).
 */
function abuseTemplates(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = abuseTemplateDefaults();
    try {
        require_once __DIR__ . '/../db/templates_db.php';
        seedMailTemplatesIfEmpty($defaults);
        $rows = getMailTemplatesRaw();
        if ($rows) {
            $out = [];
            foreach ($rows as $key => $r) {
                $out[$key] = [
                    'label'   => $r['label'],
                    'subject' => $r['subject'],
                    'body'    => $r['body'],
                ];
            }
            // generic ist der harte Fallback in buildDraft() — immer vorhalten
            if (!isset($out['generic']) && isset($defaults['generic'])) {
                $out['generic'] = $defaults['generic'];
            }
            return $cache = $out;
        }
    } catch (\Throwable $e) {
        // DB nicht erreichbar -> Code-Defaults
    }
    return $cache = $defaults;
}

/** Eingebaute Standard-Vorlagen (Seed für die DB + Fallback). */
function abuseTemplateDefaults(): array {
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
  Target       : {org} infrastructure (port 22)
  Our reference: {ref}
  Report date  : {date}

Log evidence (timestamps in UTC):

{evidence}

Please take appropriate action to stop this activity and, if possible, let us
know the outcome. Keep the reference {ref} in the subject line when replying so
we can match your answer to this case.

Kind regards,
{reporter}
{org} Abuse Team
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
  Target       : {org} infrastructure
  Our reference: {ref}
  Report date  : {date}

Evidence:

{evidence}

Please investigate and stop this activity. Please keep {ref} in the subject
line when replying.

Kind regards,
{reporter}
{org} Abuse Team
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
{org} Abuse Team
TXT,
        ],
    ];
}

/**
 * Signaturzeile für {reporter}: "Profilname (Rolle)".
 * Reihenfolge: full_name > Login-Name > 'reporter_name' aus der Config.
 * Rolle wird angehängt, wenn bekannt (roleLabel() aus auth.php).
 */
function reporterSignature(array $report): string {
    $name = trim((string)($report['created_by_full_name'] ?? ''))
        ?: trim((string)($report['created_by_name'] ?? ''))
        ?: trim((string) cfg('reporter_name', ''));

    $role = '';
    if (function_exists('roleLabel')) {
        $role = roleLabel((string)($report['created_by_role'] ?? ''));
    }

    if ($name !== '' && $role !== '') return "{$name} ({$role})";
    return $name;
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
        'reporter' => reporterSignature($report),
        'date'     => date('Y-m-d H:i') . ' UTC',
        'evidence' => trim($report['note'] ?? '') ?: '(bitte Log-Auszug / Belege hier einfügen)',
        'hoster'   => $report['hoster_name'] ?? '',
        'org'      => mailOrg(),
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
