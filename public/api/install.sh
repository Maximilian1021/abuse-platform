#!/usr/bin/env bash
# =============================================================================
#  Auth Monitor — Installer
#  curl -s https://abuse.max1021.de/api/install.sh | REG_TOKEN=xxx bash
# =============================================================================

set -euo pipefail

PLATFORM="https://abuse.max1021.de/api"
INSTALL_DIR="/opt/auth-monitor"
SERVICE_NAME="auth-monitor"
LOG_DIR="/var/log/auth-monitor"
CRON_FILE="/etc/cron.d/auth-monitor"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

info()    { echo -e "${CYAN}[INFO]${RESET}  $*"; }
success() { echo -e "${GREEN}[OK]${RESET}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${RESET}  $*"; }
error()   { echo -e "${RED}[ERROR]${RESET} $*" >&2; exit 1; }
header()  { echo -e "\n${BOLD}── $* ──${RESET}"; }

# =============================================================================
#  Root-Check
# =============================================================================
[[ $EUID -ne 0 ]] && error "Root-Rechte erforderlich. Bitte als root ausführen oder mit sudo bash."

# =============================================================================
#  Banner
# =============================================================================
echo -e "${BOLD}"
echo "  ╔══════════════════════════════════════╗"
echo "  ║   Auth Monitor — Installer v1.0      ║"
echo "  ║   abuse.max1021.de                   ║"
echo "  ╚══════════════════════════════════════╝"
echo -e "${RESET}"

# =============================================================================
#  Abhängigkeiten prüfen
# =============================================================================
header "Abhängigkeiten prüfen"

MISSING=()
for cmd in curl mysql systemctl iptables dig; do
    if command -v "$cmd" &>/dev/null; then
        success "$cmd gefunden"
    else
        MISSING+=("$cmd")
        warn "$cmd nicht gefunden"
    fi
done

if [[ ${#MISSING[@]} -gt 0 ]]; then
    warn "Fehlende Tools: ${MISSING[*]}"
    info "Versuche Installation via apt..."
    apt-get update -qq 2>/dev/null || true
    for pkg in "${MISSING[@]}"; do
        case "$pkg" in
            mysql)    apt-get install -y -qq default-mysql-client 2>/dev/null || warn "mysql-client konnte nicht installiert werden" ;;
            dig)      apt-get install -y -qq dnsutils 2>/dev/null || warn "dnsutils konnte nicht installiert werden" ;;
            iptables) apt-get install -y -qq iptables 2>/dev/null || warn "iptables konnte nicht installiert werden" ;;
        esac
    done
fi

# =============================================================================
#  Registrierungs-Token
# =============================================================================
header "Server registrieren"

REG_TOKEN="${REG_TOKEN:-}"
if [[ -z "$REG_TOKEN" ]]; then
    read -rp "Registrierungs-Token (aus dem Dashboard): " REG_TOKEN </dev/tty
fi
[[ -z "$REG_TOKEN" ]] && error "Kein Registrierungs-Token angegeben."

# =============================================================================
#  Server-Name
# =============================================================================
DEFAULT_NAME=$(hostname -f 2>/dev/null || hostname)
read -rp "Server-Name [${DEFAULT_NAME}]: " SERVER_NAME </dev/tty
SERVER_NAME="${SERVER_NAME:-$DEFAULT_NAME}"

# =============================================================================
#  Auto-Block
# =============================================================================
echo ""
echo -e "${YELLOW}Auto-Block:${RESET} Blockt angreifende IPs täglich via iptables."
echo "  Empfohlen für dedizierte Server. NICHT empfohlen wenn du viele dynamische"
echo "  eigene IPs hast (z.B. Heimanschluss ohne feste IP)."
echo ""
read -rp "Auto-Block via iptables aktivieren? [j/N]: " AUTO_BLOCK_ANSWER </dev/tty
AUTO_BLOCK=false
[[ "$AUTO_BLOCK_ANSWER" =~ ^[jJyY]$ ]] && AUTO_BLOCK=true

# =============================================================================
#  Server-IP ermitteln
# =============================================================================
SERVER_IP=$(curl -s4 --max-time 5 ifconfig.me 2>/dev/null \
    || curl -s4 --max-time 5 icanhazip.com 2>/dev/null \
    || hostname -I 2>/dev/null | awk '{print $1}' \
    || echo "unknown")

info "Erkannte öffentliche IP: ${SERVER_IP}"

# =============================================================================
#  Registrierung
# =============================================================================
info "Registriere Server bei abuse.max1021.de..."

REG_RESPONSE=$(curl -s --max-time 15 -X POST "${PLATFORM}/agent-register.php" \
    -d "reg_token=${REG_TOKEN}" \
    -d "name=${SERVER_NAME}" \
    -d "hostname=$(hostname -f 2>/dev/null || hostname)" \
    -d "ip=${SERVER_IP}" \
    -d "auto_block=${AUTO_BLOCK}")

SERVER_TOKEN=$(echo "$REG_RESPONSE" | grep -o '"token":"[^"]*"' | cut -d'"' -f4 || true)

if [[ -z "$SERVER_TOKEN" ]]; then
    echo "Server-Antwort: $REG_RESPONSE"
    error "Registrierung fehlgeschlagen. Token ungültig oder abgelaufen?"
fi

success "Server '${SERVER_NAME}' registriert."

# =============================================================================
#  Verzeichnisse
# =============================================================================
header "Verzeichnisse anlegen"

mkdir -p "$INSTALL_DIR" "$LOG_DIR"
chmod 700 "$INSTALL_DIR"
success "Installationsverzeichnis: $INSTALL_DIR"
success "Log-Verzeichnis: $LOG_DIR"

# =============================================================================
#  Config laden
# =============================================================================
header "Konfiguration laden"

CONFIG_FILE="${INSTALL_DIR}/config.sh"

curl -s --max-time 15 "${PLATFORM}/agent-config.php?token=${SERVER_TOKEN}" > "$CONFIG_FILE" \
    || error "Konfiguration konnte nicht geladen werden."

# Server-spezifische Werte anhängen
cat >> "$CONFIG_FILE" <<EOF

# Server-Identifikation
SERVER_TOKEN="${SERVER_TOKEN}"
SERVER_NAME_OVERRIDE="${SERVER_NAME}"
CENTRAL_CONFIG_URL="${PLATFORM}/agent-config.php"
EOF

chmod 600 "$CONFIG_FILE"
success "Konfiguration gespeichert: $CONFIG_FILE"

# =============================================================================
#  Skripte herunterladen
# =============================================================================
header "Agenten-Skripte herunterladen"

SCRIPTS=(auth-monitor.sh auth-logger.sh daily-reset.sh ip-lookup.sh config-refresh.sh)
[[ "$AUTO_BLOCK" == "true" ]] && SCRIPTS+=(auto-block.sh)

for script in "${SCRIPTS[@]}"; do
    curl -s --max-time 30 "${PLATFORM}/agents/${script}" -o "${INSTALL_DIR}/${script}" \
        || error "Konnte ${script} nicht herunterladen."
    chmod +x "${INSTALL_DIR}/${script}"
    success "$script"
done

# =============================================================================
#  systemd Service
# =============================================================================
header "systemd Service einrichten"

cat > "/etc/systemd/system/${SERVICE_NAME}.service" <<EOF
[Unit]
Description=Auth Monitor — SSH Brute Force Detector
After=network.target
Wants=network.target

[Service]
Type=simple
ExecStart=${INSTALL_DIR}/auth-monitor.sh --watch
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
SyslogIdentifier=auth-monitor

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable "${SERVICE_NAME}" --quiet
systemctl restart "${SERVICE_NAME}"
sleep 2

if systemctl is-active --quiet "${SERVICE_NAME}"; then
    success "Service läuft (systemctl status ${SERVICE_NAME})"
else
    warn "Service gestartet aber Status unklar — prüfe: journalctl -u ${SERVICE_NAME} -n 20"
fi

# =============================================================================
#  Cron Jobs
# =============================================================================
header "Cron Jobs einrichten"

cat > "$CRON_FILE" <<EOF
# Auth Monitor — Cron Jobs
# Täglich: Events aggregieren + DB aufräumen (00:01)
1 0 * * * root ${INSTALL_DIR}/daily-reset.sh >> ${LOG_DIR}/daily-reset.log 2>&1

# Täglich: IP-Geo-Infos nachladen (06:00)
0 6 * * * root ${INSTALL_DIR}/ip-lookup.sh >> ${LOG_DIR}/ip-lookup.log 2>&1

# Stündlich: Config von Plattform aktualisieren (Heartbeat)
0 * * * * root ${INSTALL_DIR}/config-refresh.sh >> ${LOG_DIR}/config-refresh.log 2>&1
EOF

if [[ "$AUTO_BLOCK" == "true" ]]; then
    echo "# Täglich: IPs via iptables blocken (00:05)" >> "$CRON_FILE"
    echo "5 0 * * * root ${INSTALL_DIR}/auto-block.sh >> ${LOG_DIR}/auto-block.log 2>&1" >> "$CRON_FILE"
    success "Cron: daily-reset, ip-lookup, config-refresh, auto-block"
else
    success "Cron: daily-reset, ip-lookup, config-refresh"
fi

# =============================================================================
#  Verbindungstest
# =============================================================================
header "MySQL Verbindung testen"

source "$CONFIG_FILE"
if echo "SELECT 1;" | mysql --host="$DB_HOST" --port="$DB_PORT" \
    --user="$DB_USER" --password="$DB_PASS" --database="$DB_NAME" \
    --connect-timeout=10 --silent 2>/dev/null | grep -q 1; then
    success "MySQL Verbindung OK (${DB_HOST}:${DB_PORT}/${DB_NAME})"
else
    warn "MySQL Verbindungstest fehlgeschlagen — Auth-Monitor läuft trotzdem, prüfe Netzwerk."
fi

# =============================================================================
#  Fertig
# =============================================================================
echo ""
echo -e "${GREEN}${BOLD}╔══════════════════════════════════════════╗"
echo -e "║   Installation abgeschlossen!            ║"
echo -e "╚══════════════════════════════════════════╝${RESET}"
echo ""
echo -e "  ${BOLD}Server:${RESET}   ${SERVER_NAME}"
echo -e "  ${BOLD}IP:${RESET}       ${SERVER_IP}"
echo -e "  ${BOLD}Auto-Block:${RESET} ${AUTO_BLOCK}"
echo ""
echo -e "  ${BOLD}Logs:${RESET}"
echo -e "    journalctl -u auth-monitor -f"
echo -e "    tail -f ${LOG_DIR}/daily-reset.log"
echo ""
echo -e "  ${BOLD}Dashboard:${RESET} https://abuse.max1021.de/auth-monitor/servers.php"
echo ""
