# Changelog

## 1.5.15 — candidate / performance P0-P1

Date: 2026-08-18

### Performance

- Added a bounded-memory Reports implementation.
- Report-period orders are now filtered by `date_created` at the WooCommerce query layer instead of loading the full history and filtering dates only in PHP.
- Order history used for inactive customers is processed separately, limited to revenue statuses and read in batches.
- Published products used by report stock/no-movement metrics are processed in batches rather than one unbounded collection.
- Existing admin report transient behavior (10 minutes) and report response schema are preserved.
- Added Phase 01 vendor-order scope optimization.
- Vendor ownership no longer starts by LEFT JOINing/grouping the complete legacy `shop_order` set with all relevant order metadata.
- Vendor ownership now queries the explicit `_ripex_seller_id`, `_ripex_vendedor` and customer fallback layers separately while preserving strict ownership precedence.
- The existing `vendor_mine_filter()` remains as a final server-side safety check before returning vendor rows.
- Added Phase 02 orders-search optimization/correctness fix.
- Order search now resolves the complete filtered matching set before pagination instead of searching only the already-loaded ~30 orders on the current page.
- Search preserves order ID, billing/customer, company, email, RUT, razón social, giro, vendedor and payment-title matching semantics.
- Status/date and seller scope are applied before search totals and page counts are produced.
- No-search orders traffic continues to use the Phase 01 path unchanged.
- Added Phase 03 inventory backend pagination.
- Inventory now resolves search/category/sort before WooCommerce product hydration and returns 60 rows per request instead of materializing up to ~500 product/variation objects.
- SKU/stock search and ordering use WooCommerce `wc_product_meta_lookup`.
- Existing inventory row fields, category behavior, stock labels, variation display and admin edit action are preserved.
- Added previous/next inventory navigation, filtered totals, page reset on filter changes and stale-search-response protection.
- Added Phase 04 customer-directory pagination and set-oriented metadata loading.
- Customer listing no longer starts with several `WP_User_Query` passes of up to 1,000 users followed by repeated per-user metadata reads.
- Admin customer/wholesaler plus commercial-metadata fallback scope is preserved.
- Seller customer assignment continues to use normalized `afreg_additional_42207` rules with a bounded final scope check.
- Customer listing now returns 50 rows per request with true filtered totals and previous/next navigation.
- Existing RUT, razón social, giro, city/region, vendedor and customer-history drawer behavior are preserved by the Phase 04 bridge/UI.
- Added Phase 05 role/capability lifecycle optimization.
- Normal requests no longer invoke the original constructor's complete `ensure_roles_caps()` reconciliation loop.
- RIPEX role reconciliation is now driven by a stored schema signature containing the candidate version and the granted `shop_manager` capability hash, with critical-role repair checks.
- The original activation path still creates/reconciles roles, and upgrades perform one migration before recording the completed signature.
- The portal constructor hook contract is reproduced by the lifecycle bridge while keeping the same `Ripex_Portal` singleton for all endpoint bridges.
- Added Phase 06 bounded export processing.
- Seller `Mis pedidos` no longer starts by materializing the complete WooCommerce order history; candidate seller-created order IDs are resolved in 100-ID batches and still pass the existing `vendor_created_filter()` check.
- Bodega/admin date export now applies `date_created` at the WooCommerce query layer and processes only the requested range in 100-order pages.
- Existing seller-created semantics, export authorization, CSV columns, filenames, `count_orders` response and `_ripex_exported_to_bodega` behavior are preserved.
- Export rows are written incrementally to a temporary stream instead of repeatedly concatenating the growing CSV string during order processing.
- The seller export's legacy `post_author` fallback remains explicitly documented for the future HPOS compatibility phase.
- Added Phase 07 generation-based cache invalidation.
- Report cache keys now include role/user/range plus `orders`, `products` and `customers` data generations.
- Admin keeps the existing 10-minute report TTL; sellers gain an isolated 5-minute report cache keyed by seller user ID.
- `force_refresh` bypasses report/component cache reads while refreshing the current-generation values for following requests.
- The report inactive-customer top-12 result is cached independently of date range for 10 minutes and keyed by role/user plus order/customer generations, avoiding repeated full historical passes when only the report range changes.
- Normal order/product/customer mutations bump the relevant generation at most once per PHP request, making old transient keys unreachable without bulk deletion from `wp_options`.
- Inventory category definitions are cached for one hour with a separate `categories` generation, so routine stock changes do not invalidate the selector.
- Permission-sensitive order/customer/inventory row/detail responses remain uncached.

### Documentation

- Added the 2026-08-18 production performance baseline.
- Added repeatable browser/server retest protocol and functional-parity checklist.
- Added optimization roadmap.
- Added dedicated infrastructure recommendation and sizing rationale.
- Added per-phase implementation notes under `docs/performance/phases/`.
- Added Phase 02 search parity/acceptance checklist.
- Added Phase 03 inventory pagination/performance checklist.
- Added Phase 04 customer directory pagination/scope checklist.
- Added Phase 05 roles/capabilities lifecycle, activation, rollback and validation documentation.
- Added Phase 06 export batching, CSV parity, legacy seller-creator and performance validation documentation.
- Added Phase 07 cache generations, invalidation matrix, cache isolation, TTL and staging validation documentation.

### Validation status

- GitHub Actions checks PHP syntax on 8.0 and 8.3, JavaScript syntax and Phase 05 constructor/lifecycle hook parity.
- Reportes P0 and Phases 01–07 are implemented in the draft branch.
- **Integrated staging functional parity and before/after performance measurements are intentionally deferred until the current optimization block is complete.**
- No merge/deployment to production is authorized before that validation block passes.

### Baseline reference

The isolated pre-optimization Reportes test reached approximately **432 MiB RSS in one PHP-FPM worker**, with approximately **586 MiB combined RSS** across the two observed production workers. The CloudLinux account limit is 1 GiB total physical memory and production has `pm.max_children=2`.
