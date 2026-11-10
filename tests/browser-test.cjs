// Runs kritiq-woo.js with the real kq.js in Chromium; checks consent gating, events, add-to-cart flush and the context cookie.
// Usage: node tests/browser-test.cjs assets/kritiq-woo.js path/to/kq.js   (needs playwright)
const { chromium } = require('playwright');
const fs = require('fs');
const assert = require('assert');
const KQ = fs.readFileSync(process.argv[3], 'utf8');
const WOO = fs.readFileSync(process.argv[2], 'utf8');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const sent = [];
  await page.route('https://www.kritiq.app/kq.js', (r) => r.fulfill({ contentType: 'text/javascript', body: KQ }));
  await page.route('https://www.kritiq.app/api/pixel/collect', async (r) => { sent.push(JSON.parse(r.request().postData())); await r.fulfill({ status: 204 }); });
  await page.route('https://shop.example.com/**', async (r) => {
    const u = new URL(r.request().url());
    if (u.searchParams.get('wc-ajax') === 'kritiq_events') return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ events: [{ e: 'AddToCart', v: 998, c: 'ZAR' }] }) });
    if (u.pathname === '/kritiq-woo.js') return r.fulfill({ contentType: 'text/javascript', body: WOO });
    const cfg = { pixelId: 'KQ-ABCDEFGH23', src: 'https://www.kritiq.app/kq.js', requireConsent: true, consentMode: 'auto', eventsUrl: '/?wc-ajax=kritiq_events',
      events: [{ e: 'Purchase', v: 1299.5, c: 'ZAR', oid: '1001' }] };
    return r.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head>
      <script>var __consent=false; window.wp_has_consent=function(){return __consent};</script>
      <script>window.kritiqWoo=${JSON.stringify(cfg)};</script><script src="/kritiq-woo.js"></script></head><body>shop</body></html>` });
  });
  await page.goto('https://shop.example.com/checkout/order-received/1001/?utm_source=facebook&kq_ad=555');
  await page.waitForTimeout(800);
  assert.strictEqual(sent.length, 0, 'nothing before consent');
  const stored = await page.evaluate(() => Object.keys(localStorage));
  assert.deepStrictEqual(stored, [], 'nothing stored before consent');

  await page.evaluate(() => { __consent = true; document.dispatchEvent(new CustomEvent('wp_listen_for_consent_change', { detail: { statistics: 'allow' } })); });
  await page.waitForTimeout(1200);
  const names = sent.map((s) => s.e);
  assert.deepStrictEqual(names, ['PageView', 'Purchase'], 'held events released after consent: ' + names);
  const p = sent[1];
  assert.strictEqual(p.v, 1299.5); assert.strictEqual(p.oid, '1001'); assert.strictEqual(p.attr.source, 'facebook'); assert.strictEqual(p.attr.kqAd, '555');
  const ctx = (await page.context().cookies()).find((c) => c.name === '_kq_ctx');
  assert.ok(ctx, 'context cookie written after consent');
  const parsed = JSON.parse(decodeURIComponent(ctx.value));
  assert.strictEqual(parsed.aid, p.aid); assert.strictEqual(parsed.attr.kqAd, '555');

  // AJAX add to cart via the classic jQuery event name, emulated without jQuery: blocks event
  await page.evaluate(() => document.dispatchEvent(new Event('wc-blocks_added_to_cart')));
  await page.waitForTimeout(800);
  assert.strictEqual(sent.at(-1).e, 'AddToCart'); assert.strictEqual(sent.at(-1).v, 998);

  // Withdraw consent: cookie removed and storage cleared
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('wp_listen_for_consent_change', { detail: { statistics: 'deny' } })));
  await page.waitForTimeout(200);
  assert.ok(!(await page.context().cookies()).find((c) => c.name === '_kq_ctx'), 'context cookie removed');
  assert.strictEqual(await page.evaluate(() => localStorage.getItem('_kq_id')), null);
  console.log('WooCommerce browser test passed:', sent.map((s) => s.e).join(', '));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
