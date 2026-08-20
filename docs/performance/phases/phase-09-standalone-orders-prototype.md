# Phase 09 — Standalone orders prototype

Date: 2026-08-20
Candidate: RIPEX Portal 1.5.17

## Goal

Measure how much latency is caused by the normal WordPress/theme/plugin bootstrap by comparing the existing RIPEX orders endpoint with a read-only standalone path that does not load OceanWP, Elementor, WooCommerce frontend code or normal WordPress plugins.

This phase is an experiment, not a production migration.

## Safety boundary

The prototype is disabled by default. Enable it only in staging:

```php
define('RIPEX_PORTAL_STANDALONE_PROTOTYPE', true);
```

Only a logged-in `ripex_admin` can mint the short-lived launch token. The prototype API accepts only that signed token and is SELECT-only.

No order, customer, product, stock, cart, option, transient or role writes are performed by the standalone API.

The existing `/portal-pedidos/` UI and all existing AJAX endpoints remain unchanged.

## Architecture

1. The normal authenticated portal runs inside WordPress.
2. `Probar portal liviano` requests a five-minute HMAC-signed token through authenticated `admin-ajax.php`.
3. A separate standalone shell is opened from the plugin directory. The token is passed in the URL fragment, read by JavaScript and immediately removed from the visible URL/history.
4. `standalone/api.php` defines `SHORTINIT` and loads `wp-load.php` only to obtain the configured database connection and WordPress salts.
5. The API does not load themes, plugins, WooCommerce objects or the normal WordPress lifecycle.
6. The first prototype endpoint reads legacy `shop_order` data directly from WordPress/WooCommerce tables using prepared SELECT queries.

## Current prototype scope

- Role: `ripex_admin` only.
- Read-only orders list.
- 30 rows per page.
- Pagination.
- Current customer AFREG metadata takes precedence over RIPEX order snapshot metadata for RUT, razón social and vendedor, matching the existing read semantics where practical.
- Billing name/company, status, payment title, export marker, total, currency and first shipping item are returned.
- The prototype does not create/update orders and intentionally has no edit controls.
- The prototype targets the current legacy `shop_order` storage. HPOS remains a separate future compatibility phase.

## Built-in measurements

Every standalone API response reports:

- total request milliseconds;
- SHORTINIT/bootstrap milliseconds;
- `$wpdb` query count;
- PHP allocated peak MiB.

These values are displayed above the orders table so the same browser session can compare the existing portal and the standalone prototype.

## Staging validation

1. Install candidate 1.5.17 in staging only.
2. Enable `RIPEX_PORTAL_STANDALONE_PROTOTYPE` in staging.
3. Open `/portal-pedidos/` as a `ripex_admin`.
4. Confirm the existing portal still behaves exactly as before.
5. Click `Probar portal liviano`.
6. Confirm the standalone page loads without OceanWP/Elementor/WooCommerce frontend assets.
7. Compare the first 30 order rows against the existing Pedidos page for IDs, dates, customer/company, status, payment, vendedor, export marker, total and shipping.
8. Record the standalone `API total`, `SHORTINIT`, query count and PHP peak values over at least five refreshes.
9. Do not authorize a production migration until parity and authorization rules are expanded to vendor/bodega roles and write operations have a separate safe architecture.

## Rollback

Remove or set to false:

```php
define('RIPEX_PORTAL_STANDALONE_PROTOTYPE', true);
```

With the flag disabled the launcher is absent and token minting returns a disabled response. Existing RIPEX portal behavior is unchanged.
