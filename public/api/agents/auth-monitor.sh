#!/usr/bin/env bash
# =============================================================================
#  auth-monitor.sh — SSH Auth Log Monitor
#  Modes: --watch (realtime) | --report (one-shot) | --schedule (cron digest)
#  Alerts: Slack Webhook + E-Mail
# =============================================================================
# Usage:
#   ./auth-monitor.sh --watch             # Realtime monitoring (keep running)
#   ./auth-monitor.sh --report            # Full log analysis + single report
#   ./auth-monitor.sh --schedule          # Incremental digest (run via cron)
# =============================================================================

set -uo pipefail

# =============================================================================
#  CONFIG — aus /opt/auth-monitor/config.sh laden
# =============================================================================

CONFIG_FILE="/opt/auth-monitor/config.sh"
if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "ERROR: $CONFIG_FILE nicht gefunden. Installation unvollständig?" >&2
    exit 1
fi
source "$CONFIG_FILE"

# Log file(s) to monitor — script tries them in order, uses first match
LOG_FILES=(
    "/var/log/auth.log"         # Debian/Ubuntu
    "/var/log/secure"           # RHEL/CentOS/Fedora
    "/var/log/messages"         # fallback
)

# Thresholds aus Config (mit Defaults)
BRUTE_FORCE_THRESHOLD="${BRUTE_FORCE_THRESHOLD:-10}"
BRUTE_FORCE_WINDOW="${BRUTE_FORCE_WINDOW:-300}"
ENUM_THRESHOLD="${ENUM_THRESHOLD:-5}"
ALERT_COOLDOWN="${ALERT_COOLDOWN:-3600}"

# Slack
SLACK_ENABLED="${SLACK_ENABLED:-false}"
SLACK_WEBHOOK_URL="${SLACK_WEBHOOK_URL:-}"

# E-Mail
EMAIL_ENABLED="${EMAIL_ENABLED:-false}"
EMAIL_TO="${EMAIL_TO:-}"
EMAIL_FROM="${EMAIL_FROM:-no-reply@localhost}"
EMAIL_SUBJECT_PREFIX="[auth-monitor]"

# State directory
STATE_DIR="/tmp/auth_monitor"
COUNT_DIR="${STATE_DIR}/counts"
FIRST_SEEN_DIR="${STATE_DIR}/first_seen"
ALERTED_DIR="${STATE_DIR}/alerted"
USERS_DIR="${STATE_DIR}/users"
POSITION_FILE="${STATE_DIR}/last_position"
SUCCESS_LOG="${STATE_DIR}/successful_logins.log"

# =============================================================================
#  SETUP
# =============================================================================

setup() {
    mkdir -p "$COUNT_DIR" "$FIRST_SEEN_DIR" "$ALERTED_DIR" "$USERS_DIR" "$STATE_DIR"
    touch "$SUCCESS_LOG"
}

find_log_file() {
    for f in "${LOG_FILES[@]}"; do
        if [[ -r "$f" ]]; then
            echo "$f"
            return 0
        fi
    done
    echo ""
}

# =============================================================================
#  PARSING — extract fields from a single log line
# =============================================================================

# Sets globals: EVENT_TYPE, IP, AUTH_USER, LOG_TIMESTAMP
parse_line() {
    local line="$1"
    EVENT_TYPE=""
    IP=""
    AUTH_USER=""
    LOG_TIMESTAMP=""

    LOG_TIMESTAMP=$(echo "$line" | awk '{print $1, $2, $3}')

    _extract_ip_after() {
        local keyword="$1"
        local text="$2"
        echo "$text" | awk -v kw="$keyword" '
        {
            for(i=1;i<=NF;i++){
                if($i==kw && (i+1)<=NF){
                    ip=$(i+1)
                    gsub(/[^0-9.]/,"",ip)
                    if(ip ~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/) { print ip; exit }
                }
            }
        }'
    }

    if echo "$line" | grep -qE "Failed password for"; then
        EVENT_TYPE="FAILED"
        AUTH_USER=$(echo "$line" | awk '{
            for(i=1;i<=NF;i++){
                if($i=="for"){
                    u=$(i+1)
                    if(u=="invalid" && $(i+2)=="user"){u=$(i+3)}
                    else if(u=="invalid"){u=$(i+2)}
                    print u; exit
                }
            }
        }')
        IP=$(_extract_ip_after "from" "$line")
        return
    fi

    if echo "$line" | grep -qE "Invalid user"; then
        EVENT_TYPE="INVALID_USER"
        AUTH_USER=$(echo "$line" | awk '{
            for(i=1;i<=NF;i++){
                if($i=="user" && (i-1)>=1 && $(i-1)=="Invalid"){
                    print $(i+1); exit
                }
            }
        }')
        IP=$(_extract_ip_after "from" "$line")
        return
    fi

    if echo "$line" | grep -qE "Accepted (password|publickey|keyboard-interactive) for"; then
        EVENT_TYPE="SUCCESS"
        AUTH_USER=$(echo "$line" | awk '{
            for(i=1;i<=NF;i++){
                if($i=="for" && (i-1)>=1 && ($(i-1)=="password"||$(i-1)=="publickey"||$(i-1)=="keyboard-interactive")){
                    print $(i+1); exit
                }
            }
        }')
        IP=$(_extract_ip_after "from" "$line")
        return
    fi

    if echo "$line" | grep -qE "Connection closed by"; then
        EVENT_TYPE="CLOSED"
        IP=$(_extract_ip_after "by" "$line")
        if echo "$IP" | grep -qvE "^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$"; then
            IP=$(_extract_ip_after "user" "$line")
        fi
        return
    fi

    if echo "$line" | grep -qE "FAILED LOGIN|authentication failure"; then
        EVENT_TYPE="FAILED"
        AUTH_USER=$(echo "$line" | awk -F'user=' '{if(NF>1){split($2,a,"[ \t]");print a[1]}}' | sed 's/rhost=.*//')
        AUTH_USER=$(echo "$AUTH_USER" | grep -oE '^[a-zA-Z0-9._-]+' || true)
        local _pam_ip
        _pam_ip=$(echo "$line" | awk -F'rhost=' '{if(NF>1){split($2,a,"[ \t]");print a[1]}}')
        if echo "$_pam_ip" | grep -qE "^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$"; then
            IP="$_pam_ip"
        fi
        return
    fi
}

# =============================================================================
#  STATE HELPERS
# =============================================================================

increment_count() {
    local ip="$1"
    local file="${COUNT_DIR}/${ip}"
    local count=0
    [[ -f "$file" ]] && count=$(cat "$file")
    count=$((count + 1))
    echo "$count" > "$file"
    echo "$count"
}

get_count() {
    local ip="$1"
    local file="${COUNT_DIR}/${ip}"
    [[ -f "$file" ]] && cat "$file" || echo 0
}

set_first_seen() {
    local ip="$1"
    local file="${FIRST_SEEN_DIR}/${ip}"
    [[ -f "$file" ]] || date +%s > "$file"
}

get_first_seen() {
    local ip="$1"
    local file="${FIRST_SEEN_DIR}/${ip}"
    [[ -f "$file" ]] && cat "$file" || echo 0
}

track_username() {
    local ip="$1"
    local user="$2"
    [[ -z "$user" || -z "$ip" ]] && return
    local file="${USERS_DIR}/${ip}"
    touch "$file"
    grep -qxF "$user" "$file" 2>/dev/null || echo "$user" >> "$file"
}

get_unique_user_count() {
    local ip="$1"
    local file="${USERS_DIR}/${ip}"
    [[ -f "$file" ]] && wc -l < "$file" || echo 0
}

should_alert() {
    local ip="$1"
    local alert_type="$2"
    local key="${ip}_${alert_type}"
    local file="${ALERTED_DIR}/${key}"

    if [[ -f "$file" ]]; then
        local last_alert
        last_alert=$(cat "$file")
        local now
        now=$(date +%s)
        local elapsed=$(( now - last_alert ))
        if [[ $elapsed -lt $ALERT_COOLDOWN ]]; then
            return 1
        fi
    fi

    date +%s > "$file"
    return 0
}

# =============================================================================
#  ALERTING
# =============================================================================

send_slack() {
    local color="$1"
    local title="$2"
    local message="$3"
    local ip="${4:-}"
    local count="${5:-}"

    [[ "$SLACK_ENABLED" != "true" ]] && return
    [[ -z "$SLACK_WEBHOOK_URL" ]] && {
        log_msg "WARN" "Slack webhook nicht konfiguriert"
        return
    }

    local hostname
    hostname=$(hostname -f 2>/dev/null || hostname)

    local fields='[]'
    if [[ -n "$ip" ]]; then
        fields=$(printf '[
          {"title":"IP","value":"%s","short":true},
          {"title":"Count","value":"%s","short":true},
          {"title":"Server","value":"%s","short":true},
          {"title":"Time","value":"%s","short":true}
        ]' "$ip" "${count:-?}" "$hostname" "$(date '+%Y-%m-%d %H:%M:%S')")
    fi

    curl -s -m 10 -X POST "$SLACK_WEBHOOK_URL" \
        -H 'Content-Type: application/json' \
        -d "$(printf '{
          "attachments": [{
            "color": "%s",
            "title": "%s",
            "text": "%s",
            "fields": %s,
            "footer": "auth-monitor on %s"
          }]
        }' "$color" "$title" "$message" "$fields" "$hostname")" \
        > /dev/null 2>&1 \
        && log_msg "INFO" "Slack alert sent: $title" \
        || log_msg "WARN" "Slack alert fehlgeschlagen"
}

send_email() {
    local subject="$1"
    local body="$2"

    [[ "$EMAIL_ENABLED" != "true" ]] && return

    local err
    if command -v msmtp &>/dev/null; then
        err=$(printf 'Subject: %s\nFrom: %s\nTo: %s\nContent-Type: text/plain; charset=UTF-8\n\n%s\n' \
            "${EMAIL_SUBJECT_PREFIX} ${subject}" "$EMAIL_FROM" "$EMAIL_TO" "$body" \
            | msmtp --read-envelope-from "$EMAIL_TO" 2>&1)
        [[ $? -eq 0 ]] && { log_msg "INFO" "E-Mail sent: $subject"; return; }
        log_msg "WARN" "msmtp error: $err"
        return 1
    fi

    if command -v mail &>/dev/null; then
        err=$(echo "$body" | mail \
            -s "${EMAIL_SUBJECT_PREFIX} ${subject}" \
            -r "$EMAIL_FROM" \
            "$EMAIL_TO" 2>&1)
        [[ $? -eq 0 ]] \
            && log_msg "INFO" "E-Mail sent (via mail): $subject" \
            || log_msg "WARN" "mail error: $err"
    else
        log_msg "WARN" "Kein msmtp/mail gefunden - E-Mail uebersprungen"
    fi
}

send_alert() {
    local alert_type="$1"
    local ip="$2"
    local count="${3:-}"
    local user="${4:-}"

    case "$alert_type" in
        BRUTE_FORCE)
            local title="Brute-Force detected: ${ip}"
            local msg="${count} failed SSH logins in ${BRUTE_FORCE_WINDOW}s from ${ip}"
            local email_body
            email_body=$(printf 'Brute-Force Alert\n=================\nIP:       %s\nAttempts: %s in %ss\nServer:   %s\nTime:     %s\n\nLast attempted user: %s\n' \
                "$ip" "$count" "$BRUTE_FORCE_WINDOW" "$(hostname)" "$(date)" "${user:-unknown}")
            send_slack "danger" "$title" "$msg" "$ip" "$count"
            send_email "Brute-Force: $ip ($count attempts)" "$email_body"
            ;;
        ENUM)
            local ucount
            ucount=$(get_unique_user_count "$ip")
            local title="User enumeration: ${ip}"
            local msg="${ucount} distinct usernames tried from ${ip}"
            local email_body
            email_body=$(printf 'User Enumeration Alert\n======================\nIP:              %s\nDistinct users:  %s\nServer:          %s\nTime:            %s\n' \
                "$ip" "$ucount" "$(hostname)" "$(date)")
            send_slack "warning" "$title" "$msg" "$ip" "$ucount"
            send_email "User enumeration: $ip ($ucount usernames)" "$email_body"
            ;;
        SUCCESS)
            local title="Successful login: ${user}@$(hostname) from ${ip}"
            local msg="User '${user}' authenticated from ${ip}"
            local email_body
            email_body=$(printf 'Successful Login Notice\n=======================\nUser:   %s\nIP:     %s\nServer: %s\nTime:   %s\n' \
                "${user:-?}" "$ip" "$(hostname)" "$(date)")
            send_slack "good" "$title" "$msg" "$ip" ""
            send_email "Login: ${user} from $ip" "$email_body"
            ;;
    esac
}

# =============================================================================
#  ANALYSIS — called for each parsed event
# =============================================================================

analyse_event() {
    [[ -z "$IP" ]] && return

    case "$EVENT_TYPE" in

        FAILED|INVALID_USER)
            set_first_seen "$IP"
            local count
            count=$(increment_count "$IP")

            track_username "$IP" "$AUTH_USER"
            local unique_users
            unique_users=$(get_unique_user_count "$IP")

            log_msg "FAIL" "IP=$IP user=${AUTH_USER:-?} count=$count unique_users=$unique_users"
            [[ "$MYSQL_AVAILABLE" == "true" ]] && log_to_mysql "$EVENT_TYPE" "$IP" "${AUTH_USER:-}"

            local first_seen
            first_seen=$(get_first_seen "$IP")
            local now
            now=$(date +%s)
            local elapsed=$(( now - first_seen ))

            if [[ $count -ge $BRUTE_FORCE_THRESHOLD && $elapsed -le $BRUTE_FORCE_WINDOW ]]; then
                if should_alert "$IP" "BRUTE_FORCE"; then
                    log_msg "ALERT" "Brute-force from $IP: $count attempts in ${elapsed}s"
                    send_alert "BRUTE_FORCE" "$IP" "$count" "$AUTH_USER"
                fi
            fi

            if [[ $elapsed -gt $BRUTE_FORCE_WINDOW ]]; then
                echo 1 > "${COUNT_DIR}/${IP}"
                date +%s > "${FIRST_SEEN_DIR}/${IP}"
            fi

            if [[ $unique_users -ge $ENUM_THRESHOLD ]]; then
                if should_alert "$IP" "ENUM"; then
                    log_msg "ALERT" "User enumeration from $IP: $unique_users distinct usernames"
                    send_alert "ENUM" "$IP" "$unique_users" ""
                fi
            fi
            ;;

        SUCCESS)
            log_msg "SUCCESS" "IP=$IP user=${AUTH_USER:-?} time=$LOG_TIMESTAMP"
            [[ "$MYSQL_AVAILABLE" == "true" ]] && log_to_mysql "SUCCESS" "$IP" "${AUTH_USER:-}"
            echo "$(date '+%Y-%m-%d %H:%M:%S') | $IP | ${AUTH_USER:-?}" >> "$SUCCESS_LOG"

            if should_alert "$IP" "SUCCESS"; then
                send_alert "SUCCESS" "$IP" "" "$AUTH_USER"
            fi
            ;;
    esac
}

# =============================================================================
#  LOGGING (to stderr so it doesn't pollute stdout)
# =============================================================================

log_msg() {
    local level="$1"
    local msg="$2"
    local color=""
    local reset="\033[0m"

    case "$level" in
        ALERT)   color="\033[1;31m" ;;
        FAIL)    color="\033[0;33m" ;;
        SUCCESS) color="\033[0;32m" ;;
        INFO)    color="\033[0;36m" ;;
        WARN)    color="\033[0;35m" ;;
        *)       color="" ;;
    esac

    printf "${color}[%s] [%-7s] %s${reset}\n" \
        "$(date '+%H:%M:%S')" "$level" "$msg" >&2
}

# =============================================================================
#  MODE: --watch  (realtime, blocks forever)
# =============================================================================

mode_watch() {
    local logfile
    logfile=$(find_log_file)
    [[ -z "$logfile" ]] && { echo "ERROR: Kein lesbares Log-File gefunden." >&2; exit 1; }

    log_msg "INFO" "Starte Realtime-Watch auf $logfile (pid=$$)"
    log_msg "INFO" "Brute-force: >${BRUTE_FORCE_THRESHOLD} failures in ${BRUTE_FORCE_WINDOW}s | Enum: >${ENUM_THRESHOLD} distinct users"
    log_msg "INFO" "Slack=${SLACK_ENABLED} | Email=${EMAIL_ENABLED} | Cooldown=${ALERT_COOLDOWN}s"
    echo "" >&2

    tail -F "$logfile" 2>/dev/null | while IFS= read -r line; do
        parse_line "$line"
        [[ -n "$EVENT_TYPE" ]] && analyse_event
    done
}

# =============================================================================
#  MODE: --report  (full one-shot analysis + single summary alert)
# =============================================================================

mode_report() {
    local logfile
    logfile=$(find_log_file)
    [[ -z "$logfile" ]] && { echo "ERROR: Kein lesbares Log-File gefunden." >&2; exit 1; }

    log_msg "INFO" "Führe vollständige Analyse durch: $logfile ..."

    local total_failed=0
    local total_success=0
    local total_invalid=0

    declare -A ip_fail_count
    declare -A ip_user_set

    while IFS= read -r line; do
        parse_line "$line"
        [[ -z "$EVENT_TYPE" || -z "$IP" ]] && continue

        case "$EVENT_TYPE" in
            FAILED|INVALID_USER)
                total_failed=$((total_failed + 1))
                [[ "$EVENT_TYPE" == "INVALID_USER" ]] && total_invalid=$((total_invalid + 1))
                ip_fail_count["$IP"]=$(( ${ip_fail_count["$IP"]:-0} + 1 ))
                if [[ -n "$AUTH_USER" ]]; then
                    ip_user_set["${IP}:users"]="${ip_user_set["${IP}:users"]:-} $AUTH_USER"
                fi
                [[ "$MYSQL_AVAILABLE" == "true" ]] && log_to_mysql "$EVENT_TYPE" "$IP" "${AUTH_USER:-}"
                ;;
            SUCCESS)
                total_success=$((total_success + 1))
                [[ "$MYSQL_AVAILABLE" == "true" ]] && log_to_mysql "SUCCESS" "$IP" "${AUTH_USER:-}"
                ;;
        esac
    done < "$logfile"

    local top_ips
    top_ips=$(for ip in "${!ip_fail_count[@]}"; do
        echo "${ip_fail_count[$ip]} $ip"
    done | sort -rn | head -10)

    local report_file
    report_file=$(mktemp /tmp/auth_report_XXXXXX)

    {
        echo "Auth Log Report --- $(date '+%Y-%m-%d %H:%M:%S')"
        echo ""
        echo "Log file : $logfile"
        echo "Server   : $(hostname -f 2>/dev/null || hostname)"
        echo ""
        echo "--- Summary ---"
        echo "Failed attempts    : $total_failed"
        echo "Invalid user tries : $total_invalid"
        echo "Successful logins  : $total_success"
        echo ""
        echo "--- Top 10 Attacking IPs ---"
        echo "$top_ips" | awk '{printf "  %-6s  %s\n", $1, $2}'
        echo ""

        local any_bf=0
        while IFS= read -r entry; do
            local cnt ip
            cnt=$(echo "$entry" | awk '{print $1}')
            ip=$(echo "$entry" | awk '{print $2}')
            if [[ $cnt -ge $BRUTE_FORCE_THRESHOLD ]]; then
                if [[ $any_bf -eq 0 ]]; then
                    echo "--- Brute-Force Candidates (>=${BRUTE_FORCE_THRESHOLD} attempts) ---"
                    any_bf=1
                fi
                echo "  $ip ($cnt attempts)"
            fi
        done <<< "$top_ips"
        [[ $any_bf -eq 1 ]] && echo ""

        if [[ -s "$SUCCESS_LOG" ]]; then
            echo "--- Recent Successful Logins ---"
            tail -20 "$SUCCESS_LOG" | while IFS= read -r l; do echo "  $l"; done
            echo ""
        fi
    } > "$report_file"

    cat "$report_file" >&2
    local report
    report=$(cat "$report_file")
    rm -f "$report_file"

    send_slack "warning" \
        "Auth Log Report -- $(hostname)" \
        "$(printf 'Failed: %d | Success: %d | Top IP: %s (%s)' \
            "$total_failed" "$total_success" \
            "$(echo "$top_ips" | head -1 | awk '{print $2}')" \
            "$(echo "$top_ips" | head -1 | awk '{print $1}')")" \
        "" ""
    send_email "Auth Log Report -- $(date '+%Y-%m-%d')" "$report"
}

# =============================================================================
#  MODE: --schedule  (incremental, for cron)
# =============================================================================

mode_schedule() {
    local logfile
    logfile=$(find_log_file)
    [[ -z "$logfile" ]] && { echo "ERROR: Kein lesbares Log-File gefunden." >&2; exit 1; }

    local filesize
    filesize=$(wc -c < "$logfile")

    local last_pos=0
    [[ -f "$POSITION_FILE" ]] && last_pos=$(cat "$POSITION_FILE")

    [[ $filesize -lt $last_pos ]] && last_pos=0

    local bytes_to_read=$(( filesize - last_pos ))
    if [[ $bytes_to_read -le 0 ]]; then
        log_msg "INFO" "Keine neuen Log-Einträge seit letztem Durchlauf."
        exit 0
    fi

    log_msg "INFO" "Lese ${bytes_to_read} neue Bytes aus $logfile (offset $last_pos)"

    dd if="$logfile" bs=1 skip="$last_pos" count="$bytes_to_read" 2>/dev/null \
    | while IFS= read -r line; do
        parse_line "$line"
        [[ -z "$EVENT_TYPE" || -z "$IP" ]] && continue
        analyse_event
    done

    echo "$filesize" > "$POSITION_FILE"

    local total_ips
    total_ips=$(ls "$COUNT_DIR" 2>/dev/null | wc -l)
    local top_ips
    top_ips=$(for f in "$COUNT_DIR"/*; do
        [[ -f "$f" ]] || continue
        printf "%s %s\n" "$(cat "$f")" "$(basename "$f")"
    done | sort -rn | head -5)

    local digest
    digest="$(printf 'Hourly Auth Digest -- %s\n' "$(date '+%Y-%m-%d %H:%M')")"
    digest+="$(printf 'Server: %s | Log: %s\n\n' "$(hostname)" "$logfile")"
    digest+="$(printf 'Total tracked IPs with failures: %s\n' "$total_ips")"
    digest+="$(printf '\nTop 5 IPs (all-time counters):\n')"
    digest+="$(echo "$top_ips" | awk '{printf "  %-6s  %s\n", $1, $2}')"

    if [[ -s "$SUCCESS_LOG" ]]; then
        digest+="$(printf '\nRecent successful logins:\n')"
        digest+="$(tail -5 "$SUCCESS_LOG" | awk '{printf "  %s\n", $0}')"
    fi

    log_msg "INFO" "Sende Digest..."
    send_slack "good" \
        "Hourly Auth Digest -- $(hostname)" \
        "$(echo "$top_ips" | head -1 | awk '{printf "Top IP: %s (%s attempts)", $2, $1}')" \
        "" ""
    send_email "Hourly Auth Digest -- $(date '+%Y-%m-%d %H:%M')" "$digest"
}

# =============================================================================
#  CLEANUP
# =============================================================================

cleanup_stale_state() {
    local max_age=$(( ALERT_COOLDOWN * 24 ))
    local now
    now=$(date +%s)

    for dir in "$COUNT_DIR" "$FIRST_SEEN_DIR" "$ALERTED_DIR" "$USERS_DIR"; do
        [[ -d "$dir" ]] || continue
        find "$dir" -type f | while read -r f; do
            local mtime
            mtime=$(stat -c %Y "$f" 2>/dev/null || stat -f %m "$f" 2>/dev/null || echo 0)
            local age=$(( now - mtime ))
            [[ $age -gt $max_age ]] && rm -f "$f"
        done
    done
}

# =============================================================================
#  ENTRY POINT
# =============================================================================

print_help() {
    cat >&2 <<EOF
auth-monitor.sh -- SSH Auth Log Monitor

Usage:
  $(basename "$0") --watch       Realtime monitoring (tail -F, blocks forever)
  $(basename "$0") --report      Full log analysis + single summary report
  $(basename "$0") --schedule    Incremental digest for cron (reads new lines only)
  $(basename "$0") --clean       Clean up stale state files
  $(basename "$0") --help        Show this help

Config: /opt/auth-monitor/config.sh
  BRUTE_FORCE_THRESHOLD = $BRUTE_FORCE_THRESHOLD  (failed logins to trigger alert)
  BRUTE_FORCE_WINDOW    = ${BRUTE_FORCE_WINDOW}s (time window)
  ENUM_THRESHOLD        = $ENUM_THRESHOLD  (distinct usernames)
  ALERT_COOLDOWN        = ${ALERT_COOLDOWN}s  (re-alert interval per IP)
  SLACK_ENABLED         = $SLACK_ENABLED
  EMAIL_ENABLED         = $EMAIL_ENABLED

State directory: $STATE_DIR
Success log:     $SUCCESS_LOG
EOF
}

setup
cleanup_stale_state

# MySQL-Logger laden (optional)
MYSQL_LOGGER="/opt/auth-monitor/auth-logger.sh"
if [[ -f "$MYSQL_LOGGER" ]]; then
    source "$MYSQL_LOGGER"
    MYSQL_AVAILABLE=true
else
    MYSQL_AVAILABLE=false
fi

case "${1:-}" in
    --watch)    mode_watch    ;;
    --report)   mode_report   ;;
    --schedule) mode_schedule ;;
    --clean)    log_msg "INFO" "Cleaning stale state..."; cleanup_stale_state; log_msg "INFO" "Done." ;;
    --help|-h)  print_help ;;
    "")         print_help ;;
    *)          echo "Unknown option: $1" >&2; print_help; exit 1 ;;
esac
