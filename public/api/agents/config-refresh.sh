#!/usr/bin/env bash
# =============================================================================
#  config-refresh.sh — Konfiguration von der Plattform aktualisieren (Heartbeat)
#  Cron: 0 * * * * root /opt/auth-monitor/config-refresh.sh >> /var/log/auth-monitor/config-refresh.log 2>&1
# =============================================================================

set -uo pipefail

CONFIG_FILE="/opt/auth-monitor/config.sh"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

if [[ ! -f "$CONFIG_FILE" ]]; then
    log "ERROR: $CONFIG_FILE nicht gefunden"
    exit 1
fi

# Aktuellen Token + URLs aus bestehender Config laden
source "$CONFIG_FILE"

if [[ -z "${SERVER_TOKEN:-}" || -z "${CENTRAL_CONFIG_URL:-}" ]]; then
    log "ERROR: SERVER_TOKEN oder CENTRAL_CONFIG_URL fehlt in $CONFIG_FILE"
    exit 1
fi

log "Config-Refresh von ${CENTRAL_CONFIG_URL}..."

NEW_CONFIG=$(curl -s --max-time 15 "${CENTRAL_CONFIG_URL}?token=${SERVER_TOKEN}" 2>/dev/null)

if [[ -z "$NEW_CONFIG" ]]; then
    log "WARN: Leere Antwort vom Config-Endpoint — behalte bestehende Config"
    exit 1
fi

# Auf HTTP-Fehler prüfen (Antwort sollte bash-sourceable KEY="VALUE" sein)
if echo "$NEW_CONFIG" | grep -qE "^(403|401|<!DOCTYPE|<html)"; then
    log "WARN: Ungültige Antwort vom Config-Endpoint (Token abgelaufen?)"
    exit 1
fi

# Neue Config schreiben + server-spezifische Werte erhalten
cat > "$CONFIG_FILE" <<EOF
${NEW_CONFIG}

# Server-Identifikation
SERVER_TOKEN="${SERVER_TOKEN}"
SERVER_NAME_OVERRIDE="${SERVER_NAME_OVERRIDE:-}"
CENTRAL_CONFIG_URL="${CENTRAL_CONFIG_URL}"
EOF

chmod 600 "$CONFIG_FILE"
log "Config-Refresh OK"
