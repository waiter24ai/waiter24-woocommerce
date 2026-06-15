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
  the import endpoint with `Authorization: Bearer <import_token>`.
- **Sync**: scheduled via WP-Cron (daily / weekly / monthly) plus a manual
  **Export Now** button.
- **Widget**: injects `widget.js` with the public widget key before `</body>`.

## Settings (WooCommerce → Waiter24 Export Woo)

| Field | Meaning |
|-------|---------|
| **Unique Key** | Public **widget key** — loads the chat widget. |
| **Import Token** | Secret token (Waiter24 dashboard → Site Settings → Automatic menu import). Authenticates the menu push. |
| **Export Period** | WP-Cron frequency. |
| **Enable Chat Widget** | Inject the widget on the storefront. |
| **Simple Stock Mode** | Always export products as in-stock. |

## Install

Copy this repository into `wp-content/plugins/waiter24-export/` (so
`waiter24-export.php` sits directly inside it) and activate the plugin in
WordPress admin. Requires WooCommerce.

## Configuration constants

`W24_IMPORT_URL` and `W24_WIDGET_URL` (top of `waiter24-export.php`) point at the
Waiter24 host. Update them for staging vs production.

## Changelog

- **1.2.0** — push the full catalog to `POST /api/integrations/menu` with a
  Bearer **import token** (replaces the old `GET /export/?key=` notify); add the
  Import Token setting; keep Unique Key as the public widget key.
- **1.1.0** — file export + widget injection + `GET` notify.
