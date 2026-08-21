<?php
/**
 * kcaldav の検証。ビルトインサーバーを立てて、CalDAVの一連(OPTIONS/認証/
 * PROPFIND/PUT/REPORT/GET/更新/DELETE/権限分離)を実際に叩く。
 * 本番configには一切触れない(環境変数 KCALDAV_CONFIG で一時configを差す)。
 * 実行: php scripts/check_kcaldav.php
 */
$pubdir = dirname(__DIR__) . '/public';
$tmp = sys_get_temp_dir() . '/kcaldav_check_' . getmypid();
@mkdir($tmp, 0700, true);

$hash = password_hash('testpass', PASSWORD_DEFAULT);
$cfg = $tmp . '/config.php';
file_put_contents($cfg, "<?php\n"
    . "define('KCALDAV_DATA_DIR', '" . $tmp . "');\n"
    . "define('KCALDAV_TZ','Asia/Tokyo');\n"
    . "function kcaldav_users(){ return array('u'=>array('password_hash'=>'" . $hash . "',"
    . "'calendars'=>array('cal'=>array('name'=>'テスト','color'=>'#000')))); }\n");

// 前回の検証で残ったサーバーが同じポートを掴んでいると、古い設定のまま応答して
// 検証結果が丸ごと嘘になる（実際に誤判定した）。掴まれていたら先に止める。
function kc_free_port($host, $port) {
    $fp = @fsockopen($host, $port, $e, $s, 0.4);
    if (!$fp) { return; }
    fclose($fp);
    fwrite(STDERR, "ポート {$port} が使用中です。前回の検証サーバーを終了してから再実行してください:\n"
        . "  pkill -f 'php -S {$host}:{$port}'\n");
    exit(2);
}

$host = '127.0.0.1'; $port = 18997;
kc_free_port($host, $port);
$env = array('KCALDAV_CONFIG' => $cfg, 'PATH' => getenv('PATH'));
$desc = array(0 => array('pipe','r'), 1 => array('file', $tmp . '/srv.log','a'), 2 => array('file', $tmp . '/srv.log','a'));
$proc = proc_open('php -S ' . $host . ':' . $port . ' ' . escapeshellarg($pubdir . '/kcaldav.php'),
                  $desc, $pipes, $pubdir, $env);
usleep(700000);

$base = "http://$host:$port/kcaldav.php";
$pass = 0; $fail = 0;
function ok($c, $label, $got = null) { global $pass, $fail;
    if ($c) { $pass++; echo "  OK   $label\n"; }
    else { $fail++; echo "  FAIL $label" . ($got === null ? '' : "  → $got") . "\n"; } }

function req($method, $url, $auth, $body = null, $headers = array()) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($auth !== null) { curl_setopt($ch, CURLOPT_USERPWD, $auth); }
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); $headers[] = 'Content-Type: text/calendar'; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return array($code, substr($resp, 0, $hs), substr($resp, $hs));
}

echo "\n[1] OPTIONS\n";
list($c, $h) = req('OPTIONS', "$base/u/cal/", null);
ok($c === 200, 'OPTIONS 200', $c);
ok(stripos($h, 'calendar-access') !== false, 'DAVヘッダにcalendar-access');

echo "\n[2] 認証\n";
$pf = '<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>';
list($c) = req('PROPFIND', "$base/u/cal/", null, $pf);
ok($c === 401, '未認証は401', $c);
list($c) = req('PROPFIND', "$base/u/cal/", 'u:wrong', $pf);
ok($c === 401, '誤パスワードは401', $c);
list($c, $h, $b) = req('PROPFIND', "$base/u/cal/", 'u:testpass',
    '<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><cs:getctag xmlns:cs="http://calendarserver.org/ns/"/></d:prop></d:propfind>');
ok($c === 207, '正しい認証は207', $c);
ok(strpos($b, '<c:calendar/>') !== false, 'resourcetypeにcalendar');
ok(strpos($b, 'getctag') !== false, 'getctagを返す');

echo "\n[3] 予定の作成・一覧・取得\n";
$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VEVENT\r\nUID:x1@t\r\nDTSTAMP:20260810T000000Z\r\nDTSTART:20260812T010000Z\r\nDTEND:20260812T020000Z\r\nSUMMARY:テスト予定\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
list($c, $h) = req('PUT', "$base/u/cal/x1.ics", 'u:testpass', $ics);
ok($c === 201, 'PUT新規は201', $c);
ok(stripos($h, 'ETag:') !== false, 'ETagを返す');
list($c, $h, $b) = req('PROPFIND', "$base/u/cal/", 'u:testpass',
    '<d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>', array('Depth: 1'));
ok(substr_count($b, '<d:response>') === 2, 'Depth1でcollection+予定1件', substr_count($b, '<d:response>'));
list($c, $h, $b) = req('REPORT', "$base/u/cal/", 'u:testpass',
    '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"/></c:filter></c:calendar-query>', array('Depth: 1'));
ok(strpos($b, 'テスト予定') !== false, 'calendar-queryがcalendar-data返す');
list($c, $h, $b) = req('GET', "$base/u/cal/x1.ics", 'u:testpass');
ok($c === 200 && strpos($b, 'SUMMARY:テスト予定') !== false, 'GETで.icsが取れる');

echo "\n[4] calendar-multiget\n";
list($c, $h, $b) = req('REPORT', "$base/u/cal/", 'u:testpass',
    '<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><d:href>/kcaldav.php/u/cal/x1.ics</d:href></c:calendar-multiget>');
ok(strpos($b, 'テスト予定') !== false, 'multigetで指定hrefのdataが返る');

echo "\n[5] 更新・削除\n";
$ics2 = str_replace('テスト予定', 'テスト予定(改)', $ics);
list($c) = req('PUT', "$base/u/cal/x1.ics", 'u:testpass', $ics2);
ok($c === 204, 'PUT更新は204', $c);
list($c) = req('DELETE', "$base/u/cal/x1.ics", 'u:testpass');
ok($c === 204, 'DELETEは204', $c);
list($c) = req('GET', "$base/u/cal/x1.ics", 'u:testpass');
ok($c === 404, '削除後GETは404', $c);

echo "\n[6] 権限分離(宣言外は触れない)\n";
list($c) = req('PROPFIND', "$base/other/cal/", 'u:testpass', $pf);
ok($c === 403, '他人のパスは403', $c);
list($c) = req('PUT', "$base/u/nope/x.ics", 'u:testpass', $ics);
ok($c === 403, '宣言外カレンダーへのPUTは403', $c);

echo "\n[7] 同期の診断ログは既定で無効(売り物が勝手にログを育てない)\n";
ok(!is_file($tmp . '/sync_access.log'), '既定ではsync_access.logを作らない');
$src = file_get_contents(dirname(__DIR__) . '/public/kcaldav.php');
ok(preg_match("/define\('KCALDAV_SYNC_LOG', false\)/", $src) === 1, 'KCALDAV_SYNC_LOGの既定はfalse');
ok(strpos($src, 'KCALDAV_SYNC_LOG_MAX') !== false, 'ログに上限がある(無限に育たない)');
ok(strpos($src, 'HTTP_AUTHORIZATION') === false || strpos($src, "'sync_access.log'") === false
   || !preg_match('/sync_access\.log.*PHP_AUTH_PW/s', $src), '診断ログに資格情報を書かない');

echo "\n[8] 同期世代(CTag/ETag)\n";
ok(strpos($src, 'KCALDAV_SYNC_REVISION') !== false, '同期世代の定数がある');
ok(preg_match('/function kc_client_etag/', $src) === 1, 'クライアント向けETagを1か所で作る');
ok(preg_match('/getetag>\' \. kc_e\(kc_client_etag/', $src) === 1, 'PROPFIND/REPORTのetagが同期世代を通る');
ok(substr_count($src, "header('ETag: ' . kc_client_etag(") === 2, 'GET/PUTのETagヘッダも同期世代を通る');

if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);

echo "\n" . ($fail === 0 ? "すべて通りました（{$pass}件）\n" : "失敗 {$fail}件 / 成功 {$pass}件\n");
exit($fail === 0 ? 0 : 1);
