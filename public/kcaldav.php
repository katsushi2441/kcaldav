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
    return ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
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
    header('DAV: 1, 2, 3, calendar-access');
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

/* ============================ ディスパッチ ============================ */

$method = kc_method();

if ($method === 'OPTIONS') { kc_options(); exit; }

// ブラウザで本体URL(パス無し)を開いたら、稼働状況と接続設定の案内を出す。
// CalDAVクライアントはPROPFINDを使うので、これはブラウザ向けの説明ページ。
if ($method === 'GET') {
    $pi = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '';
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
    if ($pi === '' && strpos($accept, 'text/html') !== false) {
        $names = array();
        foreach (kcaldav_users() as $uid => $u) {
            foreach ($u['calendars'] as $ck => $meta) {
                $names[] = kc_e($meta['name']) . ' → <code>' . kc_e(kc_base_url() . '/' . rawurlencode($uid) . '/' . rawurlencode($ck) . '/') . '</code>';
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>kcaldav</title>'
           . '<style>body{font-family:-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.8;'
           . 'max-width:620px;margin:0 auto;padding:26px 18px;color:#24313d;background:#f6f8fa}'
           . 'h1{font-size:20px}.ok{display:inline-block;background:#1e6e46;color:#fff;border-radius:999px;'
           . 'padding:2px 12px;font-size:12px;font-weight:700}code{background:#fff;border:1px solid #dde4ea;'
           . 'border-radius:6px;padding:2px 6px;font-size:12px;word-break:break-all}'
           . '.card{background:#fff;border:1px solid #dde4ea;border-radius:12px;padding:16px 18px;margin:14px 0}'
           . 'ol{padding-left:1.2em}small{color:#6b7a88}</style></head><body>'
           . '<h1>kcaldav <span class="ok">稼働中</span></h1>'
           . '<p>1ファイルのCalDAVサーバーです。PC(Thunderbird)・スマホ(iPhone標準/Android DAVx5)から'
           . '同じ予定表を読み書き同期できます。この画面は説明用で、実際の同期はカレンダーアプリから行います。</p>'
           . '<div class="card"><b>カレンダーのURL</b><ol><li>' . implode('</li><li>', $names) . '</li></ol>'
           . '<small>Thunderbird:「新しいカレンダー → ネットワーク上」にユーザー名と上のURLを入力。'
           . 'iPhone:「設定 → カレンダー → アカウント追加 → その他 → CalDAV」。</small></div>'
           . '<p><small>Kurage App Store の製品。ユーザー・カレンダー・パスワードは kcaldav_config.php で設定します。</small></p>'
           . '</body></html>';
        exit;
    }
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
