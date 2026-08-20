# Phase 07 — cache and explicit invalidation

Date: 2026-08-18  
Status: implemented in candidate `1.5.15`, pending integrated staging validation

## Objective

Reuse expensive but stable RIPEX aggregates without serving cross-role or stale commercial data, and invalidate caches from normal WooCommerce/WordPress mutations instead of relying only on TTL expiration.

This phase is intentionally conservative. It does **not** cache order detail, customer detail, editable forms, order lists, inventory rows, seller ownership sets or any other response where authorization/state freshness is more important than reuse.

## Problems before Phase 07

### Report result cache

Production 1.5.14 already had a 10-minute transient for admin reports, but:

- only admin benefited;
- the key contained role/user/date range but no data version;
- a changed order/product/customer could leave the previous result reachable until TTL expiration;
- seller reports always recalculated;
- changing the report range caused the complete inactive-customer historical pass to run again.

### Inventory category list

Phase 03 paginated inventory rows, but every inventory request still regenerated the complete `product_cat` selector even though category definitions change infrequently.

## Generation-based architecture

New file:

`includes/class-ripex-portal-cache-performance.php`

The cache layer stores small generation counters for four independent domains:

- `orders`
- `products`
- `customers`
- `categories`

A cache key is a hash of:

1. namespace;
2. role/user/filter-specific key parts;
3. the current generations required by that cache.

When data changes, the corresponding generation is incremented once per PHP request. The old transient is not bulk-deleted; its key simply stops being generated and it expires naturally according to its TTL.

This avoids broad `DELETE ... LIKE` operations against `wp_options` and avoids maintaining a registry of every transient key produced by every user/range combination.

## Report result cache

Full report cache key contains:

- role;
- current user ID;
- date from;
- date to;
- `orders` generation;
- `products` generation;
- `customers` generation.

TTL:

- RIPEX Admin: **600 seconds / 10 minutes** (same TTL as the previous admin behavior);
- RIPEX Vendedor: **300 seconds / 5 minutes**.

`force_refresh` bypasses the cache read, recalculates the current data and writes the new current-generation result for subsequent requests.

Because role and user ID are part of the key, a seller can never reuse another seller's report transient.

## Inactive-customer component cache

The expensive inactive-customer table depends on the complete revenue-order history and seller/customer scope, but not on the report date range selected by the operator.

Phase 07 therefore adds a second component cache containing only the final top-12 inactive customer rows.

Key contains:

- role;
- current user ID;
- `orders` generation;
- `customers` generation.

TTL: **600 seconds / 10 minutes**.

Effect: after one uncached historical pass, changing a report from (for example) 30 days to 90 days does not immediately require another full historical order traversal solely to rebuild the same inactive-customer table.

`force_refresh` also bypasses this component cache.

## Inventory category cache

The inventory category selector is cached for **3600 seconds / 1 hour**.

Its key uses only the independent `categories` generation. Stock changes therefore do not invalidate the selector.

The category generation is bumped on:

- product category creation;
- product category edit;
- product category deletion.

`inventory_categories()` uses `hide_empty=false`, so product stock or category assignment changes do not alter the list of category definitions that the selector needs.

Inventory product rows and stock are **not** cached by Phase 07.

## Invalidation rules

### Orders generation

Bumped from normal order lifecycle hooks including:

- new order;
- order update;
- status change;
- refund create/delete;
- legacy `shop_order` save.

This invalidates report values such as revenue, order count, products sold, companies, sellers, transports, statuses and inactive-customer history.

### Products generation

Bumped from product/variation lifecycle and stock hooks including:

- new/update product;
- product/variation stock set;
- product/variation post save.

This invalidates Reportes stock, low-stock and no-movement data.

### Customers generation

Bumped from user registration/profile/deletion and relevant usermeta changes, including RIPEX commercial fields:

- RUT (`afreg_additional_42210`);
- razón social (`afreg_additional_42208`);
- giro (`afreg_additional_42209`);
- vendedor (`afreg_additional_42207`);
- credit term (`afreg_additional_46135`);
- RIPEX vendor label/nickname/first/last name;
- billing name/company/phone/city/state;
- shipping city/state.

This invalidates report/customer-label data where the current customer/seller metadata changes the displayed aggregation.

### Categories generation

Bumped only for product-category taxonomy create/edit/delete.

## Request-level write deduplication

WooCommerce may emit more than one relevant hook while completing a single business action. The cache layer records which domains have already been bumped during the current PHP request and performs at most one generation write per domain/request.

For example, creating an order may trigger multiple order hooks and stock hooks. Phase 07 can bump `orders` once and `products` once rather than repeatedly updating the same option.

## Storage behavior

Generation values are normal WordPress options configured as non-autoload where supported by the current WordPress API call.

Cached values continue to use WordPress transients. No Redis/Memcached dependency is introduced by this phase. If a persistent object cache is enabled later, WordPress can move transient/object-cache work away from repeated database reads without requiring RIPEX business-code changes.

## Known limitation

Explicit invalidation assumes data mutations pass through standard WordPress/WooCommerce hooks. A direct SQL update performed outside those APIs can bypass generation increments. TTL remains a secondary safety bound, and the Reportes UI retains `force_refresh`.

This limitation is documented rather than hidden; direct database writes are not part of the normal RIPEX application flow.

## Integrated staging checklist

### Report result cache

1. Admin opens the same range twice; second request should be materially faster and return identical data.
2. Seller opens the same range twice; second request should use the seller-specific 5-minute cache.
3. Confirm two different sellers cannot share cached report results.
4. Change the date range and confirm range-specific KPIs remain correct.
5. Use `force_refresh` and confirm a fresh calculation occurs.

### Invalidation

For every test, capture the relevant generation option before/after and verify the next Reportes request recalculates using a new key:

1. create/update an order;
2. change order status;
3. alter product stock;
4. edit a customer's RUT/razón social/giro/vendedor;
5. edit a seller label/name used by matching logic.

Confirm only the relevant generation(s) increment and repeated hooks in the same request do not cause repeated increments.

### Historical component cache

1. run an uncached report;
2. change only the report date range;
3. verify inactive-customer rows remain identical and the historical component is reused;
4. modify an eligible historical order/customer and confirm the component cache invalidates.

### Inventory categories

1. load Inventory twice and confirm category options are identical;
2. alter stock and confirm category generation does not change;
3. create/edit/delete a product category and confirm category generation changes and the selector refreshes.

## Performance measurements

For Reportes capture separately:

- first uncached request after invalidation;
- second identical cached request;
- different range with inactive-customer component already warm;
- `force_refresh` request.

Record:

- browser Network duration;
- PHP-FPM peak RSS;
- CPU;
- response size;
- report parity against production 1.5.14 baseline.

The critical comparison remains the original isolated production peak of approximately **432 MiB RSS** for Reportes.

## Acceptance gate

Phase 07 passes when:

- cached and uncached report payloads are functionally identical;
- seller caches remain isolated by user ID;
- normal order/product/customer mutations make old report cache keys unreachable;
- inactive-customer history is reused across report ranges when the underlying history has not changed;
- Inventory category choices refresh after taxonomy edits but not routine stock changes;
- no permission-sensitive list/detail response is persistently cached.

## Rollback

1. Remove `includes/class-ripex-portal-cache-performance.php` from `ripex-portal.php`.
2. Revert the Phase 07 changes in `class-ripex-portal-performance.php` and `class-ripex-portal-inventory-performance.php`.

The underlying Phase 03 inventory pagination and P0 bounded Reportes implementation remain independently usable.
