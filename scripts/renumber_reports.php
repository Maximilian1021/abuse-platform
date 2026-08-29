<?php
/**
 * renumber_reports.php — vergibt allen bestehenden Reports eine Nummer im
 * aktuellen Format  PREFIX-YY-NNNN  (Prefix + 2-stelliges Jahr + laufende Nummer
 * pro Kalenderjahr, nach Erstellreihenfolge).
 *
 *   php scripts/renumber_reports.php            # Vorschau (dry run)
 *   php scripts/renumber_reports.php --apply    # wirklich schreiben
 *
 * ACHTUNG: ändert bereits vergebene Reportnummern. Nur laufen lassen, wenn noch
 * keine echten Mails mit alten Nummern rausgegangen sind (oder das egal ist).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../app/db/abuse_db.php';

$apply = in_array('--apply', $argv, true);
$db = getMySQL();

$rows = $db->query("SELECT id, ref, created_at FROM abuse_reports ORDER BY created_at ASC, id ASC")
           ->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) { echo "Keine Reports vorhanden.\n"; exit(0); }

$perYear = [];
$plan = [];
foreach ($rows as $r) {
    $year = (int) date('Y', strtotime($r['created_at']));
    $perYear[$year] = ($perYear[$year] ?? 0) + 1;
    $newRef = makeReportRef($perYear[$year], $r['created_at']);
    $plan[] = [$r['id'], $r['ref'], $newRef, $newRef !== $r['ref']];
}

$changed = 0;
foreach ($plan as [$id, $old, $new, $diff]) {
    printf("  #%-4d  %-16s  ->  %s%s\n", $id, $old ?: '(leer)', $new, $diff ? '' : '   (unverändert)');
    if ($diff) $changed++;
}
echo "\n{$changed} von " . count($plan) . " Reports würden geändert.\n";

if (!$apply) { echo "Dry run — mit --apply wirklich schreiben.\n"; exit(0); }

$db->beginTransaction();
try {
    // 1. alle refs leeren (UNIQUE-Kollisionen beim Umnummerieren vermeiden)
    $db->exec("UPDATE abuse_reports SET ref = NULL");
    $upd = $db->prepare("UPDATE abuse_reports SET ref = ? WHERE id = ?");
    foreach ($plan as [$id, , $new]) $upd->execute([$new, $id]);
    $db->commit();
    echo "Fertig — {$changed} Reportnummern aktualisiert.\n";
} catch (Throwable $e) {
    $db->rollBack();
    echo "FEHLER, nichts geändert: " . $e->getMessage() . "\n";
    exit(1);
}
