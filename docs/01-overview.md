# kcaldav のしくみ（AIエージェント向け設計マニュアル）

予約でも請求でもなく、**カレンダーの二人同期(CalDAV)を1ファイルで**やる製品。
巨大なフレームワーク(sabre/dav 等)を使わず、CalDAVで実際に必要な処理だけを
素のPHPで書いてある。人間が読んでも分かるようにしている。

## 何をするものか

Thunderbird・iPhone標準カレンダー・Android(DAVx5)は、いずれも **CalDAV** という
標準プロトコルのクライアントを内蔵している。kcaldav はそのサーバー側。
どの端末から予定を足しても、他の端末に反映される(サーバーが唯一の正)。

- URLの形(この1ファイルがサーバー全体):
  - `.../kcaldav.php/<ユーザー>/` … カレンダー一覧(principal / calendar-home)
  - `.../kcaldav.php/<ユーザー>/<カレンダー>/` … カレンダー(collection)
  - `.../kcaldav.php/<ユーザー>/<カレンダー>/<uid>.ics` … 予定1件(resource)

## CalDAVで実装している動詞

| メソッド | 役割 | 実装 |
|---|---|---|
| OPTIONS | 自分がCalDAV対応だと名乗る(`DAV: ... calendar-access`) | `kc_options()` |
| PROPFIND | カレンダー/予定の一覧とプロパティ(displayname, getctag, getetag) | `kc_propfind()` |
| REPORT | calendar-query(全予定を返す) / calendar-multiget(指定hrefを返す) | `kc_report()` |
| GET | 予定1件の.icsを返す | `kc_get()` |
| PUT | 予定を作成/更新(ETagを返す) | `kc_put()` |
| DELETE | 予定を削除 | `kc_delete()` |

これだけで Thunderbird / iPhone / DAVx5 の読み書き同期が成立する。
sync-collection(差分同期)は実装せず、**getctag**(カレンダーの状態ハッシュ)で
変更を検知させている。クライアントは getctag が変わったら一覧を取り直す。
個人〜家族規模ではこれで十分で、実装がずっと小さくなる。

## データの持ち方

- SQLite 1ファイル `kcaldav_data/kcaldav.sqlite`、テーブル `events`
  (userid, calendar, uri, uid, etag, ical, updated_at)。
- 予定の本体(iCalendarテキスト)はそのまま `ical` 列に入れる。kcaldavは中身を
  解釈せず保管・配信するだけ(だから対応が壊れにくい)。
- ETag = `md5(ical)`。変更で自動的に変わる。getctag = 件数+最終更新+id合計のハッシュ。
- バックアップは **kcaldav.sqlite をFTPで落とすだけ**。

## セキュリティ

- Basic認証(HTTPS前提)。パスワードは password_hash / password_verify。
- **URLの先頭セグメント＝認証ユーザーを強制**。他人のパスは403。
- 宣言外(config に無い)カレンダーへのPUTは403。任意SQL・任意コードは受けない。
- 全SQLはPDOプレースホルダ。URLの値をSQLの識別子に直接入れない。
- 出力XMLは htmlspecialchars(`kc_e`)。
- `kcaldav_data/` と *.sqlite はWebから403(.htaccess)。

## FastCGIでのBasic認証

CGI/FastCGI環境では `PHP_AUTH_USER` が空のことがある。そのため
`HTTP_AUTHORIZATION`(および REDIRECT_*)を自前でbase64復元している。
同梱の `.htaccess` の `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`
がヘッダを転送する(これが無いと認証が通らないサーバーがある)。

## 設置手順

1. `kcaldav_config.php.example` を `kcaldav_config.php` にコピーして編集
   (ユーザー・カレンダー・パスワードハッシュ)
2. ハッシュ: `php scripts/make_password_hash.php`
3. `public/` をFTPアップロード(`kcaldav_data/.htaccess` も)
4. カレンダーアプリに `https://ドメイン/kcaldav.php/<ユーザー>/<カレンダー>/` を登録
5. 検証: `php scripts/check_kcaldav.php`(18件OK)

## WEBカレンダー(ブラウザで読み書き・アプリ不要)

ブラウザで本体URL(例 `https://ドメイン/kcaldav.php/` やリライトした `/cal/`)を
開くと、Basic認証のあと**予定の一覧・追加・編集・削除ができるWEB画面**になる
(`kc_web_app()`)。スマホのブラウザだけで完結する。ここで足した予定は同じ
eventsテーブルに入るので、下の各クライアントにも反映される。

- 一覧は「これからの予定」を先に、過去は折りたたみ
- 追加/編集フォーム: タイトル・日付・時刻・終日・場所。入力はJST、保存はUTC(Z)
- CSRFトークン＋PRG(リダイレクト)で二重送信を防止

## クライアント別メモ(CalDAV同期)

- **Thunderbird**: 新しいカレンダー → ネットワーク上 → CalDAV → ユーザー名 + カレンダーURL
- **iPhone / iPad**: 設定 → カレンダー → アカウント追加 → その他 → CalDAV(アプリ不要)。
  サーバは `ドメイン/<ユーザー>/` でも、カレンダーURL直接でもよい
- **Android(KashCal)**: 単体でCalDAV対応。アカウント追加 → CalDAV → ベースURL・ユーザー名・パスワード
- **Android(DAVx5)**: F-Droid版は無料。ベースURL `https://ドメイン/<ユーザー>/` を登録し、
  普段のカレンダーアプリで読み書き

### 書き込みが「読み取り専用」になるとき
クライアントが編集を無効化する場合、多くは `current-user-privilege-set` を
見て判断している。kcaldav はカレンダー/予定の両方で read/write/bind 権限を返す。
OPTIONS の DAV ヘッダにも `access-control` を含める。これが無いと KashCal 等は
読み取り専用扱いにする。

PHP 5.6以上(8.3確認済み)。DB・Composer・npm 不要。
