<?php
/* Bharat GPS live chat (Oct 2026), the same desk as ScanPlay's.
   Visitors chat from the pop-up on the website (livechat/widget.js); the agent answers from livechat/agent.html
   (web, with browser push alerts via livechat/sw.js). Own SQLite file data/livechat.db (never served: .htaccess blocks
   data/). Agent sign-in = the website admin password (set in /admin/); a signed-in admin token works here too.
   Every name + mobile a visitor gives also becomes a callback request in the admin panel.
   Every visit, new chat and visitor message becomes an "event" that rings the agent (app polls `pending`, browsers get
   a Web Push). Always HTTP 200: the host replaces 4xx bodies. Never cacheable: the host / CDN must not reuse answers. */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache'); header('Expires: 0');
header('X-LiteSpeed-Cache-Control: no-cache'); header('CDN-Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Agent-Pass');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') exit;
date_default_timezone_set('Asia/Kolkata');

$cfg = file_exists(__DIR__.'/../config.php') ? include __DIR__.'/../config.php' : [];
if (!is_array($cfg)) $cfg = [];
define('LC_DIR', __DIR__.'/../data');
if (!is_dir(LC_DIR)) @mkdir(LC_DIR, 0755, true);

$PUSH_AFTER = false;   // set when the answer should be followed by a Web Push to the agent's browsers
function out($ok, $d = []) {
  global $PUSH_AFTER;
  $body = json_encode(['ok'=>$ok] + $d);
  if (!$PUSH_AFTER) { echo $body; exit; }
  ignore_user_abort(true); header('Content-Length: '.strlen($body)); header('Connection: close'); echo $body;
  if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_end_flush(); @flush(); }
  pushAll(); exit;
}
try {
  $db = new PDO('sqlite:'.LC_DIR.'/livechat.db');
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=4000;");
  $db->exec("CREATE TABLE IF NOT EXISTS chats (id INTEGER PRIMARY KEY, token TEXT UNIQUE, site TEXT, name TEXT, phone TEXT, page TEXT,
      ip TEXT, created INTEGER, last INTEGER, status TEXT DEFAULT 'open', joined INTEGER DEFAULT 0, agent_read INTEGER DEFAULT 0, visitor_seen INTEGER DEFAULT 0)");
  $db->exec("CREATE TABLE IF NOT EXISTS msgs (id INTEGER PRIMARY KEY, chat_id INTEGER, who TEXT, body TEXT, ts INTEGER)");
  $db->exec("CREATE INDEX IF NOT EXISTS msgs_chat ON msgs(chat_id, id)");
  $db->exec("CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT)");
  $db->exec("CREATE TABLE IF NOT EXISTS rl (k TEXT PRIMARY KEY, n INTEGER, reset INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY, kind TEXT, title TEXT, body TEXT, chat INTEGER, ts INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS visits (vid TEXT PRIMARY KEY, site TEXT, page TEXT, ip TEXT, ts INTEGER, seen INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS subs (endpoint TEXT PRIMARY KEY, created INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS sess (sid TEXT PRIMARY KEY, vid TEXT, site TEXT, ip TEXT, started INTEGER, seen INTEGER, page TEXT, ref TEXT, gone INTEGER DEFAULT 0)");
  $db->exec("CREATE INDEX IF NOT EXISTS sess_live ON sess(seen)"); $db->exec("CREATE INDEX IF NOT EXISTS sess_ip ON sess(ip)");
  foreach (["win TEXT DEFAULT 'none'", "trail TEXT DEFAULT ''", "device TEXT DEFAULT ''"] as $col) { try { $db->exec("ALTER TABLE sess ADD COLUMN $col"); } catch (Exception $e) {} }
  $db->exec("CREATE TABLE IF NOT EXISTS vusers (vid TEXT PRIMARY KEY, user_id INTEGER, signed INTEGER, ts INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS contacts (phone TEXT PRIMARY KEY, name TEXT, place TEXT, ip TEXT, page TEXT, chat_id INTEGER, offers INTEGER DEFAULT 0, vid TEXT, first INTEGER, last INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS ipgeo (ip TEXT PRIMARY KEY, city TEXT, region TEXT, country TEXT, ts INTEGER)");
} catch (Exception $e) { out(false, ['error'=>'Chat is not available right now']); }

function q($s, $p = []) { global $db; $st = $db->prepare($s); $st->execute($p); return $st; }
function row($s, $p = []) { return q($s, $p)->fetch(PDO::FETCH_ASSOC); }
function rows($s, $p = []) { return q($s, $p)->fetchAll(PDO::FETCH_ASSOC); }
function ip() { return $_SERVER['REMOTE_ADDR'] ?? '?'; }
function in_($k, $def = '') { return $_POST[$k] ?? ($_GET[$k] ?? $def); }
function kv($k, $def = '') { $r = row("SELECT v FROM kv WHERE k=?", [$k]); return $r ? $r['v'] : $def; }
function setKv($k, $v) { q("INSERT INTO kv (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v", [$k, $v]); }
function limit($b, $max, $win) {
  $k = $b.':'.ip(); $r = row("SELECT n,reset FROM rl WHERE k=?", [$k]); $now = time();
  if (!$r || $r['reset'] < $now) { q("INSERT INTO rl (k,n,reset) VALUES (?,1,?) ON CONFLICT(k) DO UPDATE SET n=1, reset=excluded.reset", [$k, $now+$win]); return; }
  if ($r['n'] >= $max) out(false, ['error'=>'Too many messages. Please wait a minute.']);
  q("UPDATE rl SET n=n+1 WHERE k=?", [$k]);
}
function txt($s, $max) { $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$s)); return mb_substr($s, 0, $max); }
function agentName() { return kv('agent_name', 'Bharat GPS Support'); }
function add($chat, $who, $body) {   // who: v visitor, a agent, s note both see, i note only the agent sees
  global $db; q("INSERT INTO msgs (chat_id,who,body,ts) VALUES (?,?,?,?)", [$chat, $who, $body, time()]); $id = (int)$db->lastInsertId();
  if ($who !== 'i') q("UPDATE chats SET last=? WHERE id=?", [time(), $chat]); return $id; }
function msgsAfter($chat, $after, $agent = false) { return array_map(fn($m) => ['id'=>(int)$m['id'],'who'=>$m['who'],'body'=>$m['body'],'ts'=>(int)$m['ts']],
  rows("SELECT id,who,body,ts FROM msgs WHERE chat_id=? AND id>? ".($agent ? "" : "AND who!='i' ")."ORDER BY id LIMIT 200", [$chat, (int)$after])); }
function visitorChat() { $t = (string)in_('t'); $c = $t !== '' ? row("SELECT * FROM chats WHERE token=?", [$t]) : null; if (!$c) out(false, ['error'=>'Chat not found', 'gone'=>true]); return $c; }
function agentOnline() { return time() - (int)kv('agent_seen', '0') < 60; }
function event($kind, $title, $body, $chat = 0) {
  global $PUSH_AFTER, $db;
  q("INSERT INTO events (kind,title,body,chat,ts) VALUES (?,?,?,?,?)", [$kind, $title, mb_substr($body, 0, 160), $chat, time()]);
  q("DELETE FROM events WHERE ts < ?", [time() - 7*86400]);
  $PUSH_AFTER = true; return (int)$db->lastInsertId();
}
function pageName($url) {
  $p = parse_url((string)$url, PHP_URL_PATH) ?: '/'; $p = preg_replace('/(^|\/)index$/', '', preg_replace('/\.(html|php)$/', '', trim($p, '/')));
  if (preg_match('/^v\d$/', $p)) $p = '';   // design preview folders count as the home page
  $f = (string)parse_url((string)$url, PHP_URL_FRAGMENT); $sec = ['shop'=>'Products', 'features'=>'Features', 'how'=>'Installation', 'reviews'=>'Reviews', 'faq'=>'FAQ', 'screens'=>'App screenshots'];
  $name = $p === '' ? 'Home page' : ucfirst(str_replace('-', ' ', $p)).' page';
  return isset($sec[$f]) ? $name.' · '.$sec[$f] : $name;
}
function siteDb() {   // the website database (../api.php): the admin password and sign-in tokens live there
  static $s = null; if ($s) return $s;
  try { $s = new PDO('sqlite:'.LC_DIR.'/bgt.db'); $s->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $s->exec("PRAGMA busy_timeout=4000");
    $s->exec("CREATE TABLE IF NOT EXISTS tokens (t TEXT PRIMARY KEY, exp INTEGER)"); $s->exec("CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT)"); }
  catch (Exception $e) { out(false, ['error'=>'Chat is not available right now']); }
  return $s;
}
function siteQ($sql, $p = []) { $st = siteDb()->prepare($sql); $st->execute($p); return $st; }
function agentAuth() {
  $t = (string)($_SERVER['HTTP_X_AGENT_PASS'] ?? in_('p'));
  if (strlen($t) < 20 || !siteQ("SELECT 1 FROM tokens WHERE t=? AND exp>?", [$t, time()])->fetch()) out(false, ['error'=>'Please sign in again', 'auth'=>true]);
  setKv('agent_seen', (string)time());
}
function agentLogin($pass) {   // password -> token (the same kind the admin panel uses)
  $hash = (string)(siteQ("SELECT v FROM kv WHERE k='admin_hash'")->fetchColumn() ?: '');
  if ($hash === '') out(false, ['error'=>'Create the admin password first: open /admin/ on this website']);
  $f = row("SELECT n,reset FROM rl WHERE k=?", ['fail:'.ip()]);
  if ($f && $f['n'] >= 10 && $f['reset'] > time()) out(false, ['error'=>'Too many wrong passwords. Try again in 15 minutes.']);
  if (!password_verify((string)$pass, $hash)) {
    if ($f && $f['reset'] > time()) q("UPDATE rl SET n=n+1 WHERE k=?", ['fail:'.ip()]);
    else q("INSERT INTO rl (k,n,reset) VALUES (?,1,?) ON CONFLICT(k) DO UPDATE SET n=1, reset=excluded.reset", ['fail:'.ip(), time()+900]);
    out(false, ['error'=>'Wrong password']);
  }
  $t = bin2hex(random_bytes(24)); siteQ("INSERT INTO tokens (t,exp) VALUES (?,?)", [$t, time() + 30*86400]); return $t;
}
function addLead($name, $phone, $msg) {   // a chat contact also shows under Callback requests in the admin panel
  try { siteQ("CREATE TABLE IF NOT EXISTS leads (id INTEGER PRIMARY KEY, name TEXT, phone TEXT, vehicle TEXT, city TEXT, source TEXT, message TEXT DEFAULT '', status TEXT DEFAULT 'New', created INTEGER)");
    $g = row("SELECT city FROM ipgeo WHERE ip=?", [ip()]);
    siteQ("INSERT INTO leads (name,phone,vehicle,city,source,message,created) VALUES (?,?,?,?,?,?,?)", [$name, $phone, '', $g ? (string)$g['city'] : '', 'Chat', mb_substr($msg, 0, 300), time()]); } catch (Exception $e) {}
}
define('LIVE_SECS', 45);   // a visitor drops off the live list this long after the last sign of life (or at once on leaving)
function geo($ip) {   // approximate city from the IP, cached 30 days, from ScanPlay's own location server when geo_key is set
                      // in config.php (owner's server, so visitor IPs never go to an outside company). Never blocks > 2 s.
  global $cfg; $g = row("SELECT city,region,country,ts FROM ipgeo WHERE ip=?", [$ip]);
  if ($g && $g['ts'] > time() - 30*86400) return $g;
  $out = ['city'=>'', 'region'=>'', 'country'=>''];
  $key = (string)($cfg['geo_key'] ?? '');   // optional, in config.php; without it the desk shows the IP and "Unknown place"
  if ($key === '') return $out;
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && function_exists('curl_init')) {
    $h = curl_init('https://reco.scanplay.in/geo?ip='.rawurlencode($ip).'&k='.$key);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>2, CURLOPT_CONNECTTIMEOUT=>2]);
    $j = json_decode((string)curl_exec($h), true); curl_close($h);
    if (!empty($j['ok']) && ($j['city'] ?? '') !== '') $out = ['city'=>(string)$j['city'], 'region'=>(string)($j['region'] ?? ''), 'country'=>(string)($j['country'] ?? '')];
    else if ($g) return $g;   // keep the old answer when the lookup fails
    else if (empty($j['ok'])) return $out;   // server not answering: try again next time, nothing cached
  }
  q("INSERT INTO ipgeo (ip,city,region,country,ts) VALUES (?,?,?,?,?) ON CONFLICT(ip) DO UPDATE SET city=excluded.city, region=excluded.region, country=excluded.country, ts=excluded.ts",
    [$ip, $out['city'], $out['region'], $out['country'], time()]);
  return $out;
}
function place($g) { return trim(implode(', ', array_filter([$g['city'] ?? '', $g['region'] ?? ''])), ', ') ?: 'Unknown place'; }
function chatTitle($c, $g = null) { return $c['name'] !== '' && $c['name'] !== null ? $c['name'] : 'Visitor from '.(($g && ($g['city'] ?? '')) ? $g['city'] : 'website'); }
function vidIn() { $v = preg_replace('/[^a-z0-9]/', '', strtolower((string)in_('v'))); return strlen($v) >= 12 && strlen($v) <= 40 ? $v : ''; }
function device($ua) {
  $ua = (string)$ua;
  if (stripos($ua, 'iPad') !== false) return 'iPad';
  if (stripos($ua, 'iPhone') !== false) return 'iPhone';
  if (stripos($ua, 'Android') !== false) return stripos($ua, 'Mobile') !== false ? 'Android phone' : 'Android tablet';
  if (stripos($ua, 'Windows') !== false) return 'Windows computer';
  if (stripos($ua, 'Macintosh') !== false) return 'Mac';
  if (stripos($ua, 'Linux') !== false) return 'Linux computer';
  return $ua === '' ? '' : 'Other device';
}
function refHost($r) { $h = parse_url((string)$r, PHP_URL_HOST); return ($h && stripos($h, 'bharatgps') === false) ? preg_replace('/^www\./', '', $h) : ''; }
function liveSess($vid) {   // the visitor's most recent visit, and whether they are on the site right now
  $se = row("SELECT * FROM sess WHERE vid=? ORDER BY seen DESC LIMIT 1", [$vid]);
  return [$se, $se && !$se['gone'] && $se['seen'] > time() - LIVE_SECS];
}
function winOk($w) { return in_array($w, ['none', 'open', 'min'], true) ? $w : 'none'; }
define('QUICK_DEFAULT', json_encode([   // the agent edits these in Settings
  ['label'=>'Welcome', 'text'=>'Hi! I am {name} from Bharat GPS Tracker. How can I help you today?'],
  ['label'=>'Ask name and number', 'text'=>'Could you please share your name and mobile number, so we can help you better?'],
  ['label'=>'Which vehicle', 'text'=>'Which vehicle do you want to track? Bike, car, truck/bus or a fleet?'],
  ['label'=>'Prices', 'text'=>'Our trackers start at Rs 2,499 including GST, 1 year tracking and installation. You can see all prices and order at https://bharatgpstracker.com/#shop'],
  ['label'=>'Engine lock', 'text'=>'Yes, with Bharat GPS Pro you can switch the engine OFF and ON from the app if the vehicle is stolen or misused.'],
  ['label'=>'Installation', 'text'=>'Our own technician installs it at your home or office in about 30 minutes. Installation is free in our service cities.'],
  ['label'=>'App', 'text'=>'Download the Bharat GPS Tracker app: Android https://play.google.com/store/apps/details?id=com.bharatgpstrackerclient.android  iPhone https://apps.apple.com/in/app/bharat-gps/id1633014650'],
  ['label'=>'Call us', 'text'=>'You can call us on 984 984 9824 (Mon–Sat, 6 AM – 9 PM).'],
]));
define('CLOSING_DEFAULT', 'Thank you for chatting with Bharat GPS Tracker! If you need anything else, message us here or call 984 984 9824. Have a great day!');
function siteUser($where, $p) { return null; }   // the Bharat GPS website has no customer accounts (yet)
function account($vid) { return [null, false]; }
function evOut($e) { return ['id'=>(int)$e['id'],'kind'=>$e['kind'],'title'=>$e['title'],'body'=>$e['body'],'chat'=>(int)$e['chat'],'ts'=>(int)$e['ts']]; }

/* ---------- Web Push (no payload, so no message encryption is needed: the browser asks push_info what happened) ---------- */
function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function vapidKey() {
  $pem = kv('vapid_pem');
  if ($pem === '') {
    $k = openssl_pkey_new(['curve_name'=>'prime256v1', 'private_key_type'=>OPENSSL_KEYTYPE_EC]);
    if (!$k) return null; openssl_pkey_export($k, $pem); setKv('vapid_pem', $pem);
  }
  $k = openssl_pkey_get_private($pem); if (!$k) return null;
  $d = openssl_pkey_get_details($k)['ec'];
  return ['pem'=>$pem, 'pub'=>"\x04".str_pad($d['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['y'], 32, "\0", STR_PAD_LEFT)];
}
function derToRaw($der) {   // ECDSA signature: ASN.1 SEQUENCE{INTEGER r, INTEGER s} -> 64 raw bytes
  $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
  $parts = [];
  for ($i = 0; $i < 2; $i++) { $len = ord($der[$o+1]); $v = substr($der, $o+2, $len); $o += 2 + $len;
    $v = ltrim($v, "\0"); $parts[] = str_pad($v, 32, "\0", STR_PAD_LEFT); }
  return $parts[0].$parts[1];
}
function pushAll() {
  if (!function_exists('curl_multi_init')) return;
  $subs = rows("SELECT endpoint FROM subs"); if (!$subs) return;
  $v = vapidKey(); if (!$v) return;
  $mh = curl_multi_init(); $hs = [];
  foreach ($subs as $s) {
    $ep = $s['endpoint']; $u = parse_url($ep); if (empty($u['scheme']) || $u['scheme'] !== 'https' || empty($u['host'])) continue;
    $jwtIn = b64u(json_encode(['typ'=>'JWT','alg'=>'ES256'])).'.'.b64u(json_encode(['aud'=>'https://'.$u['host'], 'exp'=>time()+12*3600, 'sub'=>'mailto:sales@bharatgps.com']));
    if (!openssl_sign($jwtIn, $sig, $v['pem'], OPENSSL_ALGO_SHA256)) continue;
    $h = curl_init($ep);
    curl_setopt_array($h, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>'', CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8, CURLOPT_CONNECTTIMEOUT=>5,
      CURLOPT_HTTPHEADER=>['TTL: 3600', 'Urgency: high', 'Content-Length: 0', 'Authorization: vapid t='.$jwtIn.'.'.b64u(derToRaw($sig)).', k='.b64u($v['pub'])]]);
    curl_multi_add_handle($mh, $h); $hs[$ep] = $h;
  }
  do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
  foreach ($hs as $ep => $h) {
    $code = (int)curl_getinfo($h, CURLINFO_HTTP_CODE);
    if ($code === 404 || $code === 410) q("DELETE FROM subs WHERE endpoint=?", [$ep]);   // browser unsubscribed
    curl_multi_remove_handle($mh, $h); curl_close($h);
  }
  curl_multi_close($mh);
}

$a = in_('action');

/* ---------- visitor (widget.js) ----------
   v = this browser's visitor id (kept in localStorage, also the chat's secret token), s = this tab's visit id. */
if ($a === 'poll') {   // every few seconds while the page is open: keeps the visitor on the live list and brings agent messages
  $vid = vidIn(); $sid = preg_replace('/[^a-z0-9]/', '', strtolower((string)in_('s')));
  if ($vid === '' || strlen($sid) < 8) out(false, ['error'=>'Bad visitor']);
  if (preg_match('/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|phantom|puppeteer|playwright|selenium|python|curl|wget|java\/|go-http|okhttp|axios|node-fetch/i', $_SERVER['HTTP_USER_AGENT'] ?? '') || ($_SERVER['HTTP_USER_AGENT'] ?? '') === '')
    out(true, ['msgs'=>[], 'named'=>false, 'agent'=>null, 'online'=>false]);   // robots and website checkers: not recorded, not shown to the agent (owner, 5 Oct)
  $page = txt(in_('page'), 300); $win = winOk((string)in_('w'));
  $se = row("SELECT sid,trail FROM sess WHERE sid=?", [$sid]);
  $trail = $se ? (json_decode((string)$se['trail'], true) ?: []) : [];
  if (!$trail || end($trail)['p'] !== pageName($page)) { $trail[] = ['p'=>pageName($page), 't'=>time()]; $trail = array_slice($trail, -30); }
  if ($se) q("UPDATE sess SET seen=?, page=?, gone=0, win=?, trail=? WHERE sid=?", [time(), $page, $win, json_encode($trail), $sid]);
  else {
    $k = 'visit:'.ip(); $r = row("SELECT n,reset FROM rl WHERE k=?", [$k]); $fresh = !$r || $r['reset'] < time() || $r['n'] < 30;
    if ($r && $r['reset'] >= time() && $r['n'] >= 300) out(true, ['msgs'=>[], 'named'=>false, 'agent'=>null, 'online'=>agentOnline()]);   // a bot opening hundreds of visits: not recorded
    if (!$r || $r['reset'] < time()) q("INSERT INTO rl (k,n,reset) VALUES (?,1,?) ON CONFLICT(k) DO UPDATE SET n=1, reset=excluded.reset", [$k, time()+3600]);
    else q("UPDATE rl SET n=n+1 WHERE k=?", [$k]);
    q("INSERT INTO sess (sid,vid,site,ip,started,seen,page,ref,win,trail,device) VALUES (?,?,?,?,?,?,?,?,?,?,?)", [$sid, $vid, txt(in_('site', 'bharatgps'), 30), ip(), time(), time(), $page, txt(in_('ref'), 300), $win, json_encode($trail), device($_SERVER['HTTP_USER_AGENT'] ?? '')]);
    q("DELETE FROM sess WHERE seen < ?", [time() - 90*86400]);
    if ($fresh) {
      $g = geo(ip()); $n = (int)(row("SELECT COUNT(*) n FROM sess WHERE ip=?", [ip()])['n'] ?? 1);
      $ref = parse_url((string)in_('ref'), PHP_URL_HOST); $ref = ($ref && stripos($ref, 'bharatgps') === false) ? ' · from '.$ref : '';
      event('visit', 'New visitor: '.place($g), pageName($page).($n > 1 ? ' · visit '.$n.' from this IP' : '').$ref);
    }
  }
  if (isset($_POST['u'])) {   // the site's own sign-in on this browser (sent once per page): link the visitor to their account
    $tok = (string)$_POST['u']; $u = (strlen($tok) >= 16 && strlen($tok) <= 128) ? siteUser('token=?', [$tok]) : null;
    if ($u) q("INSERT INTO vusers (vid,user_id,signed,ts) VALUES (?,?,1,?) ON CONFLICT(vid) DO UPDATE SET user_id=excluded.user_id, signed=1, ts=excluded.ts", [$vid, (int)$u['id'], time()]);
    else q("UPDATE vusers SET signed=0 WHERE vid=?", [$vid]);
  }
  $c = row("SELECT * FROM chats WHERE token=?", [$vid]);
  if (!$c) out(true, ['msgs'=>[], 'named'=>false, 'agent'=>null, 'online'=>agentOnline()]);
  $m = msgsAfter($c['id'], in_('after', 0));
  if ($m) q("UPDATE chats SET visitor_seen=? WHERE id=?", [end($m)['id'], $c['id']]);
  out(true, ['msgs'=>$m, 'named'=>$c['phone'] !== '' && $c['phone'] !== null, 'agent'=>$c['joined'] ? agentName() : null, 'online'=>agentOnline()]);
}
if ($a === 'win') {   // the visitor opened or minimised the chat window - the agent sees it (a note only the agent sees)
  $vid = vidIn(); $sid = preg_replace('/[^a-z0-9]/', '', strtolower((string)in_('s'))); $w = winOk((string)in_('w'));
  if ($vid === '' || $sid === '') out(false, ['error'=>'Bad visitor']);
  $old = row("SELECT win FROM sess WHERE sid=?", [$sid]);
  q("UPDATE sess SET win=?, seen=?, gone=0 WHERE sid=?", [$w, time(), $sid]);
  $c = row("SELECT id FROM chats WHERE token=?", [$vid]);
  if ($c && $old && $old['win'] !== $w && $w !== 'none') add($c['id'], 'i', $w === 'open' ? 'Visitor opened the chat' : 'Visitor minimised the chat');
  out(true);
}
if ($a === 'leave') {   // sendBeacon when the tab closes or moves on; the next page of the site brings the visitor back
  $sid = preg_replace('/[^a-z0-9]/', '', strtolower((string)in_('s')));
  if ($sid !== '') q("UPDATE sess SET gone=1, seen=? WHERE sid=?", [time(), $sid]);
  out(true);
}
if ($a === 'send') {   // first message needs name + mobile (asked by the widget at that moment)
  $vid = vidIn(); if ($vid === '') out(false, ['error'=>'Bad visitor']);
  limit('send', 30, 60);
  $b = txt(in_('body'), 1000); if ($b === '') out(false, ['error'=>'Type a message']);
  $c = row("SELECT * FROM chats WHERE token=?", [$vid]);
  $named = $c && $c['phone'] !== '' && $c['phone'] !== null;
  if (!$named) {
    $name = txt(in_('name'), 60); $phone = preg_replace('/\D/', '', (string)in_('phone'));
    if (strlen($phone) === 12 && substr($phone, 0, 2) === '91') $phone = substr($phone, 2);
    if ($name === '' || !preg_match('/^[6-9]\d{9}$/', $phone)) out(false, ['error'=>$name === '' ? 'Enter your name' : 'Enter a 10-digit mobile number', 'needDetails'=>true]);
    if (!$c) {
      limit('start', 10, 3600);
      q("INSERT INTO chats (token,site,name,phone,page,ip,created,last) VALUES (?,?,?,?,?,?,?,?)", [$vid, txt(in_('site', 'bharatgps'), 30), $name, $phone, txt(in_('page'), 300), ip(), time(), time()]);
      $c = row("SELECT * FROM chats WHERE token=?", [$vid]);
    } else { q("UPDATE chats SET name=?, phone=? WHERE id=?", [$name, $phone, $c['id']]); $c['name'] = $name; $c['phone'] = $phone; }
    add($c['id'], 's', $name.' · +91 '.$phone);
    $g = row("SELECT city,region FROM ipgeo WHERE ip=?", [ip()]);
    q("INSERT INTO contacts (phone,name,place,ip,page,chat_id,offers,vid,first,last) VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT(phone) DO UPDATE SET name=excluded.name, place=excluded.place, chat_id=excluded.chat_id, offers=MAX(contacts.offers, excluded.offers), vid=excluded.vid, last=excluded.last",
      [$phone, $name, place($g ?: []), ip(), pageName(in_('page')), (int)$c['id'], in_('offers') === '1' ? 1 : 0, $vid, time(), time()]);
    event('chat', 'New chat: '.$name, '+91 '.$phone.' · '.$b, (int)$c['id']);
    addLead($name, $phone, $b);
  }
  if ($c['status'] === 'closed') q("UPDATE chats SET status='open' WHERE id=?", [$c['id']]);
  $id = add($c['id'], 'v', $b);
  if ($named) q("UPDATE contacts SET last=? WHERE chat_id=?", [time(), (int)$c['id']]);
  if ($named) event('msg', $c['name'], $b, (int)$c['id']);
  out(true, ['id'=>$id]);
}

/* ---------- agent ---------- */
if ($a === 'login') { if (in_('password') !== '') { $tok = agentLogin(in_('password')); $_SERVER['HTTP_X_AGENT_PASS'] = $tok; } agentAuth(); out(true, ['token'=>(string)$_SERVER['HTTP_X_AGENT_PASS'], 'name'=>agentName(), 'quick'=>json_decode(kv('quick', QUICK_DEFAULT), true), 'closing'=>kv('closing', CLOSING_DEFAULT)]); }
if ($a === 'contacts') {   // every name + mobile collected in chats, kept for promotions
  agentAuth();
  $list = rows("SELECT phone,name,place,page,chat_id,offers,vid,first,last FROM contacts ORDER BY last DESC LIMIT 5000");
  foreach ($list as &$r) { foreach (['chat_id','offers','first','last'] as $k) $r[$k] = (int)$r[$k]; [$ac] = account((string)$r['vid']); $r['registered'] = $ac ? 1 : 0; $r['email'] = $ac ? $ac['email'] : ''; unset($r['vid']); }
  unset($r); out(true, ['contacts'=>$list]);
}
if ($a === 'quick_set') {   // the agent's own quick replies (3 buttons above the reply box)
  agentAuth(); $in = json_decode((string)in_('quick'), true); $out = [];
  foreach ((array)$in as $x) { $l = txt($x['label'] ?? '', 40); $t = txt($x['text'] ?? '', 1000); if ($l !== '' && $t !== '') $out[] = ['label'=>$l, 'text'=>$t]; }
  if (!$out) out(false, ['error'=>'Add at least one quick reply']);
  $cl = txt(in_('closing'), 1000); if ($cl === '') out(false, ['error'=>'Write the closing message']);
  setKv('quick', json_encode(array_slice($out, 0, 12))); setKv('closing', $cl); out(true, ['quick'=>array_slice($out, 0, 12), 'closing'=>$cl]);
}
if ($a === 'chats') {
  agentAuth();
  $list = rows("SELECT c.id,c.site,c.name,c.phone,c.page,c.created,c.last,c.status,c.agent_read,
      (SELECT body FROM msgs m WHERE m.chat_id=c.id AND m.who!='s' ORDER BY id DESC LIMIT 1) lastmsg,
      (SELECT who FROM msgs m WHERE m.chat_id=c.id AND m.who!='s' ORDER BY id DESC LIMIT 1) lastwho,
      (SELECT COUNT(*) FROM msgs m WHERE m.chat_id=c.id AND m.who='v' AND m.id>c.agent_read) unread
    FROM chats c WHERE c.last > ? ORDER BY c.last DESC LIMIT 100", [time() - 30*86400]);
  foreach ($list as &$r) { foreach (['id','created','last','agent_read','unread'] as $k) $r[$k] = (int)$r[$k];
    $cc = row("SELECT ip,token FROM chats WHERE id=?", [$r['id']]);
    $g = row("SELECT city,region FROM ipgeo WHERE ip=?", [(string)$cc['ip']]); $r['title'] = chatTitle($r, $g); $r['place'] = place($g ?: []);
    [$ac, $sg] = account($cc['token']); $r['acct'] = $ac ? ($sg ? 'signed' : 'reg') : '';
    [$se, $on] = liveSess($cc['token']); $r['online'] = $on ? 1 : 0; $r['win'] = $on ? $se['win'] : ''; $r['nowPage'] = $on ? pageName($se['page']) : ''; }
  unset($r);
  $ev = row("SELECT MAX(id) x FROM events")['x'] ?? 0;
  $live = rows("SELECT s.sid, s.vid, s.ip, s.started, s.seen, s.page, s.ref, s.win, s.device, g.city, g.region, g.country,
      (SELECT COUNT(*) FROM sess x WHERE x.ip=s.ip) visits, (SELECT id FROM chats c WHERE c.token=s.vid) chat,
      (SELECT name FROM chats c WHERE c.token=s.vid) name
    FROM sess s LEFT JOIN ipgeo g ON g.ip=s.ip WHERE s.gone=0 AND s.seen > ? ORDER BY s.started DESC LIMIT 200", [time() - LIVE_SECS]);
  foreach ($live as &$v) { $v['place'] = place($v); $v['pageName'] = pageName($v['page']); $v['secs'] = max(0, (int)$v['seen'] - (int)$v['started']); $v['from'] = refHost($v['ref']); unset($v['ref']);
    [$ac, $sg] = account($v['vid']); $v['acct'] = $ac ? ($sg ? 'signed' : 'reg') : ''; if ($ac && !$v['name']) $v['name'] = $ac['name'];
    foreach (['started','seen','visits','chat'] as $k) $v[$k] = (int)$v[$k]; unset($v['sid']); }
  unset($v);
  out(true, ['chats'=>$list, 'name'=>agentName(), 'event'=>(int)$ev, 'visitors'=>count($live), 'live'=>$live, 'now'=>time()]);
}
if ($a === 'events') {   // agent panel: what happened since its last look (to ring for new visitors too)
  agentAuth();
  out(true, ['events'=>array_map('evOut', rows("SELECT * FROM events WHERE id > ? ORDER BY id DESC LIMIT 10", [(int)in_('since', 0)]))]);
}
if ($a === 'msgs') {
  agentAuth(); $c = row("SELECT * FROM chats WHERE id=?", [(int)in_('id', 0)]); if (!$c) out(false, ['error'=>'Chat not found']);
  if (!$c['joined']) { q("UPDATE chats SET joined=1 WHERE id=?", [$c['id']]); add($c['id'], 's', agentName().' joined the chat'); }
  $m = msgsAfter($c['id'], in_('after', 0), true);
  $lastV = row("SELECT MAX(id) x FROM msgs WHERE chat_id=? AND who='v'", [$c['id']])['x'] ?? 0;
  q("UPDATE chats SET agent_read=? WHERE id=?", [(int)$lastV, $c['id']]);
  $g = row("SELECT city,region,country FROM ipgeo WHERE ip=?", [(string)$c['ip']]);
  [$se, $on] = liveSess($c['token']);
  out(true, ['msgs'=>$m, 'chat'=>['id'=>(int)$c['id'],'title'=>chatTitle($c, $g),'name'=>$c['name'],'phone'=>$c['phone'],'page'=>$c['page'],'site'=>$c['site'],'status'=>$c['status'],'created'=>(int)$c['created'],'seen'=>(int)$c['visitor_seen'],
    'ip'=>$c['ip'],'place'=>place($g ?: []),'online'=>$on ? 1 : 0,'nowPage'=>$on ? pageName($se['page']) : '','secs'=>$on ? (int)$se['seen'] - (int)$se['started'] : 0,
    'visits'=>(int)(row("SELECT COUNT(*) n FROM sess WHERE ip=?", [(string)$c['ip']])['n'] ?? 0),
    'win'=>$on ? $se['win'] : '', 'from'=>$se ? refHost($se['ref']) : '', 'device'=>$se ? (string)$se['device'] : '',
    'trail'=>$se ? (json_decode((string)$se['trail'], true) ?: []) : [], 'lastSeen'=>$se ? (int)$se['seen'] : 0,
    'account'=>($acc = account($c['token']))[0], 'signedIn'=>$acc[1] ? 1 : 0]]);
}
if ($a === 'start') {   // agent opens a chat with a live visitor (who has not written yet) with a welcome message
  agentAuth(); $vid = vidIn(); if ($vid === '') out(false, ['error'=>'Visitor not found']);
  $se = row("SELECT * FROM sess WHERE vid=? ORDER BY seen DESC LIMIT 1", [$vid]); if (!$se) out(false, ['error'=>'Visitor not found']);
  $c = row("SELECT * FROM chats WHERE token=?", [$vid]);
  if (!$c) {
    q("INSERT INTO chats (token,site,name,phone,page,ip,created,last,joined) VALUES (?,?,?,?,?,?,?,?,1)", [$vid, $se['site'], '', '', $se['page'], $se['ip'], time(), time()]);
    $c = row("SELECT * FROM chats WHERE token=?", [$vid]);
    $q = json_decode(kv('quick', QUICK_DEFAULT), true);
    add($c['id'], 'a', txt(in_('body'), 2000) ?: str_replace('{name}', agentName(), $q[0]['text'] ?? 'Hi! How can I help you today?'));
  }
  out(true, ['id'=>(int)$c['id']]);
}
if ($a === 'reply') {
  agentAuth(); $c = row("SELECT * FROM chats WHERE id=?", [(int)in_('id', 0)]); if (!$c) out(false, ['error'=>'Chat not found']);
  $b = txt(in_('body'), 2000); if ($b === '') out(false, ['error'=>'Type a message']);
  if (!$c['joined']) { q("UPDATE chats SET joined=1 WHERE id=?", [$c['id']]); add($c['id'], 's', agentName().' joined the chat'); }
  q("UPDATE chats SET status='open' WHERE id=?", [$c['id']]);
  out(true, ['id'=>add($c['id'], 'a', $b)]);
}
if ($a === 'close') {
  agentAuth(); $id = (int)in_('id', 0);
  if (!row("SELECT 1 FROM chats WHERE id=? AND status!='closed'", [$id])) out(true);
  add($id, 'a', str_replace('{name}', agentName(), kv('closing', CLOSING_DEFAULT)));   // the closing message, then the chat ends
  q("UPDATE chats SET status='closed' WHERE id=?", [$id]); add($id, 's', 'Chat ended. Send a message anytime to start again.');
  out(true);
}
if ($a === 'setname') { agentAuth(); $n = txt(in_('name'), 30); if ($n === '') out(false, ['error'=>'Enter a name']); setKv('agent_name', $n); out(true, ['name'=>$n]); }
if ($a === 'pending') {   // the Android app asks this every few seconds; `since` = last event it has rung for
  agentAuth();
  $since = (int)in_('since', -1);
  $last = (int)(row("SELECT MAX(id) x FROM events")['x'] ?? 0);
  $ev = $since < 0 ? [] : array_map('evOut', rows("SELECT * FROM events WHERE id > ? ORDER BY id DESC LIMIT 10", [$since]));
  $n = (int)(row("SELECT COUNT(*) n FROM msgs m JOIN chats c ON c.id=m.chat_id WHERE m.who='v' AND m.id>c.agent_read")['n'] ?? 0);
  out(true, ['unread'=>$n, 'last'=>$last, 'events'=>$ev]);
}
if ($a === 'push_key') { agentAuth(); $v = vapidKey(); if (!$v) out(false, ['error'=>'Push is not available on this server']); out(true, ['key'=>b64u($v['pub'])]); }
if ($a === 'push_sub') {
  agentAuth(); $ep = (string)in_('endpoint');
  if (!preg_match('#^https://[^\s]{10,800}$#', $ep)) out(false, ['error'=>'Bad subscription']);
  q("INSERT OR IGNORE INTO subs (endpoint,created) VALUES (?,?)", [$ep, time()]); out(true);
}
if ($a === 'push_info') {   // asked by sw.js when a push arrives; knowing a subscribed endpoint is the permission
  $ep = (string)in_('endpoint');
  if ($ep === '' || !row("SELECT 1 FROM subs WHERE endpoint=?", [$ep])) out(false, ['error'=>'Not subscribed']);
  $e = row("SELECT * FROM events ORDER BY id DESC LIMIT 1");
  out(true, ['event'=>$e ? evOut($e) : null]);
}
out(false, ['error'=>'Unknown action']);
