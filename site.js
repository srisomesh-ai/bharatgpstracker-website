/* Bharat GPS website: fills the page from the admin panel (api.php?action=site), and runs the
   "Buy Now" order form and the "Get a free callback" form. The HTML already holds the same details, so the
   page reads fine even before (or without) the data. */
(function () {
  var API = 'api.php';
  var D = null;
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); }
  function inr(n) { return '₹' + Number(n || 0).toLocaleString('en-IN'); }
  function digits(s) { return String(s || '').replace(/\D/g, ''); }
  function telHref(p) { var d = digits(p); return 'tel:+' + (d.length === 10 ? '91' + d : d); }
  function post(action, data, cb) {
    var f = new FormData(); f.append('action', action); for (var k in data) f.append(k, data[k]);
    fetch(API + '?action=' + action + '&_=' + Date.now(), { method: 'POST', body: f, cache: 'no-store' })
      .then(function (r) { return r.json(); }).then(cb).catch(function () { cb({ ok: false, error: 'No internet connection. Please try again.' }); });
  }
  var TICK = '<svg class="i" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>';
  var DEV = {
    bike: '<svg width="96" height="96" viewBox="0 0 64 64" fill="none"><rect x="18" y="20" width="28" height="22" rx="6" fill="#fff" stroke="#13274a" stroke-width="2"/><circle cx="32" cy="31" r="4.5" fill="#FF423D"/></svg>',
    car: '<svg width="104" height="104" viewBox="0 0 64 64" fill="none"><rect x="12" y="16" width="40" height="30" rx="7" fill="#fff" stroke="#13274a" stroke-width="2"/><circle cx="32" cy="31" r="6" fill="#FF423D"/><rect x="18" y="21" width="6" height="3" rx="1.5" fill="#0f8a5f"/></svg>',
    truck: '<svg width="104" height="96" viewBox="0 0 64 64" fill="none"><rect x="6" y="20" width="32" height="22" rx="3" fill="#fff" stroke="#13274a" stroke-width="2"/><path d="M38 26h10l8 8v8H38z" fill="#fff" stroke="#13274a" stroke-width="2"/><circle cx="16" cy="44" r="4" fill="#FF423D"/><circle cx="46" cy="44" r="4" fill="#FF423D"/></svg>',
    bus: '<svg width="96" height="96" viewBox="0 0 64 64" fill="none"><rect x="14" y="10" width="36" height="40" rx="6" fill="#fff" stroke="#13274a" stroke-width="2"/><path d="M14 30h36" stroke="#13274a" stroke-width="2"/><circle cx="22" cy="42" r="3" fill="#FF423D"/><circle cx="42" cy="42" r="3" fill="#FF423D"/></svg>',
    person: '<svg width="96" height="96" viewBox="0 0 64 64" fill="none"><rect x="22" y="14" width="20" height="34" rx="8" fill="#fff" stroke="#13274a" stroke-width="2"/><circle cx="32" cy="27" r="4" fill="#FF423D"/><path d="M28 40h8" stroke="#13274a" stroke-width="2"/></svg>'
  };

  /* ---------- fill the page ---------- */
  function txt(sel, v) { var e = $(sel); if (e && v != null && v !== '') e.textContent = v; }
  function cities() { return (D.content.cities || '').split('\n').map(function (c) { return c.trim(); }).filter(Boolean); }
  function fill() {
    var c = D.content;
    var off = $('#offer'); if (off) { off.hidden = !c.offer_on; off.textContent = c.offer_text; }
    var np = $('#navPhone'); if (np) { np.href = telHref(c.phone1); np.querySelector('span').textContent = c.phone1; }
    $$('[data-login]').forEach(function (a) { a.href = c.login_url; });
    $$('[data-play]').forEach(function (a) { a.href = c.play_url; });
    var h = $('#heroTitle');
    if (h && c.hero_title) { var t = esc(c.hero_title); h.innerHTML = /Bharat GPS/.test(t) ? t.replace('Bharat GPS', '<em>Bharat GPS</em>') : t; }
    txt('#heroText', c.hero_text);
    txt('#heroRating', c.stat_rating + ' rating on Google Play · 10K+ downloads');
    txt('#shotRating', c.stat_rating + ' ★');
    txt('#tVehicles', c.stat_vehicles); txt('#tInstall', c.stat_install); txt('#tSince', c.stat_since); txt('#tCities', String(cities().length));
    var bc = $('#bandCall'); if (bc) bc.href = telHref(c.phone1);
    var cl = $('#cityList'); if (cl) cl.innerHTML = cities().map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('');
    $$('select[data-cities]').forEach(function (s) { var v = s.value; s.innerHTML = cities().concat(['Other']).map(function (x) { return '<option>' + esc(x) + '</option>'; }).join(''); if (v) s.value = v; if (!s.value) s.selectedIndex = 0; });
    var fc = $('#footContact');
    if (fc) fc.innerHTML = '<h4>Contact</h4>' + [c.phone1, c.phone2].filter(Boolean).map(function (p) { return '<a href="' + telHref(p) + '">' + esc(p) + '</a>'; }).join('') +
      (c.email_sales ? '<a href="mailto:' + esc(c.email_sales) + '">' + esc(c.email_sales) + '</a>' : '') + '<p>' + esc(c.address) + '</p>' + (c.hours_call ? '<p>Call centre: ' + esc(c.hours_call) + '</p>' : '');
    var fp = $('#footProducts'); if (fp && D.products.length) fp.innerHTML = '<h4>Products</h4>' + D.products.map(function (p) { return '<a href="#shop">' + esc(p.name) + '</a>'; }).join('');
    if (D.reviews.length && $('#revs')) $('#revs').innerHTML = D.reviews.map(function (r) {
      return '<div class="rv reveal in"><div class="st" aria-label="' + r.stars + ' stars">' + '★★★★★'.slice(0, r.stars) + '</div><p>' + esc(r.body) + '</p><div class="who">' + esc(r.name) + '<span>' + esc(r.place) + '</span></div></div>'; }).join('');
    if (D.faqs.length && $('#faqList')) $('#faqList').innerHTML = D.faqs.map(function (f) { return '<details><summary>' + esc(f.q) + '</summary><p>' + esc(f.a) + '</p></details>'; }).join('');
    drawCards();
  }
  function drawCards() {
    var box = $('#cards'); if (!box || !D.products.length) return;
    box.innerHTML = D.products.map(function (p) {
      var badge = p.popular ? '<span class="off" style="background:var(--saffron)">Bestseller</span>' : (p.mrp > p.price ? '<span class="off">Save ' + inr(p.mrp - p.price) + '</span>' : '');
      var pic = p.image ? '<img src="' + esc(p.image) + '" alt="' + esc(p.name) + '" loading="lazy">' : (DEV[p.icon] || DEV.car);
      var feats = p.features.slice(); if (p.free_install && !feats.some(function (f) { return /install/i.test(f); })) feats.push('Free doorstep installation');
      var out = p.stock === 'Out of stock';
      return '<div class="pc reveal in' + (p.popular ? ' best' : '') + '"><div class="top">' + badge + pic + '</div><div class="body"><h3>' + esc(p.name) + '</h3><div class="for">' + esc(p.descr || p.cat) + '</div>' +
        '<div class="pr"><b>' + inr(p.price) + '</b>' + (p.mrp > p.price ? '<s>' + inr(p.mrp) + '</s>' : '') + '</div>' +
        '<div class="ren">Incl. GST, 1 year tracking' + (p.renewal ? ' · then ' + inr(p.renewal) + '/year' : '') + (p.stock === 'Made to order' ? ' · made to order' : '') + '</div>' +
        '<ul>' + feats.map(function (f) { return '<li>' + TICK + esc(f) + '</li>'; }).join('') + '</ul>' +
        '<div class="acts1">' + (out ? '<button class="btn btn-o" type="button" data-callback="' + esc(p.name) + '">Out of stock · Get a callback</button>' : '<button class="btn btn-s" type="button" data-buy="' + p.id + '">Buy Now</button>') + '</div></div></div>';
    }).join('');
  }

  /* ---------- callback form ---------- */
  var cb = $('#cbForm');
  if (cb) cb.addEventListener('submit', function (e) {
    e.preventDefault();
    var err = $('#cbErr'), b = cb.querySelector('button[type=submit]'), v = cb.querySelector('input[name=v]:checked');
    var name = $('#n').value.trim(), ph = digits($('#m').value); if (ph.length === 12 && ph.indexOf('91') === 0) ph = ph.slice(2);
    if (!name) { err.textContent = 'Enter your name'; $('#n').focus(); return; }
    if (!/^[6-9]\d{9}$/.test(ph)) { err.textContent = 'Enter a 10-digit mobile number'; $('#m').focus(); return; }
    err.textContent = ''; b.disabled = true; b.textContent = 'Sending…';
    post('lead', { name: name, phone: ph, vehicle: v ? v.value : '', city: $('#c').value, source: 'Home page form' }, function (r) {
      b.disabled = false;
      if (!r.ok) { err.textContent = r.error || 'Not sent. Please try again.'; b.textContent = 'Request Callback'; return; }
      b.textContent = 'Request sent'; $('#cbNote').textContent = 'Thank you, ' + name + '! We will call you on ' + ph + ' shortly.';
      $('#n').value = ''; $('#m').value = '';
      setTimeout(function () { b.textContent = 'Request Callback'; }, 6000);
    });
  });

  /* ---------- Buy Now: order form ---------- */
  var modal = null, cur = null;
  function closeModal() { if (modal) { modal.remove(); modal = null; document.body.style.overflow = ''; } }
  function openOrder(id) {
    if (!D) return;
    cur = D.products.filter(function (p) { return p.id === id; })[0]; if (!cur) return;
    var pay = D.pay, fleet = /fleet|commercial|bus|ais/i.test(cur.cat + ' ' + cur.name);
    var opts = (pay.online ? '<label class="po"><input type="radio" name="pay" value="online" checked><span><b>Pay online now</b><small>UPI, cards, net banking</small></span></label>' : '') +
               (pay.install ? '<label class="po"><input type="radio" name="pay" value="install"' + (pay.online ? '' : ' checked') + '><span><b>Pay on installation</b><small>Book now, pay the technician</small></span></label>' : '');
    closeModal();
    modal = document.createElement('div'); modal.className = 'om'; modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true'); modal.setAttribute('aria-label', 'Order ' + cur.name);
    modal.innerHTML = '<div class="om-card"><button class="om-x" type="button" aria-label="Close">×</button>' +
      '<div class="om-item"><div class="om-pic">' + (cur.image ? '<img src="' + esc(cur.image) + '" alt="">' : (DEV[cur.icon] || DEV.car)) + '</div><div><h3>' + esc(cur.name) + '</h3><span>' + esc(cur.descr || cur.cat) + '</span><b>' + inr(cur.price) + ' <small>each, incl. GST</small></b></div></div>' +
      '<form class="om-form" novalidate>' +
      (fleet ? '<label>How many vehicles?<input name="qty" type="number" min="1" max="50" value="1" inputmode="numeric"></label>' : '<input type="hidden" name="qty" value="1">') +
      '<div class="om-2"><label>Your name<input name="name" autocomplete="name" maxlength="60" required></label><label>Mobile number<input name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="14" required></label></div>' +
      '<div class="om-2"><label>Vehicle number' + (fleet ? 's' : '') + '<input name="vehicle" placeholder="AP 31 AB 1234" maxlength="40" style="text-transform:uppercase"></label><label>City<select name="city" data-cities></select></label></div>' +
      '<label>Installation address<textarea name="address" rows="2" maxlength="300" autocomplete="street-address" placeholder="House / office, street, area"></textarea></label>' +
      '<div class="om-pay"><span>How do you want to pay?</span>' + opts + '</div>' +
      '<div class="om-total"><span>Total</span><b id="omTotal">' + inr(cur.price) + '</b></div>' +
      '<div class="ferr" role="alert"></div><button class="btn btn-s om-go" type="submit">Place order</button>' +
      '<p class="om-note">Our team calls you to confirm a time for installation.</p></form></div>';
    document.body.appendChild(modal); document.body.style.overflow = 'hidden';
    var sel = modal.querySelector('select[name=city]'); sel.innerHTML = cities().concat(['Other']).map(function (x) { return '<option>' + esc(x) + '</option>'; }).join('');
    var f = modal.querySelector('form');
    function total() { var q = Math.max(1, Math.min(50, parseInt(f.qty.value, 10) || 1)); $('#omTotal').textContent = inr(cur.price * q); var pm = f.querySelector('input[name=pay]:checked');
      modal.querySelector('.om-go').textContent = pm && pm.value === 'online' ? 'Pay ' + inr(cur.price * q) : 'Place order'; }
    f.addEventListener('input', total); f.addEventListener('change', total); total();
    modal.querySelector('.om-x').onclick = closeModal;
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    f.addEventListener('submit', function (e) { e.preventDefault(); submitOrder(f); });
    setTimeout(function () { f.name.focus(); }, 60);
  }
  function submitOrder(f) {
    var err = f.querySelector('.ferr'), b = f.querySelector('.om-go');
    var ph = digits(f.phone.value); if (ph.length === 12 && ph.indexOf('91') === 0) ph = ph.slice(2);
    var pm = f.querySelector('input[name=pay]:checked');
    if (!f.name.value.trim()) { err.textContent = 'Enter your name'; f.name.focus(); return; }
    if (!/^[6-9]\d{9}$/.test(ph)) { err.textContent = 'Enter a 10-digit mobile number'; f.phone.focus(); return; }
    if (!f.address.value.trim()) { err.textContent = 'Enter the installation address'; f.address.focus(); return; }
    if (!pm) { err.textContent = 'Choose how you want to pay'; return; }
    err.textContent = ''; b.disabled = true; var label = b.textContent; b.textContent = 'Please wait…';
    post('order', { product: cur.id, qty: f.qty.value, name: f.name.value.trim(), phone: ph, vehicle: f.vehicle.value.trim(), city: f.city.value, address: f.address.value.trim(), pay: pm.value }, function (r) {
      if (!r.ok) { b.disabled = false; b.textContent = label; err.textContent = r.error || 'Order not placed. Please try again.'; return; }
      if (!r.rzp) { done(r.code, false, f.name.value.trim()); return; }
      payOnline(r, f, b, label, err);
    });
  }
  function payOnline(r, f, b, label, err) {
    function open() {
      var rz = new window.Razorpay({ key: r.rzp.key, order_id: r.rzp.order_id, amount: r.rzp.amount, currency: 'INR', name: 'Bharat GPS Tracker', description: r.rzp.item + ' · ' + r.code,
        prefill: { name: r.rzp.name, contact: '+91' + r.rzp.phone }, theme: { color: '#FF423D' },
        handler: function (res) { b.textContent = 'Confirming payment…';
          post('paid', { code: r.code, payment_id: res.razorpay_payment_id, signature: res.razorpay_signature }, function (v) {
            if (v.ok) done(r.code, true, r.rzp.name); else { b.disabled = false; b.textContent = label; err.textContent = v.error; } }); },
        modal: { ondismiss: function () { b.disabled = false; b.textContent = label; err.textContent = 'Payment not completed. Try again, or choose pay on installation.'; } } });
      rz.open();
    }
    if (window.Razorpay) return open();
    var s = document.createElement('script'); s.src = 'https://checkout.razorpay.com/v1/checkout.js'; s.onload = open;
    s.onerror = function () { b.disabled = false; b.textContent = label; err.textContent = 'Online payment could not load. Check the internet, or choose pay on installation.'; };
    document.head.appendChild(s);
  }
  function done(code, paid, name) {
    var c = D.content;
    modal.querySelector('.om-card').innerHTML = '<button class="om-x" type="button" aria-label="Close">×</button><div class="om-done"><div class="om-ok">' + TICK + '</div>' +
      '<h3>' + (paid ? 'Payment received. Thank you!' : 'Order placed. Thank you!') + '</h3><p>Your order number is <b>' + esc(code) + '</b>.</p>' +
      '<p>' + esc(name) + ', our team will call you shortly to fix a time for installation.</p><p class="om-note">Questions? Call <a href="' + telHref(c.phone1) + '">' + esc(c.phone1) + '</a></p>' +
      '<button class="btn btn-s om-close" type="button">Done</button></div>';
    modal.querySelector('.om-x').onclick = closeModal; modal.querySelector('.om-close').onclick = closeModal;
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-buy],[data-callback]'); if (!t) return;
    if (t.dataset.buy) { openOrder(+t.dataset.buy); return; }
    var n = $('#n'); if (n) { document.querySelector('.hero').scrollIntoView({ behavior: 'smooth' }); setTimeout(function () { n.focus(); }, 500); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

  fetch(API + '?action=site&_=' + Date.now(), { cache: 'no-store' }).then(function (r) { return r.json(); })
    .then(function (r) { if (r && r.ok) { D = r; fill(); } }).catch(function () {});
})();
