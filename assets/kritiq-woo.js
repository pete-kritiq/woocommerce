/*! KRITIQ Pixel for WooCommerce. Loads kq.js and fires ecommerce events; kq.js handles consent and sending. */
(function (w, d) {
  'use strict';
  var C = w.kritiqWoo;
  if (!C || !C.pixelId) return;

  w.kq = w.kq || function () { (w.kq.q = w.kq.q || []).push(arguments); };
  var s = d.createElement('script');
  s.async = true;
  s.src = C.src;
  (d.head || d.documentElement).appendChild(s);

  kq('init', C.pixelId, { requireConsent: C.requireConsent });
  kq('track', 'PageView');

  function fire(e) {
    if (!e || !e.e) return;
    var props = {};
    if (typeof e.v === 'number') props.value = e.v;
    if (e.c) props.currency = e.c;
    if (e.oid) props.orderId = e.oid;
    kq('track', e.e, props);
  }
  (C.events || []).forEach(fire);

  // ----- Add to cart: queued server-side, fetched here (works with page caching) -----------
  function flush() {
    if (!w.fetch) return;
    w.fetch(C.eventsUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : { events: [] }; })
      .then(function (b) { (b.events || []).forEach(fire); })
      .catch(function () {});
  }
  if (/(?:^|;\s*)kq_pending=1/.test(d.cookie)) flush();
  if (w.jQuery) w.jQuery(d.body).on('added_to_cart', function () { setTimeout(flush, 50); });
  d.addEventListener('wc-blocks_added_to_cart', function () { setTimeout(flush, 300); });

  // ----- Consent -----------------------------------------------------------------------------
  // Context cookie for server-side purchases: written only after consent, removed on refusal.
  function ls(key) {
    try { var v = w.localStorage.getItem(key); return v ? JSON.parse(v) : null; } catch (e) { return null; }
  }
  function writeContext() {
    var aid = ls('_kq_id');
    if (!aid) return;
    var val = encodeURIComponent(JSON.stringify({ aid: aid, attr: ls('_kq_attr') }));
    d.cookie = '_kq_ctx=' + val + '; path=/; max-age=' + 30 * 86400 + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }
  function clearContext() {
    d.cookie = '_kq_ctx=; path=/; max-age=0; SameSite=Lax';
  }
  var granted = false;
  function grant() {
    if (granted) return;
    granted = true;
    kq('consent', 'granted');
    setTimeout(writeContext, 800); // after kq.js has created the browser ID
  }
  function deny() {
    granted = false;
    kq('consent', 'denied');
    clearContext();
  }
  // Public hooks for any banner: window.dispatchEvent(new CustomEvent('kritiq:consent', { detail: 'granted' }))
  w.addEventListener('kritiq:consent', function (e) { e.detail === 'granted' ? grant() : deny(); });

  if (!C.requireConsent) {
    setTimeout(writeContext, 800);
    return;
  }
  if (ls('_kq_consent') === 'granted') setTimeout(writeContext, 800);
  if (C.consentMode !== 'auto') return;

  var previouslyGranted = ls('_kq_consent') === 'granted';

  // WP Consent API (Complianz, CookieYes, Cookiebot for WP, GDPR Cookie Compliance, ...)
  if (typeof w.wp_has_consent === 'function') {
    if (w.wp_has_consent('statistics')) grant();
    else if (previouslyGranted) deny(); // consent withdrawn since the last visit
    d.addEventListener('wp_listen_for_consent_change', function (e) {
      var c = e.detail || {};
      if (c.statistics === 'allow') grant();
      else if (c.statistics === 'deny') deny();
    });
    return;
  }

  // Cookiebot without the WP Consent API
  function cookiebot() {
    var cb = w.Cookiebot;
    if (!cb || !cb.consent) return;
    if (cb.consent.statistics) grant();
    else if (cb.hasResponse) deny();
  }
  w.addEventListener('CookiebotOnAccept', cookiebot);
  w.addEventListener('CookiebotOnDecline', cookiebot);
  cookiebot();
})(window, document);
