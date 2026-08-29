<?php
/**
 * Shared top navigation
 * Usage: set $navActive before including:
 *   $navActive = 'hub' | 'report-monitor' | 'hoster-db' | 'admin'
 * Requires: currentUser() available (auth.php already included)
 */
require_once __DIR__ . '/config.php';
$_navUser   = currentUser();
$_navActive = $navActive ?? '';
?>
<style>
.site-nav {
    position: sticky;
    top: 0;
    z-index: 100;
    background: rgba(8,11,16,.88);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-bottom: 1px solid #1e2740;
    padding: 0 20px;
    height: 50px;
    display: flex;
    align-items: center;
    gap: 0;
}
.site-nav-logo {
    display: flex;
    align-items: center;
    gap: 9px;
    text-decoration: none;
    color: #e2e8f0;
    margin-right: 28px;
    flex-shrink: 0;
}
.site-nav-logo-icon {
    width: 28px;
    height: 28px;
    background: linear-gradient(135deg, #1d4ed8, #7c3aed);
    border-radius: 7px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.site-nav-logo-icon svg { width: 15px; height: 15px; }
.site-nav-brand { font-size: 13px; font-weight: 600; letter-spacing: -.01em; white-space: nowrap; }
.site-nav-links { display: flex; align-items: center; gap: 2px; flex: 1; }
.site-nav-link {
    font-size: 13px;
    color: #64748b;
    text-decoration: none;
    padding: 5px 10px;
    border-radius: 6px;
    transition: color .15s, background .15s;
    white-space: nowrap;
}
.site-nav-link:hover { color: #e2e8f0; background: rgba(20,25,38,.8); }
.site-nav-link.active { color: #e2e8f0; background: rgba(20,25,38,.8); }
.site-nav-link.active-blue { color: #60a5fa; }
.site-nav-right { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.site-nav-user {
    font-size: 12px;
    color: #475569;
    background: rgba(20,25,38,.6);
    border: 1px solid #1e2740;
    border-radius: 6px;
    padding: 4px 9px;
    white-space: nowrap;
}
.site-nav-logout {
    font-size: 12px;
    color: #64748b;
    text-decoration: none;
    background: rgba(20,25,38,.6);
    border: 1px solid #1e2740;
    border-radius: 6px;
    padding: 4px 9px;
    transition: color .15s, border-color .15s;
    white-space: nowrap;
}
.site-nav-logout:hover { color: #f87171; border-color: rgba(239,68,68,.3); }

/* ── Mobil ── */
@media (max-width: 640px) {
    .site-nav {
        height: auto;
        min-height: 46px;
        padding: 6px 12px;
        flex-wrap: wrap;
        gap: 6px;
    }
    .site-nav-logo { margin-right: 0; }
    .site-nav-brand { display: none; }
    .site-nav-user  { display: none; }
    .site-nav-right { margin-left: auto; }
    .site-nav-links {
        order: 3;
        width: 100%;
        flex: none;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        gap: 4px;
        padding-bottom: 2px;
    }
    .site-nav-links::-webkit-scrollbar { display: none; }
    .site-nav-link   { font-size: 14px; padding: 8px 14px; }
    .site-nav-logout { font-size: 13px; padding: 7px 12px; }
}
</style>

<nav class="site-nav">
    <a href="/" class="site-nav-logo">
        <div class="site-nav-logo-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
        </div>
        <span class="site-nav-brand"><?= htmlspecialchars(siteName()) ?></span>
    </a>
    <div class="site-nav-links">
        <a href="/" class="site-nav-link <?= $_navActive === 'hub' ? 'active active-blue' : '' ?>">Hub</a>
        <a href="/report-monitor/" class="site-nav-link <?= $_navActive === 'report-monitor' ? 'active active-blue' : '' ?>">Reports</a>
        <a href="/hoster-db/" class="site-nav-link <?= $_navActive === 'hoster-db' ? 'active active-blue' : '' ?>">Hoster-DB</a>
        <?php if ($_navUser && $_navUser['role'] === 'admin'): ?>
        <a href="/admin/" class="site-nav-link <?= $_navActive === 'admin' ? 'active active-blue' : '' ?>">Admin</a>
        <?php endif; ?>
    </div>
    <div class="site-nav-right">
        <?php if ($_navUser): ?>
        <span class="site-nav-user"><?= htmlspecialchars($_navUser['username']) ?></span>
        <?php endif; ?>
        <a href="/logout.php" class="site-nav-logout">Abmelden</a>
    </div>
</nav>
