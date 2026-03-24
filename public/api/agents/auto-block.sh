#!/usr/bin/env bash
# =============================================================================
#  auto-block.sh — Automatischer IP-Block nach daily-reset
#  Läuft täglich um 00:05 (nach daily-reset.sh um 00:01)
#  Blockt alle IPs der letzten N Tage via iptables
#  Whitelist: DynDNS + statische IPs + eigene Server-IPs
# =============================================================================

set -uo pipefail

CONFIG_FILE="/opt/auth-monitor/config.sh"
if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "ERROR: $CONFIG_FILE nicht gefunden" >&2
    exit 1
fi
source "$CONFIG_FILE"

# Schwellenwerte aus Config
MIN_FAILS="${MIN_BLOCK_FAILS:-5}"
BLOCK_WINDOW_DAYS="${BLOCK_WINDOW_DAYS:-30}"

# =============================================================================
#  WHITELIST CONFIG
#  DynDNS und statische IPs die NIEMALS geblockt werden
# =============================================================================

# DynDNS — wird bei jedem Lauf frisch aufgelöst (leer = deaktiviert)
DYNDNS=""

# Statische Whitelist-IPs (eigene Server, Büro etc.)
WHITELIST_STATIC=(
    "127.0.0.1"
    "::1"
    # "1.2.3.4"   # weitere IPs hier eintragen
)

# iptables Chain Name
CHAIN="AUTH_MONITOR_BLOCK"

LOG_FILE="/var/log/auth-monitor/auto-block.log"

# =============================================================================
#  SETUP
# =============================================================================

mkdir -p "$(dirname "$LOG_FILE")"
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE" >&2; }

mysql_exec() {
    mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
          --password="$DB_PASS" --database="$DB_NAME" \
          --connect-timeout=10 --silent 2>/dev/null <<< "$1"
}

# =============================================================================
#  WHITELIST AUFBAUEN
# =============================================================================

build_whitelist() {
    local whitelist=()

    for ip in "${WHITELIST_STATIC[@]}"; do
        whitelist+=("$ip")
    done

    # DynDNS auflösen (optional)
    if [[ -n "$DYNDNS" ]]; then
        local dyn_ip
        dyn_ip=$(dig +short "$DYNDNS" A 2>/dev/null | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -1)
        if [[ -n "$dyn_ip" ]]; then
            whitelist+=("$dyn_ip")
            log "DynDNS $DYNDNS -> $dyn_ip (whitelisted)"
        else
            log "WARN: DynDNS $DYNDNS konnte nicht aufgelöst werden!"
        fi
    fi

    # Eigene Server-IPs automatisch whitelisten
    while IFS= read -r ip; do
        [[ -n "$ip" ]] && whitelist+=("$ip")
    done < <(hostname -I 2>/dev/null | tr ' ' '\n' | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+')

    echo "${whitelist[@]}"
}

is_whitelisted() {
    local check_ip wl_ip wl
    check_ip=$(echo "$1" | tr -d '[:space:]')
    wl=($2)
    for wl_ip in "${wl[@]}"; do
        [[ "$(echo "$wl_ip" | tr -d '[:space:]')" == "$check_ip" ]] && return 0
    done
    return 1
}

# =============================================================================
#  IPTABLES CHAIN SETUP
# =============================================================================

setup_chain() {
    if ! iptables -L "$CHAIN" &>/dev/null; then
        iptables -N "$CHAIN"
        log "iptables Chain '$CHAIN' angelegt"
    fi

    if ! iptables -L INPUT | grep -q "$CHAIN"; then
        iptables -I INPUT 1 -j "$CHAIN"
        log "Chain '$CHAIN' in INPUT eingehängt"
    fi
}

# =============================================================================
#  BLOCK / UNBLOCK
# =============================================================================

block_ip() {
    local ip="$1"
    if ! iptables -C "$CHAIN" -s "$ip" -j DROP 2>/dev/null; then
        iptables -A "$CHAIN" -s "$ip" -j DROP
        return 0
    fi
    return 1
}

flush_chain() {
    iptables -F "$CHAIN" 2>/dev/null
    log "Chain '$CHAIN' geleert"
}

# =============================================================================
#  PERSISTENZ — Blocks überleben Reboots
# =============================================================================

save_rules() {
    if command -v iptables-save &>/dev/null; then
        iptables-save > /etc/iptables/rules.v4 2>/dev/null || \
        iptables-save > /etc/iptables.rules 2>/dev/null || true
    fi
}

# =============================================================================
#  MAIN
# =============================================================================

YESTERDAY=$(date -d yesterday '+%Y-%m-%d')
WINDOW_START=$(date -d "${BLOCK_WINDOW_DAYS} days ago" '+%Y-%m-%d')

log "=== Auto-Block Start — Fenster: $WINDOW_START bis $YESTERDAY | Min. Fehlversuche: $MIN_FAILS ==="

WHITELIST=$(build_whitelist)
log "Whitelist: $WHITELIST"

setup_chain
flush_chain

IPS=$(mysql_exec "
    SELECT ip, SUM(fail_count) as fails
    FROM daily_stats
    WHERE stat_date BETWEEN '$WINDOW_START' AND '$YESTERDAY'
    GROUP BY ip
    HAVING fails >= $MIN_FAILS
    ORDER BY fails DESC;
" | tail -n +1)

TOTAL=$(echo "$IPS" | grep -c '\.' || echo 0)
log "$TOTAL IPs zum Blocken gefunden (>= $MIN_FAILS Fehlversuche in $BLOCK_WINDOW_DAYS Tagen)"

BLOCKED=0
SKIPPED_WL=0
SKIPPED_DUP=0

while IFS=$'\t' read -r ip fails; do
    [[ -z "$ip" ]] && continue

    if is_whitelisted "$ip" "$WHITELIST"; then
        log "SKIP (whitelist): $ip ($fails Versuche)"
        SKIPPED_WL=$((SKIPPED_WL + 1))
        continue
    fi

    if block_ip "$ip"; then
        log "BLOCKED: $ip ($fails Versuche)"
        BLOCKED=$((BLOCKED + 1))
    else
        SKIPPED_DUP=$((SKIPPED_DUP + 1))
    fi

done <<< "$IPS"

save_rules

log "=== Auto-Block Ende — Geblockt: $BLOCKED | Whitelist: $SKIPPED_WL | Bereits geblockt: $SKIPPED_DUP ==="
log "Aktuelle Blocks in Chain: $(iptables -L "$CHAIN" | grep -c DROP || echo 0)"
