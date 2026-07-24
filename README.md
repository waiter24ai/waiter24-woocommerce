# Waiter24 — WooCommerce plugin (`waiter24-export`)

Exports the WooCommerce catalog to a Waiter24 tenant and embeds the AI chat
widget. This is the canonical, version-controlled copy of the plugin that runs
inside a WordPress/WooCommerce store.

Uses the shared import endpoint (`POST /api/integrations/menu`, Bearer = tenant
import token) and the same JSON schema as the Shopify, Magento and Shopware
integrations (see [`examples/menu-import-sample.json`](examples/menu-import-sample.json)).

## What it does

- **Export** (`w24_run_export`): reads published products and builds the neutral
  menu JSON (categories/subcategories, variations, sale prices, stock,
  WooCommerce cart selectors in `site_config`).
- **Push** (`w24_save_and_notify`): writes a local copy and `POST`s the JSON to
  the import endpoint with `Authorization: Bearer <import_token>`. Failures are
  surfaced on the settings page with the endpoint's own message (a 401 usually
  means the Import Token and the Unique Key were swapped).
- **Sync**: scheduled via WP-Cron (daily / weekly / monthly) plus a manual
  **Export Now** button.
- **Widget**: injects `widget.js` with the public widget key before `</body>`.
- **Site cart**: the export announces `ajax_add_url` (WC AJAX `add_to_cart`) and
  `cart_read_url` (Store API `wc/store/v1/cart`) in `site_config`, so the chat
  widget adds to — and reads — the real WooCommerce cart with no page reload.
- **Add-ons**: quantity-priced dish extras ride one additive POST field,
  `waiter24_addons`; three filters attach them to the cart line, fold their
  price into the line total and display them under the item. See
  `docs/addons.md` in the main waiter-saas repo.

## Settings (WooCommerce → Waiter24 Export Woo)

| Field | Meaning |
|-------|---------|
| **Unique Key** | Public **widget key** — loads the chat widget. |
| **Import Token** | Secret token (Waiter24 dashboard → Widget Settings → Menu auto-import). Authenticates the menu push. |
| **Export Period** | WP-Cron frequency. |
| **Enable Chat Widget** | Inject the widget on the storefront. |
| **Demo Mode** | Hide the widget from regular visitors; it loads only on URLs carrying the `?waiter24_demo=1` parameter. The settings page shows a ready demo link (opens in a new tab). The parameter is remembered for the browsing session and re-applied to in-chat links, so the chat stays visible while clicking around. |
| **Simple Stock Mode** | Always export products as in-stock. |

## Requirements

- WordPress 6.0+ with **WooCommerce 7.0+** active
- PHP 7.4+ (8.x recommended)
- Outbound HTTPS from the store to `https://waiter24.ai`

## Install

1. Copy this repository into `wp-content/plugins/waiter24-export/` (so
   `waiter24-export.php` sits directly inside it — not in a nested subfolder).
   Alternatively, zip the folder and upload it via **Plugins → Add New → Upload
   Plugin**.
2. **Plugins → Installed Plugins → Waiter24 — export WooCommerce → Activate.**
3. Open **WooCommerce → Waiter24 Export Woo** and fill in:
   - **Unique Key** — the public widget key (Waiter24 dashboard → Widget Settings).
   - **Import Token** — the secret token (Widget Settings → Menu auto-import).
4. Press **Export Now** to push the catalog immediately and verify the
   connection; the WP-Cron schedule keeps it in sync afterwards.
5. Tick **Enable Chat Widget** to load the assistant on the storefront.

## Configuration constants

`W24_IMPORT_URL` and `W24_WIDGET_URL` (top of `waiter24-export.php`) point at the
Waiter24 host. **This build ships production defaults** — both constants point at
`https://waiter24.ai` and TLS verification is on (`sslverify => true` in
`w24_save_and_notify()`).

For local testing against an OSPanel dev host, override both constants to
`http://waiter.loc` and set `sslverify => false`, since the dev host has no
certificate. Don't ship that build to a store.

## Changelog

- **1.7.0** — **Settings link** on the Plugins list row (jumps straight to
  WooCommerce → Waiter24 Export Woo). **Uninstall cleanup** (`uninstall.php`):
  deleting the plugin now removes `waiter24_export_settings`, the cron schedule
  and the local export file — previously the saved Unique Key / Import Token
  survived a delete-and-reinstall and reappeared pre-filled in the form.
- **1.6.0** — **add-ons**: quantity-priced dish extras sent as `waiter24_addons`
  alongside the add-to-cart call, attached to the cart line, priced into the line
  total and rendered under the item in cart/checkout. Drop the dead
  `cart_counter_selector` from the exported `site_config` (the widget never read
  it).
- **1.5.0** — **site-cart integration**: export `ajax_add_url` (resolved via
  `WC_AJAX::get_endpoint()` so subdirectory installs work) and `cart_read_url`
  (Store API) in `site_config`, letting the widget add to and read the real cart
  without a reload. Surface import errors on the settings page instead of a
  generic failure, warn that the export replaces the primary menu, and add
  translations (de/fr/uk).
- **1.4.0** — add **Demo Mode**: the widget loads only on URLs carrying the
  `?waiter24_demo=1` parameter (with a ready demo link on the settings page),
  letting you preview the assistant while keeping it hidden from shoppers.
- **1.3.1** — drop the unused `stock_status` / `qty` item fields (not in the
  import schema); Simple Stock Mode now drives the exported `is_available`
  directly; default the endpoint/widget URLs to production and verify TLS on the
  push (`sslverify => true`).
- **1.2.0** — push the full catalog to `POST /api/integrations/menu` with a
  Bearer **import token** (replaces the old `GET /export/?key=` notify); add the
  Import Token setting; keep Unique Key as the public widget key.
- **1.1.0** — file export + widget injection + `GET` notify.
