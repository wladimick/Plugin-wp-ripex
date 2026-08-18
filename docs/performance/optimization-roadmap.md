# RIPEX performance optimization roadmap

## Principle

Preserve business behavior first. Optimize query shape, memory use, pagination and caching incrementally. Every P0/P1 change must be measurable against `2026-08-18-baseline.md` and pass the functional parity checklist.

The current working model is to implement several controlled phases in the same draft branch, keep CI running after each phase, and execute the full staging functional/performance test block once the agreed optimization package is complete.

## P0 — Reportes

**Status:** first implementation complete; integrated staging validation pending.

Root cause in 1.5.14:

- complete order history loaded with `limit=-1` for every uncached report;
- date range applied in PHP instead of at query level;
- complete product catalog materialized with `limit=-1`;
- historical inactive-customer calculation shares the same all-order object set;
- isolated production test reached ~432 MiB RSS in one PHP worker.

First optimization iteration:

- query report-period orders at the WooCommerce data layer using `date_created`;
- process orders in bounded pages rather than one unbounded object collection;
- calculate historical inactive-customer data in a separate status-restricted paginated pass;
- process published products in bounded pages;
- preserve the existing output schema and 10-minute admin report transient.

Acceptance gate: same report values for the same role/date range, with materially lower peak RSS and faster AJAX response.

## P0 — vendor order scope

**Status:** Phase 01 implemented; integrated staging validation pending.

The 1.5.14 vendor ownership resolver scans/grouped the complete legacy `shop_order` set and relevant postmeta before the 30-row page can be produced.

Phase 01:

- query `_ripex_seller_id` candidates directly;
- query `_ripex_vendedor` candidates only when no explicit seller exists;
- resolve RIPEX-assigned customer IDs and query only `_customer_user` fallback candidates when neither explicit order vendor field exists;
- preserve strict seller → vendor label → customer fallback precedence;
- keep `vendor_mine_filter()` as defense in depth before returning a row;
- keep the legacy implementation untouched underneath the bridge for simple rollback.

Acceptance gate: same vendor totals/visibility, no cross-seller data leakage, lower SQL/memory cost for vendor page loads.

Detailed implementation note: `docs/performance/phases/phase-01-vendor-order-scope.md`.

## P1 — Pedidos search

**Status:** Phase 02 implemented; integrated staging validation pending.

The original orders endpoint paginated first and searched only the already-loaded ~30 order objects, so valid matches on later pages could appear to not exist.

Phase 02:

- resolve candidate order IDs from the historic searchable fields before pagination;
- preserve current AFREG usermeta-first / order-snapshot fallback behavior;
- apply status/date filters before WooCommerce object creation;
- apply vendor scope before pagination;
- validate the exact historic PHP search string before totals/page calculation;
- paginate only the true matching set;
- delegate all no-search requests unchanged to Phase 01.

Acceptance gate: search results are independent of the user's current unfiltered page, totals/pagination match the real result set, and vendor isolation remains exact.

Detailed implementation note: `docs/performance/phases/phase-02-orders-search.md`.

## P1 — Inventario

**Status:** Phase 03 implemented; integrated staging validation pending.

The 1.5.14 inventory path can retrieve up to 500 product/variation IDs, materialize every candidate as a WooCommerce product object, resolve category/parent/stock data and sort the complete set in PHP before returning it.

Phase 03:

- resolve inventory search/category/sort before object hydration;
- use WooCommerce `wc_product_meta_lookup` for SKU/stock search and ordering;
- preserve the current product + variation behavior when no category is selected;
- preserve the current parent-product-only behavior when a category is selected;
- paginate at 60 rows per request;
- warm metadata/categories only for the requested page and required variation parents;
- add previous/next inventory navigation and true filtered totals;
- keep the existing 350 ms search debounce and protect against stale responses;
- keep the main `portal.js` untouched by isolating the new inventory UI in its own script.

Acceptance gate: complete inventory remains navigable with equivalent search/filter/sort/stock behavior while a normal page no longer materializes the previous ~500-object collection.

Detailed implementation note: `docs/performance/phases/phase-03-inventory-pagination.md`.

## P1 — Clientes

**Status:** Phase 04 implemented; integrated staging validation pending.

The original customer directory could run multiple `WP_User_Query` passes of up to 1,000 users and then perform per-user metadata reads, normalization, filtering and sorting before returning as many as 1,000 rows.

Phase 04:

- preserve the admin union of customer/wholesaler roles and RIPEX commercial-metadata fallback users;
- preserve normalized seller assignment through `afreg_additional_42207` and keep `customer_assigned_to_vendor()` as defense in depth;
- resolve basic users and only the customer-list metadata fields in bounded set-oriented queries;
- retain the first metadata value per key to match `get_user_meta(..., true)` behavior;
- preserve city/region precedence, RUT, razón social, giro and vendedor fields;
- paginate at 50 customers per request;
- add previous/next customer navigation and true filtered totals;
- preserve the existing customer-history drawer and response contract;
- isolate the new customer directory UI in `customers-pagination.js` rather than modifying the large base `portal.js`.

Acceptance gate: complete eligible customer scope remains navigable with identical role boundaries and customer fields while one browser request no longer returns/processes the previous up-to-1,000-row directory payload.

Detailed implementation note: `docs/performance/phases/phase-04-customers-pagination.md`.

## P1 — roles/capabilities lifecycle

**Status:** Phase 05 implemented; integrated staging validation pending.

The original `Ripex_Portal` constructor calls `ensure_roles_caps()` whenever the singleton is created, causing every normal WordPress request to repeat role/capability reconciliation and granted `shop_manager` capability copying.

Phase 05:

- install the existing `Ripex_Portal` singleton without invoking the capability-reconciling constructor;
- register the exact existing constructor hook contract separately, excluding only `ensure_roles_caps()`;
- reconcile capabilities only when a stored role-schema signature changes;
- include RIPEX version and the granted `shop_manager` capability hash in that signature;
- run cheap critical-role/capability checks so missing core RIPEX permissions can still self-heal;
- leave the original activation path unchanged;
- add CI that statically compares constructor hook registrations with the lifecycle bridge and fails if they diverge.

Acceptance gate: all portal/auth/payment/AJAX hooks behave identically, RIPEX roles retain required capabilities, and normal requests stop executing the full capability reconciliation loop.

Detailed implementation note: `docs/performance/phases/phase-05-roles-capabilities-lifecycle.md`.

## P1 — exports

**Status:** next proposed phase.

Remove unbounded order materialization from vendor/date exports where possible. Use bounded batches while preserving CSV/PDF output and authorization rules.

## P1 — cache/invalidation

Extend bounded cache use to stable aggregates where useful. Cache keys must include role, user scope and filter range. Invalidate on relevant order/product/customer changes rather than relying only on TTL.

## P2 — HPOS compatibility

Audit every direct `wp_posts`/`wp_postmeta` order access and migrate to WooCommerce order APIs/datastores. Declare HPOS compatibility only after staging validation and synchronization checks.

## P2 — observability

Add optional debug-only instrumentation for endpoint duration, peak memory and query count. Never log sensitive customer/order payloads.

## Integrated deployment/test sequence

1. implement agreed P0/P1 phases in the draft branch;
2. keep PHP 8.0/8.3, JavaScript syntax and lifecycle-parity CI checks green after each phase;
3. deploy the complete candidate to staging;
4. run functional parity by role;
5. repeat browser/server captures from the baseline protocol;
6. correct any regression and rerun the block;
7. production window with backup/rollback only after acceptance;
8. repeat baseline tests in production;
9. document measured before/after result for client delivery.
