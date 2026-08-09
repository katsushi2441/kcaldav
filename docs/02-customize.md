# kcaldav 改造レシピ集（AIエージェント向け）

「設定でできること」(ユーザー・カレンダー・パスワード追加)は SKILL.md を見る。
ここは**コードを触る改造**。終わったら必ず `php scripts/check_kcaldav.php`(18件)を通す。

## レシピ1: 連絡先(CardDAV)も同期したい

kcaldav はカレンダー(CalDAV)専用。連絡先も要るなら、`events` と同じ構造で
`contacts` テーブル(uri, etag, vcard) を足し、`.../kcaldav.php/<user>/addressbook/`
に対して PROPFIND/GET/PUT/DELETE を実装する。resourcetype を
`<card:addressbook xmlns:card="urn:ietf:params:xml:ns:carddav"/>` にし、
REPORT は addressbook-query / addressbook-multiget を返す。CalDAV側とほぼ同型。

## レシピ2: 予約システム(kreserve)の予定をカレンダーに出したい

kreserve など他システムの予定を、読み取り専用でこのカレンダーに流し込める。
一番簡単なのは「橋渡しスクリプト」を cron で回し、kreserve の予約を
iCalendarに変換して kcaldav に PUT する方式(kcaldav 本体は無改造)。
PUTするだけなので、Basic認証で叩けば外部から予定を入れられる。

## レシピ3: 変更通知(誰かが予定を入れたらメール)

`kc_put()` の保存直後にメール送信を足す。二重送信を避けるため、通知済みフラグは
持たず「新規(201)のときだけ送る」程度に留めると安全。大量PUT時は送りすぎに注意。

## レシピ4: sync-collection(差分同期)に対応してポーリングを軽くする

現状は getctag 方式(変更時に全件取り直し)。予定が数千件を超えて重いなら、
`events` に単調増加の `syncrev` を持たせ、REPORT `sync-collection` で
sync-token 以降の変更(追加/更新/削除)だけ返す。supported-report-set に
`<d:sync-collection/>` を足す。削除を追うため、削除は行を消さず tombstone にする。

## レシピ5: 読み取り専用の共有カレンダー(公開URL)

「予定を見せるだけ」のカレンダーが欲しいときは、特定カレンダーだけ認証なしGETを
許し、PUT/DELETEは常に403にする分岐を入れる。あるいは iCal(.ics)を1本吐く
エンドポイントを足して、相手には「購読(読み取り専用)」で渡す。

## やってはいけないこと

- 「URL先頭セグメント＝認証ユーザー」の強制を外す(他人の予定が見えてしまう)
- 宣言外カレンダーへのPUTを通す(任意のカレンダーが作れてしまう)
- URLのセグメントをSQLの識別子やパスに直に入れる(インジェクション/パストラバーサル)
- `kcaldav.sqlite` を直接手で編集する(壊れたら全予定が読めなくなる。PUT/DELETE経由で)
- `kcaldav_data/` の .htaccess deny を外す(予定＝個人情報が丸見えになる)
