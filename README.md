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

- **Export** (`w24_run_export` → `w24_build_item`): reads published products in
  pages of 200 and builds the neutral menu JSON (categories/subcategories,
  variations, sale prices, stock, WooCommerce cart selectors in `site_config`).
  Paging is what keeps a multi-thousand-product catalog from exhausting memory.
- **Push** (`w24_save_and_notify`): `POST`s the JSON to the import endpoint with
  `Authorization: Bearer <import_token>`. The outcome (time, product count,
  error message) is stored in `waiter24_export_last_run` and shown on the
  settings page — a 401 usually means the Import Token and the Unique Key were
  swapped.
- **Sync**: scheduled via WP-Cron (daily / weekly / monthly) plus a manual
  **Export Now** button. The schedule is created one hour out, never at `time()`,
  so activation cannot fire an export before the token has been entered.
- **Batching** (`w24_start_export` → `w24_run_export_chunk` → `w24_finalize_export`):
  the catalog is never built inside the request that asks for it — that is what
  produced "504 Gateway Timeout" on big stores. Each slice (50 products,
  `waiter24_export_batch_size`) is one Action Scheduler action and one short
  POST carrying `import_session` + `chunk`; the closing POST sends
  `final: true` and no items, which is the only call that lets Waiter24 hide
  products the store dropped. Progress lives in `waiter24_export_progress`;
  a run whose slices stop arriving for 10 minutes is considered dead so
  **Export Now** unblocks itself.
- **Two runners, one export.** Action Scheduler only moves if the site's
  background tasks fire, and on many hosts they do not (WP-Cron off, loopback
  blocked) — the action then sits "pending" and nothing is exported. So the
  settings page polls `wp_ajax_waiter24_export_step` and, when the queue has
  not advanced the export for `W24_EXPORT_HANDOFF_SECONDS`, runs the next slice
  inside that AJAX request. They cannot collide: `w24_run_export_chunk()` runs
  only the slice `waiter24_export_progress` is waiting for, so a late queue
  runner exits instead of rewinding the counter.
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
| `waiter24_export_image_size` | `medium` | Registered image size used for `photo_url`. |
| `waiter24_export_batch_size` | `200` | Products fetched per page during export (clamped 1–500). |
| `waiter24_cart_line_addons` | — | Accept/reject/reprice add-ons before they touch a cart line. |
| `waiter24_max_addons_per_line` | `20` | Cap on add-ons per cart line. |
| `waiter24_max_addon_price` | `0` (no cap) | Optional per-add-on price ceiling. |

## Settings (WooCommerce → Waiter24 AI Assistant)

| Field | Meaning |
|-------|---------|
| **Unique Key** | Public **widget key** — loads the chat widget. |
| **Import Token** | Secret token (Waiter24 dashboard → Widget Settings → Menu auto-import). Authenticates the menu push. |
| **Export Period** | WP-Cron frequency. |
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
