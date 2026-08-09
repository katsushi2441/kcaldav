#!/usr/bin/env bash
# kappstore配布zip。設定の実物・予約データは含めない。
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p outputs
stamp=$(date +%Y%m%d)
zip="outputs/kcaldav-${stamp}.zip"
rm -f "$zip"
zip -r "$zip" \
  public/kcaldav.php public/kcaldav_config.php.example \
  public/.htaccess public/kcaldav_data/.htaccess \
  scripts/make_password_hash.php scripts/check_kcaldav.php \
  skills docs README.md LICENSE \
  -x '*.sqlite*' -x '*.log' -x '.htaccess.exbridge' >/dev/null
echo "built: $zip ($(du -h "$zip" | cut -f1))"
