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
- Seller customer assignment continues to use normalized `afreg_additional_42207` rules with the existing scope helper as defense in depth.
- Customer listing now returns 50 rows per request with true filtered totals and previous/next navigation.
- Existing RUT, razón social, giro, city/region, vendedor and customer-history drawer behavior are preserved by the Phase 04 bridge/UI.

### Documentation

- Added the 2026-08-18 production performance baseline.
- Added repeatable browser/server retest protocol and functional-parity checklist.
- Added optimization roadmap.
- Added dedicated infrastructure recommendation and sizing rationale.
- Added per-phase implementation notes under `docs/performance/phases/`.
- Added Phase 02 search parity/acceptance checklist.
- Added Phase 03 inventory pagination/performance checklist.
- Added Phase 04 customer directory pagination/scope checklist.

### Validation status

- GitHub Actions checks PHP syntax on 8.0 and 8.3 and JavaScript syntax.
- Reportes P0, vendor-order Phase 01, orders-search Phase 02, inventory Phase 03 and customers Phase 04 are implemented in the draft branch.
- **Integrated staging functional parity and before/after performance measurements are intentionally deferred until the current optimization block is complete.**
- No merge/deployment to production is authorized before that validation block passes.

### Baseline reference

The isolated pre-optimization Reportes test reached approximately **432 MiB RSS in one PHP-FPM worker**, with approximately **586 MiB combined RSS** across the two observed production workers. The CloudLinux account limit is 1 GiB total physical memory and production has `pm.max_children=2`.
