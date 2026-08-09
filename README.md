# kcaldav — 1ファイルのCalDAVサーバー ＋ WEBカレンダー

同じ予定表を、**ブラウザ・スマホ・PCのどこからでも読み書き**するための最小CalDAV
サーバー。**フレームワーク不使用・PHP1ファイル・DBはSQLite。** レンタルサーバーに
FTPで置くだけで動く。

つながる先:
- **WEBカレンダー**（同梱）… ブラウザで予定を一覧・追加・編集・削除。アプリ不要
- **iPhone / iPad** … OS標準のカレンダー（アプリ不要）
- **Android** … KashCal（アプリ単体でCalDAV対応）や DAVx5＋任意のカレンダー
- **PC（Thunderbird）** … 内蔵のCalDAVクライアント

どの入口から予定を足しても、他の入口に反映される（サーバーが唯一の正）。

- sabre/dav 等の巨大依存を使わず、CalDAVで実際に必要な動詞
  (OPTIONS/PROPFIND/REPORT/GET/PUT/DELETE)だけを素のPHPで実装(約600行)
- 書き込み権限(current-user-privilege-set)を明示するので、クライアントで編集できる
- ユーザー・カレンダー・パスワードは `kcaldav_config.php` で宣言。宣言外は触れない
- Basic認証(HTTPS)。予定はSQLite 1ファイルに保存。PHP 5.6〜8.3
- 検証 `php scripts/check_kcaldav.php`(18件) と `php scripts/check_web.php`(15件)。
  設定変更はAI(Claude Code)に頼める(skills/・docs/同梱)

## 使い方

1. `kcaldav_config.php.example` を `kcaldav_config.php` にコピーし、ユーザーと
   カレンダーとパスワードハッシュ(`php scripts/make_password_hash.php`)を設定
2. `public/` をFTPでアップロード(`kcaldav_data/.htaccess`も)
3. カレンダーアプリに `https://ドメイン/kcaldav.php/<ユーザー>/<カレンダー>/` を登録

詳細は docs/01-overview.md。ライセンス: MIT。
