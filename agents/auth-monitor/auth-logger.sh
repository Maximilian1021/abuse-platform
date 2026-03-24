#!/usr/bin/env bash
# =============================================================================
#  auth-logger.sh — MySQL Logger für auth-monitor.sh
#  Wird von auth-monitor.sh aufgerufen ODER standalone per Pipe genutzt
#
#  Standalone-Nutzung:
#    ./auth-monitor.sh --watch 2>&1 | grep -E "FAIL|SUCCESS" | ./auth-logger.sh
#
#  Integration in auth-monitor.sh:
#    In analyse_event() nach jedem Event: log_to_mysql "$EVENT_TYPE" "$IP" "$AUTH_USER"
# =============================================================================

# =============================================================================
#  CONFIG
# =============================================================================

CONF_FILE="$(dirname "$0")/auth-monitor.conf"
if [[ ! -f "$CONF_FILE" ]]; then echo "ERROR: $CONF_FILE nicht gefunden" >&2; exit 1; fi
# shellcheck source=auth-monitor.conf
source "$CONF_FILE"

# Retry bei MySQL-Verbindungsfehler
DB_RETRY=3
DB_RETRY_WAIT=2

# Server-Identifier in der DB
SERVER_NAME=$(hostname -f 2>/dev/null || hostname)

# =============================================================================
#  MYSQL HELPER
# =============================================================================

mysql_cmd() {
    mysql \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USER" \
        --password="$DB_PASS" \
        --database="$DB_NAME" \
        --connect-timeout=10 \
        --silent \
        --skip-column-names \
        2>/dev/null
}

mysql_exec() {
    local sql="$1"
    local attempt=0
    while [[ $attempt -lt $DB_RETRY ]]; do
        printf '%s' "$sql" | mysql_cmd && return 0
        attempt=$((attempt + 1))
        sleep "$DB_RETRY_WAIT"
    done
    echo "[auth-logger] WARN: MySQL query failed after $DB_RETRY attempts" >&2
    return 1
}

# Escapes single quotes in strings for MySQL
mysql_escape() {
    echo "$1" | sed "s/'/\\\\'/g"
}

# =============================================================================
#  CORE FUNCTION — einen Event in die DB schreiben
# =============================================================================

log_to_mysql() {
    local event_type="$1"   # FAILED | INVALID_USER | SUCCESS
    local ip="$2"
    local username="${3:-}"
    local ts
    ts=$(date '+%Y-%m-%d %H:%M:%S')

    # Validierung
    [[ -z "$ip" ]] && return 0
    echo "$ip" | grep -qE "^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$" || return 0
    [[ "$event_type" =~ ^(FAILED|INVALID_USER|SUCCESS)$ ]] || return 0

    local esc_ip esc_user esc_server
    esc_ip=$(mysql_escape "$ip")
    esc_user=$(mysql_escape "${username:-}")
    esc_server=$(mysql_escape "$SERVER_NAME")

    # Event einfügen
    mysql_exec "INSERT INTO auth_events (timestamp, log_date, ip, username, event_type, server)
                VALUES ('$ts', DATE('$ts'), '$esc_ip', '$esc_user', '$event_type', '$esc_server');"
}

# =============================================================================
#  VERBINDUNGSTEST
# =============================================================================

test_connection() {
    echo "SELECT 'OK' AS connection_test;" | mysql_cmd
    if [[ $? -eq 0 ]]; then
        echo "[auth-logger] MySQL Verbindung OK ($DB_HOST:$DB_PORT/$DB_NAME)" >&2
        return 0
    else
        echo "[auth-logger] ERROR: MySQL Verbindung fehlgeschlagen!" >&2
        echo "  Host: $DB_HOST:$DB_PORT" >&2
        echo "  User: $DB_USER / DB: $DB_NAME" >&2
        return 1
    fi
}

# =============================================================================
#  ENTRY POINT
# =============================================================================

case "${1:-}" in
    --test)
        test_connection
        ;;
    --log)
        # Direkte Nutzung: auth-logger.sh --log FAILED 1.2.3.4 root
        log_to_mysql "${2:-}" "${3:-}" "${4:-}"
        ;;
    --help|-h)
        cat >&2 <<EOF
auth-logger.sh — MySQL Logger für auth-monitor

Usage:
  $(basename "$0") --test                     Verbindungstest
  $(basename "$0") --log FAILED 1.2.3.4 root  Einzelnen Event loggen
  source $(basename "$0")                      Als Library in auth-monitor.sh

Config: DB_HOST / DB_PORT / DB_USER / DB_PASS / DB_NAME oben im Skript
EOF
        ;;
    *)
        # Wird als Library gesourced — nichts tun
        ;;
esac
