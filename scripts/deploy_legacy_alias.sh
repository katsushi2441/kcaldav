#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
set -a
. /home/kojima/work/aixec/.env
set +a

remote="/web/exbridge_jp/kcaldav"
curl --fail --silent --show-error --ftp-create-dirs \
  -T deploy/legacy-kcaldav.htaccess \
  "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/.htaccess"

echo "published legacy CalDAV alias: https://exbridge.jp/kcaldav/"
