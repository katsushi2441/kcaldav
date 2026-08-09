# kcaldav — 1ファイルのCalDAVサーバー

Thunderbird・iPhone標準カレンダー・Android(DAVx5)から、同じ予定表を読み書き
同期するための最小CalDAVサーバー。**フレームワーク不使用・PHP1ファイル・DBはSQLite。**
レンタルサーバーにFTPで置くだけで動く。

- sabre/dav 等の巨大依存を使わず、CalDAVで実際に必要な動詞
  (OPTIONS/PROPFIND/REPORT/GET/PUT/DELETE)だけを素のPHPで実装(約450行)
- ユーザー・カレンダー・パスワードは `kcaldav_config.php` で宣言。宣言外は触れない
- Basic認証(HTTPS)。予定はSQLite 1ファイルに保存。PHP 5.6〜8.3
- 検証 `php scripts/check_kcaldav.php`(18件)。設定変更はAI(Claude Code)に頼める(skills/・docs/同梱)

## 使い方

1. `kcaldav_config.php.example` を `kcaldav_config.php` にコピーし、ユーザーと
   カレンダーとパスワードハッシュ(`php scripts/make_password_hash.php`)を設定
2. `public/` をFTPでアップロード(`kcaldav_data/.htaccess`も)
3. カレンダーアプリに `https://ドメイン/kcaldav.php/<ユーザー>/<カレンダー>/` を登録

詳細は docs/01-overview.md。ライセンス: MIT。
