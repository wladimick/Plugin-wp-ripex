# RIPEX performance optimization roadmap

## Principle

Preserve business behavior first. Optimize query shape, memory use, pagination and caching incrementally. Every P0/P1 change must be measurable against `2026-08-18-baseline.md` and pass the functional parity checklist.

## P0 — Reportes

**Status:** implementation started.

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

The current vendor ownership function can scan all legacy `shop_order` posts and related postmeta before pagination. Refactor toward WooCommerce datastore/HPOS-aware filtering and a persistent seller/customer mapping where necessary.

Acceptance gate: same seller visibility rules, no cross-seller data leakage, backend pagination occurs before object materialization.

## P1 — Pedidos search

Move search into the backend query before pagination. Current post-query filtering can miss matches outside the currently loaded page.

## P1 — Clientes

Replace up-to-1000-user multi-query loads and PHP filtering with backend pagination and targeted filters. Preserve RUT, razón social, giro, ciudad and vendedor behavior.

## P1 — Inventario

Replace the current up-to-500 product/variation batch with true backend pagination (target 50–100 rows) and server-side sorting/filtering.

## P1 — roles/capabilities lifecycle

Stop reconciling RIPEX role capabilities on every request. Run capability migrations on activation/version migration instead.

## P1 — cache/invalidation

Extend bounded cache use to stable aggregates where useful. Cache keys must include role, user scope and filter range. Invalidate on relevant order/product/customer changes rather than relying only on TTL.

## P2 — HPOS compatibility

Audit every direct `wp_posts`/`wp_postmeta` order access and migrate to WooCommerce order APIs/datastores. Declare HPOS compatibility only after staging validation and synchronization checks.

## P2 — observability

Add optional debug-only instrumentation for endpoint duration, peak memory and query count. Never log sensitive customer/order payloads.

## Deployment sequence

1. branch + code review;
2. PHP lint/static review;
3. deploy to staging;
4. functional parity test by role;
5. repeat server/browser capture;
6. production window with backup/rollback;
7. repeat baseline tests in production;
8. document measured before/after result.
