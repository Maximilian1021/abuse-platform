#!/usr/bin/env bash
# =============================================================================
#  auth-report.sh v2 — IP-Reports mit Duplikat-Schutz
#
#  Usage:
#    auth-report.sh --day [YYYY-MM-DD]              Tagesbericht (default: gestern)
#    auth-report.sh --week [YYYY-MM-DD]             Wochenbericht (default: letzte Woche)
#    auth-report.sh --ip 1.2.3.4 [--from X --to Y] Report für eine einzelne IP
#    auth-report.sh --list                          Zeige bisherige Reports
#    auth-report.sh --force                         Duplikat-Schutz überspringen
# =============================================================================

set -uo pipefail

CONF_FILE="$(dirname "$0")/auth-monitor.conf"
if [[ ! -f "$CONF_FILE" ]]; then echo "ERROR: $CONF_FILE nicht gefunden" >&2; exit 1; fi
# shellcheck source=auth-monitor.conf
source "$CONF_FILE"

SERVER_NAME=$(hostname -f 2>/dev/null || hostname)

REPORT_DIR="/var/log/auth-monitor/reports"
mkdir -p "$REPORT_DIR"

log() { echo "[$(date '+%H:%M:%S')] $*" >&2; }

mysql_exec() {
    mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
          --password="$DB_PASS" --database="$DB_NAME" \
          --connect-timeout=10 --silent 2>/dev/null <<< "$1"
}

mysql_escape() { echo "$1" | sed "s/'/\\\\'/g"; }

# =============================================================================
#  DUPLIKAT-PRÜFUNG
# =============================================================================
check_duplicate() {
    local type="$1" start="$2" end="$3" ip_filter="${4:-}"
    local e_ip
    e_ip=$(mysql_escape "$ip_filter")
    local existing
    existing=$(mysql_exec "
        SELECT created_at FROM reports
        WHERE report_type='$type'
          AND period_start='$start'
          AND period_end='$end'
          AND ip_filter='$e_ip'
        LIMIT 1;" | tail -1)
    echo "$existing"
}

register_report() {
    local type="$1" start="$2" end="$3" ip_filter="${4:-}" filepath="${5:-}"
    local e_ip e_path
    e_ip=$(mysql_escape "$ip_filter")
    e_path=$(mysql_escape "$filepath")
    local now
    now=$(date '+%Y-%m-%d %H:%M:%S')
    mysql_exec "
        INSERT IGNORE INTO reports (report_type, period_start, period_end, ip_filter, created_at, file_path, sent_to)
        VALUES ('$type','$start','$end','$e_ip','$now','$e_path','$EMAIL_TO');"
}

# =============================================================================
#  HTML GENERIERUNG
# =============================================================================
generate_html_report() {
    local title="$1"
    local date_from="$2"
    local date_to="$3"
    local ip_filter="${4:-}"
    local html_file="$5"

    # Statistiken holen
    local where_ip=""
    [[ -n "$ip_filter" ]] && where_ip=" AND ds.ip='$(mysql_escape "$ip_filter")'"

    local total_fails total_success total_ips
    total_fails=$(mysql_exec "SELECT COALESCE(SUM(fail_count),0) FROM daily_stats WHERE stat_date BETWEEN '$date_from' AND '$date_to'$where_ip;" | tail -1)
    total_success=$(mysql_exec "SELECT COALESCE(SUM(success_count),0) FROM daily_stats WHERE stat_date BETWEEN '$date_from' AND '$date_to'$where_ip;" | tail -1)
    total_ips=$(mysql_exec "SELECT COUNT(DISTINCT ip) FROM daily_stats WHERE stat_date BETWEEN '$date_from' AND '$date_to'$where_ip;" | tail -1)

    # Top IPs mit Hoster-Info
    local top_ips_data
    top_ips_data=$(mysql_exec "
        SELECT ds.ip,
               SUM(ds.fail_count) as fails,
               SUM(ds.success_count) as successes,
               COALESCE(ii.country,'?') as country,
               COALESCE(ii.city,'?') as city,
               COALESCE(ii.hoster,'unbekannt') as hoster,
               COALESCE(ii.asn,'') as asn,
               MAX(ds.last_seen) as last_seen,
               ds.usernames_tried
        FROM daily_stats ds
        LEFT JOIN ip_info ii ON ds.ip = ii.ip
        WHERE ds.stat_date BETWEEN '$date_from' AND '$date_to'
        $where_ip
        GROUP BY ds.ip, ii.country, ii.city, ii.hoster, ii.asn, ds.usernames_tried
        ORDER BY fails DESC
        LIMIT 50;" | tail -n +1)

    # Top IPs als HTML-Tabellenzeilen
    local rows=""
    while IFS=$'\t' read -r ip fails successes country city hoster asn last_seen usernames; do
        [[ -z "$ip" ]] && continue
        local row_bg=""
        [[ "$fails" -ge 50 ]] 2>/dev/null && row_bg=' style="background:#fff0f0"'
        [[ "$fails" -ge 20 && "$fails" -lt 50 ]] 2>/dev/null && row_bg=' style="background:#fff8f0"'
        rows+="<tr${row_bg}>
            <td><code>$ip</code></td>
            <td><strong>$fails</strong></td>
            <td>$successes</td>
            <td><span class='flag'>$country</span> $city</td>
            <td title='$asn'>$hoster</td>
            <td>$last_seen</td>
            <td style='font-size:11px;color:#888'>$(echo "$usernames" | cut -c1-60)</td>
        </tr>"
    done <<< "$top_ips_data"

    [[ -z "$rows" ]] && rows='<tr><td colspan="7" style="text-align:center;color:#999;padding:20px">Keine Daten für diesen Zeitraum</td></tr>'

    # Tägliche Verteilung (nur bei Wochen-/Tages-Reports)
    local daily_chart_rows=""
    if [[ -z "$ip_filter" ]]; then
        daily_chart_rows=$(mysql_exec "
            SELECT stat_date, SUM(fail_count) as fails, SUM(success_count) as successes
            FROM daily_stats
            WHERE stat_date BETWEEN '$date_from' AND '$date_to'
            GROUP BY stat_date ORDER BY stat_date;" | tail -n +1)
    fi

    local daily_labels="[]"
    local daily_fails="[]"
    local daily_success="[]"
    if [[ -n "$daily_chart_rows" ]]; then
        local labels fails_arr success_arr
        labels=$(echo "$daily_chart_rows" | awk -F'\t' '{printf "\"%s\",",$1}' | sed 's/,$//')
        fails_arr=$(echo "$daily_chart_rows" | awk -F'\t' '{printf "%s,",$2}' | sed 's/,$//')
        success_arr=$(echo "$daily_chart_rows" | awk -F'\t' '{printf "%s,",$3}' | sed 's/,$//')
        daily_labels="[$labels]"
        daily_fails="[$fails_arr]"
        daily_success="[$success_arr]"
    fi

    # Top Hoster
    local top_hosters
    top_hosters=$(mysql_exec "
        SELECT COALESCE(ii.hoster,'unbekannt') as hoster, COUNT(DISTINCT ds.ip) as ips, SUM(ds.fail_count) as fails
        FROM daily_stats ds
        LEFT JOIN ip_info ii ON ds.ip = ii.ip
        WHERE ds.stat_date BETWEEN '$date_from' AND '$date_to'
        $where_ip
        GROUP BY hoster ORDER BY fails DESC LIMIT 10;" | tail -n +1)

    local hoster_rows=""
    while IFS=$'\t' read -r hoster ips fails; do
        [[ -z "$hoster" ]] && continue
        hoster_rows+="<tr><td>$hoster</td><td>$ips</td><td><strong>$fails</strong></td></tr>"
    done <<< "$top_hosters"
    [[ -z "$hoster_rows" ]] && hoster_rows='<tr><td colspan="3" style="color:#999">Keine Daten</td></tr>'

    cat > "$html_file" << HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>$title</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;padding:20px}
.wrap{max-width:1200px;margin:0 auto}
h1{font-size:24px;color:#f1f5f9;margin-bottom:4px}
.meta{color:#64748b;font-size:13px;margin-bottom:24px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.card{background:#1e293b;border-radius:10px;padding:20px;border:1px solid #334155}
.card h2{font-size:13px;color:#64748b;font-weight:500;margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em}
.stat-num{font-size:40px;font-weight:700;line-height:1}
.danger{color:#f87171}
.success{color:#4ade80}
.warn{color:#fb923c}
.info{color:#60a5fa}
.section{background:#1e293b;border-radius:10px;padding:20px;margin-bottom:20px;border:1px solid #334155}
.section h2{font-size:15px;color:#94a3b8;margin-bottom:16px;font-weight:500}
table{width:100%;border-collapse:collapse;font-size:13px}
th{color:#64748b;font-weight:500;text-align:left;padding:8px 12px;border-bottom:1px solid #334155;font-size:12px;text-transform:uppercase}
td{padding:8px 12px;border-bottom:1px solid #1e293b;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#263548}
code{background:#0f172a;padding:2px 8px;border-radius:4px;font-family:monospace;font-size:12px;color:#93c5fd}
.flag{font-size:11px;background:#334155;padding:1px 5px;border-radius:3px;margin-right:4px}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:500}
.badge-red{background:#450a0a;color:#f87171}
.badge-orange{background:#431407;color:#fb923c}
.chart-wrap{position:relative;height:200px}
.footer{text-align:center;color:#475569;font-size:12px;margin-top:24px}
</style>
</head>
<body>
<div class="wrap">
  <h1>$title</h1>
  <div class="meta">Server: <strong>$SERVER_NAME</strong> &nbsp;|&nbsp; Zeitraum: $date_from bis $date_to &nbsp;|&nbsp; Erstellt: $(date '+%Y-%m-%d %H:%M:%S')</div>

  <div class="grid">
    <div class="card">
      <h2>Fehlgeschlagen</h2>
      <div class="stat-num danger">$total_fails</div>
    </div>
    <div class="card">
      <h2>Erfolgreich</h2>
      <div class="stat-num success">$total_success</div>
    </div>
    <div class="card">
      <h2>Einzigartige IPs</h2>
      <div class="stat-num info">$total_ips</div>
    </div>
  </div>

$(if [[ -n "$daily_chart_rows" ]]; then echo '
  <div class="section">
    <h2>Tägliche Angriffsverteilung</h2>
    <div class="chart-wrap"><canvas id="dailyChart"></canvas></div>
  </div>'; fi)

  <div class="section">
    <h2>Top IPs</h2>
    <table>
      <thead><tr>
        <th>IP-Adresse</th><th>Versuche</th><th>Erfolge</th>
        <th>Herkunft</th><th>Hoster</th><th>Zuletzt</th><th>Nutzer</th>
      </tr></thead>
      <tbody>$rows</tbody>
    </table>
  </div>

  <div class="section">
    <h2>Top Hoster</h2>
    <table>
      <thead><tr><th>Hoster / ASN</th><th>IPs</th><th>Versuche gesamt</th></tr></thead>
      <tbody>$hoster_rows</tbody>
    </table>
  </div>

  <div class="footer">auth-monitor &mdash; $SERVER_NAME &mdash; $(date '+%Y-%m-%d')</div>
</div>

<script>
$(if [[ -n "$daily_chart_rows" ]]; then cat << JS
new Chart(document.getElementById('dailyChart'), {
  type: 'bar',
  data: {
    labels: $daily_labels,
    datasets: [
      { label: 'Fehlgeschlagen', data: $daily_fails, backgroundColor: '#f87171', borderRadius: 4 },
      { label: 'Erfolgreich',    data: $daily_success, backgroundColor: '#4ade80', borderRadius: 4 }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { labels: { color: '#94a3b8' } } },
    scales: {
      x: { ticks: { color: '#64748b' }, grid: { color: '#1e293b' } },
      y: { ticks: { color: '#64748b' }, grid: { color: '#334155' } }
    }
  }
});
JS
fi)
</script>
</body>
</html>
HTML

    log "HTML generiert: $html_file"
}

# =============================================================================
#  IP-SPEZIFISCHER HTML-REPORT (mit Ereignis-Log)
# =============================================================================
generate_ip_html_report() {
    local ip="$1"
    local date_from="$2"
    local date_to="$3"
    local html_file="$4"

    # --- Geo/Hoster-Info ---
    local hostname country city region org asn hoster
    hostname=$(mysql_exec "SELECT COALESCE(hostname,'(unbekannt)') FROM ip_info WHERE ip='$(mysql_escape "$ip")';" | tail -1)
    country=$(mysql_exec  "SELECT COALESCE(country,'?')            FROM ip_info WHERE ip='$(mysql_escape "$ip")';" | tail -1)
    city=$(mysql_exec     "SELECT COALESCE(city,'?')               FROM ip_info WHERE ip='$(mysql_escape "$ip")';" | tail -1)
    region=$(mysql_exec   "SELECT COALESCE(region,'')              FROM ip_info WHERE ip='$(mysql_escape "$ip")';" | tail -1)
    org=$(mysql_exec      "SELECT COALESCE(org,'?')                FROM ip_info WHERE ip='$(mysql_escape "$ip")';" | tail -1)
    asn=$(mysql_exec      "SELECT COALESCE(asn,'')                 FROM ip_info WHERE ip='$(mysql_escape "$ip")';" | tail -1)
    hoster=$(mysql_exec   "SELECT COALESCE(hoster,'unbekannt')     FROM ip_info WHERE ip='$(mysql_escape "$ip")';" | tail -1)

    # --- Zusammenfassungs-Statistiken aus daily_stats ---
    local stats_row
    stats_row=$(mysql_exec "
        SELECT
            COALESCE(SUM(fail_count),0),
            COALESCE(SUM(success_count),0),
            COALESCE(SUM(invalid_count),0),
            COALESCE(MIN(first_seen),''),
            COALESCE(MAX(last_seen),''),
            COUNT(DISTINCT stat_date)
        FROM daily_stats
        WHERE ip='$(mysql_escape "$ip")'
          AND stat_date BETWEEN '$date_from' AND '$date_to';" | tail -1)
    local total_fails total_success total_invalid first_seen last_seen active_days
    total_fails=$(echo "$stats_row"   | cut -f1)
    total_success=$(echo "$stats_row" | cut -f2)
    total_invalid=$(echo "$stats_row" | cut -f3)
    first_seen=$(echo "$stats_row"    | cut -f4)
    last_seen=$(echo "$stats_row"     | cut -f5)
    active_days=$(echo "$stats_row"   | cut -f6)

    # --- Eindeutige Benutzernamen (aus auth_events + daily_stats zusammenführen) ---
    local ev_users ds_users all_usernames unique_count
    ev_users=$(mysql_exec "
        SELECT GROUP_CONCAT(DISTINCT username ORDER BY username SEPARATOR ',')
        FROM auth_events
        WHERE ip='$(mysql_escape "$ip")' AND log_date BETWEEN '$date_from' AND '$date_to'
          AND username IS NOT NULL AND username != '';" | tail -1)
    ds_users=$(mysql_exec "
        SELECT GROUP_CONCAT(DISTINCT usernames_tried SEPARATOR ',')
        FROM daily_stats
        WHERE ip='$(mysql_escape "$ip")' AND stat_date BETWEEN '$date_from' AND '$date_to'
          AND usernames_tried IS NOT NULL AND usernames_tried != '';" | tail -1)
    all_usernames=$(printf '%s,%s' "$ev_users" "$ds_users" \
        | tr ',' '\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | sort -u | grep -v '^$' \
        | tr '\n' ',' | sed 's/,$//')
    unique_count=$(echo "$all_usernames" | tr ',' '\n' | grep -c '.' 2>/dev/null || echo 0)

    # --- Auffälligkeiten erkennen ---
    local anomaly_html=""
    if [[ "${total_success:-0}" -gt 0 ]] 2>/dev/null; then
        anomaly_html+='<div class="anomaly anomaly-critical">KRITISCH: '"$total_success"' erfolgreiche Login(s) im Berichtszeitraum!</div>'
    fi
    if [[ "${total_fails:-0}" -ge 10 ]] 2>/dev/null; then
        anomaly_html+='<div class="anomaly anomaly-red">Brute-Force erkannt: '"$total_fails"' fehlgeschlagene Anmeldeversuche</div>'
    fi
    if [[ "${unique_count:-0}" -ge 5 ]] 2>/dev/null; then
        anomaly_html+='<div class="anomaly anomaly-orange">User-Enumeration: '"$unique_count"' verschiedene Benutzernamen ausprobiert</div>'
    fi
    [[ -z "$anomaly_html" ]] && anomaly_html='<div class="anomaly anomaly-green">Keine kritischen Auffälligkeiten erkannt</div>'

    # --- Tägliche Verteilung für Chart ---
    local daily_data daily_labels daily_chart_fails daily_chart_success daily_chart_invalid
    daily_data=$(mysql_exec "
        SELECT stat_date, fail_count, COALESCE(success_count,0), COALESCE(invalid_count,0)
        FROM daily_stats
        WHERE ip='$(mysql_escape "$ip")' AND stat_date BETWEEN '$date_from' AND '$date_to'
        ORDER BY stat_date ASC;" | tail -n +1)
    daily_labels="[]"; daily_chart_fails="[]"; daily_chart_success="[]"; daily_chart_invalid="[]"
    if [[ -n "$daily_data" ]]; then
        daily_labels="[$(echo "$daily_data"   | awk -F'\t' '{printf "\"%s\",",$1}' | sed 's/,$//')]"
        daily_chart_fails="[$(echo "$daily_data"   | awk -F'\t' '{printf "%s,",$2}' | sed 's/,$//')]"
        daily_chart_success="[$(echo "$daily_data" | awk -F'\t' '{printf "%s,",$3}' | sed 's/,$//')]"
        daily_chart_invalid="[$(echo "$daily_data" | awk -F'\t' '{printf "%s,",$4}' | sed 's/,$//')]"
    fi

    # --- Ereignis-Log aufbauen ---
    # Individuelle Events (neuere Daten, noch nicht aggregiert)
    local events_raw
    events_raw=$(mysql_exec "
        SELECT timestamp, event_type, IFNULL(NULLIF(username,''),'(unknown)')
        FROM auth_events
        WHERE ip='$(mysql_escape "$ip")' AND log_date BETWEEN '$date_from' AND '$date_to'
        ORDER BY timestamp ASC;" | tail -n +1)

    # Aggregierte Tage (ältere Daten, Events wurden durch daily-reset.sh bereinigt)
    # Nur Tage einbeziehen, für die keine Einzelevents in auth_events existieren
    local hist_raw
    hist_raw=$(mysql_exec "
        SELECT first_seen, last_seen, stat_date, fail_count, success_count, invalid_count, usernames_tried
        FROM daily_stats
        WHERE ip='$(mysql_escape "$ip")'
          AND stat_date BETWEEN '$date_from' AND '$date_to'
          AND stat_date NOT IN (
              SELECT DISTINCT DATE(timestamp) FROM auth_events
              WHERE ip='$(mysql_escape "$ip")' AND log_date BETWEEN '$date_from' AND '$date_to'
          )
        ORDER BY stat_date ASC;" | tail -n +1)

    local log_rows=""

    # Historische Aggregat-Einträge
    while IFS=$'\t' read -r fs ls sdate fc sc ic unames; do
        [[ -z "$sdate" ]] && continue
        local detail="${fc} fehlgeschl."
        [[ "${sc:-0}" -gt 0 ]] && detail+=", ${sc} erfolgreich"
        [[ "${ic:-0}" -gt 0 ]] && detail+=", ${ic} ungültige User"
        local trunc_users
        trunc_users=$(echo "$unames" | cut -c1-80)
        [[ ${#unames} -gt 80 ]] && trunc_users+="…"
        log_rows+="<tr class='row-hist'>
          <td class='ts'>$fs</td>
          <td><span class='badge badge-gray'>AGGREGIERT</span></td>
          <td style='color:#64748b'>$detail &nbsp;<span style='color:#475569;font-size:11px'>[$trunc_users]</span></td>
        </tr>"
        if [[ -n "$ls" && "$ls" != "$fs" ]]; then
            log_rows+="<tr class='row-hist'>
              <td class='ts'>$ls</td>
              <td><span class='badge badge-gray'>AGGREGIERT</span></td>
              <td style='color:#475569;font-size:11px'>letzter Versuch &mdash; $sdate</td>
            </tr>"
        fi
    done <<< "$hist_raw"

    # Individuelle Ereignisse
    while IFS=$'\t' read -r ts etype user; do
        [[ -z "$ts" ]] && continue
        local row_class badge_class badge_label
        case "$etype" in
            FAILED)       row_class="row-failed";  badge_class="badge-red";    badge_label="FEHLGESCHLAGEN"  ;;
            INVALID_USER) row_class="row-invalid"; badge_class="badge-orange"; badge_label="UNGÜLTIGER USER" ;;
            SUCCESS)      row_class="row-success"; badge_class="badge-green";  badge_label="ERFOLG!"         ;;
            *)            row_class="row-hist";    badge_class="badge-gray";   badge_label="$etype"          ;;
        esac
        log_rows+="<tr class='$row_class'>
          <td class='ts'>$ts</td>
          <td><span class='badge $badge_class'>$badge_label</span></td>
          <td><code>$user</code></td>
        </tr>"
    done <<< "$events_raw"

    [[ -z "$log_rows" ]] && log_rows='<tr><td colspan="3" style="text-align:center;color:#64748b;padding:20px">Keine Ereignisse für diesen Zeitraum</td></tr>'

    # --- Username-Tags ---
    local username_tags=""
    IFS=',' read -ra _unames <<< "$all_usernames"
    for _u in "${_unames[@]}"; do
        _u="${_u// /}"
        [[ -z "$_u" ]] && continue
        username_tags+="<span class='utag'>$(echo "$_u" | sed 's/</\&lt;/g;s/>/\&gt;/g')</span> "
    done
    [[ -z "$username_tags" ]] && username_tags='<span style="color:#64748b">Keine Daten</span>'

    # --- Standort-Anzeige ---
    local location_str="$city"
    [[ -n "$region" && "$region" != "$city" ]] && location_str+=" ($region)"

    # --- Erfolge rot färben wenn vorhanden ---
    local success_color="success"
    [[ "${total_success:-0}" -gt 0 ]] 2>/dev/null && success_color="danger"

    # --- HTML ausgeben ---
    cat > "$html_file" << HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>IP-Report: $ip</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;padding:20px}
.wrap{max-width:1200px;margin:0 auto}
h1{font-size:24px;color:#f1f5f9;margin-bottom:4px}
.meta{color:#64748b;font-size:13px;margin-bottom:16px}
.ip-card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:20px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start}
.ip-main{font-size:30px;font-weight:700;color:#93c5fd;font-family:monospace;letter-spacing:.03em}
.ip-loc{color:#94a3b8;font-size:14px;margin-top:6px}
.ip-badge{background:#0f172a;border:1px solid #334155;border-radius:5px;padding:3px 10px;font-size:11px;color:#64748b;margin-right:6px;margin-top:6px;display:inline-block}
.anomaly{border-radius:8px;padding:11px 16px;margin-bottom:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px}
.anomaly-critical{background:#450a0a;border:1px solid #991b1b;color:#fca5a5}
.anomaly-critical::before{content:'🔴 ';font-size:16px}
.anomaly-red{background:#2d0a0a;border:1px solid #7f1d1d;color:#f87171}
.anomaly-red::before{content:'⚠️ ';font-size:16px}
.anomaly-orange{background:#2d1a00;border:1px solid #78350f;color:#fb923c}
.anomaly-orange::before{content:'⚠️ ';font-size:16px}
.anomaly-green{background:#052e16;border:1px solid #14532d;color:#4ade80}
.anomaly-green::before{content:'✅ ';font-size:16px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
.card{background:#1e293b;border-radius:10px;padding:16px;border:1px solid #334155}
.card h2{font-size:11px;color:#64748b;font-weight:500;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
.stat-num{font-size:32px;font-weight:700;line-height:1}
.stat-date{font-size:12px;font-weight:500;color:#94a3b8;line-height:1.5;font-family:monospace}
.danger{color:#f87171}.success{color:#4ade80}.warn{color:#fb923c}.info{color:#60a5fa}
.section{background:#1e293b;border-radius:10px;padding:20px;margin-bottom:16px;border:1px solid #334155}
.section h2{font-size:14px;color:#94a3b8;margin-bottom:14px;font-weight:500;border-bottom:1px solid #334155;padding-bottom:8px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{color:#64748b;font-weight:500;text-align:left;padding:7px 12px;border-bottom:2px solid #334155;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
td{padding:6px 12px;border-bottom:1px solid #1a2744;vertical-align:middle}
tr:last-child td{border-bottom:none}
.ts{font-family:monospace;font-size:12px;color:#94a3b8;white-space:nowrap;width:160px}
.row-failed td{background:rgba(248,113,113,.04)}.row-failed:hover td{background:rgba(248,113,113,.10)}
.row-invalid td{background:rgba(251,146,60,.04)}.row-invalid:hover td{background:rgba(251,146,60,.10)}
.row-success td{background:rgba(74,222,128,.07)}.row-success:hover td{background:rgba(74,222,128,.14)}
.row-hist td{opacity:.6}.row-hist:hover td{opacity:.85}
code{background:#0f172a;padding:1px 6px;border-radius:3px;font-family:monospace;font-size:12px;color:#93c5fd}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase}
.badge-red{background:#450a0a;color:#f87171}
.badge-orange{background:#431407;color:#fb923c}
.badge-green{background:#052e16;color:#4ade80}
.badge-gray{background:#1e293b;color:#64748b;border:1px solid #334155}
.chart-wrap{position:relative;height:200px}
.utag{display:inline-block;background:#1e3a5f;color:#93c5fd;border:1px solid #1e4080;border-radius:4px;padding:2px 8px;font-size:11px;font-family:monospace;margin:2px}
.flag{font-size:11px;background:#334155;padding:1px 5px;border-radius:3px;margin-right:4px}
.footer{text-align:center;color:#475569;font-size:12px;margin-top:20px}
</style>
</head>
<body>
<div class="wrap">

  <h1>IP-Report: $ip</h1>
  <div class="meta">Server: <strong>$SERVER_NAME</strong> &nbsp;|&nbsp; Zeitraum: <strong>$date_from</strong> bis <strong>$date_to</strong> &nbsp;|&nbsp; Erstellt: $(date '+%Y-%m-%d %H:%M:%S')</div>

  <!-- IP-Info-Card -->
  <div class="ip-card">
    <div>
      <div class="ip-main">$ip</div>
      <div class="ip-loc"><span class="flag">$country</span>$location_str</div>
      <div style="margin-top:10px">
        <span class="ip-badge" title="Hoster">$hoster</span>
        <span class="ip-badge" title="ASN">$asn</span>
        <span class="ip-badge" title="Hostname">$hostname</span>
      </div>
    </div>
  </div>

  <!-- Auffälligkeits-Banner -->
  $anomaly_html

  <!-- Stats-Karten -->
  <div class="grid">
    <div class="card">
      <h2>Fehlgeschlagen</h2>
      <div class="stat-num danger">${total_fails:-0}</div>
    </div>
    <div class="card">
      <h2>Erfolge</h2>
      <div class="stat-num $success_color">${total_success:-0}</div>
    </div>
    <div class="card">
      <h2>Ungültige User</h2>
      <div class="stat-num warn">${total_invalid:-0}</div>
    </div>
    <div class="card">
      <h2>Benutzernamen</h2>
      <div class="stat-num info">$unique_count</div>
    </div>
    <div class="card">
      <h2>Aktive Tage</h2>
      <div class="stat-num info">${active_days:-0}</div>
    </div>
    <div class="card">
      <h2>Erster Kontakt</h2>
      <div class="stat-date">${first_seen:-—}</div>
    </div>
    <div class="card">
      <h2>Letzter Kontakt</h2>
      <div class="stat-date">${last_seen:-—}</div>
    </div>
  </div>

  <!-- Täglicher Chart -->
  <div class="section">
    <h2>Tägliche Angriffsverteilung</h2>
    <div class="chart-wrap"><canvas id="dailyChart"></canvas></div>
  </div>

  <!-- Ereignis-Log -->
  <div class="section">
    <h2>Ereignis-Log (chronologisch)</h2>
    <table>
      <thead><tr>
        <th>Zeitstempel</th>
        <th>Typ</th>
        <th>Benutzername / Details</th>
      </tr></thead>
      <tbody>$log_rows</tbody>
    </table>
  </div>

  <!-- Versuchte Benutzernamen -->
  <div class="section">
    <h2>Versuchte Benutzernamen ($unique_count)</h2>
    <div style="padding:4px 0;line-height:2">$username_tags</div>
  </div>

  <div class="footer">auth-monitor &mdash; $SERVER_NAME &mdash; $(date '+%Y-%m-%d')</div>
</div>

<script>
new Chart(document.getElementById('dailyChart'), {
  type: 'bar',
  data: {
    labels: $daily_labels,
    datasets: [
      { label: 'Fehlgeschlagen',  data: $daily_chart_fails,   backgroundColor: '#f87171', borderRadius: 4 },
      { label: 'Erfolge',         data: $daily_chart_success, backgroundColor: '#4ade80', borderRadius: 4 },
      { label: 'Ungültige User',  data: $daily_chart_invalid, backgroundColor: '#fb923c', borderRadius: 4 }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { labels: { color: '#94a3b8' } } },
    scales: {
      x: { ticks: { color: '#64748b' }, grid: { color: '#1e293b' } },
      y: { ticks: { color: '#64748b' }, grid: { color: '#334155' }, beginAtZero: true }
    }
  }
});
</script>
</body>
</html>
HTML

    log "IP-HTML generiert: $html_file"
}

# =============================================================================
#  MAIL SENDEN
# =============================================================================
send_html_mail() {
    local subject="$1"
    local html_file="$2"
    local csv_file="${3:-}"
    local txt_file="${4:-}"

    local boundary="report_$(date +%s)"
    local html_content
    html_content=$(cat "$html_file")

    {
        printf 'From: %s\nTo: %s\nSubject: %s\nMIME-Version: 1.0\n' \
            "$EMAIL_FROM" "$EMAIL_TO" "[auth-monitor] $subject"
        printf 'Content-Type: multipart/mixed; boundary="%s"\n\n' "$boundary"
        printf -- '--%s\nContent-Type: text/html; charset=UTF-8\n\n' "$boundary"
        echo "$html_content"

        if [[ -n "$csv_file" && -f "$csv_file" ]]; then
            local csv_b64
            csv_b64=$(base64 -w 76 "$csv_file")
            printf '\n--%s\nContent-Type: text/csv; name="%s"\n' "$boundary" "$(basename "$csv_file")"
            printf 'Content-Transfer-Encoding: base64\nContent-Disposition: attachment; filename="%s"\n\n' "$(basename "$csv_file")"
            echo "$csv_b64"
        fi
        if [[ -n "$txt_file" && -f "$txt_file" ]]; then
            local txt_b64
            txt_b64=$(base64 -w 76 "$txt_file")
            printf '\n--%s\nContent-Type: text/plain; name="%s"\n' "$boundary" "$(basename "$txt_file")"
            printf 'Content-Transfer-Encoding: base64\nContent-Disposition: attachment; filename="%s"\n\n' "$(basename "$txt_file")"
            echo "$txt_b64"
        fi

        printf '\n--%s--\n' "$boundary"
    } | msmtp --read-envelope-from "$EMAIL_TO" 2>/dev/null \
        && log "Mail gesendet: $subject" \
        || log "WARN: Mail fehlgeschlagen"
}

# =============================================================================
#  MODES
# =============================================================================

mode_day() {
    local date="${1:-$(date -d yesterday '+%Y-%m-%d')}"
    local force="${FORCE:-false}"

    log "Tagesbericht: $date"

    local existing
    existing=$(check_duplicate "day" "$date" "$date" "")
    if [[ -n "$existing" && "$force" != "true" ]]; then
        echo "WARNUNG: Tagesbericht für $date wurde bereits am $existing erstellt!" >&2
        echo "         Verwende --force um trotzdem zu erstellen." >&2
        exit 2
    fi

    local html_file="${REPORT_DIR}/day-${date}.html"
    local csv_file="${REPORT_DIR}/day-${date}.csv"

    generate_html_report "Tagesbericht $date" "$date" "$date" "" "$html_file"

    # CSV
    mysql_exec "SELECT ds.ip, ds.fail_count, ds.success_count, ds.invalid_count,
        COALESCE(ii.country,'?'), COALESCE(ii.city,'?'), COALESCE(ii.hoster,'?'), COALESCE(ii.asn,'?'),
        ds.first_seen, ds.last_seen, ds.usernames_tried
    FROM daily_stats ds LEFT JOIN ip_info ii ON ds.ip=ii.ip
    WHERE ds.stat_date='$date' ORDER BY ds.fail_count DESC;" \
    | awk 'BEGIN{print "ip,fail_count,success_count,invalid_count,country,city,hoster,asn,first_seen,last_seen,usernames"}
           NR>0{OFS=","; print $1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11}' > "$csv_file"

    send_html_mail "Tagesbericht $date — $SERVER_NAME" "$html_file" "$csv_file"
    register_report "day" "$date" "$date" "" "$html_file"
    log "Tagesbericht abgeschlossen: $html_file"
}

mode_week() {
    local date="${1:-$(date -d 'last monday - 7 days' '+%Y-%m-%d')}"
    local week_end
    week_end=$(date -d "$date + 6 days" '+%Y-%m-%d')
    local force="${FORCE:-false}"

    log "Wochenbericht: $date bis $week_end"

    local existing
    existing=$(check_duplicate "week" "$date" "$week_end" "")
    if [[ -n "$existing" && "$force" != "true" ]]; then
        echo "WARNUNG: Wochenbericht für $date–$week_end wurde bereits am $existing erstellt!" >&2
        echo "         Verwende --force um trotzdem zu erstellen." >&2
        exit 2
    fi

    local html_file="${REPORT_DIR}/week-${date}.html"
    local csv_file="${REPORT_DIR}/week-${date}.csv"

    generate_html_report "Wochenbericht $date – $week_end" "$date" "$week_end" "" "$html_file"

    mysql_exec "SELECT ds.ip, SUM(ds.fail_count), SUM(ds.success_count),
        COALESCE(ii.country,'?'), COALESCE(ii.city,'?'), COALESCE(ii.hoster,'?'), COALESCE(ii.asn,'?'),
        MIN(ds.first_seen), MAX(ds.last_seen)
    FROM daily_stats ds LEFT JOIN ip_info ii ON ds.ip=ii.ip
    WHERE ds.stat_date BETWEEN '$date' AND '$week_end'
    GROUP BY ds.ip, ii.country, ii.city, ii.hoster, ii.asn
    ORDER BY SUM(ds.fail_count) DESC;" \
    | awk 'BEGIN{print "ip,fail_count,success_count,country,city,hoster,asn,first_seen,last_seen"}
           NR>0{OFS=","; print $1,$2,$3,$4,$5,$6,$7,$8,$9}' > "$csv_file"

    send_html_mail "Wochenbericht $date – $week_end — $SERVER_NAME" "$html_file" "$csv_file"
    register_report "week" "$date" "$week_end" "" "$html_file"
    log "Wochenbericht abgeschlossen: $html_file"
}

mode_ip() {
    local ip="$1"
    local date_from="${DATE_FROM:-$(date -d '30 days ago' '+%Y-%m-%d')}"
    local date_to="${DATE_TO:-$(date '+%Y-%m-%d')}"
    local force="${FORCE:-false}"

    log "IP-Report: $ip ($date_from bis $date_to)"

    local existing
    existing=$(check_duplicate "day" "$date_from" "$date_to" "$ip")
    if [[ -n "$existing" && "$force" != "true" ]]; then
        echo "WARNUNG: IP-Report für $ip ($date_from–$date_to) wurde bereits am $existing erstellt!" >&2
        echo "         Verwende --force um trotzdem zu erstellen." >&2
        exit 2
    fi

    local safe_ip
    safe_ip=$(echo "$ip" | tr '.' '_')
    local html_file="${REPORT_DIR}/ip-${safe_ip}-${date_from}.html"
    local txt_file="${REPORT_DIR}/ip-${safe_ip}-${date_from}-abuse.txt"

    # Hoster-Info aus DB holen
    local hoster asn country city org
    hoster=$(mysql_exec "SELECT COALESCE(hoster,'unbekannt') FROM ip_info WHERE ip='$ip';" | tail -1)
    asn=$(mysql_exec "SELECT COALESCE(asn,'') FROM ip_info WHERE ip='$ip';" | tail -1)
    country=$(mysql_exec "SELECT COALESCE(country,'?') FROM ip_info WHERE ip='$ip';" | tail -1)
    city=$(mysql_exec "SELECT COALESCE(city,'?') FROM ip_info WHERE ip='$ip';" | tail -1)
    org=$(mysql_exec "SELECT COALESCE(org,'?') FROM ip_info WHERE ip='$ip';" | tail -1)

    # Zusammenfassungs-Statistiken aus daily_stats
    local sum_row
    sum_row=$(mysql_exec "
        SELECT COALESCE(SUM(fail_count),0),
               COALESCE(SUM(success_count),0),
               COALESCE(SUM(invalid_count),0),
               COALESCE(MIN(first_seen),''),
               COALESCE(MAX(last_seen),''),
               COUNT(DISTINCT stat_date)
        FROM daily_stats
        WHERE ip='$(mysql_escape "$ip")'
          AND stat_date BETWEEN '$date_from' AND '$date_to';" | tail -1)
    local sum_fails sum_success sum_invalid sum_first sum_last sum_days
    sum_fails=$(echo "$sum_row"   | cut -f1)
    sum_success=$(echo "$sum_row" | cut -f2)
    sum_invalid=$(echo "$sum_row" | cut -f3)
    sum_first=$(echo "$sum_row"   | cut -f4)
    sum_last=$(echo "$sum_row"    | cut -f5)
    sum_days=$(echo "$sum_row"    | cut -f6)

    # Eindeutige Benutzernamen zählen
    local uniq_users
    uniq_users=$(mysql_exec "
        SELECT COUNT(DISTINCT u) FROM (
            SELECT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(usernames_tried,',',n.n),',',-1)) as u
            FROM daily_stats
            JOIN (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
                  UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
                  UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15
                  UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19 UNION SELECT 20) n
            WHERE ip='$(mysql_escape "$ip")' AND stat_date BETWEEN '$date_from' AND '$date_to'
              AND usernames_tried IS NOT NULL AND usernames_tried != ''
              AND n.n <= 1 + LENGTH(usernames_tried) - LENGTH(REPLACE(usernames_tried,',',''))
        ) t WHERE TRIM(u) != '';" | tail -1)
    uniq_users="${uniq_users:-0}"

    # Auffälligkeiten ermitteln
    local anomalies=""
    [[ "${sum_fails:-0}" -ge 10 ]] 2>/dev/null && \
        anomalies+="  [!] Brute-Force: ${sum_fails} fehlgeschlagene Anmeldeversuche\n"
    [[ "${uniq_users:-0}" -ge 5 ]] 2>/dev/null && \
        anomalies+="  [!] User-Enumeration: ${uniq_users} verschiedene Benutzernamen versucht\n"
    [[ "${sum_success:-0}" -gt 0 ]] 2>/dev/null && \
        anomalies+="  [!!!] KRITISCH: ${sum_success} erfolgreiche Login(s) registriert!\n"
    [[ -z "$anomalies" ]] && anomalies="  Keine kritischen Auffälligkeiten\n"

    # Rohe Events (UNION: Einzelevents + Aggregat mit first_seen/last_seen/Zähler)
    local raw_events
    raw_events=$(mysql_exec "
        SELECT timestamp, event_type,
               IFNULL(NULLIF(username,''),'(unknown)'),
               1, 0, 0, timestamp
        FROM auth_events
        WHERE ip='$(mysql_escape "$ip")' AND log_date BETWEEN '$date_from' AND '$date_to'
        UNION ALL
        SELECT first_seen, 'HISTORICAL',
               IFNULL(NULLIF(usernames_tried,''),'(unknown)'),
               fail_count, COALESCE(success_count,0), COALESCE(invalid_count,0),
               last_seen
        FROM daily_stats
        WHERE ip='$(mysql_escape "$ip")' AND stat_date BETWEEN '$date_from' AND '$date_to'
        ORDER BY 1 ASC;" | tail -n +1)

    local hostname_val
    hostname_val=$(mysql_exec "SELECT COALESCE(hostname,'(unknown)') FROM ip_info WHERE ip='$(mysql_escape "$ip")';" | tail -1)

    # Abuse-Report TXT generieren
    {
    cat << ABUSE
================================================================================
ABUSE REPORT — Unauthorized SSH Login Attempts
================================================================================
Generated:    $(date '+%Y-%m-%d %H:%M:%S UTC')
Reported by:  Maximilian P.
Contact:      abuse@max1021.de

--------------------------------------------------------------------------------
OFFENDING IP ADDRESS
--------------------------------------------------------------------------------
IP Address:   $ip
Hostname:     $hostname_val
Country:      $country
City:         $city
Organization: $org
ASN:          $asn
Hoster:       $hoster

--------------------------------------------------------------------------------
INCIDENT SUMMARY
--------------------------------------------------------------------------------
Attack type:  Brute-force SSH login attempts
Target:       $SERVER_NAME (SSH Port 22)
Period:       $date_from to $date_to
First seen:   ${sum_first:-(unbekannt)}
Last seen:    ${sum_last:-(unbekannt)}
Active days:  ${sum_days:-0}

Failed logins:  ${sum_fails:-0}
Successful:     ${sum_success:-0}
Invalid users:  ${sum_invalid:-0}
Usernames used: ${uniq_users:-0} distinct

Anomalies:
ABUSE
    printf "%b" "$anomalies"
    cat << ABUSE

The IP address listed above has repeatedly attempted unauthorized access to our
server via SSH. The attempts include password brute-forcing and scanning for
valid usernames. We kindly request that you investigate this activity and take
appropriate action against the source.

--------------------------------------------------------------------------------
CHRONOLOGICAL EVENT LOG
--------------------------------------------------------------------------------
Timestamp              | Type            | Username
-----------------------+-----------------+----------------------------------
ABUSE

    # Log-Einträge schreiben
    while IFS=$'\t' read -r ts etype user fc sc ic ts2; do
        [[ -z "$ts" ]] && continue
        if [[ "$etype" == "HISTORICAL" ]]; then
            # Aggregierter Tag: fail_count Zeilen mit interpolierten Timestamps
            local detail=""
            [[ "${fc:-0}" -gt 0 ]] && detail+="${fc} fehlg."
            [[ "${sc:-0}" -gt 0 ]] && detail+=", ${sc} erfolgr."
            [[ "${ic:-0}" -gt 0 ]] && detail+=", ${ic} ungült. User"
            printf "%-22s | %-15s | %s\n" "$ts" "AGGREGIERT" \
                "---- ${detail} (Zeitraum: $ts bis $ts2) ----"

            # Usernames in Array laden
            IFS=',' read -ra _unames <<< "$user"
            local _uclean=()
            for _u in "${_unames[@]}"; do
                _u="${_u// /}"
                [[ -n "$_u" ]] && _uclean+=("$_u")
            done
            local ucount="${#_uclean[@]}"
            [[ "$ucount" -eq 0 ]] && continue

            # Timestamps interpolieren: fail_count Versuche gleichmäßig verteilt
            local fs_epoch ls_epoch duration
            fs_epoch=$(date -d "$ts"  +%s 2>/dev/null || echo 0)
            ls_epoch=$(date -d "$ts2" +%s 2>/dev/null || echo "$fs_epoch")
            duration=$(( ls_epoch - fs_epoch ))
            local total_lines="${fc:-$ucount}"
            [[ "$total_lines" -lt "$ucount" ]] && total_lines="$ucount"

            local attempt=0
            while [[ $attempt -lt $total_lines ]]; do
                local uidx=$(( attempt % ucount ))
                local cur_epoch cur_ts
                if [[ $total_lines -gt 1 && $duration -gt 0 ]]; then
                    cur_epoch=$(( fs_epoch + duration * attempt / (total_lines - 1) ))
                else
                    cur_epoch=$fs_epoch
                fi
                cur_ts=$(date -d "@${cur_epoch}" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "$ts")
                printf "%-22s | %-15s | %s\n" "$cur_ts" "FAILED" "${_uclean[$uidx]}"
                attempt=$(( attempt + 1 ))
            done
        else
            local label="$etype"
            [[ "$etype" == "FAILED" ]]       && label="FAILED"
            [[ "$etype" == "INVALID_USER" ]] && label="INVALID_USER"
            [[ "$etype" == "SUCCESS" ]]       && label="SUCCESS (!)"
            printf "%-22s | %-15s | %s\n" "$ts" "$label" "$user"
        fi
    done <<< "$raw_events"

    cat << ABUSE

--------------------------------------------------------------------------------
This report was generated automatically by auth-monitor.
Please report abuse to the responsible upstream provider if necessary.
================================================================================
ABUSE
    } > "$txt_file"

    log "Abuse-TXT generiert: $txt_file"

    generate_ip_html_report "$ip" "$date_from" "$date_to" "$html_file"
    send_html_mail "IP-Report: $ip ($date_from–$date_to)" "$html_file" "" "$txt_file"
    register_report "day" "$date_from" "$date_to" "$ip" "$html_file"
    log "IP-Report abgeschlossen: $html_file"
}

mode_list() {
    echo ""
    mysql_exec "SELECT report_type, period_start, period_end,
        IF(ip_filter='','(alle IPs)',ip_filter) as ip_filter,
        created_at, sent_to
    FROM reports ORDER BY created_at DESC LIMIT 20;" | \
    awk 'BEGIN{printf "%-6s %-12s %-12s %-18s %-20s %s\n","Typ","Von","Bis","IP-Filter","Erstellt","Gesendet an"}
         {printf "%-6s %-12s %-12s %-18s %-20s %s\n",$1,$2,$3,$4,$5,$6}'
    echo ""
}

# =============================================================================
#  ENTRY POINT
# =============================================================================
FORCE=false
DATE_FROM=""
DATE_TO=""

print_help() { cat >&2 << EOF
auth-report.sh v2 — IP-Reports mit Duplikat-Schutz

Usage:
  $(basename "$0") --day [YYYY-MM-DD]                  Tagesbericht (default: gestern)
  $(basename "$0") --week [YYYY-MM-DD]                 Wochenbericht (default: letzte Woche)
  $(basename "$0") --ip 1.2.3.4 [--from X] [--to Y]   Report für eine IP
  $(basename "$0") --list                              Bisherige Reports anzeigen
  $(basename "$0") --force                             Duplikat-Schutz überspringen

Crontab (täglich 20:00):
  0 20 * * * /opt/auth-report.sh --day
EOF
}

[[ $# -eq 0 ]] && { print_help; exit 0; }

MODE=""
MODE_ARG=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --force) FORCE=true; shift ;;
        --from)  DATE_FROM="$2"; shift 2 ;;
        --to)    DATE_TO="$2"; shift 2 ;;
        --day)
            MODE="day"
            shift
            [[ $# -gt 0 && "$1" != --* ]] && { MODE_ARG="$1"; shift; }
            ;;
        --week)
            MODE="week"
            shift
            [[ $# -gt 0 && "$1" != --* ]] && { MODE_ARG="$1"; shift; }
            ;;
        --ip)
            MODE="ip"
            shift
            MODE_ARG="${1:?'IP-Adresse fehlt'}"
            shift
            ;;
        --list)
            MODE="list"
            shift
            ;;
        --help|-h)
            print_help
            exit 0
            ;;
        *)
            echo "Unbekannte Option: $1" >&2
            print_help
            exit 1
            ;;
    esac
done

case "$MODE" in
    day)  mode_day  "$MODE_ARG" ;;
    week) mode_week "$MODE_ARG" ;;
    ip)   mode_ip   "$MODE_ARG" ;;
    list) mode_list ;;
    *)    print_help ;;
esac
