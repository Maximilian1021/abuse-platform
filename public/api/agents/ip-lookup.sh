#!/usr/bin/env bash
# =============================================================================
#  ip-lookup.sh — IP Anreicherung via ipinfo.io
#  Cron: 0 6 * * * root /opt/auth-monitor/ip-lookup.sh >> /var/log/auth-monitor/ip-lookup.log 2>&1
#  Auch aufrufbar: /opt/auth-monitor/ip-lookup.sh --ip 1.2.3.4
# =============================================================================

set -uo pipefail

CONFIG_FILE="/opt/auth-monitor/config.sh"
if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "ERROR: $CONFIG_FILE nicht gefunden" >&2
    exit 1
fi
source "$CONFIG_FILE"

IPINFO_TOKEN="${IPINFO_TOKEN:-}"
CACHE_DAYS="${IP_CACHE_DAYS:-7}"
RATE_LIMIT_SLEEP=0.2  # Sekunden zwischen API-Calls (kostenlos: 50k/Monat)

log() { echo "[$(date '+%H:%M:%S')] $*"; }

mysql_exec() {
    mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
          --password="$DB_PASS" --database="$DB_NAME" \
          --connect-timeout=10 --silent 2>/dev/null <<< "$1"
}

mysql_escape() { echo "$1" | sed "s/'/\\\\'/g" | sed 's/\\/\\\\/g'; }

lookup_ip() {
    local ip="$1"
    local now
    now=$(date '+%Y-%m-%d %H:%M:%S')

    local token_param=""
    [[ -n "$IPINFO_TOKEN" ]] && token_param="?token=${IPINFO_TOKEN}"

    local response
    response=$(curl -s -m 10 "https://ipinfo.io/${ip}${token_param}" 2>/dev/null)
    [[ -z "$response" ]] && { log "WARN: Keine Antwort für $ip"; return 1; }

    local hostname city region country org timezone
    hostname=$(echo "$response" | grep '"hostname"' | sed 's/.*: *"\(.*\)".*/\1/' | head -1)
    city=$(echo "$response"     | grep '"city"'     | sed 's/.*: *"\(.*\)".*/\1/' | head -1)
    region=$(echo "$response"   | grep '"region"'   | sed 's/.*: *"\(.*\)".*/\1/' | head -1)
    country=$(echo "$response"  | grep '"country"'  | sed 's/.*: *"\(.*\)".*/\1/' | head -1)
    org=$(echo "$response"      | grep '"org"'      | sed 's/.*: *"\(.*\)".*/\1/' | head -1)
    timezone=$(echo "$response" | grep '"timezone"' | sed 's/.*: *"\(.*\)".*/\1/' | head -1)

    local asn hoster
    asn=$(echo "$org" | grep -oE '^AS[0-9]+' || echo '')
    hoster=$(echo "$org" | sed 's/^AS[0-9]* //' || echo "$org")

    local e_ip e_hostname e_city e_region e_country e_org e_asn e_hoster e_tz
    e_ip=$(mysql_escape "$ip")
    e_hostname=$(mysql_escape "${hostname:-}")
    e_city=$(mysql_escape "${city:-}")
    e_region=$(mysql_escape "${region:-}")
    e_country=$(mysql_escape "${country:-}")
    e_org=$(mysql_escape "${org:-}")
    e_asn=$(mysql_escape "${asn:-}")
    e_hoster=$(mysql_escape "${hoster:-}")
    e_tz=$(mysql_escape "${timezone:-}")

    mysql_exec "
INSERT INTO ip_info (ip, hostname, city, region, country, org, asn, hoster, timezone, cached_at)
VALUES ('$e_ip','$e_hostname','$e_city','$e_region','$e_country','$e_org','$e_asn','$e_hoster','$e_tz','$now')
ON DUPLICATE KEY UPDATE
    hostname  = '$e_hostname',
    city      = '$e_city',
    region    = '$e_region',
    country   = '$e_country',
    org       = '$e_org',
    asn       = '$e_asn',
    hoster    = '$e_hoster',
    timezone  = '$e_tz',
    cached_at = '$now';
"
    log "OK: $ip -> ${country:-?} / ${city:-?} / ${hoster:-?} (${asn:-?})"
    sleep "$RATE_LIMIT_SLEEP"
}

# Einzelne IP per Argument
if [[ "${1:-}" == "--ip" && -n "${2:-}" ]]; then
    lookup_ip "$2"
    exit 0
fi

# Alle IPs aus daily_stats die noch nicht oder zu alt gecacht sind
log "=== IP-Lookup Start ==="

CACHE_CUTOFF=$(date -d "-${CACHE_DAYS} days" '+%Y-%m-%d %H:%M:%S')

IPS=$(mysql_exec "
SELECT DISTINCT ds.ip
FROM daily_stats ds
LEFT JOIN ip_info ii ON ds.ip = ii.ip
WHERE ii.ip IS NULL OR ii.cached_at < '$CACHE_CUTOFF'
ORDER BY ds.ip;
" | tail -n +1)

TOTAL=$(echo "$IPS" | grep -c '\.' || echo 0)
log "$TOTAL IPs zu verarbeiten..."

COUNT=0
while IFS= read -r ip; do
    [[ -z "$ip" ]] && continue
    COUNT=$((COUNT + 1))
    log "[$COUNT/$TOTAL] $ip"
    lookup_ip "$ip"
done <<< "$IPS"

log "=== IP-Lookup Ende -- $COUNT IPs verarbeitet ==="
