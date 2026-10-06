/* Bharat GPS live chat pop-up (Oct 2026, same as ScanPlay's). Add to a page with:
   <script src="/livechat/widget.js" data-site="bharatgps" defer></script>
   - Every few seconds it tells the server this visitor is on the site (the agent sees a live list) and fetches
     agent messages: the agent can start the chat; a new agent message opens the window, plays a sound and
     blinks the tab title.
   - Opens by itself only once per visit, 10 s after the visit; once the visitor closes it, it stays closed until the
     agent writes. The agent sees whether the window is open or minimised.
   - No form up front: name + mobile are asked when the visitor sends a first message.
   - Robots are not shown to the agent (owner, 5 Oct): automated browsers and known robots get no chat at all, and a visit
     is recorded only after a first touch, scroll, mouse move or key press (website checkers open many pages without any). */
(function () {
  if (window.__spChat) return; window.__spChat = 1;
  if (navigator.webdriver || /bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|phantom|puppeteer|playwright|selenium/i.test(navigator.userAgent || '')) return;
  var me = document.currentScript || document.querySelector('script[src*="livechat/widget.js"]');
  var API = (me && me.src ? me.src.replace(/widget\.js.*$/, '') : '/livechat/') + 'api.php';
  var SITE = (me && me.getAttribute('data-site')) || 'bharatgps';
  var TITLE = (me && me.getAttribute('data-title')) || 'Bharat GPS support';
  var DELAY = +((me && me.getAttribute('data-delay')) || 10) * 1000;
  var AUTO = !me || me.getAttribute('data-auto') !== '0';   /* data-auto="0" (Studio): never opens by itself, only the button or the agent */
  var K = 'bgtchat_' + SITE;
  function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }
  function ss(k, v) { try { if (v === undefined) return sessionStorage.getItem(k); sessionStorage.setItem(k, v); } catch (e) { return null; } }
  function rid() { return Date.now().toString(36) + Math.random().toString(36).slice(2, 12); }

  var VID = ls(K + '_vid'); if (!VID || VID.length < 12) { VID = rid() + rid().slice(0, 4); ls(K + '_vid', VID); }
  var SID = ss(K + '_sid'); if (!SID) { SID = rid(); ss(K + '_sid', SID); }
  var last = 0, open = false, timer = null, unread = 0, sending = false, shown = {}, named = false, pending = '', first = true;

  var host = document.createElement('div'); host.id = 'bgt-chat';
  var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
  var raised = !!document.querySelector('a.wa');   /* sit above the WhatsApp button where the page has one */
  var B = raised ? 86 : 18;
  var Z = AUTO ? 2147483000 : 7;   /* in the Studio the chat stays under the Studio's own windows */
  root.innerHTML = '<style>' +
    ':host{all:initial}*{box-sizing:border-box;font-family:"Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}' +
    '.fab{position:fixed;right:max(18px,env(safe-area-inset-right));bottom:calc(' + B + 'px + env(safe-area-inset-bottom));z-index:' + Z + ';width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;color:#fff;background:#FF423D;box-shadow:0 10px 30px rgba(255,66,61,.45);display:grid;place-items:center;padding:0}' +
    '.fab svg{width:28px;height:28px;fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}' +
    '.fab.wig{animation:wig .6s ease 3}@keyframes wig{25%{transform:rotate(-14deg) scale(1.08)}75%{transform:rotate(14deg) scale(1.08)}}' +
    '.fab.shake{animation:shake 1s ease}@keyframes shake{20%{transform:rotate(-14deg) scale(1.08)}40%{transform:rotate(12deg) scale(1.08)}60%{transform:rotate(-8deg)}80%{transform:rotate(5deg)}}' +
    '.ring{position:fixed;right:max(18px,env(safe-area-inset-right));bottom:calc(' + B + 'px + env(safe-area-inset-bottom));z-index:' + Z + ';width:56px;height:56px;border-radius:50%;background:#FF8F8B;pointer-events:none;animation:ring 1.8s ease-out infinite}.ring.off{display:none}' +
    '@keyframes ring{0%{transform:scale(1);opacity:.55}100%{transform:scale(1.75);opacity:0}}' +
    '.live{position:absolute;top:-1px;left:-1px;width:14px;height:14px;border-radius:50%;background:#12B76A;border:2px solid #fff;display:none}.live.on{display:block}' +
    '.help{position:fixed;right:calc(max(18px,env(safe-area-inset-right)) + 66px);bottom:calc(' + (B + 10) + 'px + env(safe-area-inset-bottom));z-index:' + Z + ';background:#fff;color:#1E2430;border-radius:14px 14px 4px 14px;padding:9px 30px 9px 12px;box-shadow:0 10px 30px rgba(20,25,35,.18);max-width:min(210px,calc(100vw - 110px));line-height:1.4;cursor:pointer;animation:pop .35s ease;display:none}' +
    '.help.on{display:block}.help b{display:flex;align-items:center;gap:4px;font-size:14px;font-weight:700}.help span{display:block;font-size:12px;color:#677184}' +
    '.help .hx{position:absolute;top:3px;right:4px;border:0;background:transparent;color:#98A0AE;font-size:17px;line-height:1;cursor:pointer;padding:3px 5px}' +
    '@keyframes pop{0%{opacity:0;transform:translateY(6px) scale(.9)}100%{opacity:1;transform:none}}' +
    '.help .typ{display:inline-flex;gap:3px;margin-left:3px}.typ i{display:block;width:5px;height:5px;border-radius:50%;background:#E2302B;animation:dot 1.2s infinite}.typ i:nth-child(2){animation-delay:.2s}.typ i:nth-child(3){animation-delay:.4s}' +
    '@keyframes dot{0%,80%,100%{opacity:.25}40%{opacity:1}}' +
    '@media (prefers-reduced-motion:reduce){.ring,.typ i,.fab.shake,.fab.wig,.help{animation:none}}' +
    '.badge{position:absolute;top:-2px;right:-2px;min-width:20px;height:20px;border-radius:10px;background:#119C90;color:#fff;font-size:12px;font-weight:700;display:none;align-items:center;justify-content:center;padding:0 5px;border:2px solid #fff}' +
    '.win{position:fixed;right:max(12px,env(safe-area-inset-right));bottom:calc(' + (B + 68) + 'px + env(safe-area-inset-bottom));z-index:' + (Z + 1) + ';width:340px;max-width:calc(100vw - 24px);height:470px;max-height:calc(100vh - ' + (B + 92) + 'px);background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(20,25,35,.28);display:flex;flex-direction:column;overflow:hidden;opacity:0;transform:translateY(16px) scale(.97);pointer-events:none;transition:opacity .2s,transform .2s}' +
    '.win.on{opacity:1;transform:none;pointer-events:auto}' +
    '.hd{background:#FF423D;color:#fff;padding:12px 14px;display:flex;align-items:center;gap:10px}' +
    '.av{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.22);display:grid;place-items:center;font-weight:800;font-size:14px;flex:none}' +
    '.tt{flex:1;min-width:0}.tt b{display:block;font-size:15px;font-weight:700}.tt span{display:block;font-size:12px;opacity:.9}' +
    '.x{background:transparent;border:0;color:#fff;font-size:24px;line-height:1;cursor:pointer;padding:4px 6px}' +
    'input,textarea{width:100%;border:1px solid #E3E7ED;border-radius:10px;padding:11px 12px;font-size:15px;color:#1E2430;outline:none;background:#fff}input:focus,textarea:focus{border-color:#E2302B}' +
    '.btn{border:0;border-radius:10px;padding:11px;font-size:15px;font-weight:700;color:#fff;background:#FF423D;cursor:pointer;width:100%}' +
    '.err{color:#D92D20;font-size:13px;min-height:16px}' +
    '.ok{display:flex;gap:8px;align-items:center;font-size:13px;color:#4A5263;cursor:pointer}.ok input{width:18px;height:18px;margin:0;accent-color:#E2302B;flex:none}' +
    '.msgs{flex:1;overflow-y:auto;padding:12px;background:#F6F7F9;display:flex;flex-direction:column;gap:6px}' +
    '.m{max-width:80%;padding:8px 12px;border-radius:14px;font-size:14px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word;color:#1E2430}' +
    '.m.v{align-self:flex-end;background:#E2302B;color:#fff;border-bottom-right-radius:4px}.m.a{align-self:flex-start;background:#fff;border:1px solid #EDEFF3;border-bottom-left-radius:4px}' +
    '.m small{display:block;font-size:10px;opacity:.6;margin-top:2px;text-align:right}' +
    '.s{align-self:center;font-size:12px;color:#677184;text-align:center;padding:2px 8px}' +
    '.form{display:none;padding:12px;border-top:1px solid #EDEFF3;flex-direction:column;gap:8px}.form.on{display:flex}.form p{margin:0;font-size:14px;color:#1E2430}' +
    '.bar{display:flex;gap:8px;padding:10px;border-top:1px solid #EDEFF3;align-items:flex-end}.bar.off{display:none}.bar textarea{resize:none;height:42px;max-height:100px;padding:10px 12px}' +
    '.snd{flex:none;width:42px;height:42px;border-radius:50%;border:0;background:#FF423D;display:grid;place-items:center;cursor:pointer}.snd svg{width:20px;height:20px;fill:#fff}' +
    '</style>' +
    '<div class="win" role="dialog" aria-label="Chat with ' + TITLE + '">' +
      '<div class="hd"><div class="av">BG</div><div class="tt"><b>' + TITLE + '</b><span class="st">We usually reply in a few minutes</span></div><button class="x" aria-label="Close chat">&times;</button></div>' +
      '<div class="msgs" aria-live="polite"><div class="s">Hi! 👋 How can we help you today?</div></div>' +
      '<div class="form"><p>Before we reply, please share your name and mobile number</p><input class="nm" placeholder="Your name" maxlength="60" autocomplete="name"><input class="ph" placeholder="Mobile number" inputmode="tel" maxlength="14" autocomplete="tel"><label class="ok"><input type="checkbox" class="of"> Send me Bharat GPS offers on WhatsApp</label><div class="err"></div><button class="btn go">Send</button></div>' +
      '<div class="bar"><textarea class="tx" placeholder="Type a message" rows="1" maxlength="1000"></textarea><button class="snd" aria-label="Send"><svg viewBox="0 0 24 24"><path d="M3.4 20.4 21 12 3.4 3.6 3.4 10.1 15 12 3.4 13.9z"/></svg></button></div>' +
    '</div>' +
    '<span class="ring" aria-hidden="true"></span><button class="fab" aria-label="Chat with us"><svg viewBox="0 0 24 24"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.9-.9L3 20l1.1-4.4A8 8 0 0 1 3 11.5 8.5 8.5 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/></svg><span class="badge"></span><span class="live"></span></button>' +
    '<div class="help" role="button" tabindex="0" aria-label="Need help? Chat with us"><button class="hx" aria-label="Hide">&times;</button><b>Need help? <span class="typ" aria-hidden="true"><i></i><i></i><i></i></span></b><span>Chat with us, we reply in minutes</span></div>';
  function $(s) { return root.querySelector(s); }
  var help = $('.help'), liveDot = $('.live'), lastTs = 0;
  var win = $('.win'), fab = $('.fab'), badge = $('.badge'), list = $('.msgs'), tx = $('.tx'), err = $('.err'), st = $('.st'), form = $('.form'), bar = $('.bar');

  function api(action, data, cb) {   /* always POST + unique URL: answers must never come from a cache */
    var f = new FormData(); f.append('action', action); f.append('v', VID); f.append('s', SID); f.append('site', SITE);
    for (var k in (data || {})) f.append(k, data[k]);
    fetch(API + '?action=' + action + '&_=' + Date.now(), { method: 'POST', body: f, cache: 'no-store' })
      .then(function (r) { return r.json(); }).then(cb).catch(function () { cb({ ok: false, error: 'No connection. Try again.' }); });
  }
  function hhmm(ts) { var d = new Date(ts * 1000); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
  function draw(m) {
    if (shown[m.id]) return; shown[m.id] = 1;
    var d = document.createElement('div');
    if (m.who === 's') { d.className = 's'; d.textContent = m.body; }
    else { d.className = 'm ' + m.who; d.textContent = m.body; var t = document.createElement('small'); t.textContent = hhmm(m.ts); d.appendChild(t); }
    list.appendChild(d); list.scrollTop = list.scrollHeight;
  }
  function setBadge() { badge.textContent = unread; badge.style.display = unread ? 'flex' : 'none'; }

  /* getting the visitor's attention when the agent writes */
  var actx = null, blink = null, baseTitle = document.title;
  function unlockSound() { try { actx = actx || new (window.AudioContext || window.webkitAudioContext)(); if (actx.state === 'suspended') actx.resume(); } catch (e) {} }
  document.addEventListener('pointerdown', unlockSound, { once: true }); document.addEventListener('keydown', unlockSound, { once: true });
  function ding() {
    try { unlockSound(); [0, 0.16].forEach(function (t, i) { var o = actx.createOscillator(), g = actx.createGain(); o.frequency.value = i ? 1175 : 880; o.connect(g); g.connect(actx.destination);
      g.gain.setValueAtTime(0.0001, actx.currentTime + t); g.gain.exponentialRampToValueAtTime(0.25, actx.currentTime + t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, actx.currentTime + t + 0.3);
      o.start(actx.currentTime + t); o.stop(actx.currentTime + t + 0.35); }); } catch (e) {}
  }
  function attention(text) {
    ding(); fab.classList.remove('wig'); void fab.offsetWidth; fab.classList.add('wig');
    if (!open) toggle(true, true);
    if (document.hidden && !blink) { var on = false; blink = setInterval(function () { on = !on; document.title = on ? '💬 ' + (text || 'New message') : baseTitle; }, 1000); }
  }
  function stopBlink() { if (blink) { clearInterval(blink); blink = null; document.title = baseTitle; } }

  function poll() {
    clearTimeout(timer);
    if (!human) return;   /* nothing is sent before the first touch / scroll / mouse move / key press */
    var d = { after: last, page: location.href, w: winState, ref: first ? (document.referrer || '') : '' };
    api('poll', d, function (r) {
      if (r.ok) {
        first = false; named = !!r.named; var fresh = null, seen = +(ls(K + '_seen') || 0);   /* alert only for agent messages not seen on any page yet */
        (r.msgs || []).forEach(function (m) { if (m.who === 'a' && m.id > seen) fresh = m; draw(m); last = Math.max(last, m.id); if (m.who !== 's') lastTs = Math.max(lastTs, m.ts); });
        liveDot.classList.toggle('on', !!r.online);
        if (last > seen) ls(K + '_seen', String(last));
        if (fresh) { if (!open) unread++; attention(fresh.body); }
        st.textContent = r.agent ? r.agent + ' is in the chat' : (r.online ? 'Online now' : 'We usually reply in a few minutes');
        setBadge();
      }
      timer = setTimeout(poll, open ? 3000 : (document.hidden ? 30000 : 15000));   /* closed chat: every 15 s (less load on the site); open: every 3 s */   /* a failed check never stops the chat */
    });
  }
  var winState = ss(K + '_win') || 'none';   /* none = not opened yet this visit, open, min = minimised */
  function setWin(w) { if (w === winState) return; winState = w; ss(K + '_win', w); api('win', { w: w }, function () {}); }
  /* a page pop-up (#wpOverlay) on screen: never open the chat over it (kept from ScanPlay; not used on this site yet) */
  function promoUp() { var o = document.getElementById('wpOverlay'); return !!(o && o.style.display === 'flex'); }
  var waitPromo = false;
  function autoOpen() { if (open || ss(K + '_auto') || ss(K + '_closed')) return; if (!human) { waitHuman = true; return; } if (promoUp()) { waitPromo = true; return; } ss(K + '_auto', '1'); toggle(true, true); }
  window.addEventListener('sp-welcome-closed', function () { promoHide(); if (!waitPromo) return; waitPromo = false; setTimeout(autoOpen, DELAY); });
  function promoHide() { host.style.display = promoUp() && !open ? 'none' : ''; }
  window.spChat = { busy: function () { return open || (lastTs && Date.now() / 1000 - lastTs < 1800); } };   /* chat open or a chat in the last 30 min */
  function showHelp() { help.classList.toggle('on', !open && !ss(K + '_nohelp')); }
  help.onclick = function (e) { if (e.target.closest('.hx')) { ss(K + '_nohelp', '1'); showHelp(); return; } toggle(true); };
  setInterval(function () { if (open || document.hidden) return; fab.classList.remove('shake'); void fab.offsetWidth; fab.classList.add('shake'); }, 8000);
  function toggle(v, auto) {
    open = v === undefined ? !open : v; win.classList.toggle('on', open); $('.ring').classList.toggle('off', open); showHelp();
    promoHide();
    if (open) { setWin('open'); unread = 0; setBadge(); stopBlink(); list.scrollTop = list.scrollHeight; if (!auto) setTimeout(function () { (form.classList.contains('on') ? $('.nm') : tx).focus(); }, 250); clearTimeout(timer); timer = setTimeout(poll, 300); }
    else {
      ss(K + '_closed', '1'); setWin('min');   /* closed by the visitor: never opens by itself again this visit, only for an agent message */
    }
  }
  fab.onclick = function () { isHuman(); toggle(); };
  $('.x').onclick = function () { toggle(false); };
  function send() {
    var v = tx.value.trim(); if (!v || sending) return;
    if (!named) { pending = v; form.classList.add('on'); bar.classList.add('off'); setTimeout(function () { $('.nm').focus(); }, 50); return; }
    sending = true;
    api('send', { body: v, page: location.href }, function (r) {
      sending = false;
      if (!r.ok) { if (r.needDetails) { named = false; pending = v; form.classList.add('on'); bar.classList.add('off'); return; } draw({ id: 'e' + Date.now(), who: 's', body: r.error || 'Not sent' }); return; }
      tx.value = ''; tx.style.height = '42px'; poll();
    });
  }
  $('.go').onclick = function () {
    var n = $('.nm').value.trim(), p = $('.ph').value.replace(/\D/g, '');
    if (p.length === 12 && p.indexOf('91') === 0) p = p.slice(2);
    if (!n) { err.textContent = 'Enter your name'; return; }
    if (!/^[6-9]\d{9}$/.test(p)) { err.textContent = 'Enter a 10-digit mobile number'; return; }
    err.textContent = ''; var b = this; b.disabled = true;
    api('send', { body: pending, name: n, phone: p, offers: $('.of').checked ? '1' : '0', page: location.href }, function (r) {
      b.disabled = false;
      if (!r.ok) { err.textContent = r.error || 'Not sent. Try again.'; return; }
      named = true; form.classList.remove('on'); bar.classList.remove('off'); tx.value = ''; pending = ''; tx.focus(); poll();
    });
  };
  $('.nm').oninput = $('.ph').oninput = function () { err.textContent = ''; };
  $('.snd').onclick = send;
  tx.onkeydown = function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } };
  tx.oninput = function () { tx.style.height = '42px'; tx.style.height = Math.min(100, tx.scrollHeight) + 'px'; };
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { stopBlink(); poll(); } });
  function leave() { try { var f = new FormData(); f.append('action', 'leave'); f.append('s', SID); navigator.sendBeacon(API + '?action=leave', f); } catch (e) {} }
  window.addEventListener('pagehide', leave);

  var human = winState === 'open' || ss(K + '_human') === '1', waitHuman = false, HEV = ['pointerdown', 'pointermove', 'touchstart', 'scroll', 'keydown', 'wheel'];
  function isHuman() {
    if (human) return; human = true; ss(K + '_human', '1');
    HEV.forEach(function (e) { window.removeEventListener(e, isHuman, true); });
    poll(); if (waitHuman) { waitHuman = false; autoOpen(); }
  }
  if (!human) HEV.forEach(function (e) { window.addEventListener(e, isHuman, { capture: true, passive: true }); });
  function boot() {
    document.body.appendChild(host);
    if (winState === 'open') toggle(true, true);   /* keep the chat open when the visitor moves to another page */
    poll();
    showHelp();
    var wo = document.getElementById('wpOverlay'); if (wo && window.MutationObserver) new MutationObserver(promoHide).observe(wo, { attributes: true, attributeFilter: ['style'] });
    if (AUTO) setTimeout(autoOpen, DELAY);
  }
  if (document.body) boot(); else document.addEventListener('DOMContentLoaded', boot);
})();
