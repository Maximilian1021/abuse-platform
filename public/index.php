<?php
require_once __DIR__ . '/../app/helpers/auth.php';
requireAuth();
$me        = currentUser();
$navActive = 'hub';
$year      = date('Y');

// ── Live-Statistiken ──────────────────────────────────────────────────────────
$stats = ['reports' => 0, 'open' => 0, 'awaiting' => 0, 'hosters' => 0];
try {
    require_once __DIR__ . '/../app/db/abuse_db.php';
    $db = getMySQL();

    $closed = "'" . implode("','", ABUSE_CLOSED_STATUSES) . "'";
    $stats['reports']  = (int)$db->query("SELECT COUNT(*) FROM abuse_reports")->fetchColumn();
    $stats['open']     = (int)$db->query("SELECT COUNT(*) FROM abuse_reports WHERE status NOT IN ($closed)")->fetchColumn();
    $stats['awaiting'] = (int)$db->query("SELECT COUNT(*) FROM abuse_reports WHERE status IN ('Gesendet','Wartet auf Antwort')")->fetchColumn();
    $stats['hosters']  = (int)$db->query("SELECT COUNT(*) FROM hoster_contacts")->fetchColumn();
} catch (Exception $e) {
    // Tabellen noch nicht vorhanden — Defaults bleiben 0
}

function fmt(int $n): string {
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 1000)    return round($n / 1000, 1) . 'k';
    return (string)$n;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<title><?= htmlspecialchars(pageTitle()) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:        #080b10;
    --surface:   #0f1420;
    --card:      #141926;
    --border:    #1e2740;
    --border-hi: #2e3f60;
    --text:      #e2e8f0;
    --muted:     #64748b;
    --subtle:    #334155;
    --accent:    #3b82f6;
    --accent-glow: rgba(59, 130, 246, 0.15);
    --red:       #ef4444;
    --red-glow:  rgba(239, 68, 68, 0.12);
    --green:     #22c55e;
    --green-glow:rgba(34, 197, 94, 0.12);
    --radius:    12px;
}

html { scroll-behavior: smooth; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    font-size: 15px;
    min-height: 100vh;
    line-height: 1.6;
}

body::before {
    content: '';
    position: fixed; inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.015'%3E%3Crect x='0' y='0' width='1' height='1'/%3E%3Crect x='20' y='20' width='1' height='1'/%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none; z-index: 0;
}

.blob { position: fixed; border-radius: 50%; filter: blur(120px); opacity: .25; pointer-events: none; z-index: 0; }
.blob-blue  { width: 600px; height: 600px; background: #1d4ed8; top: -200px; left: -200px; }
.blob-red   { width: 400px; height: 400px; background: #9f1239; bottom: -100px; right: -100px; }

.wrap { position: relative; z-index: 1; max-width: 1000px; margin: 0 auto; padding: 0 24px; }

/* ── Hero ── */
.hero { padding: 80px 0 56px; text-align: center; }
.hero-label {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 500; color: #60a5fa;
    background: rgba(59,130,246,.1); border: 1px solid rgba(59,130,246,.2);
    border-radius: 20px; padding: 4px 14px; margin-bottom: 24px;
    letter-spacing: .04em; text-transform: uppercase;
}
.hero-label-dot {
    width: 6px; height: 6px; background: #3b82f6; border-radius: 50%;
    animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.8)} }

h1 {
    font-size: clamp(30px, 5vw, 50px);
    font-weight: 700; line-height: 1.15; letter-spacing: -.03em; color: #f1f5f9; margin-bottom: 20px;
}
h1 span {
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.hero-sub { font-size: 17px; color: var(--muted); max-width: 520px; margin: 0 auto 48px; line-height: 1.7; }

/* ── Cards ── */
.cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; margin-bottom: 56px; }
@media (max-width: 760px) { .cards { grid-template-columns: 1fr; } }

.card {
    background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
    padding: 26px; text-decoration: none; color: inherit;
    display: flex; flex-direction: column; gap: 14px;
    transition: border-color .2s, transform .2s, box-shadow .2s;
    position: relative; overflow: hidden;
}
.card::before { content: ''; position: absolute; inset: 0; opacity: 0; transition: opacity .2s; border-radius: var(--radius); }
.card:hover { transform: translateY(-3px); border-color: var(--border-hi); }
.card:hover::before { opacity: 1; }
.card-report::before  { background: var(--red-glow); }
.card-hoster::before  { background: var(--green-glow); }
.card-report:hover { box-shadow: 0 0 40px rgba(239,68,68,.1); }
.card-hoster:hover { box-shadow: 0 0 40px rgba(34,197,94,.1); }

.card-icon {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; position: relative; z-index: 1;
}
.card-report .card-icon { background: rgba(239,68,68,.12); }
.card-hoster .card-icon { background: rgba(34,197,94,.12); }
.card-icon svg { width: 22px; height: 22px; }
.card-report .card-icon svg { stroke: #f87171; }
.card-hoster .card-icon svg { stroke: #4ade80; }

.card-title { font-size: 16px; font-weight: 600; color: #f1f5f9; position: relative; z-index: 1; }
.card-desc  { font-size: 13px; color: var(--muted); line-height: 1.65; flex: 1; position: relative; z-index: 1; }
.card-tags  { display: flex; flex-wrap: wrap; gap: 6px; position: relative; z-index: 1; }
.tag { font-size: 11px; padding: 3px 9px; border-radius: 6px; font-weight: 500; border: 1px solid transparent; }
.tag-blue   { background: rgba(59,130,246,.1);  color: #93c5fd; border-color: rgba(59,130,246,.2); }
.tag-red    { background: rgba(239,68,68,.1);   color: #fca5a5; border-color: rgba(239,68,68,.2); }
.tag-purple { background: rgba(139,92,246,.1);  color: #c4b5fd; border-color: rgba(139,92,246,.2); }
.tag-green  { background: rgba(34,197,94,.1);   color: #86efac; border-color: rgba(34,197,94,.2); }
.tag-gray   { background: rgba(100,116,139,.1); color: #94a3b8; border-color: rgba(100,116,139,.2); }
.card-arrow {
    position: absolute; top: 22px; right: 22px; color: var(--subtle);
    transition: color .2s, transform .2s; z-index: 1;
}
.card:hover .card-arrow { color: var(--muted); transform: translate(2px, -2px); }

/* ── Stats bar ── */
.stats {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 1px; background: var(--border);
    border: 1px solid var(--border); border-radius: var(--radius);
    overflow: hidden; margin-bottom: 56px;
}
@media (max-width: 600px) { .stats { grid-template-columns: 1fr 1fr; } }

.stat { background: var(--card); padding: 18px 22px; text-align: center; }
.stat-num {
    font-size: 24px; font-weight: 700; letter-spacing: -.02em;
    color: #f1f5f9; line-height: 1; margin-bottom: 4px;
}
.stat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }

/* ── Footer ── */
footer {
    border-top: 1px solid var(--border); padding: 22px 0;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.footer-left  { font-size: 13px; color: var(--muted); }
.footer-left strong { color: var(--subtle); }
.footer-right { font-size: 12px; color: #334155; }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../app/helpers/nav.php'; ?>

<div class="blob blob-blue"></div>
<div class="blob blob-red"></div>

<div class="wrap">

    <!-- Hero -->
    <div class="hero">
        <div class="hero-label">
            <span class="hero-label-dot"></span>
            Security Monitoring
        </div>
        <h1>Zentrales <span>Abuse</span> Dashboard</h1>
        <p class="hero-sub">
            Abuse-Meldungen erstellen, per E-Mail an den Hoster verschicken,
            Antworten verfolgen &ndash; plus Hoster-Kontaktdatenbank für <?= htmlspecialchars(siteDomain() ?: siteName()) ?>.
        </p>
    </div>

    <!-- Cards -->
    <div class="cards">

        <a class="card card-report" href="report-monitor/">
            <div class="card-arrow">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M17 7H7M17 7v10"/></svg>
            </div>
            <div class="card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <path d="M14 2v6h6M9 13h6M9 17h4"/>
                </svg>
            </div>
            <div class="card-title">Report Monitor</div>
            <div class="card-desc">
                Abuse-Report als Entwurf erstellen, an die Abuse-Adresse des Hosters
                mailen und Antworten mit Reportnummer automatisch dem Fall zuordnen.
            </div>
            <div class="card-tags">
                <span class="tag tag-red">E-Mail-Versand</span>
                <span class="tag tag-green">Antwort-Tracking</span>
                <span class="tag tag-purple">Verlauf</span>
            </div>
        </a>

        <a class="card card-hoster" href="hoster-db/">
            <div class="card-arrow">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M17 7H7M17 7v10"/></svg>
            </div>
            <div class="card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <ellipse cx="12" cy="5" rx="9" ry="3"/>
                    <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
                    <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                </svg>
            </div>
            <div class="card-title">Hoster-Datenbank</div>
            <div class="card-desc">
                Kontaktdaten und Erfahrungen für Abuse-Meldungen je Hoster.
                URL, E-Mail, Methode und ob der Hoster überhaupt reagiert.
            </div>
            <div class="card-tags">
                <span class="tag tag-green">Hoster-Kontakte</span>
                <span class="tag tag-gray">Erfahrungen</span>
                <?php if ($stats['hosters'] > 0): ?>
                <span class="tag tag-blue"><?= $stats['hosters'] ?> Einträge</span>
                <?php endif; ?>
            </div>
        </a>

    </div>

    <!-- Live-Stats -->
    <div class="stats">
        <div class="stat">
            <div class="stat-num" style="color:#fca5a5"><?= fmt($stats['reports']) ?></div>
            <div class="stat-label">Reports gesamt</div>
        </div>
        <div class="stat">
            <div class="stat-num" style="color:#fdba74"><?= fmt($stats['open']) ?></div>
            <div class="stat-label">Offen</div>
        </div>
        <div class="stat">
            <div class="stat-num" style="color:#60a5fa"><?= fmt($stats['awaiting']) ?></div>
            <div class="stat-label">Wartet auf Antwort</div>
        </div>
        <div class="stat">
            <div class="stat-num" style="color:#4ade80"><?= $stats['hosters'] ?></div>
            <div class="stat-label">Hoster-Einträge</div>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <div class="footer-left"><?= footerHtml() ?></div>
        <div class="footer-right">&copy; <?= $year ?></div>
    </footer>

</div>

</body>
</html>
