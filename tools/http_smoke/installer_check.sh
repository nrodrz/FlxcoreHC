#!/usr/bin/env bash
# FlxCoreHC v1.172.16 — runs the REAL web installer over HTTP on an isolated database (flx_install_chk), TEST ENVIRONMENT ONLY.
# Uses FLX_CONFIG (a temp file) — app/config.php is never read for the install target and never modified.
# Needs: php (pdo_mysql), python3, the mysql client, and DB credentials from FLX_CONFIG or app/config.php.
#   bash tools/http_smoke/installer_check.sh      exit 0 = ALL PASS · 1 = failure · 3 = prerequisites missing
set -u
root="$(cd "$(dirname "$0")/../.." && pwd)"; cd "$root" || exit 1
for b in php python3 mysql; do command -v $b >/dev/null 2>&1 || { echo "SKIP(3): $b not found"; exit 3; }; done
SRC="${FLX_CONFIG:-app/config.php}"; [ -f "$SRC" ] || { echo "SKIP(3): no config (app/config.php or FLX_CONFIG)"; exit 3; }
PORT="${FLX_INSTALL_PORT:-8098}"
cfgjson="$(php -r '$c = require $argv[1]; echo json_encode($c);' "$SRC")"
get() { php -r '$c = json_decode($argv[1], true); echo $c[$argv[2]] ?? $argv[3];' "$cfgjson" "$1" "$2"; }
export FLX_DBHOST="$(get db_host 127.0.0.1)" FLX_DBUSER="$(get db_user root)" FLX_DBPASS="$(get db_pass '')" FLX_DBNAME=flx_install_chk FLX_BASE="http://127.0.0.1:$PORT"
DBPORT="$(get db_port 3306)"
MYSQL=(mysql -h"$FLX_DBHOST" -P"$DBPORT" -u"$FLX_DBUSER"); [ -n "$FLX_DBPASS" ] && MYSQL+=("-p$FLX_DBPASS")
TMPCFG="$(mktemp -u)"; export FLX_CONFIG="$TMPCFG"   # does not exist yet: the installer must create it
DOCS="$(mktemp -d)"; rc=0; server_pid=""
cleanup() { [ -n "$server_pid" ] && kill "$server_pid" 2>/dev/null; rm -f "$TMPCFG"; rm -rf "$DOCS"; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS flx_install_chk;" >/dev/null 2>&1; }
trap cleanup EXIT
for scenario in http https; do
  echo "--- installer over $scenario ---"
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS flx_install_chk; CREATE DATABASE flx_install_chk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || { echo "cannot create flx_install_chk"; exit 1; }
  rm -f "$TMPCFG"
  php -S "127.0.0.1:$PORT" tools/router.php >/dev/null 2>&1 & server_pid=$!
  sleep 2
  python3 tools/http_smoke/installer_check.py "$scenario" || rc=1
  kill "$server_pid" 2>/dev/null; wait "$server_pid" 2>/dev/null; server_pid=""
done
exit $rc
