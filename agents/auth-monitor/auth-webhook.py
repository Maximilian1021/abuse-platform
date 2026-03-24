#!/usr/bin/env python3
# =============================================================================
#  auth-webhook.py — HTTP API Endpoint für auth-report.sh
#  Läuft auf dem 24fire-Server, wird vom Dashboard aufgerufen
#
#  Start:  python3 /opt/auth-webhook.py
#  Systemd: siehe unten
# =============================================================================

import http.server
import json
import subprocess
import hmac
import hashlib
import urllib.parse
import os
import logging
from datetime import datetime

# =============================================================================
#  CONFIG
# =============================================================================

PORT        = 8765
BIND        = "0.0.0.0"
def _load_conf(path: str) -> dict:
    conf = {}
    try:
        with open(path) as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#'):
                    k, _, v = line.partition('=')
                    conf[k.strip()] = v.strip().strip('"')
    except FileNotFoundError:
        pass
    return conf

_conf = _load_conf(os.path.join(os.path.dirname(__file__), "auth-monitor.conf"))
SECRET_TOKEN = _conf.get("WEBHOOK_TOKEN", "")
REPORT_SCRIPT = "/opt/auth-report.sh"
LOG_FILE    = "/var/log/auth-monitor/webhook.log"

# =============================================================================
#  LOGGING
# =============================================================================

os.makedirs("/var/log/auth-monitor", exist_ok=True)
logging.basicConfig(
    level=logging.INFO,
    format='[%(asctime)s] %(levelname)s %(message)s',
    datefmt='%H:%M:%S',
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler()
    ]
)
log = logging.getLogger(__name__)

# =============================================================================
#  REQUEST HANDLER
# =============================================================================

class WebhookHandler(http.server.BaseHTTPRequestHandler):

    def log_message(self, format, *args):
        pass  # Eigenes Logging

    def send_json(self, code, data):
        body = json.dumps(data, ensure_ascii=False).encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', len(body))
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self):
        self.send_response(200)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'X-Token, Content-Type')
        self.end_headers()

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        params = urllib.parse.parse_qs(parsed.query)

        # Token prüfen
        token = self.headers.get('X-Token') or params.get('token', [''])[0]
        if not hmac.compare_digest(token, SECRET_TOKEN):
            log.warning(f"Ungültiger Token von {self.client_address[0]}")
            self.send_json(403, {'error': 'Ungültiger Token'})
            return

        path = parsed.path.rstrip('/')

        # --- /status --- Healthcheck
        if path == '/status':
            self.send_json(200, {
                'status': 'ok',
                'server': os.uname().nodename,
                'time': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            })
            return

        # --- /report --- Report generieren
        if path == '/report':
            report_type = params.get('type', ['day'])[0]
            date        = params.get('date', [''])[0]
            ip          = params.get('ip', [''])[0]
            date_from   = params.get('from', [''])[0]
            date_to     = params.get('to', [''])[0]
            force       = params.get('force', ['0'])[0] == '1'

            # Command zusammenbauen
            cmd = [REPORT_SCRIPT]

            if ip:
                cmd += ['--ip', ip]
                if date_from: cmd += ['--from', date_from]
                if date_to:   cmd += ['--to', date_to]
            elif report_type == 'week':
                cmd.append('--week')
                if date: cmd.append(date)
            else:
                cmd.append('--day')
                if date: cmd.append(date)

            if force:
                cmd.append('--force')

            log.info(f"Report-Request von {self.client_address[0]}: {' '.join(cmd)}")

            try:
                result = subprocess.run(
                    cmd,
                    capture_output=True,
                    text=True,
                    timeout=120
                )
                output = result.stdout + result.stderr
                success = result.returncode == 0

                # Duplikat-Warnung erkennen
                duplicate = 'WARNUNG' in output and 'bereits erstellt' in output

                log.info(f"Report fertig: returncode={result.returncode}")

                self.send_json(200 if success else 500, {
                    'success': success,
                    'duplicate': duplicate,
                    'output': output.strip(),
                    'command': ' '.join(cmd)
                })

            except subprocess.TimeoutExpired:
                log.error("Report Timeout!")
                self.send_json(504, {'error': 'Report Timeout (>120s)'})
            except Exception as e:
                log.error(f"Report Fehler: {e}")
                self.send_json(500, {'error': str(e)})
            return

        self.send_json(404, {'error': 'Unbekannter Endpoint'})

# =============================================================================
#  MAIN
# =============================================================================

if __name__ == '__main__':
    server = http.server.HTTPServer((BIND, PORT), WebhookHandler)
    log.info(f"Auth-Webhook gestartet auf {BIND}:{PORT}")
    log.info(f"Token: {SECRET_TOKEN[:8]}...")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        log.info("Webhook gestoppt.")
