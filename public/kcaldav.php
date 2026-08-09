<?php
/**
 * kcaldav — 1ファイルのCalDAVサーバー(フレームワーク不使用)。
 *
 * Thunderbird・iPhone標準カレンダー・Android(DAVx5)から、同じ予定表を
 * 読み書き同期するための最小CalDAVサーバー。sabre/dav等の巨大な依存は使わず、
 * CalDAVで実際に必要な動詞(OPTIONS/PROPFIND/REPORT/GET/PUT/DELETE)だけを
 * 素のPHPで実装している。保存はSQLite 1ファイル。PHP 5.6〜8.3で動く。
 *
 * URLの形(この1ファイルがサーバー全体):
 *   .../kcaldav.php/<ユーザー>/                     … カレンダー一覧(principal)
 *   .../kcaldav.php/<ユーザー>/<カレンダー>/          … カレンダー(collection)
 *   .../kcaldav.php/<ユーザー>/<カレンダー>/<uid>.ics … 予定1件(resource)
 *
 * 設定(ユーザー・パスワード・カレンダー)は kcaldav_config.php に置く。
 * そこに無い相手は一切さわれない。任意コードもSQLも受け付けない。
 */

// 検証時のみ、環境変数で別configを差せる(本番configを汚さない)
$__cfg = getenv('KCALDAV_CONFIG');
if (!$__cfg || !is_file($__cfg)) { $__cfg = __DIR__ . '/kcaldav_config.php'; }
if (!is_file($__cfg)) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo "kcaldav_config.php がありません。kcaldav_config.php.example をコピーして設定してください。";
    exit;
}
require $__cfg;

if (!defined('KCALDAV_DATA_DIR')) { define('KCALDAV_DATA_DIR', __DIR__ . '/kcaldav_data'); }
define('KCALDAV_DB', KCALDAV_DATA_DIR . '/kcaldav.sqlite');
if (!defined('KCALDAV_REALM')) { define('KCALDAV_REALM', 'kcaldav'); }

date_default_timezone_set(defined('KCALDAV_TZ') ? KCALDAV_TZ : 'Asia/Tokyo');

// XML名前空間
$GLOBALS['KC_NS'] = array(
    'DAV:' => 'd',
    'urn:ietf:params:xml:ns:caldav' => 'c',
    'http://calendarserver.org/ns/' => 'cs',
    'http://apple.com/ns/ical/' => 'ical',
);

/* ============================ DB ============================ */

function kc_db() {
    static $db = null;
    if ($db !== null) { return $db; }
    if (!is_dir(KCALDAV_DATA_DIR)) { @mkdir(KCALDAV_DATA_DIR, 0775, true); }
    $db = new PDO('sqlite:' . KCALDAV_DB);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        userid TEXT NOT NULL, calendar TEXT NOT NULL, uri TEXT NOT NULL,
        uid TEXT, etag TEXT NOT NULL, ical TEXT NOT NULL,
        updated_at INTEGER NOT NULL,
        UNIQUE(userid, calendar, uri))');
    return $db;
}

function kc_ctag($userid, $calendar) {
    $st = kc_db()->prepare('SELECT COUNT(*) n, COALESCE(MAX(updated_at),0) m, COALESCE(SUM(id),0) s
                            FROM events WHERE userid=? AND calendar=?');
    $st->execute(array($userid, $calendar));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return '"' . substr(md5($r['n'] . ':' . $r['m'] . ':' . $r['s']), 0, 16) . '"';
}

/* ============================ Auth ============================ */

function kc_read_auth() {
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        return array($_SERVER['PHP_AUTH_USER'], isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '');
    }
    // FastCGI/CGI では Authorization ヘッダを自前で復元する(.htaccess で転送)
    $h = '';
    foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION',
                   'REDIRECT_REDIRECT_HTTP_AUTHORIZATION') as $k) {
        if (!empty($_SERVER[$k])) { $h = $_SERVER[$k]; break; }
    }
    if (stripos($h, 'basic ') === 0) {
        $dec = base64_decode(substr($h, 6));
        if ($dec !== false && strpos($dec, ':') !== false) {
            return explode(':', $dec, 2);
        }
    }
    return array(null, null);
}

function kc_require_auth() {
    list($u, $p) = kc_read_auth();
    $users = kcaldav_users();
    if ($u !== null && isset($users[$u])) {
        $hash = $users[$u]['password_hash'];
        $ok = (strpos($hash, '$') === 0) ? password_verify((string)$p, $hash)
                                         : hash_equals($hash, (string)$p);
        if ($ok) { return $u; }
    }
    header('WWW-Authenticate: Basic realm="' . KCALDAV_REALM . '"');
    header('Content-Type: text/plain; charset=utf-8', true, 401);
    echo 'Authentication required';
    exit;
}

/* ============================ ルーティング ============================ */

function kc_path_segments() {
    $pi = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
    if ($pi === '' && isset($_SERVER['REQUEST_URI'])) {
        // PATH_INFO が無い環境向けのフォールバック
        $script = $_SERVER['SCRIPT_NAME'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (strpos($uri, $script) === 0) { $pi = substr($uri, strlen($script)); }
    }
    $pi = rawurldecode($pi);
    $parts = array_values(array_filter(explode('/', $pi), 'strlen'));
    return $parts;
}

function kc_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    // .htaccess のリライトできれいなURL(/cal/…)にする場合は
    // kcaldav_config.php で KCALDAV_PUBLIC_BASE('/cal' 等) を指定する。
    $base = (defined('KCALDAV_PUBLIC_BASE') && KCALDAV_PUBLIC_BASE !== '')
        ? KCALDAV_PUBLIC_BASE : $_SERVER['SCRIPT_NAME'];
    return ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $base;
}
function kc_href($path) { // path は先頭スラッシュ付き相対
    return parse_url(kc_base_url(), PHP_URL_PATH) . $path;
}

function kc_method() {
    $m = $_SERVER['REQUEST_METHOD'];
    return strtoupper($m);
}

function kc_body() {
    static $b = null;
    if ($b === null) { $b = file_get_contents('php://input'); }
    return $b;
}

/* ============================ XML ヘルパ ============================ */

function kc_xml_header() {
    header('Content-Type: application/xml; charset=utf-8', true, 207);
    $ns = '';
    foreach ($GLOBALS['KC_NS'] as $uri => $pfx) { $ns .= ' xmlns:' . $pfx . '="' . $uri . '"'; }
    return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<d:multistatus' . $ns . '>';
}
function kc_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/* 1つの <d:response> を作る。$props は array(prop-xml => value|null, ...) */
function kc_response($href, $found_props, $missing_props = array()) {
    $x = '<d:response><d:href>' . kc_e($href) . '</d:href>';
    if ($found_props) {
        $x .= '<d:propstat><d:prop>';
        foreach ($found_props as $p) { $x .= $p; }
        $x .= '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>';
    }
    if ($missing_props) {
        $x .= '<d:propstat><d:prop>';
        foreach ($missing_props as $p) { $x .= '<' . $p . '/>'; }
        $x .= '</d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>';
    }
    return $x . '</d:response>';
}

/* リクエストされたプロパティ名の一覧(ローカル名)を取り出す */
function kc_requested_props($xml) {
    $names = array();
    if ($xml && preg_match('#<[^>]*:?prop\b[^>]*>(.*?)</[^>]*:?prop>#s', $xml, $m)) {
        if (preg_match_all('#<([a-zA-Z0-9]+:)?([a-zA-Z0-9\-]+)#', $m[1], $mm)) {
            foreach ($mm[2] as $n) { $names[strtolower($n)] = true; }
        }
    }
    return $names;
}

/* ============================ プロパティ生成 ============================ */

function kc_prop_calendar_set($userid, $calendar, $meta, $want) {
    $ctag = kc_ctag($userid, $calendar);
    $home = '/' . rawurlencode($userid) . '/';
    $selfp = $home . rawurlencode($calendar) . '/';
    $all = array(
        'resourcetype' => '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype>',
        'displayname'  => '<d:displayname>' . kc_e($meta['name']) . '</d:displayname>',
        'getctag'      => '<cs:getctag>' . $ctag . '</cs:getctag>',
        'sync-token'   => '<d:sync-token>' . $ctag . '</d:sync-token>',
        'supported-calendar-component-set' =>
            '<c:supported-calendar-component-set><c:comp name="VEVENT"/><c:comp name="VTODO"/></c:supported-calendar-component-set>',
        'supported-report-set' =>
            '<d:supported-report-set>'
            . '<d:supported-report><d:report><c:calendar-query/></d:report></d:supported-report>'
            . '<d:supported-report><d:report><c:calendar-multiget/></d:report></d:supported-report>'
            . '</d:supported-report-set>',
        'calendar-color'   => '<ical:calendar-color>' . kc_e($meta['color']) . '</ical:calendar-color>',
        'current-user-principal' => '<d:current-user-principal><d:href>' . kc_e(kc_href($home)) . '</d:href></d:current-user-principal>',
        'owner' => '<d:owner><d:href>' . kc_e(kc_href($home)) . '</d:href></d:owner>',
        'calendar-home-set' => '<c:calendar-home-set><d:href>' . kc_e(kc_href($home)) . '</d:href></c:calendar-home-set>',
        // 「あなたはこのカレンダーに書き込めます」を明示。これが無いと
        // 一部クライアント(KashCal等)がカレンダーを読み取り専用扱いにする。
        'current-user-privilege-set' =>
            '<d:current-user-privilege-set>'
            . '<d:privilege><d:read/></d:privilege>'
            . '<d:privilege><d:write/></d:privilege>'
            . '<d:privilege><d:write-content/></d:privilege>'
            . '<d:privilege><d:write-properties/></d:privilege>'
            . '<d:privilege><d:bind/></d:privilege>'
            . '<d:privilege><d:unbind/></d:privilege>'
            . '<d:privilege><d:read-current-user-privilege-set/></d:privilege>'
            . '</d:current-user-privilege-set>',
    );
    return kc_pick($all, $want);
}

function kc_prop_home($userid, $want) {
    $home = '/' . rawurlencode($userid) . '/';
    $all = array(
        'resourcetype' => '<d:resourcetype><d:collection/><d:principal/></d:resourcetype>',
        'displayname'  => '<d:displayname>' . kc_e($userid) . '</d:displayname>',
        'current-user-principal' => '<d:current-user-principal><d:href>' . kc_e(kc_href($home)) . '</d:href></d:current-user-principal>',
        'principal-url' => '<d:principal-URL><d:href>' . kc_e(kc_href($home)) . '</d:href></d:principal-URL>',
        'calendar-home-set' => '<c:calendar-home-set><d:href>' . kc_e(kc_href($home)) . '</d:href></c:calendar-home-set>',
        'calendar-user-address-set' => '<c:calendar-user-address-set><d:href>' . kc_e(kc_href($home)) . '</d:href></c:calendar-user-address-set>',
    );
    return kc_pick($all, $want);
}

function kc_prop_event($row, $want, $with_data = false) {
    $all = array(
        'getetag' => '<d:getetag>' . kc_e($row['etag']) . '</d:getetag>',
        'getcontenttype' => '<d:getcontenttype>text/calendar; charset=utf-8; component=vevent</d:getcontenttype>',
        'resourcetype' => '<d:resourcetype/>',
        'current-user-privilege-set' =>
            '<d:current-user-privilege-set>'
            . '<d:privilege><d:read/></d:privilege>'
            . '<d:privilege><d:write/></d:privilege>'
            . '<d:privilege><d:write-content/></d:privilege>'
            . '</d:current-user-privilege-set>',
    );
    if ($with_data || isset($want['calendar-data'])) {
        $all['calendar-data'] = '<c:calendar-data>' . kc_e($row['ical']) . '</c:calendar-data>';
    }
    return kc_pick($all, $want);
}

/* want が空(allprop的)なら主要プロパティを返す。指定があればその範囲で返す */
function kc_pick($all, $want) {
    if (!$want) { return array_values($all); }
    $out = array();
    foreach ($all as $key => $xml) {
        if (isset($want[$key]) || isset($want[strtolower($key)])) { $out[] = $xml; }
    }
    // 何も一致しなければ、最低限 resourcetype/getetag は返す(クライアント互換)
    if (!$out) {
        foreach (array('resourcetype', 'getetag', 'displayname') as $k) {
            if (isset($all[$k])) { $out[] = $all[$k]; }
        }
    }
    return $out;
}

/* ============================ メソッド実装 ============================ */

function kc_options() {
    // access-control を名乗ることで、current-user-privilege-set を
    // クライアントが「書き込み可否の判断材料」として読む
    header('DAV: 1, 2, 3, access-control, calendar-access');
    header('Allow: OPTIONS, GET, HEAD, POST, PUT, DELETE, PROPFIND, REPORT');
    header('Content-Length: 0');
    http_response_code(200);
}

function kc_depth() {
    $d = isset($_SERVER['HTTP_DEPTH']) ? trim($_SERVER['HTTP_DEPTH']) : '0';
    return ($d === '' ) ? '0' : $d;
}

function kc_propfind($userid, $seg) {
    $want = kc_requested_props(kc_body());
    $depth = kc_depth();
    $users = kcaldav_users();
    $cals = $users[$userid]['calendars'];

    echo kc_xml_header();

    if (count($seg) <= 1) {
        // principal / calendar-home。自分自身 + (Depth1で)各カレンダー
        echo kc_response(kc_href('/' . rawurlencode($userid) . '/'), kc_prop_home($userid, $want));
        if ($depth === '1') {
            foreach ($cals as $ckey => $meta) {
                $href = kc_href('/' . rawurlencode($userid) . '/' . rawurlencode($ckey) . '/');
                echo kc_response($href, kc_prop_calendar_set($userid, $ckey, $meta, $want));
            }
        }
    } else {
        // カレンダーcollection。自身 + (Depth1で)各予定
        $ckey = $seg[1];
        if (!isset($cals[$ckey])) { echo '</d:multistatus>'; return; }
        $meta = $cals[$ckey];
        $href = kc_href('/' . rawurlencode($userid) . '/' . rawurlencode($ckey) . '/');
        echo kc_response($href, kc_prop_calendar_set($userid, $ckey, $meta, $want));
        if ($depth === '1') {
            $st = kc_db()->prepare('SELECT uri, etag, ical FROM events WHERE userid=? AND calendar=? ORDER BY id');
            $st->execute(array($userid, $ckey));
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $eh = $href . rawurlencode($row['uri']);
                echo kc_response($eh, kc_prop_event($row, $want));
            }
        }
    }
    echo '</d:multistatus>';
}

function kc_report($userid, $seg) {
    $body = kc_body();
    $want = kc_requested_props($body);
    $ckey = isset($seg[1]) ? $seg[1] : '';
    $users = kcaldav_users();
    if (!isset($users[$userid]['calendars'][$ckey])) { http_response_code(404); return; }
    $base = kc_href('/' . rawurlencode($userid) . '/' . rawurlencode($ckey) . '/');

    // calendar-multiget: 指定された href だけ返す
    if (stripos($body, 'calendar-multiget') !== false) {
        preg_match_all('#<[^>]*:?href>\s*([^<]+?)\s*</[^>]*:?href>#i', $body, $m);
        echo kc_xml_header();
        foreach ($m[1] as $h) {
            $uri = rawurldecode(basename(parse_url(trim($h), PHP_URL_PATH)));
            $st = kc_db()->prepare('SELECT uri, etag, ical FROM events WHERE userid=? AND calendar=? AND uri=?');
            $st->execute(array($userid, $ckey, $uri));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) { echo kc_response($base . rawurlencode($row['uri']), kc_prop_event($row, $want, true)); }
            else { echo '<d:response><d:href>' . kc_e($base . rawurlencode($uri)) . '</d:href>'
                 . '<d:status>HTTP/1.1 404 Not Found</d:status></d:response>'; }
        }
        echo '</d:multistatus>';
        return;
    }

    // calendar-query(+その他): とりあえず全予定を calendar-data つきで返す
    // (個人カレンダー規模ではフィルタ省略で問題ない。クライアント側で絞る)
    echo kc_xml_header();
    $st = kc_db()->prepare('SELECT uri, etag, ical FROM events WHERE userid=? AND calendar=? ORDER BY id');
    $st->execute(array($userid, $ckey));
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        echo kc_response($base . rawurlencode($row['uri']), kc_prop_event($row, $want, true));
    }
    echo '</d:multistatus>';
}

function kc_get($userid, $seg, $head = false) {
    $ckey = isset($seg[1]) ? $seg[1] : '';
    $uri = isset($seg[2]) ? $seg[2] : '';
    $st = kc_db()->prepare('SELECT etag, ical FROM events WHERE userid=? AND calendar=? AND uri=?');
    $st->execute(array($userid, $ckey, $uri));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); return; }
    header('Content-Type: text/calendar; charset=utf-8');
    header('ETag: ' . $row['etag']);
    if ($head) { http_response_code(200); return; }
    echo $row['ical'];
}

function kc_put($userid, $seg) {
    $ckey = isset($seg[1]) ? $seg[1] : '';
    $uri = isset($seg[2]) ? $seg[2] : '';
    $users = kcaldav_users();
    if (!isset($users[$userid]['calendars'][$ckey]) || $uri === '' || substr($uri, -4) !== '.ics') {
        http_response_code(403); return;
    }
    $ical = kc_body();
    if (stripos($ical, 'BEGIN:VCALENDAR') === false) { http_response_code(415); return; }
    if (strlen($ical) > 1048576) { http_response_code(413); return; }
    $etag = '"' . md5($ical) . '"';
    // UID を控えておく(将来の重複検出用)
    $uid = '';
    if (preg_match('/^UID:(.+)$/mi', $ical, $mm)) { $uid = trim($mm[1]); }

    $db = kc_db();
    $st = $db->prepare('SELECT id FROM events WHERE userid=? AND calendar=? AND uri=?');
    $st->execute(array($userid, $ckey, $uri));
    $exists = $st->fetchColumn();

    // If-Match / If-None-Match の最小対応
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === '*' && $exists) {
        http_response_code(412); return;
    }

    if ($exists) {
        $u = $db->prepare('UPDATE events SET ical=?, etag=?, uid=?, updated_at=? WHERE id=?');
        $u->execute(array($ical, $etag, $uid, time(), $exists));
    } else {
        $i = $db->prepare('INSERT INTO events (userid,calendar,uri,uid,etag,ical,updated_at) VALUES (?,?,?,?,?,?,?)');
        $i->execute(array($userid, $ckey, $uri, $uid, $etag, $ical, time()));
    }
    header('ETag: ' . $etag);
    http_response_code($exists ? 204 : 201);
}

function kc_delete($userid, $seg) {
    $ckey = isset($seg[1]) ? $seg[1] : '';
    $uri = isset($seg[2]) ? $seg[2] : '';
    $st = kc_db()->prepare('DELETE FROM events WHERE userid=? AND calendar=? AND uri=?');
    $st->execute(array($userid, $ckey, $uri));
    http_response_code($st->rowCount() ? 204 : 404);
}

/* ============================ WEBカレンダー(ブラウザで読み書き) ============================
 * アプリを入れなくても、スマホ/PCのブラウザから予定を一覧・追加・編集・削除できる。
 * 書き込みはCalDAVと同じeventsテーブルに入るので、Thunderbird等にも反映される。 */

function kc_ics_esc($s) {
    return str_replace(array("\\", "\n", ",", ";"), array("\\\\", "\\n", "\\,", "\\;"), (string)$s);
}
function kc_ics_unesc($s) {
    return str_replace(array("\\n", "\\N", "\\,", "\\;", "\\\\"), array("\n", "\n", ",", ";", "\\"), (string)$s);
}

/** JSTの日付+時刻(文字列)からUTCのepochを作る */
function kc_jst_to_ts($date, $time) {
    $dt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time, new DateTimeZone('Asia/Tokyo'));
    return $dt ? $dt->getTimestamp() : false;
}

/** VEVENTを1件組み立てる(時刻はUTCで書く=VTIMEZONE不要で確実) */
function kc_ics_build($uid, $summary, $start_ts, $end_ts, $allday, $location, $desc) {
    $stamp = gmdate('Ymd\THis\Z');
    if ($allday) {
        $ds = 'DTSTART;VALUE=DATE:' . gmdate('Ymd', $start_ts);
        $de = 'DTEND;VALUE=DATE:' . gmdate('Ymd', $end_ts);
    } else {
        $ds = 'DTSTART:' . gmdate('Ymd\THis\Z', $start_ts);
        $de = 'DTEND:' . gmdate('Ymd\THis\Z', $end_ts);
    }
    $l = array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//kcaldav//web//JA', 'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT', 'UID:' . $uid, 'DTSTAMP:' . $stamp, $ds, $de,
        'SUMMARY:' . kc_ics_esc($summary));
    if ($location !== '') { $l[] = 'LOCATION:' . kc_ics_esc($location); }
    if ($desc !== '') { $l[] = 'DESCRIPTION:' . kc_ics_esc($desc); }
    $l[] = 'END:VEVENT'; $l[] = 'END:VCALENDAR';
    return implode("\r\n", $l) . "\r\n";
}

/** iCalの1本から表示用に要点を取り出す。時刻はepoch(UTC基準)で返す */
function kc_ics_parse($ical) {
    $ical = preg_replace("/\r\n[ \t]/", '', $ical);   // 折り返し行の展開
    $out = array('summary' => '(無題)', 'start' => null, 'end' => null,
                 'allday' => false, 'location' => '', 'uid' => '');
    if (preg_match('/^UID:(.*)$/mi', $ical, $m)) { $out['uid'] = trim($m[1]); }
    if (preg_match('/^SUMMARY(?:;[^:\r\n]*)?:(.*)$/mi', $ical, $m)) { $out['summary'] = kc_ics_unesc(trim($m[1])); }
    if (preg_match('/^LOCATION(?:;[^:\r\n]*)?:(.*)$/mi', $ical, $m)) { $out['location'] = kc_ics_unesc(trim($m[1])); }
    foreach (array('start' => 'DTSTART', 'end' => 'DTEND') as $k => $name) {
        if (preg_match('/^' . $name . '([;][^:\r\n]*)?:([0-9TZ]+)/mi', $ical, $m)) {
            list($ts, $ad) = kc_parse_dt($m[1], $m[2]);
            $out[$k] = $ts;
            if ($ad) { $out['allday'] = true; }
        }
    }
    return $out;
}
function kc_parse_dt($params, $val) {
    $val = trim($val);
    if (stripos($params, 'VALUE=DATE') !== false || preg_match('/^\d{8}$/', $val)) {
        $dt = DateTime::createFromFormat('Ymd', substr($val, 0, 8), new DateTimeZone('Asia/Tokyo'));
        return array($dt ? $dt->getTimestamp() : null, true);
    }
    if (substr($val, -1) === 'Z') {
        $dt = DateTime::createFromFormat('Ymd\THis\Z', $val, new DateTimeZone('UTC'));
        return array($dt ? $dt->getTimestamp() : null, false);
    }
    $tz = 'Asia/Tokyo';
    if (preg_match('/TZID=([^;:]+)/i', $params, $mm)) { $tz = trim($mm[1]); }
    try { $z = new DateTimeZone($tz); } catch (Exception $e) { $z = new DateTimeZone('Asia/Tokyo'); }
    $dt = DateTime::createFromFormat('Ymd\THis', substr($val, 0, 15), $z);
    return array($dt ? $dt->getTimestamp() : null, false);
}

function kc_jst($ts, $fmt) {
    if ($ts === null) { return ''; }
    $dt = new DateTime('@' . $ts); $dt->setTimezone(new DateTimeZone('Asia/Tokyo'));
    return $dt->format($fmt);
}

function kc_web_start_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('KCALWEB');
        $sec = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        @session_set_cookie_params(0, '/', '', $sec, true);
        @session_start();
    }
    if (empty($_SESSION['kc_csrf'])) { $_SESSION['kc_csrf'] = bin2hex(random_bytes(16)); }
}

function kc_web_app() {
    $user = kc_require_auth();          // ブラウザにBasic認証を要求
    kc_web_start_session();
    $users = kcaldav_users();
    $cals = $users[$user]['calendars'];
    $ckeys = array_keys($cals);
    $cal = isset($_REQUEST['cal']) && isset($cals[$_REQUEST['cal']]) ? $_REQUEST['cal'] : $ckeys[0];
    $base = kc_href('/' . rawurlencode($user) . '/' . rawurlencode($cal) . '/');
    $self = kc_href('/' . rawurlencode($user) . '/');    // フォームのaction(戻り先)
    $msg = '';

    // ---- 追加・更新・削除 ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ok = isset($_POST['csrf']) && hash_equals($_SESSION['kc_csrf'], (string)$_POST['csrf']);
        $act = isset($_POST['action']) ? $_POST['action'] : '';
        if ($ok && $act === 'save') {
            $summary = trim((string)(isset($_POST['summary']) ? $_POST['summary'] : ''));
            $date = (string)(isset($_POST['date']) ? $_POST['date'] : '');
            $edate = trim((string)(isset($_POST['edate']) ? $_POST['edate'] : ''));
            $allday = !empty($_POST['allday']);
            $stime = (string)(isset($_POST['stime']) ? $_POST['stime'] : '09:00');
            $etime = (string)(isset($_POST['etime']) ? $_POST['etime'] : '10:00');
            $loc = trim((string)(isset($_POST['location']) ? $_POST['location'] : ''));
            $editid = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($summary === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $msg = 'タイトルと日付を入れてください。';
            } else {
                if ($edate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $edate)) { $edate = $date; }
                if ($allday) {
                    $s = kc_jst_to_ts($date, '00:00');
                    $e = kc_jst_to_ts($edate, '00:00') + 86400;   // 終日は翌日を排他終端に
                } else {
                    $s = kc_jst_to_ts($date, preg_match('/^\d{2}:\d{2}$/', $stime) ? $stime : '09:00');
                    $e = kc_jst_to_ts($edate, preg_match('/^\d{2}:\d{2}$/', $etime) ? $etime : '10:00');
                    if ($e <= $s) { $e = $s + 3600; }
                }
                $db = kc_db();
                if ($editid) {
                    $st = $db->prepare('SELECT uri, ical FROM events WHERE id=? AND userid=? AND calendar=?');
                    $st->execute(array($editid, $user, $cal));
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $uid = '';
                        if (preg_match('/^UID:(.*)$/mi', $row['ical'], $mm)) { $uid = trim($mm[1]); }
                        if ($uid === '') { $uid = $row['uri']; }
                        $ical = kc_ics_build($uid, $summary, $s, $e, $allday, $loc, '');
                        $u = $db->prepare('UPDATE events SET ical=?, etag=?, updated_at=? WHERE id=?');
                        $u->execute(array($ical, '"' . md5($ical) . '"', time(), $editid));
                        $msg = '更新しました。';
                    }
                } else {
                    $uid = 'web-' . bin2hex(random_bytes(8)) . '@kcaldav';
                    $uri = $uid . '.ics';
                    $ical = kc_ics_build($uid, $summary, $s, $e, $allday, $loc, '');
                    $i = $db->prepare('INSERT INTO events (userid,calendar,uri,uid,etag,ical,updated_at) VALUES (?,?,?,?,?,?,?)');
                    $i->execute(array($user, $cal, $uri, $uid, '"' . md5($ical) . '"', $ical, time()));
                    $msg = '追加しました。';
                }
            }
        } elseif ($ok && $act === 'del' && !empty($_POST['id'])) {
            $d = kc_db()->prepare('DELETE FROM events WHERE id=? AND userid=? AND calendar=?');
            $d->execute(array((int)$_POST['id'], $user, $cal));
            $msg = '削除しました。';
        }
        // PRG: 再送防止に自分へリダイレクト
        header('Location: ' . $self . '?cal=' . rawurlencode($cal) . ($msg !== '' ? '&m=' . rawurlencode($msg) : ''));
        exit;
    }

    if (isset($_GET['m'])) { $msg = (string)$_GET['m']; }
    $editing = null;
    if (isset($_GET['edit'])) {
        $st = kc_db()->prepare('SELECT id, ical FROM events WHERE id=? AND userid=? AND calendar=?');
        $st->execute(array((int)$_GET['edit'], $user, $cal));
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) { $editing = kc_ics_parse($r['ical']); $editing['id'] = $r['id']; }
    }

    // ---- 予定一覧(今日以降を先に) ----
    $st = kc_db()->prepare('SELECT id, ical FROM events WHERE userid=? AND calendar=? ORDER BY id DESC');
    $st->execute(array($user, $cal));
    $events = array();
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $p = kc_ics_parse($r['ical']); $p['id'] = $r['id'];
        if ($p['start'] === null) { continue; }
        $events[] = $p;
    }
    usort($events, function ($a, $b) { return $a['start'] - $b['start']; });
    $todaymid = strtotime('today 00:00');
    $upcoming = array(); $past = array();
    foreach ($events as $e) { if (($e['end'] !== null ? $e['end'] : $e['start']) >= $todaymid) { $upcoming[] = $e; } else { $past[] = $e; } }

    kc_web_render($user, $cal, $cals, $self, $msg, $editing, $upcoming, array_reverse($past));
    exit;
}

function kc_web_render($user, $cal, $cals, $self, $msg, $editing, $upcoming, $past) {
    $csrf = kc_e($_SESSION['kc_csrf']);
    $wd = array('日','月','火','水','木','金','土');
    $ev_html = function ($e) use ($self, $cal, $csrf, $wd) {
        $d = kc_jst($e['start'], 'n/j') . '(' . $wd[(int)kc_jst($e['start'], 'w')] . ')';
        if ($e['allday']) { $time = '終日'; }
        else {
            $time = kc_jst($e['start'], 'H:i');
            if ($e['end']) { $time .= '–' . kc_jst($e['end'], 'H:i'); }
        }
        $loc = $e['location'] !== '' ? '<span class="loc">📍' . kc_e($e['location']) . '</span>' : '';
        return '<div class="ev"><div class="evd">' . kc_e($d) . '<small>' . kc_e($time) . '</small></div>'
            . '<div class="evb"><b>' . kc_e($e['summary']) . '</b>' . $loc . '</div>'
            . '<div class="eva">'
            . '<a class="mini" href="' . $self . '?cal=' . rawurlencode($cal) . '&amp;edit=' . $e['id'] . '#form">編集</a>'
            . '<form method="post" onsubmit="return confirm(\'削除しますか？\')">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="cal" value="' . kc_e($cal) . '">'
            . '<input type="hidden" name="action" value="del"><input type="hidden" name="id" value="' . $e['id'] . '">'
            . '<button class="mini del">削除</button></form></div></div>';
    };
    $up = ''; foreach ($upcoming as $e) { $up .= $ev_html($e); }
    if ($up === '') { $up = '<p class="empty">これからの予定はありません。上のフォームから追加できます。</p>'; }
    $pasthtml = ''; foreach ($past as $e) { $pasthtml .= $ev_html($e); }

    // カレンダー切り替え
    $tabs = '';
    if (count($cals) > 1) {
        foreach ($cals as $ck => $meta) {
            $on = ($ck === $cal) ? ' on' : '';
            $tabs .= '<a class="tab' . $on . '" href="' . $self . '?cal=' . rawurlencode($ck) . '">' . kc_e($meta['name']) . '</a>';
        }
    }

    // 追加/編集フォームの初期値
    $isedit = $editing !== null;
    $f = array('id' => $isedit ? $editing['id'] : '', 'summary' => $isedit ? $editing['summary'] : '',
        'date' => $isedit ? kc_jst($editing['start'], 'Y-m-d') : date('Y-m-d'),
        'edate' => $isedit && $editing['end'] ? kc_jst($editing['allday'] ? $editing['end'] - 86400 : $editing['end'], 'Y-m-d') : ($isedit ? kc_jst($editing['start'], 'Y-m-d') : date('Y-m-d')),
        'stime' => $isedit && !$editing['allday'] ? kc_jst($editing['start'], 'H:i') : '09:00',
        'etime' => $isedit && !$editing['allday'] && $editing['end'] ? kc_jst($editing['end'], 'H:i') : '10:00',
        'allday' => $isedit ? $editing['allday'] : false,
        'location' => $isedit ? $editing['location'] : '');

    header('Content-Type: text/html; charset=utf-8');
    $calname = kc_e($cals[$cal]['name']);
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $calname . '</title>'
       . '<style>'
       . ':root{--a:#2f6bd8}*{box-sizing:border-box}'
       . 'body{margin:0;background:#eef2f5;color:#22303c;'
       . 'font-family:-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.7}'
       . 'header{position:sticky;top:0;background:var(--a);color:#fff;padding:12px 16px;font-weight:800;font-size:17px}'
       . 'main{max-width:640px;margin:0 auto;padding:12px 14px 60px}'
       . '.tabs{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0}'
       . '.tab{font-size:13px;text-decoration:none;color:#42566a;border:1px solid #cfd9e2;border-radius:999px;padding:4px 12px;background:#fff}'
       . '.tab.on{background:var(--a);color:#fff;border-color:var(--a)}'
       . '.msg{background:#e7f5ec;border:1px solid #b9e0c7;color:#1e6e46;border-radius:10px;padding:8px 12px;margin:10px 0;font-size:14px}'
       . '.card{background:#fff;border:1px solid #dbe3ea;border-radius:14px;padding:14px;margin:12px 0}'
       . '.card h2{margin:0 0 10px;font-size:15px}'
       . 'label{display:block;font-size:12px;color:#5a6b7a;font-weight:700;margin:8px 0 3px}'
       . 'input[type=text],input[type=date],input[type=time]{width:100%;border:1px solid #cfd9e2;border-radius:9px;padding:10px;font:inherit;background:#fbfdfe}'
       . '.row{display:flex;gap:10px}.row>div{flex:1}'
       . '.chk{display:flex;align-items:center;gap:8px;margin-top:10px;font-size:14px;color:#22303c;font-weight:600}'
       . '.chk input{width:20px;height:20px}'
       . '.btn{margin-top:12px;width:100%;border:0;border-radius:11px;background:var(--a);color:#fff;font:700 15px inherit;padding:13px;cursor:pointer}'
       . '.btn.sub{background:#fff;color:#42566a;border:1px solid #cfd9e2;padding:9px}'
       . '.ev{display:flex;align-items:flex-start;gap:10px;background:#fff;border:1px solid #dbe3ea;border-radius:12px;padding:10px 12px;margin:8px 0}'
       . '.evd{flex:0 0 74px;font-weight:800;font-size:14px;color:var(--a)}.evd small{display:block;color:#5a6b7a;font-weight:600;font-size:12px}'
       . '.evb{flex:1;min-width:0}.evb b{font-size:15px}.loc{display:block;color:#6b7a88;font-size:12px}'
       . '.eva{display:flex;flex-direction:column;gap:5px}'
       . '.mini{font-size:12px;text-decoration:none;text-align:center;color:var(--a);border:1px solid #cfd9e2;border-radius:8px;padding:4px 10px;background:#fff}'
       . 'form.inl,.eva form{margin:0}.mini.del{color:#c0392b;width:100%}'
       . '.empty{color:#6b7a88;font-size:14px;text-align:center;padding:14px}'
       . 'h3.sec{font-size:13px;color:#6b7a88;margin:20px 0 4px}'
       . 'details{margin-top:10px}summary{font-size:13px;color:#6b7a88;cursor:pointer}'
       . '</style></head><body>'
       . '<header>🗓 ' . $calname . '</header><main>'
       . ($tabs !== '' ? '<div class="tabs">' . $tabs . '</div>' : '')
       . ($msg !== '' ? '<div class="msg">' . kc_e($msg) . '</div>' : '')
       // 追加/編集フォーム
       . '<div class="card" id="form"><h2>' . ($isedit ? '予定を編集' : '＋ 予定を追加') . '</h2>'
       . '<form method="post" class="inl" action="' . $self . '">'
       . '<input type="hidden" name="csrf" value="' . $csrf . '">'
       . '<input type="hidden" name="cal" value="' . kc_e($cal) . '">'
       . '<input type="hidden" name="action" value="save">'
       . ($isedit ? '<input type="hidden" name="id" value="' . kc_e($f['id']) . '">' : '')
       . '<label>タイトル</label><input type="text" name="summary" required maxlength="200" value="' . kc_e($f['summary']) . '" placeholder="例: 打ち合わせ">'
       . '<div class="row"><div><label>日付</label><input type="date" name="date" required value="' . kc_e($f['date']) . '"></div>'
       . '<div><label>終了日</label><input type="date" name="edate" value="' . kc_e($f['edate']) . '"></div></div>'
       . '<div class="row"><div><label>開始</label><input type="time" name="stime" value="' . kc_e($f['stime']) . '"></div>'
       . '<div><label>終了</label><input type="time" name="etime" value="' . kc_e($f['etime']) . '"></div></div>'
       . '<label class="chk"><input type="checkbox" name="allday" value="1"' . ($f['allday'] ? ' checked' : '') . '>終日</label>'
       . '<label>場所（任意）</label><input type="text" name="location" maxlength="200" value="' . kc_e($f['location']) . '">'
       . '<button class="btn">' . ($isedit ? '更新する' : '追加する') . '</button>'
       . ($isedit ? '<a class="btn sub" style="display:block;text-align:center;text-decoration:none;margin-top:8px" href="' . $self . '?cal=' . rawurlencode($cal) . '">キャンセル</a>' : '')
       . '</form></div>'
       // 予定一覧
       . '<h3 class="sec">これからの予定</h3>' . $up;
    if ($past !== array()) {
        echo '<details><summary>過去の予定を見る（' . count($past) . '件）</summary>' . $pasthtml . '</details>';
    }
    echo '<p class="empty" style="margin-top:24px">この画面はブラウザで予定を読み書きできます。'
       . 'PC(Thunderbird)やスマホのカレンダーアプリと同じ予定表につながっています。</p>'
       . '</main></body></html>';
}

/* ============================ ディスパッチ ============================ */

$method = kc_method();

if ($method === 'OPTIONS') { kc_options(); exit; }

// ブラウザ(GET/POST・HTML希望・パス無し)は WEBカレンダー画面へ。
// CalDAVクライアントはPROPFIND等を使うので、そちらは下のCalDAV処理へ。
if ($method === 'GET' || $method === 'POST') {
    $pi = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '';
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
    if ($pi === '' && strpos($accept, 'text/html') !== false) { kc_web_app(); exit; }
}


$authuser = kc_require_auth();
$seg = kc_path_segments();

// パスにユーザーが無ければ、自分のホームへ誘導(iPhone等がルートを叩いたとき)
if (count($seg) === 0) {
    if ($method === 'PROPFIND') { $seg = array($authuser); }
    else { header('Location: ' . kc_href('/' . rawurlencode($authuser) . '/')); http_response_code(302); exit; }
}

// 自分のデータ以外はさわれない
if ($seg[0] !== $authuser) {
    header('Content-Type: text/plain; charset=utf-8', true, 403);
    echo 'Forbidden'; exit;
}

switch ($method) {
    case 'PROPFIND': kc_propfind($authuser, $seg); break;
    case 'REPORT':   kc_report($authuser, $seg); break;
    case 'GET':      kc_get($authuser, $seg); break;
    case 'HEAD':     kc_get($authuser, $seg, true); break;
    case 'PUT':      kc_put($authuser, $seg); break;
    case 'DELETE':   kc_delete($authuser, $seg); break;
    default:
        header('Allow: OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, REPORT', true, 405);
        echo 'Method Not Allowed';
}
