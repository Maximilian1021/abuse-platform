#!/usr/bin/env bash
# =============================================================================
#  daily-reset.sh — Tägliche Aggregation + Reset
#  Cron: 1 0 * * * /opt/daily-reset.sh >> /var/log/auth-monitor/daily-reset.log 2>&1
# =============================================================================

CONF_FILE="$(dirname "$0")/auth-monitor.conf"
if [[ ! -f "$CONF_FILE" ]]; then echo "ERROR: $CONF_FILE nicht gefunden" >&2; exit 1; fi
# shellcheck source=auth-monitor.conf
source "$CONF_FILE"
SERVER_NAME=$(hostname -f 2>/dev/null || hostname)

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

mysql_exec() {
    mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
          --password="$DB_PASS" --database="$DB_NAME" \
          --connect-timeout=10 --silent 2>/dev/null <<< "$1"
}

YESTERDAY=$(date -d yesterday '+%Y-%m-%d')

log "=== Daily Reset Start — aggregiere $YESTERDAY ==="

# 1. Aggregiere gestrige Events in daily_stats
log "Aggregiere auth_events -> daily_stats für $YESTERDAY ..."
mysql_exec "
INSERT INTO daily_stats (stat_date, ip, fail_count, success_count, invalid_count, usernames_tried, first_seen, last_seen, server)
SELECT
    log_date,
    ip,
    SUM(event_type IN ('FAILED','INVALID_USER'))   AS fail_count,
    SUM(event_type = 'SUCCESS')                    AS success_count,
    SUM(event_type = 'INVALID_USER')               AS invalid_count,
    GROUP_CONCAT(DISTINCT NULLIF(username,'') ORDER BY username SEPARATOR ', ') AS usernames_tried,
    MIN(timestamp) AS first_seen,
    MAX(timestamp) AS last_seen,
    server
FROM auth_events
WHERE log_date = '$YESTERDAY'
GROUP BY log_date, ip, server
ON DUPLICATE KEY UPDATE
    fail_count      = VALUES(fail_count),
    success_count   = VALUES(success_count),
    invalid_count   = VALUES(invalid_count),
    usernames_tried = VALUES(usernames_tried),
    first_seen      = VALUES(first_seen),
    last_seen       = VALUES(last_seen);
"

ROWS=$(mysql_exec "SELECT ROW_COUNT();" | tail -1)
log "daily_stats: $ROWS IPs aggregiert"

# 2. Lösche gestrige Events aus auth_events
log "Lösche auth_events für $YESTERDAY ..."
mysql_exec "DELETE FROM auth_events WHERE log_date = '$YESTERDAY';"
DELETED=$(mysql_exec "SELECT ROW_COUNT();" | tail -1)
log "auth_events: $DELETED Zeilen gelöscht"

# 3. Statistik
TOTAL_FAILS=$(mysql_exec "SELECT COALESCE(SUM(fail_count),0) FROM daily_stats WHERE stat_date='$YESTERDAY';" | tail -1)
TOTAL_IPS=$(mysql_exec "SELECT COUNT(DISTINCT ip) FROM daily_stats WHERE stat_date='$YESTERDAY';" | tail -1)
TOP_IP=$(mysql_exec "SELECT CONCAT(ip,' (',fail_count,' Versuche)') FROM daily_stats WHERE stat_date='$YESTERDAY' ORDER BY fail_count DESC LIMIT 1;" | tail -1)

log "Zusammenfassung $YESTERDAY: $TOTAL_FAILS Fehlversuche von $TOTAL_IPS IPs | Top: $TOP_IP"
log "=== Daily Reset Ende ==="
