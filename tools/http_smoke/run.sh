#!/usr/bin/env bash
# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
# FlxCoreHC v1.173.2 — HTTP-level smoke test on an ISOLATED database (flx_smoke). TEST ENVIRONMENT ONLY.
# Builds a fixture (owner, manager, staff, coordinator, read-only, need-to-know-restricted staff, portal + custom portal
# logins, two providers with credentials/documents/tasks/events/cases), starts PHP's built-in server, then:
#   * asserts authorization probes (verify permission, cross-provider reads/writes, dashboard disclosure, portal confinement)
#   * crawls every reachable page as six roles and fails on HTTP 5xx or any PHP warning/notice/fatal in the output
# Needs: php (pdo_mysql), python3, the `mysql` client. Uses FLX_CONFIG (a temp config) — app/config.php is never modified.
#   bash tools/http_smoke/run.sh          exit 0 = ALL PASS · 1 = failure · 3 = prerequisites missing
set -u
root="$(cd "$(dirname "$0")/../.." && pwd)"; cd "$root" || exit 1
for b in php python3 mysql; do command -v $b >/dev/null 2>&1 || { echo "SKIP(3): $b not found"; exit 3; }; done
[ -f "${FLX_CONFIG:-app/config.php}" ] || { echo "SKIP(3): no config (app/config.php or FLX_CONFIG)"; exit 3; }
PORT="${FLX_SMOKE_PORT:-8099}"
SRC_CFG="${FLX_CONFIG:-app/config.php}"; cfgjson="$(php -r '$c = require $argv[1]; echo json_encode($c);' "$SRC_CFG")"
get() { php -r '$c = json_decode($argv[1], true); echo $c[$argv[2]] ?? $argv[3];' "$cfgjson" "$1" "$2"; }
DBHOST="$(get db_host 127.0.0.1)"; DBPORT="$(get db_port 3306)"; DBUSER="$(get db_user root)"; DBPASS="$(get db_pass '')"
export FLX_DBHOST="$DBHOST" FLX_DBUSER="$DBUSER" FLX_DBPASS="$DBPASS" FLX_DBNAME=flx_smoke FLX_BASE="http://127.0.0.1:$PORT"
export FLX_IDS="$(mktemp)"
SRC_CFG="${FLX_CONFIG:-app/config.php}"; TMPCFG="$(mktemp)"; export FLX_CONFIG="$TMPCFG"
server_pid=""; sink_pid=""
# v1.172.0: a local SMTP sink, so the tests read the codes and links the application really e-mails.
export FLX_SMTP_PORT="${FLX_SMTP_PORT:-8125}" FLX_MAILBOX="$(mktemp)"
cleanup() { [ -n "$server_pid" ] && kill "$server_pid" 2>/dev/null; [ -n "$sink_pid" ] && kill "$sink_pid" 2>/dev/null; rm -f "$TMPCFG" "$FLX_MAILBOX"; }
trap cleanup EXIT
MYSQL=(mysql -h"$DBHOST" -P"$DBPORT" -u"$DBUSER"); [ -n "$DBPASS" ] && MYSQL+=("-p$DBPASS")
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS flx_smoke; CREATE DATABASE flx_smoke CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || { echo "cannot create flx_smoke"; exit 1; }
php -r '$c = json_decode($argv[1], true); $c["db_name"] = "flx_smoke"; $c["app_env"] = "dev"; $c["docs_path"] = sys_get_temp_dir() . "/flx_smoke_docs"; @mkdir($c["docs_path"], 0750, true); $c["crypto_data_key"] = bin2hex(random_bytes(32)); $c["crypto_index_key"] = bin2hex(random_bytes(32)); file_put_contents(getenv("FLX_CONFIG"), "<?php return " . var_export($c, true) . ";\n");' "$cfgjson"
php tools/http_smoke/setup.php || { echo "fixture setup failed"; exit 1; }
python3 tools/http_smoke/smtp_sink.py "$FLX_SMTP_PORT" "$FLX_MAILBOX" >/dev/null 2>&1 & sink_pid=$!
php -S "127.0.0.1:$PORT" tools/router.php >/dev/null 2>&1 & server_pid=$!
sleep 2
python3 tools/http_smoke/drive.py; rc=$?
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS flx_smoke;" >/dev/null 2>&1
exit $rc
