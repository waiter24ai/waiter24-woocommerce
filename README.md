# Waiter24 AI Assistant for WooCommerce

Developer documentation for the WordPress plugin that syncs a WooCommerce
catalog to a Waiter24 tenant and embeds the AI chat widget. This is the
canonical, version-controlled copy.

> **User-facing documentation lives in [`readme.txt`](readme.txt)** — that is the
> file WordPress.org renders on the plugin page. Keep the two in sync: the
> changelog, the version and the feature list appear in both.
>
> **Releasing:** the submission runbook (`PUBLISHING.md`) and the directory
> assets (`.wordpress-org/` — icon, banner, screenshots) are **deliberately kept
> out of this repository**, which is public and doubles as the plugin's download
> location. They live alongside this checkout, untracked, and go straight into
> the SVN `assets/` directory at release time. Ask the maintainer if you need
> them.

Uses the shared import endpoint (`POST /api/integrations/menu`, Bearer = tenant
import token) and the same JSON schema as the Shopify, Magento and Shopware
integrations (see [`examples/menu-import-sample.json`](examples/menu-import-sample.json)).

| | |
|---|---|
| WordPress.org slug | `waiter24-ai-assistant-for-woocommerce` |
| Text domain | `waiter24-ai-assistant-for-woocommerce` — **must** equal the slug |
| Main file | `waiter24-ai-assistant-for-woocommerce.php` |
| Admin page slug | `waiter24-export` (kept from earlier versions; existing installs have it bookmarked) |
| Option keys | `waiter24_export_settings`, `waiter24_export_last_run` |
| Cron hook | `waiter24_scheduled_export` |
| License | GPLv2 or later |

## What it does

- **Export** (`w24_public_product_ids` → `w24_build_item`): builds the neutral
  menu JSON (categories/subcategories, variations, sale prices, stock,
  WooCommerce cart selectors in `site_config`) for the products a shopper can
  actually reach. `publish` status is not enough on its own — three more sets
  are subtracted as id lists (cheaper than loading products to ask them):
  catalog-visibility **hidden** (`exclude-from-catalog` *and*
  `exclude-from-search`), **password-protected**, and **out of stock** when
  `woocommerce_hide_out_of_stock_items` is on. That last one applies even in
  Simple Stock Mode: that toggle governs how availability is reported, not
  whether the store shows the product. Stores that need their own rule have
  `waiter24_export_product_ids`. Variations come from
  `get_available_variations()`, which already drops disabled ones.
- **Push** (`w24_save_and_notify`): `POST`s the JSON to the import endpoint with
  `Authorization: Bearer <import_token>`. The outcome (time, product count,
  error message) is stored in `waiter24_export_last_run` and shown on the
  settings page — a 401 usually means the Import Token and the Unique Key were
  swapped.
- **Sync**: a manual **Export Now** button, plus an optional WP-Cron schedule
  (daily / weekly / monthly). `export_period` defaults to `off` and **no cron
  event exists while it is off** (`w24_ensure_cron()` clears any leftover) — a
  fresh install must never push a catalog nobody asked it to push. Stores set up
  before 1.12.0 have an explicit period saved, so the new default never reaches
  them and their schedule survives the upgrade. When on, the schedule is created
  one hour out, never at `time()`, so switching it on cannot fire an export
  before the token has been entered.
- **Batching** (`w24_start_export` → `w24_run_export_chunk` → `w24_finalize_export`):
  the catalog is never built inside the request that asks for it — that is what
  produced "504 Gateway Timeout" on big stores. `w24_start_export()` pins the
  catalogue to an id list (`waiter24_export_queue`); slices walk it by offset, so
  a product added or deleted mid-export cannot shift the paging and make a slice
  skip — and a skipped product would be hidden when the session closes. Each
  slice builds for `waiter24_export_batch_seconds` (12) up to
  `waiter24_export_batch_size` (50) products, **whichever comes first**: a fixed
  product count is a bet on host speed, and on shared hosting that bet loses to
  `max_execution_time` (a real store built ~2 products/second, so 50 products
  meant a 28-second request). One slice is one Action Scheduler action and one
  short POST carrying `import_session` + `chunk`; the closing POST sends
  `final: true` and no items, which is the only call that lets Waiter24 hide
  products the store dropped. Progress lives in `waiter24_export_progress`;
  a run whose slices stop arriving for 10 minutes is considered dead so
  **Export Now** unblocks itself.
- **Two runners, one export.** Action Scheduler only moves if the site's
  background tasks fire, and on many hosts they do not (WP-Cron off, loopback
  blocked) — the action then sits "pending" and nothing is exported. So every
  admin page polls `wp_ajax_waiter24_export_step` (`w24_print_export_driver()`
  on `admin_footer`) and, when the queue has not advanced the export for
  `W24_EXPORT_HANDOFF_SECONDS`, runs the next slice inside that AJAX request —
  the handoff is one-way (`progress['driver']`), or every slice would wait out
  the window again. They cannot collide: `w24_run_export_chunk()` runs only the
  slice `waiter24_export_progress` is waiting for, so a late queue runner exits
  instead of rewinding the counter.
- **Schedule without cron.** The recurring export is a WP-Cron event, so the
  same dead-cron site would never sync unattended. `w24_maybe_run_missed_schedule()`
  (`admin_init`) treats an event overdue by more than an hour as "WP-Cron is
  dead" and starts the export right there — but only while automatic sync is on:
  with it off there is no schedule to catch up on, and an admin page load must
  never start an export on its own — starting is two option writes, the
  catalogue work happens in the AJAX driver above, so no admin page load is
  slowed. The schedule is pushed forward *before* the attempt, so a failing
  start cannot retry on every page load. `w24_cron_notice()` states the
  situation on the settings screen and points at the real fix (a system cron
  calling `wp-cron.php`).
- **Widget**: enqueues `widget.js` with the public widget key in the footer;
  `script_loader_tag` adds `defer`, `data-key` and (in demo mode)
  `data-demo-param`. In demo mode the enqueue is skipped entirely unless the
  request carries `?waiter24_demo=1`, and that response defines
  `DONOTCACHEPAGE` so a page cache cannot store it.
- **Site cart**: the export announces `ajax_add_url` (WC AJAX `add_to_cart`) and
  `cart_read_url` (Store API `wc/store/v1/cart`) in `site_config`, so the chat
  widget adds to — and reads — the real WooCommerce cart with no page reload.
- **Add-ons**: quantity-priced extras ride one additive POST field,
  `waiter24_addons`. See `docs/addons.md` in the main waiter-saas repo for the
  cross-platform contract.

## Add-on price handling (read before touching it)

Add-on `{name, price, qty}` triples arrive **from the shopper's browser** and are
untrusted. `w24_sanitize_addons()` is the only gate:

- prices are clamped to `>= 0` — a negative price would discount the cart line
  and let a visitor check out below list price;
- `qty` is clamped to 1–99, names to 120 characters, and the list to 20 entries
  per line, so a crafted request cannot bloat the cart session;
- everything is re-normalized on session load, because a session may hold data
  written by an older version.

The residual limitation: the plugin has no local add-on catalog to compare
against, so a shopper can still *understate* an add-on's price. A store that
cares can validate against its own source of truth via the
`waiter24_cart_line_addons` filter. Authoritative validation for orders placed
through Waiter24 itself happens server-side (`sanitizeAddonPrices()`).

The line price is set **absolutely** from `w24_base_price` (stashed once per
request in `woocommerce_add_cart_item` / `woocommerce_get_cart_item_from_session`),
never additively — `woocommerce_before_calculate_totals` can fire several times
in one request, and `get_price() + addons` compounds the surcharge on each pass.

## Filters

| Filter | Default | Purpose |
|--------|---------|---------|
| `waiter24_export_image_size` | `waiter24_thumb` | Registered image size used for `photo_url`. The plugin's own 150×150 size, because WordPress's `thumbnail` is whatever the store set in Settings → Media — 768px on a real store we debugged. Missing sub-sizes are generated on the spot; see `w24_product_image_url()` for the whole chain and why `$is_intermediate` is not trusted. |
| `waiter24_register_image_size` | `true` | Whether to register `waiter24_thumb` at all. Off saves one small file per upload site-wide, at the cost of exporting whatever sub-size the store happens to have. |
| `waiter24_generate_missing_image_sizes` | `true` | Whether the export may generate an image size the store never made. Off falls through to the smallest sub-size the store already has. |
| `waiter24_export_batch_size` | `200` | Products fetched per page during export (clamped 1–500). |
| `waiter24_cart_line_addons` | — | Accept/reject/reprice add-ons before they touch a cart line. |
| `waiter24_max_addons_per_line` | `20` | Cap on add-ons per cart line. |
| `waiter24_max_addon_price` | `0` (no cap) | Optional per-add-on price ceiling. |

## Settings (WooCommerce → Waiter24 AI Assistant)

| Field | Meaning |
|-------|---------|
| **Unique Key** | Public **widget key** — loads the chat widget. |
| **Import Token** | Secret token (Waiter24 dashboard → Site Integration → Menu auto-import). Authenticates the menu push. |
| **Automatic Sync** | Off (default) / Daily / Weekly / Monthly. Off means no WP-Cron event exists and only **Export Now** sends the catalog. |
| **Enable Chat Widget** | Inject the widget on the storefront. |
| **Demo Mode** | Narrows **Enable Chat Widget** to demo links: the script is printed only on requests carrying `?waiter24_demo=1`, so regular visitors get a page without it. The settings page shows a ready demo link. Links the assistant opens keep the parameter; a page opened without it has no chat. |
| **Simple Stock Mode** | Always export products as in-stock. |

## Requirements

- WordPress 6.5+ (`Requires Plugins` needs 6.5) with **WooCommerce 7.0+** active
- PHP 7.4+ (8.x recommended)
- Outbound HTTPS from the store to `https://waiter24.ai`

## Local development

`W24_IMPORT_URL` and `W24_WIDGET_URL` (top of the main file) point at the
Waiter24 host. **This build ships production defaults** — both point at
`https://waiter24.ai` and TLS verification is on (`sslverify => true`).

For local testing against an OSPanel dev host, override both constants to
`http://waiter.loc` and set `sslverify => false`, since the dev host has no
certificate. Never ship that build.

## Repo layout

```
waiter24-ai-assistant-for-woocommerce.php   the plugin
uninstall.php                               delete-time cleanup
readme.txt                                  WordPress.org page (user-facing)
LICENSE                                     GPLv2
languages/                                  .pot + de/fr/uk .po/.mo
bin/build-zip.php                           release ZIP builder
examples/                                   sample import payload (not shipped)
```

Only the first five entries end up in the distributed ZIP — see
[`.distignore`](.distignore) and `bin/build-zip.php`.

Untracked, kept locally next to the checkout (see `.gitignore` for why):

```
.wordpress-org/                             directory icon/banner/screenshots
PUBLISHING.md                               submission + release runbook
```

## Changelog

The user-facing changelog is maintained in [`readme.txt`](readme.txt) (that is
what WordPress.org renders). Summary of the current release:

- **1.14.0** — photos export small for real: `$is_intermediate` from
  `wp_get_attachment_image_src()` is no longer treated as evidence (it comes
  from the `image_downsize` filter, and themes answer it with the original file
  flagged as a thumbnail), the target size is the plugin's own `waiter24_thumb`
  rather than the store-configurable `thumbnail`, and the fallback picks the
  smallest sub-size **by measured pixels** instead of by name. The settings page
  reports how many photos went out full size, and the version is shown there and
  sent with every export (`client.version`).
- **1.12.0** — automatic sync is a setting of its own and off by default, so a
  fresh install exports nothing until **Export Now** is pressed (existing
  schedules are untouched); photos export at `thumbnail` size, and a sub-size
  the store never generated is created instead of silently falling back to the
  full-size original.
- **1.11.0** — only publicly reachable products are exported (hidden,
  password-protected and — where the store hides them — out-of-stock products
  are left out); `waiter24_export_product_ids` filter.
- **1.10.3** — slices are bounded by seconds, not by product count (a slow host
  was killed by `max_execution_time` half way through); catalogue pinned to an
  id snapshot; the admin driver retries a died batch instead of stopping
  silently; progress reads "X of Y".
- **1.10.2** — missed schedules run from wp-admin on sites with no working
  WP-Cron; an export in progress continues from any admin screen; the settings
  page says when WP-Cron is dead.
- **1.10.1** — the settings page drives the export when the site's background
  queue does not (pending action, zero products exported); live counter instead
  of a page reload; batch size 100 → 50.
- **1.10.0** — the export runs in the background (Action Scheduler) and pushes
  the catalog in batches of 100 products under one `import_session`, ending with
  a `final` call. Fixes "504 Gateway Timeout" on stores too large to export
  inside one request; a half-finished run now hides nothing.
- **1.9.0** — Demo Mode enforced server-side: the widget script is not printed
  at all without `?waiter24_demo=1` (previously it loaded everywhere and hid
  itself, which a page cache or a script optimizer could defeat). The demo
  response is marked non-cacheable; the session-wide memory of the demo is gone.
- **1.8.0** — first WordPress.org release. Relicensed **GPLv2 or later**.
  Security: client-supplied add-on prices are clamped so they can never discount
  a cart line, with qty/name/count bounds. Fixed the add-on surcharge being
  applied more than once per request. Add-ons are now copied onto the order line
  item. HPOS compatibility declared. Widget moved to `wp_enqueue_script`. Export
  paged for large catalogs. Stopped writing a catalog copy into
  `wp-content/uploads/` (the settings page reports the last run instead). The
  activation schedule no longer fires immediately, and cron rescheduling moved
  out of the sanitize callback.
- **1.7.0** — Settings link on the Plugins row; uninstall cleanup.
- **1.6.0** — add-ons.
- **1.5.0** — site-cart integration (`ajax_add_url`, `cart_read_url`), surfaced
  import errors, de/fr/uk translations.
- **1.4.0** — Demo Mode.
- **1.3.1** — dropped unused item fields; production endpoints by default.
- **1.2.0** — Bearer import-token push replaces the old `GET /export/?key=`.
- **1.1.0** — file export + widget injection.
