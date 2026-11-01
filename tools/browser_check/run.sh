#!/usr/bin/env bash
# FlxCoreHC v1.172.16 — REAL-BROWSER checks (headless Chromium through Playwright) on an ISOLATED database (flx_browser).
# TEST ENVIRONMENT ONLY. This is separate from tools/runtime_check.sh because it needs Playwright, which a plain PHP host
# does not have. It covers what an HTTP client cannot see: scripts really run, the Content-Security-Policy really allows
# them, the QR code really scans, and the onboarding wizard really switches between a person and an organization.
#   bash tools/browser_check/run.sh        exit 0 = ALL PASS · 1 = failure · 3 = prerequisites missing (NOT RUN)
set -u
root="$(cd "$(dirname "$0")/../.." && pwd)"; cd "$root" || exit 1
for b in php python3 mysql; do command -v $b >/dev/null 2>&1 || { echo "NOT RUN (3): $b not found"; exit 3; }; done
python3 -c "import playwright" 2>/dev/null || { echo "NOT RUN (3): Playwright for Python is not installed (pip install playwright)"; exit 3; }
[ -f "${FLX_CONFIG:-app/config.php}" ] || { echo "NOT RUN (3): no config (app/config.php or FLX_CONFIG)"; exit 3; }
PORT="${FLX_BROWSER_PORT:-8097}"
SRC_CFG="${FLX_CONFIG:-app/config.php}"; cfgjson="$(php -r '$c = require $argv[1]; echo json_encode($c);' "$SRC_CFG")"
get() { php -r '$c = json_decode($argv[1], true); echo $c[$argv[2]] ?? $argv[3];' "$cfgjson" "$1" "$2"; }
DBHOST="$(get db_host 127.0.0.1)"; DBPORT="$(get db_port 3306)"; DBUSER="$(get db_user root)"; DBPASS="$(get db_pass '')"
export FLX_DBHOST="$DBHOST" FLX_DBUSER="$DBUSER" FLX_DBPASS="$DBPASS" FLX_DBNAME=flx_browser FLX_BASE="http://127.0.0.1:$PORT"
export FLX_IDS="$(mktemp)" FLX_SMTP_PORT="${FLX_SMTP_PORT:-8126}" FLX_MAILBOX="$(mktemp)" FLX_SHOTS="${FLX_SHOTS:-$(mktemp -d)}"
TMPCFG="$(mktemp)"; export FLX_CONFIG="$TMPCFG"
server_pid=""; sink_pid=""
cleanup() { [ -n "$server_pid" ] && kill "$server_pid" 2>/dev/null; [ -n "$sink_pid" ] && kill "$sink_pid" 2>/dev/null; rm -f "$TMPCFG" "$FLX_MAILBOX" "$FLX_IDS"; }
trap cleanup EXIT
MYSQL=(mysql -h"$DBHOST" -P"$DBPORT" -u"$DBUSER"); [ -n "$DBPASS" ] && MYSQL+=("-p$DBPASS")
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS flx_browser; CREATE DATABASE flx_browser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || { echo "cannot create flx_browser"; exit 1; }
php -r '$c = json_decode($argv[1], true); $c["db_name"] = "flx_browser"; $c["app_env"] = "dev"; $c["app_url"] = getenv("FLX_BASE"); $c["docs_path"] = sys_get_temp_dir() . "/flx_browser_docs"; @mkdir($c["docs_path"], 0750, true); $c["crypto_data_key"] = bin2hex(random_bytes(32)); $c["crypto_index_key"] = bin2hex(random_bytes(32)); file_put_contents(getenv("FLX_CONFIG"), "<?php return " . var_export($c, true) . ";\n");' "$cfgjson"
php tools/http_smoke/setup.php || { echo "fixture setup failed"; exit 1; }
python3 tools/http_smoke/smtp_sink.py "$FLX_SMTP_PORT" "$FLX_MAILBOX" >/dev/null 2>&1 & sink_pid=$!
php -S "127.0.0.1:$PORT" tools/router.php >/dev/null 2>&1 & server_pid=$!
sleep 2
python3 tools/browser_check/check.py; rc=$?
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS flx_browser;" >/dev/null 2>&1
echo "screenshots: $FLX_SHOTS"
exit $rc
