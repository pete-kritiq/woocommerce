=== KRITIQ Pixel for WooCommerce ===
Contributors: kritiq
Tags: woocommerce, pixel, conversion tracking, ad attribution, analytics
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See what your ads and creatives do on your store. Consent-aware ecommerce events for KRITIQ, set up in one click.

== Description ==

KRITIQ scores your video ads before you spend, then shows how each one performed. This plugin connects your WooCommerce store to KRITIQ so the second half works: every product view, add to cart, checkout and purchase is attributed to the ad that brought the visitor.

* **One-click setup.** Click "Connect to KRITIQ", sign in or start a free trial, pick your brand. The pixel is created and installed for you.
* **Ecommerce events out of the box:** PageView, ViewContent, AddToCart (classic and block cart, AJAX buttons), InitiateCheckout and Purchase with order value, currency and order number.
* **Purchases you would otherwise miss.** Paid orders are also sent from your server, so purchases still count when an ad blocker or a skipped thank-you page stops the browser. Each order is counted once.
* **Consent first.** Nothing is stored in the browser and nothing is sent until the visitor accepts statistics cookies. Works with the WP Consent API (Complianz, CookieYes, Cookiebot and others).
* **Private by design.** No IP addresses, names, emails or phone numbers are stored. Only the page path, referrer domain, ad click, an anonymous browser ID, and order value and number.
* Compatible with High-Performance Order Storage, the Cart and Checkout blocks, and full-page caching.

== External services ==

This plugin connects to KRITIQ (https://kritiq.app), operated by KRITIQ (Pty) Ltd, South Africa.

* **The KRITIQ pixel script** (https://kritiq.app/kq.js) is loaded on your store's pages for visitors, except shop managers. After the visitor consents (when consent is required, the default), it sends the event name, page URL without query string, referrer, ad click parameters (utm_source, utm_medium, utm_campaign, kq_ad, and whether fbclid, ttclid or gclid was present), an anonymous random browser ID, and for purchases the order value, currency and order number, to https://kritiq.app/api/pixel/collect.
* **Server-side purchases** (only when your store is connected and the visitor consented): when an order is paid, your server sends the order value, currency, order number, and the visitor's anonymous browser ID and ad click to https://kritiq.app/api/stores/events.
* **Connecting your store** sends your store's domain, name and currency to https://kritiq.app/connect/store, and your admin's browser signs in there. Your server then exchanges a one-time code at https://kritiq.app/api/stores/token and reads connection status from https://kritiq.app/api/stores/me.

Privacy policy: https://kritiq.app/legal/privacy · Terms: https://kritiq.app/legal/terms

You are responsible for telling your visitors about this in your cookie notice and privacy policy.

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. Go to WooCommerce → KRITIQ and click **Connect to KRITIQ**.
3. Open your store in a private window, accept cookies, and add something to the cart. Events show in KRITIQ → Media → Pixel within a few minutes.

Prefer not to connect? Paste a Pixel ID from KRITIQ → Media → Pixel instead. Browser events work; server-side purchases need the connection.

== Frequently Asked Questions ==

= My cookie banner doesn't support the WP Consent API =

Choose "I will call kq('consent', 'granted') from my own banner" and call `kq('consent', 'granted')` (or `'denied'`) from your banner's accept and reject callbacks. You can also dispatch `window.dispatchEvent(new CustomEvent('kritiq:consent', { detail: 'granted' }))`.

= How do I track leads? =

Call `kq('track', 'Lead')` when a form is submitted successfully.

= Can I change the purchase value? =

Choose order total or products after discounts in the settings, or use the `kritiq_pixel_order_value` filter.

= Developer filters =

`kritiq_pixel_should_track`, `kritiq_pixel_config`, `kritiq_pixel_page_events`, `kritiq_pixel_order_value`, `kritiq_pixel_server_event`, `kritiq_pixel_app_url` (point to a staging KRITIQ).

== Changelog ==

= 1.0.0 =
* First release.
