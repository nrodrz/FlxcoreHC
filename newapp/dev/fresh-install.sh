#!/bin/bash
# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
# fresh-install.sh <copy-dir> <port> <db-name> : copies the app to <copy-dir>, makes an empty database and runs the web installer
# with made-up data (local testing only). Needs: php, mysql/mariadb client (root over the socket), curl.
# Env: DB_SOCKET (default /run/mysqld/mysqld.sock), DB_USER/DB_PASS (the app's database user, created here), APP_NAME.
set -e
HERE=$(cd "$(dirname "$0")/.." && pwd); RUN=$1; PORT=$2; DB=$3
SOCK=${DB_SOCKET:-/run/mysqld/mysqld.sock}; U=${DB_USER:-appdev}; PW=${DB_PASS:-appdevpw}; NAME=${APP_NAME:-ITOps}
rm -rf "$RUN"; mkdir -p "$RUN"; (cd "$HERE" && tar --exclude=./dev --exclude=./docs --exclude=./node_modules --exclude=./CLAUDE.md -cf - .) | (cd "$RUN" && tar -xf -)
mysql -uroot --socket=$SOCK -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS '$U'@'127.0.0.1' IDENTIFIED BY '$PW'; GRANT ALL ON \`$DB\`.* TO '$U'@'127.0.0.1';"
(cd "$RUN" && php -S 127.0.0.1:$PORT -t "$RUN" "$HERE/dev/router.php" > /tmp/php_$PORT.log 2>&1 &); sleep 1
J=/tmp/cj_$PORT; rm -f $J; B=http://127.0.0.1:$PORT/install/
curl -s -c $J -b $J "$B?step=welcome" >/dev/null
curl -s -c $J -b $J -d step=database -d db_host=127.0.0.1 -d db_port=3306 -d db_name=$DB -d db_user=$U -d db_pass=$PW -d sample=1 $B | grep -o 'class="err[^<]*<[^<]*' | head -3 || true
curl -s -c $J -b $J -d step=admin -d admin_email=admin@example.com -d 'admin_password=Adm1n!Harb0ur#7' -d 'admin_name=Ada Admin' $B >/dev/null
curl -s -c $J -b $J -d step=o365 $B >/dev/null
curl -s -c $J -b $J -d step=branding -d "app_name=$NAME" -d app_url=http://127.0.0.1:$PORT $B >/dev/null
curl -s -c $J -b $J -d step=finalize $B | grep -o -i 'installation complete\|error[^<]*' | head -3
echo "Running at http://127.0.0.1:$PORT/  (admin@example.com / Adm1n!Harb0ur#7, made-up test account)"
