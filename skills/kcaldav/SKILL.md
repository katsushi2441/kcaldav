---
name: kcaldav
description: 1ファイルのCalDAVサーバー kcaldav の設定変更・改造を安全に行う。ユーザー/カレンダー追加、パスワード変更、予定データの扱いはこの手順に従う。
---

# kcaldav — CalDAVサーバーの運用・改造スキル

kcaldav は Thunderbird・iPhone・Android から予定表を読み書き同期するための
1ファイルのCalDAVサーバー。PHPのみ・DBはSQLite・依存ライブラリなし。

## ファイル構成（触っていい場所）

| ファイル | 役割 | 編集 |
|---|---|---|
| `kcaldav_config.php` | ユーザー・カレンダー・パスワード | **設定変更はここだけ** |
| `kcaldav.php` | サーバー本体(CalDAVの全処理) | 改造時のみ |
| `kcaldav_data/kcaldav.sqlite` | 予定データ | **直接編集禁止** |

## よくある依頼と正しいやり方

### 「カレンダーを増やしたい（仕事用・プライベート用）」
`kcaldav_config.php` の該当ユーザーの `calendars` に足す。
```php
'calendars' => array(
    'default' => array('name' => 'マイカレンダー', 'color' => '#2f6bd8'),
    'work'    => array('name' => '仕事',           'color' => '#e2725b'),  // 追加
),
```
- **キー('default','work')は運用開始後に変えない**。カレンダーアプリ側のURLと、
  保存済みの予定の対応が切れる。
- 追加後、アプリ側に `.../kcaldav.php/<ユーザー>/work/` を新しいカレンダーとして登録。

### 「家族の分もアカウントを作りたい」
`kcaldav_users()` にユーザーを足す。各ユーザーは自分のカレンダーしか見えない。
```php
'hanako' => array('password_hash' => '...', 'calendars' => array(
    'default' => array('name' => '花子の予定', 'color' => '#1e7a3c'),
)),
```
パスワードハッシュは `php scripts/make_password_hash.php` で作る。

### 「パスワードを変えたい」
`make_password_hash.php` で新しいハッシュを作り、`password_hash` を差し替える。
変更後はカレンダーアプリ側も新パスワードに直す(全端末)。

## 改造の鉄則

1. **他人のデータには触れない構造を壊さない。** ディスパッチで「URLの先頭セグメント
   ＝認証ユーザー」を強制している(`$seg[0] !== $authuser` なら403)。ここは緩めない。
2. **宣言外のカレンダーへは書けない。** PUTは `kcaldav_users()` に無いカレンダーを403で弾く。
3. 予定は必ず PDO のプレースホルダで読み書きする(SQLインジェクション対策)。
   URLのセグメント(ユーザー名・カレンダー名・ファイル名)をSQLに直接入れない。
4. 出力XMLは `kc_e()`(htmlspecialchars)を通す。
5. `kcaldav_data/` はWebから403(.htaccess)。この deny は外さない。
6. 変更後は必ず `php scripts/check_kcaldav.php` を実行し、18件全部通ることを確認する。

## CalDAVの動詞（どこを直せばよいか）

- `kc_propfind()` … カレンダー/予定の一覧・プロパティ(displayname, getctag, getetag)
- `kc_report()` … calendar-query(全予定を返す)/ calendar-multiget(指定hrefを返す)
- `kc_get/kc_put/kc_delete()` … 予定1件の取得・保存・削除
- `kc_prop_*()` … 返すプロパティのXML。クライアント対応を増やすときはここに足す

## 動作確認

```bash
php -l kcaldav.php
php scripts/check_kcaldav.php   # OPTIONS/認証/PROPFIND/REPORT/PUT/GET/更新/削除/権限分離 18件
```
