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

**Status:** proposed Phase 03.

Replace the current up-to-500 product/variation batch with true backend pagination (target 50–100 rows) and server-side sorting/filtering. Preserve the current inventory response fields and search behavior while avoiding large per-request product object collections.

## P1 — Clientes

Replace up-to-1000-user multi-query loads and PHP filtering with backend pagination and targeted filters. Preserve RUT, razón social, giro, ciudad and vendedor behavior.

## P1 — roles/capabilities lifecycle

Stop reconciling RIPEX role capabilities on every request. Run capability migrations on activation/version migration instead.

## P1 — exports

Remove unbounded order materialization from vendor/date exports where possible. Use bounded batches while preserving CSV/PDF output and authorization rules.

## P1 — cache/invalidation

Extend bounded cache use to stable aggregates where useful. Cache keys must include role, user scope and filter range. Invalidate on relevant order/product/customer changes rather than relying only on TTL.

## P2 — HPOS compatibility

Audit every direct `wp_posts`/`wp_postmeta` order access and migrate to WooCommerce order APIs/datastores. Declare HPOS compatibility only after staging validation and synchronization checks.

## P2 — observability

Add optional debug-only instrumentation for endpoint duration, peak memory and query count. Never log sensitive customer/order payloads.

## Integrated deployment/test sequence

1. implement agreed P0/P1 phases in the draft branch;
2. keep PHP 8.0/8.3 CI checks green after each phase;
3. deploy the complete candidate to staging;
4. run functional parity by role;
5. repeat browser/server captures from the baseline protocol;
6. correct any regression and rerun the block;
7. production window with backup/rollback only after acceptance;
8. repeat baseline tests in production;
9. document measured before/after result for client delivery.
