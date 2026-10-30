#!/bin/bash
# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
# package.sh [out-dir] : makes the full install zip (app only, files at the zip root) named after the build in api/lib/Build.php.
set -e
HERE=$(cd "$(dirname "$0")/.." && pwd); OUT=$(cd "${1:-$HERE/..}" && pwd)
B=$(sed -n "s/.*define('ITOPS_BUILD', '\([^']*\)').*/\1/p" "$HERE/api/lib/Build.php")
Z="$OUT/ITOps-$B-Full-Install.zip"; rm -f "$Z"
(cd "$HERE" && zip -qr -X "$Z" . -x 'dev/*' 'docs/*' 'CLAUDE.md' 'STARTER-REPORT.md' 'config.php' '*-license.php' '.gitignore' 'public/uploads/*/*')
echo "$Z"
