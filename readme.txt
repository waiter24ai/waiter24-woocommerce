=== Waiter24 AI Assistant for WooCommerce ===
Contributors: waiter24
Tags: ai, chatbot, ai assistant, product recommendations, live chat
Requires at least: 6.5
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 1.11.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your WooCommerce catalog to Waiter24 and add an AI chat assistant that answers product questions and fills the real cart.

== Description ==

**Waiter24 AI Assistant for WooCommerce** connects your store to [Waiter24](https://waiter24.ai/), an AI sales-assistant service, and does two things:

1. **Keeps your catalog in sync.** Published products — names, descriptions, prices, sale prices, categories, tags, images, weight, stock and variations — are sent to your Waiter24 account on a schedule (daily, weekly or monthly) or on demand with one button.
2. **Puts the assistant on your storefront.** One checkbox loads the Waiter24 chat widget, which answers questions about your products in your shopper's own language and can add items straight to the **real WooCommerce cart** without a page reload.

The assistant only ever talks about the catalog you sync, so it does not invent products, prices or availability.

= What shoppers can do in the chat =

* Ask what a product is, what is in it, what it costs, whether it is in stock.
* Get suggestions ("something vegetarian under $15", "what goes with this?").
* Add a product — including the exact variation — to the WooCommerce cart, then continue to your normal checkout.
* See the cart update live in your theme's mini-cart.

= Store-owner features =

* **Manual export** — one "Export Now" button, with live progress and the outcome (products sent, or the exact error) reported on the settings page. It runs in the background in batches, so a catalog of any size can neither time out nor exhaust memory.
* **Scheduled sync** via WP-Cron: daily, weekly or monthly.
* **Demo Mode** — the widget script is served only to visitors arriving on a special `?waiter24_demo=1` link; every other page is sent without it, so you can evaluate the assistant on a live store without customers seeing it.
* **Simple Stock Mode** — export everything as in-stock (useful for made-to-order menus), or follow real WooCommerce stock.
* **Add-ons** — quantity-priced extras the assistant can offer ("+2 extra cheese") are attached to the cart line, priced into the line total, and copied onto the order so you can fulfil them.
* Translated into German, French and Ukrainian.

= What it does NOT do =

* It does not process payments or replace your checkout — shoppers finish in WooCommerce as usual.
* It does not read, store or transmit your orders or your customers' account data.
* It does not modify your theme or your product pages.

= External services =

This plugin requires a **Waiter24** account (waiter24.ai) — a third-party, cloud-hosted AI service. The plugin is a client for that service and does not work without it. There is a free trial; paid plans apply afterwards.

**1. Catalog sync (server-to-server).** When you press "Export Now", and on every scheduled run, your site sends an HTTPS `POST` to `https://waiter24.ai/api/integrations/menu`, authenticated with the Import Token you paste into the settings. The request contains, for your published products only:

* product name, description, price, sale price, currency, weight, categories, tags, stock availability, sort order, product ID;
* the product's public permalink and public image URL;
* your store's public cart URL, add-to-cart AJAX URL and Store API cart URL, plus a set of CSS selectors for your theme's add-to-cart controls.

No customer data, order data, user account data or admin credentials are ever included in this request.

**2. Chat widget (visitor's browser).** When "Enable Chat Widget" is on, your storefront loads the script `https://waiter24.ai/widget.js`. This script is the service itself: the chat interface, its behaviour and the AI replies are all produced by Waiter24 and cannot be bundled locally. Once a visitor opens the chat, their browser communicates directly with Waiter24 and transmits:

* the messages the visitor types;
* the page they are on and the product they are viewing;
* a locally generated chat session identifier;
* their IP address and user agent, as with any third-party request;
* optionally, the contents of their WooCommerce cart, if you enable the cart-aware option in your Waiter24 dashboard.

Because visitor data is sent to a third party, disclose Waiter24 in your own privacy policy and obtain consent where your jurisdiction requires it (for example, under the GDPR). The widget script is not sent to the page at all while "Enable Chat Widget" is off, and only on demo links while "Demo Mode" is on.

Waiter24 terms of service: https://waiter24.ai/en/terms
Waiter24 privacy policy: https://waiter24.ai/en/privacy
Waiter24 data processing agreement: https://waiter24.ai/en/dpa

== Installation ==

1. Install and activate **WooCommerce** first — this plugin requires it.
2. Install "Waiter24 AI Assistant for WooCommerce" from **Plugins → Add New**, or upload the ZIP under **Plugins → Add New → Upload Plugin**, then activate it.
3. Create an account at [waiter24.ai](https://waiter24.ai/) if you do not have one.
4. Go to **WooCommerce → Waiter24 AI Assistant** and fill in the two fields:
   * **Unique Key** — the public widget key from your Waiter24 dashboard (Site Integration).
   * **Import Token** — the 48-character secret token from **Site Integration → Menu auto-import**. These two are different values; using the widget key here returns a 401.
5. Choose an **Export Period** and press **Save Settings**.
6. Press **Export Now** to send the catalog immediately and confirm the connection works.
7. Tick **Enable Chat Widget** to show the assistant on your storefront — or tick **Demo Mode** first if you want to preview it privately.

== Frequently Asked Questions ==

= Do I need a Waiter24 account? =

Yes. The plugin is a client for the Waiter24 cloud service; the AI replies are generated there. Without an account and its two keys the plugin has nothing to connect to. A free trial is available.

= Is the plugin free? =

The plugin itself is free and GPL-licensed. The Waiter24 service it connects to is a paid subscription after the trial period.

= What data leaves my site? =

Your published product catalog, plus the public URLs and selectors the chat needs to add to the cart. No orders, no customers, no user accounts. Visitor chat messages go from the visitor's browser to Waiter24. See the "External services" section above for the full list.

= Will the assistant recommend products I do not sell? =

No. It answers from the catalog you sync, and it does not receive anything else about your store.

= Can I try it without customers seeing it? =

Yes — turn on **Demo Mode**. The widget script is then added only to pages requested with `?waiter24_demo=1` in the URL (the settings page gives you a ready link); every other page is served without it, so regular shoppers never load the chat. Links the assistant opens keep the parameter, so the chat stays visible while you browse the demo.

= Which products are exported? =

Only products a shopper can actually reach: published, not password-protected, and not set to catalog visibility "Hidden". If your store hides out-of-stock items, those are left out too. Variable products are exported with their available variations. Drafts, private and trashed products are skipped.

Large catalogs are read and pushed in batches, so the export exhausts neither PHP memory nor the request time limit.

= I have thousands of products and the export times out =

It cannot any more. Since 1.10.0 "Export Now" only *starts* the export: the work is handed to Action Scheduler (the background queue that ships with WooCommerce) and the catalog goes out in small batches, each its own short request. A batch builds for about 12 seconds and sends whatever it managed, so however slow your hosting is, no single request runs long enough to be killed. The settings page shows "X of Y products exported" and the final result.

If your site does not run background tasks at all (WP-Cron disabled, or loopback requests blocked by the host), the scheduled batch would simply stay "pending". That is covered too: when the queue has not moved the export for 15 seconds, the WordPress admin pushes the batches itself. Just stay in the admin until the counter stops — any admin page continues it, not only the plugin's own.

= What happens to the scheduled sync if my host has no cron? =

The daily/weekly/monthly schedule is a WP-Cron event, so on a site where WP-Cron is disabled or blocked it would never fire. Since 1.10.2 the plugin notices an overdue schedule when you open the WordPress admin and runs the missed export from there, in background batches. In practice the catalog then syncs about as often as you sign in, and the settings page tells you this is happening.

For a sync that does not depend on anyone signing in, ask your host to call `wp-cron.php` from a real cron job (most panels offer this) — commonly every 5 minutes. The plugin then uses the schedule normally.

= Does the export delete anything in Waiter24? =

No. It replaces the contents of your primary Waiter24 menu. Items that no longer exist in the store are hidden there, not deleted.

= The export fails with a 401 error. What is wrong? =

The two keys are swapped. **Import Token** is the 48-character secret from *Site Integration → Menu auto-import*; **Unique Key** is the short public widget key. Only the Import Token authenticates the export.

= Nothing happens on schedule. Why? =

The schedule uses WP-Cron, which only runs when your site receives traffic. On a quiet store, or with `DISABLE_WP_CRON` set, trigger a real system cron for `wp-cron.php` — or just use "Export Now" when you change the catalog.

= How does the chat add items to the cart? =

Through WooCommerce's own AJAX add-to-cart endpoint, the same one your theme uses, so any cart plugin, tax rule or coupon logic still applies. The plugin does not implement a cart of its own.

= What are add-ons? =

Optional paid extras (for example "extra cheese ×2") that the assistant can offer while a shopper is ordering. They are attached to the cart line, their cost is added to the line total, and they are written onto the order so your staff can see them. Add-on values arriving from the browser are validated: prices are clamped so they can never reduce a line's price. If you want to verify them against your own list, use the `waiter24_cart_line_addons` filter.

= Can I change which image size is exported? =

Yes, with the `waiter24_export_image_size` filter (defaults to `medium`).

== Screenshots ==

1. The settings page under WooCommerce → Waiter24 AI Assistant: both keys, the sync schedule, and the widget, demo and stock toggles.
2. The assistant's opening screen on the storefront, offering the questions shoppers ask most.
3. The assistant answering a question about current promotions, with matching products and their sale prices shown as cards.
4. A product added to the real WooCommerce cart from inside the chat — the cart total in the site header updates without a page reload.

== Changelog ==

= 1.11.1 =
* Changed: the plugin settings screen and the documentation now point at the "Site Integration" page in your Waiter24 dashboard, which is where the widget key and the import token moved. Text only — nothing about the export or the widget behaves differently.

= 1.11.0 =
* Changed: only products a shopper can actually reach are exported. "Published" is not the same as public in WooCommerce, so three groups are now left out: products whose catalog visibility is **Hidden**, **password-protected** products, and — when "Hide out of stock items" is switched on in WooCommerce → Settings → Products — **out-of-stock** products. Drafts, private and trashed products were skipped before and still are. Products already sent to Waiter24 that now fall outside this set are hidden there on the next export, not deleted.
* Added: `waiter24_export_product_ids` filter for stores that need to decide this themselves.

= 1.10.3 =
* Fixed: on slow hosting the export stopped part-way through — a fixed batch of 50 products took longer to build than PHP's `max_execution_time` allowed, so the request was killed before it could send anything. Batches are now bounded by time rather than by a product count: each one builds for about 12 seconds and sends whatever it managed, so a slow store simply sends fewer products per batch instead of failing. Filterable via `waiter24_export_batch_seconds`.
* Fixed: a batch that died mid-request used to end the export silently. The admin now retries with a backoff — and since batches are time-boxed, the retry asks for less work than the attempt that failed.
* Changed: the catalog is pinned to a list of product ids when the export starts, so products added or deleted while it runs cannot shift the paging and cause a product to be skipped (a skipped product would have been hidden when the import session closed).
* Changed: progress now reads "120 of 480 products exported" instead of a bare running count.

= 1.10.2 =
* Fixed: the *scheduled* export was still dead on sites without working background tasks — it is a WP-Cron event, and WP-Cron was exactly what those sites do not run. An overdue schedule is now picked up on any WordPress admin page: the export starts there and the batches go out over background AJAX calls, so the catalog syncs roughly as often as the store owner signs in. No admin page is slowed down by it — the batches are not built during the page load.
* Changed: an export in progress is now carried on from any admin screen, not only the plugin's settings page. Navigate away and it keeps going.
* Added: the settings page says plainly when WP-Cron is disabled or blocked, and what to ask the host for if unattended sync is wanted.

= 1.10.1 =
* Fixed: on sites whose background tasks never fire — WP-Cron disabled, or loopback requests blocked by the host — the export was queued and then sat there as a pending scheduled action, exporting nothing. The settings page now pushes the batches itself whenever the queue has not moved the export for 15 seconds, so the export completes as long as that page stays open. Where the queue does work, it still does the job and the page only watches.
* Changed: the progress counter updates in place instead of reloading the page, and batches are 50 products (was 100) so one batch fits comfortably inside a slow host's request limit.

= 1.10.0 =
* Fixed: "Export Now" no longer dies with a "504 Gateway Timeout" on a large catalog. The store's web server used to cut the page off while PHP was still reading products; the export now runs in the background on Action Scheduler (WooCommerce's own queue) and pushes the catalog in batches of 100 products, each its own short request. Neither end ever holds the whole catalog. The settings page shows live progress while it runs.
* Changed: batches of one export share an import session; only its closing call tells Waiter24 which products the store no longer sells. A run that dies half way therefore hides nothing — it just leaves the previous menu in place until the next export.
* Changed: the batch size is 100 products (was 200 rows per query in one big push) and still filterable via `waiter24_export_batch_size`.

= 1.9.0 =
* Changed: Demo Mode is now enforced on the server. The widget script is left out of the page entirely unless the URL carries `?waiter24_demo=1`, instead of being loaded everywhere and hidden by the widget itself. A page cache or a script optimizer can no longer leak the widget to regular visitors, and the demo response is flagged as non-cacheable.
* Changed: the demo is no longer remembered for the whole browsing session. Links the assistant opens still carry the parameter; opening a page without it hides the chat again.

= 1.8.0 =
* First release on WordPress.org. Relicensed under GPLv2 or later.
* Security: add-on values arriving from the browser are now clamped — a negative price can no longer reduce a cart line's total, and quantity, name length and add-on count are bounded.
* Fixed: the add-on surcharge could be applied more than once per request, inflating the line total, because `woocommerce_before_calculate_totals` can fire repeatedly. The price is now set absolutely from the product's pristine price.
* Added: add-ons are copied onto the order line item, so the store can see the extras it has to fulfil (previously they were visible only in the cart).
* Added: HPOS (High-Performance Order Storage) compatibility declaration.
* Changed: the chat widget is now registered through `wp_enqueue_script` instead of printed directly into the footer.
* Changed: large catalogs are exported in batches instead of loading every product object at once.
* Changed: the export no longer writes a copy of the catalog into `wp-content/uploads/`. The settings page shows the last run's time, product count and outcome instead. Any leftover file is removed on uninstall.
* Changed: the scheduled export is no longer created to fire immediately on activation (it would run before the Import Token was entered); rescheduling moved out of the settings sanitize callback.
* Fixed: duplicated space in the admin menu label. The widget setting's description now describes where the script is actually loaded, matching the switch to `wp_enqueue_script`.

= 1.7.0 =
* "Settings" quick link on the Plugins list row.
* Uninstall cleanup: deleting the plugin now removes its settings and cron schedule, so a delete-and-reinstall no longer resurfaces an old Unique Key / Import Token.

= 1.6.0 =
* Add-ons: quantity-priced extras sent alongside the add-to-cart call, attached to the cart line and priced into the line total.

= 1.5.0 =
* Site-cart integration: the export announces the AJAX add-to-cart endpoint and the Store API cart endpoint, letting the chat add to and read the real cart with no page reload.
* Import errors are surfaced on the settings page with the endpoint's own message.
* German, French and Ukrainian translations.

= 1.4.0 =
* Demo Mode: the widget loads only on URLs carrying `?waiter24_demo=1`.

= 1.3.1 =
* Simple Stock Mode drives the exported availability directly; production endpoints and TLS verification by default.

= 1.2.0 =
* Push the full catalog to the Waiter24 import endpoint with a Bearer Import Token.

= 1.1.0 =
* Catalog export and chat-widget injection.

== Upgrade Notice ==

= 1.11.1 =
Text-only update: the help text now points at the renamed "Site Integration" page in your Waiter24 dashboard.

= 1.11.0 =
The export is now limited to publicly visible products: hidden, password-protected and (where the store hides them) out-of-stock products are left out.

= 1.10.3 =
Fixes exports that stopped part-way on slow hosting. Batches now adapt to how fast your store actually is.

= 1.10.2 =
Scheduled exports now also work on hosts with no working WP-Cron: they run from the WordPress admin instead of never running at all.

= 1.10.1 =
Fixes exports that stayed stuck at zero products on hosts where WordPress background tasks do not run.

= 1.10.0 =
Large catalogs no longer time out: the export runs in the background and is pushed in batches. Upgrade if "Export Now" ever ended in a Gateway Timeout.

= 1.9.0 =
Demo Mode now hides the widget on the server: the script is not printed at all on pages without the demo parameter. Upgrade if Demo Mode seemed to have no effect on your store.

= 1.8.0 =
Security and correctness release: add-on prices from the browser are now validated, and the add-on surcharge can no longer be applied twice to one cart line. Add-ons are also saved onto the order. The plugin no longer writes a copy of your catalog into wp-content/uploads.
