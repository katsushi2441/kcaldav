# kcaldav

1ファイルのCalDAVサーバー。作業前に必ず読む:

- skills/kcaldav/SKILL.md — 設定変更・改造の正しいやり方(鉄則あり)
- docs/01-overview.md — 設計(CalDAVの動詞と保存の仕組み)
- docs/02-customize.md — 改造レシピ

変更後は `php scripts/check_kcaldav.php` で26件全部通ることを確認する（WEB画面を触ったら `php scripts/check_web.php` の21件も）。
