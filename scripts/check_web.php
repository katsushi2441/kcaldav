<?php
// WEB画面(ブラウザで読み書き)の検証。proc_openで自前サーバーを立てて叩く。
$pub = dirname(__DIR__) . '/public';
$tmp = sys_get_temp_dir() . '/kcweb_' . getmypid();
@mkdir($tmp, 0700, true);
$hash = password_hash('1111', PASSWORD_DEFAULT);
$cfg = $tmp . '/config.php';
file_put_contents($cfg, "<?php\ndefine('KCALDAV_DATA_DIR','$tmp');\ndefine('KCALDAV_TZ','Asia/Tokyo');\n"
    . "function kcaldav_users(){return array('kojima'=>array('password_hash'=>'$hash','calendars'=>array('default'=>array('name'=>'マイカレンダー','color'=>'#2f6bd8'))));}\n");
$env = array('KCALDAV_CONFIG' => $cfg, 'PATH' => getenv('PATH'));
$desc = array(1 => array('file', $tmp . '/log', 'a'), 2 => array('file', $tmp . '/log', 'a'));
$proc = proc_open('php -S 127.0.0.1:18996 ' . escapeshellarg($pub . '/kcaldav.php'), $desc, $p, $pub, $env);
usleep(700000);
$B = 'http://127.0.0.1:18996/kcaldav.php';
$jar = $tmp . '/jar';
$pass = 0; $fail = 0;
function ok($c, $l) { global $pass, $fail; if ($c) { $pass++; echo "  OK   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } }
function http($m, $url, $opt = array()) {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => array('Accept: text/html'), CURLOPT_FOLLOWLOCATION => false));
    if (isset($opt['post'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['post']); }
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return array($code, substr($r, 0, $hs), substr($r, $hs));
}

echo "\n[1] 認証\n";
$ch = curl_init("$B/"); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>array('Accept: text/html')));
$body=curl_exec($ch); ok(strpos($body,'ログイン')!==false, '未ログインはログイン画面'); curl_close($ch);

echo "\n[1b] フォームログイン\n";
list($lc)=http('POST',"$B/",array('post'=>'action=login&login_user=kojima&login_pass=1111'));
ok($lc===302,'正しい資格でログイン(302)');
list($bc)=http('POST',"$B/",array('post'=>'action=login&login_user=kojima&login_pass=wrong'));
ok($bc===200,'誤資格はログイン画面(200)');
// 再ログイン(誤りでセッション未確立のため)
http('POST',"$B/",array('post'=>'action=login&login_user=kojima&login_pass=1111'));

echo "\n[2] WEB画面が出る\n";
list($c, $h, $b) = http('GET', "$B/");
ok($c === 200, 'ログイン後200');
ok(strpos($b, '予定を追加') !== false, '追加フォームがある');
ok(strpos($b, 'マイカレンダー') !== false, 'カレンダー名が出る');
preg_match('/name="csrf" value="([a-f0-9]+)"/', $b, $m); $csrf = isset($m[1]) ? $m[1] : '';
ok($csrf !== '', 'CSRFトークンがある');

echo "\n[3] 予定を追加(POST)→一覧に出る\n";
list($c, $h) = http('POST', "$B/", array('post' =>
    "csrf=$csrf&cal=default&action=save&summary=" . rawurlencode('テスト会議') . "&date=2026-08-15&edate=2026-08-15&stime=14:00&etime=15:00&location=" . rawurlencode('会議室A')));
ok($c === 302, '追加後はリダイレクト(PRG)');
list($c, $h, $b) = http('GET', "$B/?cal=default&view=list");
ok(strpos($b, 'テスト会議') !== false, '一覧にタイトルが出る');
ok(strpos($b, '14:00') !== false, '時刻が出る(JST)');
ok(strpos($b, '会議室A') !== false, '場所が出る');

echo "\n[4] CalDAV側(Thunderbird)からも同じ予定が見える=同期の核\n";
$ch = curl_init("$B/kojima/default/");
curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST=>'REPORT', CURLOPT_RETURNTRANSFER=>true, CURLOPT_USERPWD=>'kojima:1111',
    CURLOPT_POSTFIELDS=>'<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"/></c:filter></c:calendar-query>',
    CURLOPT_HTTPHEADER=>array('Depth: 1')));
$rep = curl_exec($ch); curl_close($ch);
ok(strpos($rep, 'テスト会議') !== false, 'CalDAVのcalendar-dataにWEBで足した予定が入る');
ok(preg_match('/DTSTART:20260815T050000Z/', $rep) === 1, '14:00 JST が 05:00Z で保存されている(UTC変換OK)');

echo "\n[5] 編集\n";
preg_match('/edit=(\d+)/', $b, $me); $id = isset($me[1]) ? $me[1] : '';
list($c, $h, $b2) = http('GET', "$B/?cal=default&edit=$id");
ok(strpos($b2, 'value="テスト会議"') !== false, '編集フォームに既存値が入る');
list($c) = http('POST', "$B/", array('post' =>
    "csrf=$csrf&cal=default&action=save&id=$id&summary=" . rawurlencode('会議(変更)') . "&date=2026-08-15&edate=2026-08-15&stime=16:00&etime=17:00"));
list($c, $h, $b) = http('GET', "$B/?cal=default&view=list");
ok(strpos($b, '会議(変更)') !== false && strpos($b, '16:00') !== false, '編集が反映される');

echo "\n[6] 削除\n";
list($c) = http('POST', "$B/", array('post' => "csrf=$csrf&cal=default&action=del&id=$id"));
list($c, $h, $b) = http('GET', "$B/?cal=default&view=list");
ok(strpos($b, '会議(変更)') === false, '削除で一覧から消える');

echo "\n[7] 終日予定\n";
list($c) = http('POST', "$B/", array('post' =>
    "csrf=$csrf&cal=default&action=save&summary=" . rawurlencode('終日イベント') . "&date=2026-08-20&edate=2026-08-20&allday=1"));
list($c, $h, $b) = http('GET', "$B/?cal=default&view=list");
ok(strpos($b, '終日イベント') !== false && strpos($b, '終日') !== false, '終日予定が追加・表示される');

echo "\n[8] タブ・フォームの起点が有効(PATH_INFO空=web app)\n";
list($c,$h,$b)=http('GET',"$B/?view=month");
preg_match('/href="([^"]*view=week)"/',$b,$mw); $wk=isset($mw[1])?str_replace('&amp;','&',$mw[1]):'';
$abs = (strpos($wk,'http')===0)?$wk:("http://127.0.0.1:18996".$wk);
list($cc)=http('GET',$abs);
ok($cc===200,'週タブのリンク先が200(404にならない)');
preg_match('/action="([^"]*)"/',$b,$ma); $act=isset($ma[1])?$ma[1]:'';
ok($act!=='' && (strpos($act,'kcaldav.php')!==false || substr($act,-1)==='/'), 'フォームactionがweb app入口を指す');

if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
array_map('unlink', glob($tmp . '/*')); @rmdir($tmp);
echo "\n" . ($fail === 0 ? "すべて通りました（{$pass}件）\n" : "失敗 {$fail}件 / 成功 {$pass}件\n");
exit($fail === 0 ? 0 : 1);
