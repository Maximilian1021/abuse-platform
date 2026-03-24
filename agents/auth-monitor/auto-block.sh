#!/usr/bin/env bash
# =============================================================================
#  auto-block.sh — Automatischer IP-Block nach daily-reset
#  Läuft nach daily-reset.sh um 00:05
#  Blockt alle IPs vom Vortag via iptables
#  Whitelist: DynDNS + statische IPs
# =============================================================================

CONF_FILE="$(dirname "$0")/auth-monitor.conf"
if [[ ! -f "$CONF_FILE" ]]; then echo "ERROR: $CONF_FILE nicht gefunden" >&2; exit 1; fi
# shellcheck source=auth-monitor.conf
source "$CONF_FILE"

# =============================================================================
#  CONFIG
# =============================================================================

# DynDNS + Whitelist kommen aus auth-monitor.conf (DYNDNS, WHITELIST_STATIC)
# Zusätzliche statische Whitelist-IPs (eigene Server, Büro etc.)
WHITELIST_STATIC=(
    "127.0.0.1"
    "::1"
    "2.56.244.116"  # eigene Server-IP (Webhook-Server)
    # "1.2.3.4"   # weitere IPs hier eintragen
)

# Mindest-Fehlversuche um geblockt zu werden (Schutz vor false positives)
MIN_FAILS=5

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

    # Statische IPs
    for ip in "${WHITELIST_STATIC[@]}"; do
        whitelist+=("$ip")
    done

    # DynDNS auflösen (IPv4)
    local dyn_ip
    dyn_ip=$(dig +short "$DYNDNS" A 2>/dev/null | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -1)
    if [[ -n "$dyn_ip" ]]; then
        whitelist+=("$dyn_ip")
        log "DynDNS $DYNDNS → $dyn_ip (whitelisted)"
    else
        log "WARN: DynDNS $DYNDNS konnte nicht aufgelöst werden!"
    fi

    # Server eigene IPs
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
    # Chain anlegen falls nicht vorhanden
    if ! iptables -L "$CHAIN" &>/dev/null; then
        iptables -N "$CHAIN"
        log "iptables Chain '$CHAIN' angelegt"
    fi

    # Chain in INPUT einhängen falls noch nicht
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
    # Nur hinzufügen wenn noch nicht vorhanden (iptables -C prüft exakte Regel)
    if ! iptables -C "$CHAIN" -s "$ip" -j DROP 2>/dev/null; then
        iptables -A "$CHAIN" -s "$ip" -j DROP
        return 0
    fi
    return 1
}

# Alle Blocks aus der Chain entfernen (für cleanup)
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
#  MAIN — IPs vom Vortag blocken
# =============================================================================

YESTERDAY=$(date -d yesterday '+%Y-%m-%d')
WINDOW_START=$(date -d '30 days ago' '+%Y-%m-%d')

log "=== Auto-Block Start — Vortag: $YESTERDAY ==="

# Whitelist aufbauen (log() schreibt nur noch nach stderr, nicht in den Rückgabewert)
WHITELIST=$(build_whitelist)
log "Whitelist: $WHITELIST"

# Chain vorbereiten und vorher leeren (verhindert unbegrenztes Wachstum & Duplikate)
setup_chain
flush_chain

# IPs der letzten 30 Tage mit genug Fehlversuchen holen (kumulativ, nicht nur Vortag)
IPS=$(mysql_exec "
    SELECT ip, SUM(fail_count) as fails
    FROM daily_stats
    WHERE stat_date BETWEEN '$WINDOW_START' AND '$YESTERDAY'
    GROUP BY ip
    HAVING fails >= $MIN_FAILS
    ORDER BY fails DESC;
" | tail -n +1)

TOTAL=$(echo "$IPS" | grep -c '\.' || echo 0)
log "$TOTAL IPs zum Blocken gefunden (>= $MIN_FAILS Fehlversuche)"

BLOCKED=0
SKIPPED_WL=0
SKIPPED_DUP=0

while IFS=$'\t' read -r ip fails; do
    [[ -z "$ip" ]] && continue

    # Whitelist prüfen
    if is_whitelisted "$ip" "$WHITELIST"; then
        log "SKIP (whitelist): $ip ($fails Versuche)"
        SKIPPED_WL=$((SKIPPED_WL + 1))
        continue
    fi

    # Blocken
    if block_ip "$ip"; then
        log "BLOCKED: $ip ($fails Versuche)"
        BLOCKED=$((BLOCKED + 1))
    else
        SKIPPED_DUP=$((SKIPPED_DUP + 1))
    fi

done <<< "$IPS"

# Regeln persistieren
save_rules

log "=== Auto-Block Ende — Geblockt: $BLOCKED | Whitelist: $SKIPPED_WL | Bereits geblockt: $SKIPPED_DUP ==="
log "Aktuelle Blocks in Chain: $(iptables -L "$CHAIN" | grep -c DROP || echo 0)"
