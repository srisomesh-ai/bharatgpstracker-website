/* Bharat GPS products page (products.html): all trackers with category buttons, search and sort, and the
   product details page (products.html?p=ID). Data and the order form come from site.js (window.BGT). */
(function () {
  var view = document.getElementById('view');
  var TICK = '<svg class="i" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>';
  var FLEET = /fleet|commercial|bus|ais/i;   // same rule as the order form: these can be ordered for many vehicles
  var D, B, state = { cat: 'All', q: '', sort: 'pop' };
  function esc(s) { return B.esc(s); }
  function inr(n) { return B.inr(n); }
  function param(k) { var m = new RegExp('[?&]' + k + '=([^&]*)').exec(location.search); return m ? decodeURIComponent(m[1]) : ''; }
  function isAis(p) { return /ais-?140/i.test(p.name + ' ' + p.cat + ' ' + p.tags.join(' ')); }
  function pic(p, src) { return src || p.images[0] ? '<img src="' + esc(src || p.images[0]) + '" alt="' + esc(p.name) + '" loading="lazy">' : (B.dev[p.icon] || B.dev.car); }
  function badge(p) {
    return p.popular ? '<span class="tag t-hot">Best seller</span>' : isAis(p) ? '<span class="tag t-ais">AIS-140</span>' : p.mrp > p.price ? '<span class="tag t-save">Save ' + inr(p.mrp - p.price) + '</span>' : '';
  }
  function renLine(p) { return 'Incl. GST & 1 year tracking' + (p.renewal ? ' · then ' + inr(p.renewal) + '/year' : ''); }
  function card(p) {
    return '<a class="card" href="products.html?p=' + p.id + '"><div class="ph">' + badge(p) + (p.stock !== 'In stock' ? '<span class="stock">' + esc(p.stock) + '</span>' : '') + pic(p) + '</div>' +
      '<div class="cb"><span class="cat">' + esc(p.cat) + '</span><h3>' + esc(p.name) + '</h3>' + (p.descr ? '<span class="d">' + esc(p.descr) + '</span>' : '') +
      (p.tags.length ? '<div class="keys">' + p.tags.map(function (t) { return '<span>' + esc(t) + '</span>'; }).join('') + '</div>' : '') +
      '<div class="pr"><b>' + inr(p.price) + '</b>' + (p.mrp > p.price ? '<s>' + inr(p.mrp) + '</s>' : '') + '</div><span class="ren">' + renLine(p) + '</span></div></a>';
  }
  function cats() { var c = []; D.products.forEach(function (p) { if (p.cat && c.indexOf(p.cat) < 0) c.push(p.cat); }); return ['All'].concat(c); }
  function help() {
    var c = D.content;
    return '<div class="help"><div><h2>Not sure which tracker you need?</h2><p>Tell us your vehicle and we will suggest the right one. Call ' + esc(c.phone1) + ' or chat with us.</p></div>' +
      '<a class="btn btn-s" href="index.html#cbForm">Get a free callback</a></div>';
  }

  /* ---------- all products ---------- */
  function list() {
    var q = state.q, shown = D.products.filter(function (p) {
      return (state.cat === 'All' || p.cat === state.cat) && (!q || [p.name, p.descr, p.cat, p.tags.join(' '), p.features.join(' ')].join(' ').toLowerCase().indexOf(q) >= 0); });
    if (state.sort === 'low') shown.sort(function (a, b) { return a.price - b.price; });
    else if (state.sort === 'high') shown.sort(function (a, b) { return b.price - a.price; });
    else shown.sort(function (a, b) { return b.popular - a.popular || a.sort - b.sort; });
    document.getElementById('grid').innerHTML = shown.length ? shown.map(card).join('') : '<div class="empty">No tracker matches. Try another word, or ask us on chat.</div>';
    document.getElementById('count').textContent = shown.length + ' of ' + D.products.length + ' trackers';
  }
  function showList() {
    document.title = 'GPS Trackers & Prices · Bharat GPS Tracker';
    view.innerHTML = '<div class="crumb"><a href="index.html">Home</a> / Products</div>' +
      '<div class="lhead"><div><h1>GPS trackers for every vehicle</h1><p>Every tracker comes with 1 year of tracking, our app, and free installation in our cities. Prices include GST.</p></div></div>' +
      '<div class="chips" role="group" aria-label="Category">' + cats().map(function (c) {
        var n = c === 'All' ? D.products.length : D.products.filter(function (p) { return p.cat === c; }).length;
        return '<button class="chip" type="button" data-cat="' + esc(c) + '" aria-pressed="' + (state.cat === c) + '">' + esc(c) + ' <span>' + n + '</span></button>'; }).join('') + '</div>' +
      '<div class="bar"><input id="q" type="search" placeholder="Search trackers, e.g. engine lock, AIS-140, bike" aria-label="Search trackers">' +
      '<select id="sort" aria-label="Sort"><option value="pop">Popular first</option><option value="low">Price: low to high</option><option value="high">Price: high to low</option></select><span class="count" id="count"></span></div>' +
      '<div class="grid" id="grid"></div>' + help();
    var qi = document.getElementById('q'); qi.value = state.q;
    document.getElementById('sort').value = state.sort; list();
    qi.oninput = function () { state.q = this.value.trim().toLowerCase(); list(); };
    document.getElementById('sort').onchange = function () { state.sort = this.value; list(); };
    view.querySelectorAll('[data-cat]').forEach(function (b) { b.onclick = function () {
      state.cat = b.dataset.cat; view.querySelectorAll('[data-cat]').forEach(function (x) { x.setAttribute('aria-pressed', x === b); }); list(); }; });
  }

  /* ---------- one product ---------- */
  function showProduct(p) {
    var c = D.content, qty = 1, fleet = FLEET.test(p.cat + ' ' + p.name), out = p.stock === 'Out of stock';
    document.title = p.name + ' · Bharat GPS Tracker';
    var md = document.querySelector('meta[name=description]'); if (md) md.content = p.name + ' at ' + inr(p.price) + ' incl. GST with 1 year tracking. ' + (p.descr || '');
    var warranty = p.specs.filter(function (s) { return /warranty/i.test(s[0]); })[0];
    var perks = (p.free_install ? ['Free installation at home'] : []).concat(D.pay.online && D.pay.install ? ['Pay online or at installation'] : D.pay.online ? ['Secure online payment'] : ['Pay at installation'])
      .concat(warranty ? [warranty[1] + ' warranty'] : []).concat(['GST invoice']);
    var wa = 'https://wa.me/' + (c.whatsapp || '') + '?text=' + encodeURIComponent('Hi Bharat GPS, I want to know about ' + p.name + '.');
    var related = D.products.filter(function (x) { return x.id !== p.id && x.cat === p.cat; }).concat(D.products.filter(function (x) { return x.id !== p.id && x.cat !== p.cat && x.popular; })).slice(0, 3);
    view.innerHTML = '<div class="crumb"><a href="index.html">Home</a> / <a href="products.html">Products</a> / ' + esc(p.name) + '</div>' +
      '<div class="pg"><div class="gal"><div class="big" id="big">' + badge(p) + pic(p) + '</div>' +
      (p.images.length > 1 ? '<div class="thumbs" role="group" aria-label="Photos">' + p.images.map(function (im, i) {
        return '<button type="button" data-img="' + i + '" aria-label="Photo ' + (i + 1) + '" aria-current="' + (i === 0) + '"><img src="' + esc(im) + '" alt=""></button>'; }).join('') + '</div>' : '') + '</div>' +
      '<div class="info"><span class="cat">' + esc(p.cat) + '</span><h1>' + esc(p.name) + '</h1>' +
      (c.stat_rating ? '<div class="rate"><b aria-hidden="true">★★★★★</b>' + esc(c.stat_rating) + ' on Google Play · 10K+ app downloads</div>' : '') +
      (p.descr ? '<p class="lead">' + esc(p.descr) + '</p>' : '') +
      '<div class="buy"><div class="pr"><b>' + inr(p.price) + '</b>' + (p.mrp > p.price ? '<s>' + inr(p.mrp) + '</s><span class="off">You save ' + inr(p.mrp - p.price) + '</span>' : '') + '</div>' +
      '<p class="incl">Incl. GST · 1 year tracking included' + (p.renewal ? ' · renewal ' + inr(p.renewal) + '/year' : '') + '</p>' +
      (fleet && !out ? '<div class="qty">Vehicles<div class="step"><button type="button" data-q="-1" aria-label="Fewer">−</button><span id="qn">1</span><button type="button" data-q="1" aria-label="More">+</button></div><span id="qt"></span></div>' : '') +
      '<div class="acts">' + (out ? '<a class="btn btn-o" href="index.html#cbForm" style="grid-column:1/-1">Out of stock · Get a callback</a>' :
        '<button class="btn btn-s" type="button" id="buyBtn">Buy Now</button><a class="btn btn-o" href="' + esc(wa) + '" target="_blank" rel="noopener">Ask on WhatsApp</a>') + '</div>' +
      '<div class="perks">' + perks.map(function (x) { return '<div>' + TICK + esc(x) + '</div>'; }).join('') + '</div>' +
      (p.stock === 'Made to order' ? '<p class="incl" style="margin:0">Made to order: installed within a few days of booking. We will call you with the date.</p>' : '') + '</div></div></div>' +
      (p.features.length ? '<div class="sec"><h2>What it does</h2><ul class="feat">' + p.features.map(function (x) { return '<li>' + TICK + esc(x) + '</li>'; }).join('') + '</ul></div>' : '') +
      (p.specs.length ? '<div class="sec"><h2>Specifications</h2><div class="tw"><table>' + p.specs.map(function (r) { return '<tr><td>' + esc(r[0]) + '</td><td>' + esc(r[1]) + '</td></tr>'; }).join('') + '</table></div></div>' : '') +
      (p.box.length ? '<div class="sec"><h2>What is in the box</h2><div class="box">' + p.box.map(function (b) { return '<span>' + esc(b) + '</span>'; }).join('') + '</div></div>' : '') +
      (related.length ? '<div class="sec"><h2>You may also like</h2><div class="rel">' + related.map(card).join('') + '</div></div>' : '') +
      '<p class="backp"><a class="back" href="products.html">← All products</a></p>';
    function upd() { document.getElementById('qn').textContent = qty; document.getElementById('qt').textContent = qty > 1 ? 'Total ' + inr(p.price * qty) : ''; }
    view.querySelectorAll('[data-q]').forEach(function (b) { b.onclick = function () { qty = Math.max(1, Math.min(50, qty + +b.dataset.q)); upd(); }; });
    view.querySelectorAll('[data-img]').forEach(function (b) { b.onclick = function () {
      view.querySelectorAll('[data-img]').forEach(function (x) { x.setAttribute('aria-current', x === b); });
      var im = document.getElementById('big').querySelector('img'); if (im) im.src = p.images[+b.dataset.img]; }; });
    var bb = document.getElementById('buyBtn'); if (bb) bb.onclick = function () { B.openOrder(p.id, qty); };
  }

  function start(r) {
    B = window.BGT;
    if (!r || !B) { view.innerHTML = '<div class="empty" style="padding:80px 10px">Could not load the products. Please refresh the page, or call 984 984 9824.</div>'; return; }
    D = r; var id = +param('p');
    if (id) { var p = D.products.filter(function (x) { return x.id === id; })[0];
      if (p) showProduct(p); else view.innerHTML = '<div class="empty" style="padding:80px 10px">This tracker is no longer available. <a href="products.html" style="color:var(--saffron);font-weight:700">See all products</a></div>'; }
    else showList();
  }
  if (window.BGT && window.BGT.data) start(window.BGT.data);
  else document.addEventListener('bgt-data', function (e) { start(e.detail); }, { once: true });
})();
