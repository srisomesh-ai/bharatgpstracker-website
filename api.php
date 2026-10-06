<?php
/* Bharat GPS Tracker website API (Oct 2026).
   One SQLite file (data/bgt.db, never served: .htaccess blocks data/). Public actions feed the website (store items,
   content, FAQs, reviews) and take orders and callback requests; admin actions need a sign-in token from `login`.
   Server secrets (Razorpay keys) live only in config.php, which is git-ignored. Always HTTP 200 with {ok:...}. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-LiteSpeed-Cache-Control: no-cache');
date_default_timezone_set('Asia/Kolkata');

$CFG = is_file(__DIR__.'/config.php') ? (include __DIR__.'/config.php') : [];
if (!is_array($CFG)) $CFG = [];
define('DATA', __DIR__.'/data');
define('UPLOADS', __DIR__.'/uploads/products');
if (!is_dir(DATA)) @mkdir(DATA, 0755, true);

function out($ok, $d = []) { echo json_encode(['ok'=>$ok] + $d, JSON_UNESCAPED_UNICODE); exit; }
function in_($k, $def = '') { return $_POST[$k] ?? ($_GET[$k] ?? $def); }
function txt($s, $max) { $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$s)); return mb_substr($s, 0, $max); }
function ip() { return $_SERVER['REMOTE_ADDR'] ?? '?'; }

try {
  $db = new PDO('sqlite:'.DATA.'/bgt.db');
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=4000;");
  $db->exec("CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT)");
  $db->exec("CREATE TABLE IF NOT EXISTS tokens (t TEXT PRIMARY KEY, exp INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS rl (k TEXT PRIMARY KEY, n INTEGER, reset INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY, name TEXT, cat TEXT, icon TEXT, price INTEGER, mrp INTEGER DEFAULT 0,
      renewal INTEGER DEFAULT 0, features TEXT, descr TEXT, image TEXT DEFAULT '', stock TEXT DEFAULT 'In stock', visible INTEGER DEFAULT 1,
      popular INTEGER DEFAULT 0, free_install INTEGER DEFAULT 1, sort INTEGER DEFAULT 0, created INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY, code TEXT UNIQUE, product_id INTEGER, product_name TEXT, qty INTEGER,
      amount INTEGER, name TEXT, phone TEXT, vehicle TEXT, city TEXT, address TEXT, pay_method TEXT, pay_status TEXT DEFAULT 'pending',
      rzp_order TEXT DEFAULT '', rzp_payment TEXT DEFAULT '', status TEXT DEFAULT 'New', note TEXT DEFAULT '', created INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS leads (id INTEGER PRIMARY KEY, name TEXT, phone TEXT, vehicle TEXT, city TEXT, source TEXT,
      message TEXT DEFAULT '', status TEXT DEFAULT 'New', created INTEGER)");
  $db->exec("CREATE TABLE IF NOT EXISTS faqs (id INTEGER PRIMARY KEY, q TEXT, a TEXT, sort INTEGER DEFAULT 0, visible INTEGER DEFAULT 1)");
  $db->exec("CREATE TABLE IF NOT EXISTS reviews (id INTEGER PRIMARY KEY, name TEXT, place TEXT, body TEXT, stars INTEGER DEFAULT 5,
      sort INTEGER DEFAULT 0, visible INTEGER DEFAULT 1)");
} catch (Exception $e) { out(false, ['error'=>'The website data is not available right now']); }

function q($s, $p = []) { global $db; $st = $db->prepare($s); $st->execute($p); return $st; }
function row($s, $p = []) { return q($s, $p)->fetch(PDO::FETCH_ASSOC); }
function rows($s, $p = []) { return q($s, $p)->fetchAll(PDO::FETCH_ASSOC); }
function kv($k, $def = '') { $r = row("SELECT v FROM kv WHERE k=?", [$k]); return $r ? $r['v'] : $def; }
function setKv($k, $v) { q("INSERT INTO kv (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v", [$k, $v]); }
function limit($b, $max, $win) {
  $k = $b.':'.ip(); $r = row("SELECT n,reset FROM rl WHERE k=?", [$k]); $now = time();
  if (!$r || $r['reset'] < $now) { q("INSERT INTO rl (k,n,reset) VALUES (?,1,?) ON CONFLICT(k) DO UPDATE SET n=1, reset=excluded.reset", [$k, $now+$win]); return; }
  if ($r['n'] >= $max) out(false, ['error'=>'Too many requests. Please wait a few minutes and try again.']);
  q("UPDATE rl SET n=n+1 WHERE k=?", [$k]);
}
function phoneIn($p) { $p = preg_replace('/\D/', '', (string)$p); if (strlen($p) === 12 && substr($p, 0, 2) === '91') $p = substr($p, 2); return preg_match('/^[6-9]\d{9}$/', $p) ? $p : ''; }

/* ---------- first-run defaults: the details already on bharatgpstracker.com ---------- */
define('CONTENT_DEFAULT', [
  'offer_on'=>1, 'offer_text'=>'Festive offer: Free installation + ₹500 off on all trackers this month',
  'hero_title'=>'Protect your vehicle with Bharat GPS',
  'hero_text'=>'Live GPS tracking, engine ON/OFF from your phone and instant theft alerts, installed at your home by our own technicians across Andhra Pradesh & Telangana.',
  'stat_vehicles'=>'10,000+', 'stat_since'=>'2017', 'stat_rating'=>'4.7', 'stat_install'=>'30 min',
  'phone1'=>'984 984 9824', 'phone2'=>'844 844 8929', 'whatsapp'=>'919849849824',
  'email_sales'=>'sales@bharatgps.com', 'email_admin'=>'admin@bharatgps.com',
  'address'=>'301, 3rd Floor, SL Ajay Arcade, Sheela Nagar, Visakhapatnam',
  'hours_call'=>'Mon–Sat, 6 AM – 9 PM', 'hours_office'=>'Mon–Sat, 9:30 AM – 6:30 PM',
  'cities'=>"Visakhapatnam\nVijayawada\nHyderabad\nGuntur\nRajahmundry\nKakinada\nNellore\nTirupati\nWarangal\nSrikakulam\nVizianagaram\nAnakapalli",
  'play_url'=>'https://play.google.com/store/apps/details?id=com.bharatgpstrackerclient.android',
  'appstore_url'=>'https://apps.apple.com/in/app/bharat-gps/id1633014650',
  'facebook'=>'https://www.facebook.com/bharatgpstracker/', 'instagram'=>'https://www.instagram.com/bharatgpstracker/',
  'login_url'=>'https://bharatgps.com',
]);
define('SETTINGS_DEFAULT', ['pay_online'=>1, 'pay_install'=>1, 'alert_email'=>'sales@bharatgps.com']);
function content() { $c = json_decode(kv('content', '{}'), true) ?: []; return array_merge(CONTENT_DEFAULT, array_intersect_key($c, CONTENT_DEFAULT)); }
function settings() { $s = json_decode(kv('settings', '{}'), true) ?: []; return array_merge(SETTINGS_DEFAULT, array_intersect_key($s, SETTINGS_DEFAULT)); }
if (kv('seeded') === '') {
  $t = time();
  $seed = [
    ['Bharat GPS Lite','Two-wheeler','bike',2499,2999,999,"Live tracking & route history\nIgnition & power-cut alerts\nAndroid & iPhone app",'For bikes & scooters',0],
    ['Bharat GPS Pro','Car','car',3999,4499,1499,"Everything in Lite\nEngine ON/OFF from app\nGeo-fence & overspeed alerts\nFree doorstep installation",'For cars & commercial vehicles',1],
    ['Bharat GPS Fleet','Commercial / AIS-140','truck',5999,6499,1999,"Everything in Pro\nTrip, km & stoppage reports\nFleet dashboard & account manager",'For trucks, buses & fleets',0],
  ];
  foreach ($seed as $i => $p) q("INSERT INTO products (name,cat,icon,price,mrp,renewal,features,descr,popular,sort,created) VALUES (?,?,?,?,?,?,?,?,?,?,?)", array_merge($p, [$i, $t]));
  foreach ([['Is installation free?','Yes, installation at your location is free in our service cities.'],
    ['What is the renewal charge?','After one year you renew the tracking plan at a fixed yearly price. The device stays yours.'],
    ['Does it drain the battery?','No. It uses very little power and sleeps when the vehicle is parked.'],
    ['Can I track multiple vehicles?','Yes, all your vehicles show in one app with one login.'],
    ['Which payment methods are accepted?','UPI, debit/credit cards, net banking and wallets. You can also pay the technician at installation.'],
    ['Do you provide GST invoice?','Yes, a GST invoice is provided for every order.']] as $i => $f) q("INSERT INTO faqs (q,a,sort) VALUES (?,?,?)", [$f[0], $f[1], $i]);
  foreach ([['Ravi Kumar','Visakhapatnam · Car owner','Got an ignition alert late at night and locked the engine from the app. Vehicle recovered the same night.'],
    ['Srinivas Transports','Vijayawada · Fleet owner','We track 22 lorries on one screen. The daily reports have made our operations far more efficient.'],
    ['Lakshmi P.','Rajahmundry · Scooter owner','Technician came home, installed in 30 minutes and explained the app in Telugu. Very good service.']] as $i => $r)
    q("INSERT INTO reviews (name,place,body,sort) VALUES (?,?,?,?)", [$r[0], $r[1], $r[2], $i]);
  setKv('seeded', (string)$t);
}

/* ---------- helpers ---------- */
function prodOut($p) {
  return ['id'=>(int)$p['id'], 'name'=>$p['name'], 'cat'=>$p['cat'], 'icon'=>$p['icon'], 'price'=>(int)$p['price'], 'mrp'=>(int)$p['mrp'],
    'renewal'=>(int)$p['renewal'], 'features'=>array_values(array_filter(array_map('trim', explode("\n", (string)$p['features'])))),
    'descr'=>(string)$p['descr'], 'image'=>(string)$p['image'], 'stock'=>$p['stock'], 'visible'=>(int)$p['visible'], 'popular'=>(int)$p['popular'],
    'free_install'=>(int)$p['free_install'], 'sort'=>(int)$p['sort']];
}
function rzpReady() { global $CFG; return !empty($CFG['rzp_key']) && !empty($CFG['rzp_secret']) && function_exists('curl_init'); }
function payOptions() { $s = settings(); return ['online'=>$s['pay_online'] && rzpReady() ? 1 : 0, 'install'=>$s['pay_install'] ? 1 : 0]; }
function alertMail($subject, $body) {   // a short e-mail to the alert address for every new order / callback request
  $to = settings()['alert_email']; if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
  $host = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'bharatgpstracker.com');
  @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $body."\n\nOpen the admin panel: https://".$host."/admin/",
    "From: Bharat GPS Website <no-reply@".$host.">\r\nContent-Type: text/plain; charset=UTF-8");
}
function rzp($path, $body) {
  global $CFG; $h = curl_init('https://api.razorpay.com/v1/'.$path);
  curl_setopt_array($h, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($body), CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15,
    CURLOPT_USERPWD=>$CFG['rzp_key'].':'.$CFG['rzp_secret'], CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
  $r = json_decode((string)curl_exec($h), true); curl_close($h); return is_array($r) ? $r : [];
}

/* ---------- admin sign-in (one password, hashed; set on first visit to /admin/) ---------- */
function token() { return (string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? in_('token')); }
function newToken() { $t = bin2hex(random_bytes(24)); q("INSERT INTO tokens (t,exp) VALUES (?,?)", [$t, time() + 30*86400]); q("DELETE FROM tokens WHERE exp < ?", [time()]); return $t; }
function adminAuth() {
  $t = token();
  if (strlen($t) < 20 || !row("SELECT 1 FROM tokens WHERE t=? AND exp>?", [$t, time()])) out(false, ['error'=>'Please sign in again', 'auth'=>true]);
}
function checkPass($p) {
  $f = row("SELECT n,reset FROM rl WHERE k=?", ['fail:'.ip()]);
  if ($f && $f['n'] >= 10 && $f['reset'] > time()) out(false, ['error'=>'Too many wrong passwords. Try again in 15 minutes.']);
  if (!password_verify((string)$p, kv('admin_hash'))) {
    if ($f && $f['reset'] > time()) q("UPDATE rl SET n=n+1 WHERE k=?", ['fail:'.ip()]);
    else q("INSERT INTO rl (k,n,reset) VALUES (?,1,?) ON CONFLICT(k) DO UPDATE SET n=1, reset=excluded.reset", ['fail:'.ip(), time()+900]);
    out(false, ['error'=>'Wrong password']);
  }
}

$a = (string)in_('action');

/* ---------- public: the website ---------- */
if ($a === 'site') {
  out(true, ['content'=>content(), 'pay'=>payOptions(),
    'products'=>array_map('prodOut', rows("SELECT * FROM products WHERE visible=1 ORDER BY sort, id")),
    'faqs'=>rows("SELECT q,a FROM faqs WHERE visible=1 ORDER BY sort, id"),
    'reviews'=>array_map(fn($r) => ['name'=>$r['name'], 'place'=>$r['place'], 'body'=>$r['body'], 'stars'=>(int)$r['stars']], rows("SELECT * FROM reviews WHERE visible=1 ORDER BY sort, id"))]);
}
if ($a === 'lead') {   // "Get a free callback" form (and other callback buttons)
  limit('lead', 6, 3600);
  $name = txt(in_('name'), 60); $phone = phoneIn(in_('phone'));
  if ($name === '') out(false, ['error'=>'Enter your name']);
  if ($phone === '') out(false, ['error'=>'Enter a 10-digit mobile number']);
  $vehicle = txt(in_('vehicle'), 30); $city = txt(in_('city'), 40);
  q("INSERT INTO leads (name,phone,vehicle,city,source,message,created) VALUES (?,?,?,?,?,?,?)", [$name, $phone, $vehicle, $city, txt(in_('source', 'Website form'), 40), txt(in_('message'), 500), time()]);
  alertMail('New callback request: '.$name, "$name\n+91 $phone\nVehicle: $vehicle\nCity: $city");
  out(true);
}
if ($a === 'order') {   // Buy Now: create the order; online payment returns the Razorpay order to open checkout
  limit('order', 8, 3600);
  $p = row("SELECT * FROM products WHERE id=? AND visible=1", [(int)in_('product')]);
  if (!$p) out(false, ['error'=>'This item is no longer available. Please refresh the page.']);
  if ($p['stock'] === 'Out of stock') out(false, ['error'=>'This item is out of stock. Request a callback and we will help you.']);
  $qty = max(1, min(50, (int)in_('qty', 1)));
  $name = txt(in_('name'), 60); $phone = phoneIn(in_('phone')); $vehicle = strtoupper(txt(in_('vehicle'), 40)); $city = txt(in_('city'), 40); $addr = txt(in_('address'), 300);
  if ($name === '') out(false, ['error'=>'Enter your name']);
  if ($phone === '') out(false, ['error'=>'Enter a 10-digit mobile number']);
  if ($city === '') out(false, ['error'=>'Choose your city']);
  if ($addr === '') out(false, ['error'=>'Enter the installation address']);
  $method = in_('pay') === 'online' ? 'online' : 'install'; $po = payOptions();
  if (!$po[$method]) out(false, ['error'=>$method === 'online' ? 'Online payment is not available right now. Choose pay on installation.' : 'Please pay online to book.']);
  $amount = (int)$p['price'] * $qty;
  $code = 'BGT-'.(1000 + (int)(row("SELECT COALESCE(MAX(id),0) n FROM orders")['n']) + 1);
  q("INSERT INTO orders (code,product_id,product_name,qty,amount,name,phone,vehicle,city,address,pay_method,created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
    [$code, (int)$p['id'], $p['name'], $qty, $amount, $name, $phone, $vehicle, $city, $addr, $method, time()]);
  if ($method === 'install') {
    alertMail("New booking $code: ".$p['name'], "$name\n+91 $phone\n{$p['name']} × $qty = ₹$amount (pay on installation)\nVehicle: $vehicle\n$addr, $city");
    out(true, ['code'=>$code, 'amount'=>$amount]);
  }
  $r = rzp('orders', ['amount'=>$amount * 100, 'currency'=>'INR', 'receipt'=>$code, 'notes'=>['order'=>$code, 'phone'=>$phone]]);
  if (empty($r['id'])) { q("UPDATE orders SET pay_status='failed', note='Could not start online payment' WHERE code=?", [$code]); out(false, ['error'=>'Online payment could not start. Try again or choose pay on installation.']); }
  q("UPDATE orders SET rzp_order=? WHERE code=?", [$r['id'], $code]);
  global $CFG;
  out(true, ['code'=>$code, 'amount'=>$amount, 'rzp'=>['key'=>$CFG['rzp_key'], 'order_id'=>$r['id'], 'amount'=>$amount * 100, 'name'=>$name, 'phone'=>$phone, 'item'=>$p['name']]]);
}
if ($a === 'paid') {   // Razorpay checkout success: verify the signature on the server before marking the order paid
  global $CFG; if (!rzpReady()) out(false, ['error'=>'Online payment is not set up']);
  $o = row("SELECT * FROM orders WHERE code=?", [txt(in_('code'), 20)]); if (!$o || $o['rzp_order'] === '') out(false, ['error'=>'Order not found']);
  $pid = txt(in_('payment_id'), 60); $sig = (string)in_('signature');
  if (!hash_equals(hash_hmac('sha256', $o['rzp_order'].'|'.$pid, $CFG['rzp_secret']), $sig)) out(false, ['error'=>'Payment could not be verified. If money was taken, call us and we will sort it out.']);
  if ($o['pay_status'] !== 'paid') {
    q("UPDATE orders SET pay_status='paid', rzp_payment=? WHERE id=?", [$pid, $o['id']]);
    alertMail("New paid order {$o['code']}: ".$o['product_name'], "{$o['name']}\n+91 {$o['phone']}\n{$o['product_name']} × {$o['qty']} = ₹{$o['amount']} (paid online)\nVehicle: {$o['vehicle']}\n{$o['address']}, {$o['city']}");
  }
  out(true, ['code'=>$o['code']]);
}

/* ---------- admin ---------- */
if ($a === 'status') out(true, ['setup'=>kv('admin_hash') === '' ? 1 : 0]);
if ($a === 'setup') {   // first visit only: choose the admin password
  if (kv('admin_hash') !== '') out(false, ['error'=>'The password is already set. Sign in instead.']);
  $p = (string)in_('password'); if (strlen($p) < 8) out(false, ['error'=>'Use at least 8 characters']);
  setKv('admin_hash', password_hash($p, PASSWORD_DEFAULT)); out(true, ['token'=>newToken()]);
}
if ($a === 'login') { checkPass(in_('password')); out(true, ['token'=>newToken()]); }
if ($a === 'logout') { q("DELETE FROM tokens WHERE t=?", [token()]); out(true); }

adminAuth();   // everything below needs a signed-in admin

if ($a === 'dash') {
  $day = strtotime('today');
  $today = row("SELECT COUNT(*) n, COALESCE(SUM(amount),0) s FROM orders WHERE created>=? AND (pay_method='install' OR pay_status='paid')", [$day]);
  $live = 0; $lf = DATA.'/livechat.db';
  if (is_file($lf)) { try { $l = new PDO('sqlite:'.$lf); $live = (int)$l->query("SELECT COUNT(*) FROM sess WHERE gone=0 AND seen>".(time()-45))->fetchColumn(); } catch (Exception $e) {} }
  out(true, ['orders_today'=>(int)$today['n'], 'sales_today'=>(int)$today['s'],
    'new_orders'=>(int)row("SELECT COUNT(*) n FROM orders WHERE status='New' AND (pay_method='install' OR pay_status='paid')")['n'],
    'new_leads'=>(int)row("SELECT COUNT(*) n FROM leads WHERE status='New'")['n'],
    'leads_today'=>(int)row("SELECT COUNT(*) n FROM leads WHERE created>=?", [$day])['n'],
    'live'=>$live, 'products_live'=>(int)row("SELECT COUNT(*) n FROM products WHERE visible=1")['n'], 'products_all'=>(int)row("SELECT COUNT(*) n FROM products")['n'],
    'orders'=>rows("SELECT * FROM orders WHERE pay_method='install' OR pay_status='paid' ORDER BY id DESC LIMIT 5"),
    'leads'=>rows("SELECT * FROM leads WHERE status='New' ORDER BY id DESC LIMIT 5"), 'pay'=>payOptions(), 'rzp'=>rzpReady() ? 1 : 0]);
}
if ($a === 'products') out(true, ['products'=>array_map('prodOut', rows("SELECT * FROM products ORDER BY sort, id"))]);
if ($a === 'product_save') {
  $id = (int)in_('id'); $name = txt(in_('name'), 80); if ($name === '') out(false, ['error'=>'Enter the item name']);
  $price = (int)in_('price'); if ($price <= 0) out(false, ['error'=>'Enter the selling price']);
  $mrp = max(0, (int)in_('mrp')); if ($mrp && $mrp <= $price) out(false, ['error'=>'MRP must be more than the selling price, or leave it empty']);
  $stock = in_array(in_('stock'), ['In stock', 'Made to order', 'Out of stock'], true) ? in_('stock') : 'In stock';
  $icon = in_array(in_('icon'), ['bike', 'car', 'truck', 'bus', 'person'], true) ? in_('icon') : 'car';
  $f = [$name, txt(in_('cat'), 40), $icon, $price, $mrp, max(0, (int)in_('renewal')), txt(in_('features'), 2000), txt(in_('descr'), 300), $stock,
    in_('visible') === '1' ? 1 : 0, in_('popular') === '1' ? 1 : 0, in_('free_install') === '1' ? 1 : 0];
  if (in_('popular') === '1') q("UPDATE products SET popular=0");   // one "Most popular" at a time
  if ($id) q("UPDATE products SET name=?,cat=?,icon=?,price=?,mrp=?,renewal=?,features=?,descr=?,stock=?,visible=?,popular=?,free_install=? WHERE id=?", array_merge($f, [$id]));
  else { q("INSERT INTO products (name,cat,icon,price,mrp,renewal,features,descr,stock,visible,popular,free_install,sort,created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
    array_merge($f, [(int)(row("SELECT COALESCE(MAX(sort),0)+1 n FROM products")['n']), time()])); $id = (int)$db->lastInsertId(); }
  out(true, ['id'=>$id]);
}
if ($a === 'product_toggle') { q("UPDATE products SET visible=? WHERE id=?", [in_('visible') === '1' ? 1 : 0, (int)in_('id')]); out(true); }
if ($a === 'product_move') {   // reorder: up / down by one place
  $list = rows("SELECT id FROM products ORDER BY sort, id"); $ids = array_map(fn($r) => (int)$r['id'], $list);
  $i = array_search((int)in_('id'), $ids, true); $j = in_('dir') === 'up' ? $i - 1 : $i + 1;
  if ($i !== false && $j >= 0 && $j < count($ids)) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; foreach ($ids as $k => $pid) q("UPDATE products SET sort=? WHERE id=?", [$k, $pid]); }
  out(true);
}
if ($a === 'product_delete') {
  $p = row("SELECT image FROM products WHERE id=?", [(int)in_('id')]);
  if ($p && $p['image'] && is_file(__DIR__.'/'.$p['image'])) @unlink(__DIR__.'/'.$p['image']);
  q("DELETE FROM products WHERE id=?", [(int)in_('id')]); out(true);
}
if ($a === 'product_image') {   // product photo: JPG / PNG / WebP up to 5 MB, saved under uploads/products with a random name
  $p = row("SELECT * FROM products WHERE id=?", [(int)in_('id')]); if (!$p) out(false, ['error'=>'Save the item first']);
  $f = $_FILES['image'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) out(false, ['error'=>'Choose a photo']);
  if ($f['size'] > 5*1024*1024) out(false, ['error'=>'The photo must be under 5 MB']);
  $info = @getimagesize($f['tmp_name']); $ext = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'][$info['mime'] ?? ''] ?? '';
  if ($ext === '') out(false, ['error'=>'Use a JPG, PNG or WebP photo']);
  if (!is_dir(UPLOADS)) @mkdir(UPLOADS, 0755, true);
  $ht = dirname(UPLOADS).'/.htaccess';   // uploads are images only: nothing in there may ever run as code
  if (!is_file($ht)) @file_put_contents($ht, "<FilesMatch \"\\.(php|phtml|php[0-9]|phar|cgi|pl|py|sh)$\">\n  Require all denied\n</FilesMatch>\n");
  $name = 'p'.$p['id'].'-'.bin2hex(random_bytes(5)).'.'.$ext;
  if (!move_uploaded_file($f['tmp_name'], UPLOADS.'/'.$name)) out(false, ['error'=>'Could not save the photo']);
  if ($p['image'] && is_file(__DIR__.'/'.$p['image'])) @unlink(__DIR__.'/'.$p['image']);
  q("UPDATE products SET image=? WHERE id=?", ['uploads/products/'.$name, $p['id']]); out(true, ['image'=>'uploads/products/'.$name]);
}
if ($a === 'product_image_remove') {
  $p = row("SELECT image FROM products WHERE id=?", [(int)in_('id')]);
  if ($p && $p['image'] && is_file(__DIR__.'/'.$p['image'])) @unlink(__DIR__.'/'.$p['image']);
  q("UPDATE products SET image='' WHERE id=?", [(int)in_('id')]); out(true);
}
if ($a === 'orders') {
  $show = in_('all') === '1' ? "" : "WHERE pay_method='install' OR pay_status='paid'";   // unpaid online attempts only with "show unpaid"
  out(true, ['orders'=>rows("SELECT * FROM orders $show ORDER BY id DESC LIMIT 2000")]);
}
if ($a === 'order_update') {
  $st = in_('status'); if (!in_array($st, ['New', 'Install scheduled', 'Installed', 'Cancelled'], true)) out(false, ['error'=>'Unknown status']);
  q("UPDATE orders SET status=?, note=? WHERE id=?", [$st, txt(in_('note'), 500), (int)in_('id')]);
  if (in_('paid') === '1') q("UPDATE orders SET pay_status='paid' WHERE id=? AND pay_method='install'", [(int)in_('id')]);
  out(true);
}
if ($a === 'leads') out(true, ['leads'=>rows("SELECT * FROM leads ORDER BY id DESC LIMIT 2000")]);
if ($a === 'lead_update') {
  $st = in_('status'); if (!in_array($st, ['New', 'Called', 'Converted', 'Not interested'], true)) out(false, ['error'=>'Unknown status']);
  q("UPDATE leads SET status=? WHERE id=?", [$st, (int)in_('id')]); out(true);
}
if ($a === 'content') out(true, ['content'=>content()]);
if ($a === 'content_save') {
  $in = json_decode((string)in_('content'), true); if (!is_array($in)) out(false, ['error'=>'Nothing to save']);
  $c = content();
  foreach (CONTENT_DEFAULT as $k => $def) if (array_key_exists($k, $in)) $c[$k] = is_int($def) ? ((int)$in[$k] ? 1 : 0) : txt($in[$k], $k === 'cities' ? 2000 : 500);
  $c['whatsapp'] = preg_replace('/\D/', '', $c['whatsapp']); if (strlen($c['whatsapp']) === 10) $c['whatsapp'] = '91'.$c['whatsapp'];
  setKv('content', json_encode($c, JSON_UNESCAPED_UNICODE)); out(true, ['content'=>$c]);
}
if ($a === 'faqs') out(true, ['faqs'=>rows("SELECT * FROM faqs ORDER BY sort, id"), 'reviews'=>rows("SELECT * FROM reviews ORDER BY sort, id")]);
if ($a === 'faq_save') {
  $qq = txt(in_('q'), 200); $aa = txt(in_('a'), 1000); if ($qq === '' || $aa === '') out(false, ['error'=>'Write the question and the answer']);
  if ((int)in_('id')) q("UPDATE faqs SET q=?, a=?, visible=? WHERE id=?", [$qq, $aa, in_('visible', '1') === '1' ? 1 : 0, (int)in_('id')]);
  else q("INSERT INTO faqs (q,a,sort) VALUES (?,?,?)", [$qq, $aa, (int)(row("SELECT COALESCE(MAX(sort),0)+1 n FROM faqs")['n'])]);
  out(true);
}
if ($a === 'review_save') {
  $n = txt(in_('name'), 60); $b = txt(in_('body'), 600); if ($n === '' || $b === '') out(false, ['error'=>'Write the name and the review']);
  $f = [$n, txt(in_('place'), 80), $b, max(1, min(5, (int)in_('stars', 5))), in_('visible', '1') === '1' ? 1 : 0];
  if ((int)in_('id')) q("UPDATE reviews SET name=?, place=?, body=?, stars=?, visible=? WHERE id=?", array_merge($f, [(int)in_('id')]));
  else q("INSERT INTO reviews (name,place,body,stars,visible,sort) VALUES (?,?,?,?,?,?)", array_merge($f, [(int)(row("SELECT COALESCE(MAX(sort),0)+1 n FROM reviews")['n'])]));
  out(true);
}
if ($a === 'item_toggle' || $a === 'item_delete' || $a === 'item_move') {   // FAQs and reviews share these
  $t = in_('kind') === 'review' ? 'reviews' : 'faqs'; $id = (int)in_('id');
  if ($a === 'item_toggle') q("UPDATE $t SET visible=? WHERE id=?", [in_('visible') === '1' ? 1 : 0, $id]);
  if ($a === 'item_delete') q("DELETE FROM $t WHERE id=?", [$id]);
  if ($a === 'item_move') {
    $ids = array_map(fn($r) => (int)$r['id'], rows("SELECT id FROM $t ORDER BY sort, id"));
    $i = array_search($id, $ids, true); $j = in_('dir') === 'up' ? $i - 1 : $i + 1;
    if ($i !== false && $j >= 0 && $j < count($ids)) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; foreach ($ids as $k => $x) q("UPDATE $t SET sort=? WHERE id=?", [$k, $x]); }
  }
  out(true);
}
if ($a === 'settings') out(true, ['settings'=>settings(), 'rzp'=>rzpReady() ? 1 : 0, 'rzp_key'=>rzpReady() ? substr($CFG['rzp_key'], 0, 12).'…' : '']);
if ($a === 'settings_save') {
  $s = settings();
  foreach (['pay_online', 'pay_install'] as $k) if (isset($_POST[$k])) $s[$k] = $_POST[$k] === '1' ? 1 : 0;
  if (isset($_POST['alert_email'])) { $e = txt($_POST['alert_email'], 120); if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) out(false, ['error'=>'Enter a valid email address']); $s['alert_email'] = $e; }
  if (!$s['pay_online'] && !$s['pay_install']) out(false, ['error'=>'Keep at least one way to pay switched on']);
  setKv('settings', json_encode($s)); out(true, ['settings'=>$s]);
}
if ($a === 'password') {
  checkPass(in_('old')); $p = (string)in_('new'); if (strlen($p) < 8) out(false, ['error'=>'Use at least 8 characters']);
  setKv('admin_hash', password_hash($p, PASSWORD_DEFAULT)); q("DELETE FROM tokens"); out(true, ['token'=>newToken()]);
}
out(false, ['error'=>'Unknown action']);
