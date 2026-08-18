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

### Documentation

- Added the 2026-08-18 production performance baseline.
- Added repeatable browser/server retest protocol and functional-parity checklist.
- Added optimization roadmap.
- Added dedicated infrastructure recommendation and sizing rationale.
- Added per-phase implementation notes under `docs/performance/phases/`.

### Validation status

- GitHub Actions syntax checks for PHP 8.0 and 8.3 are active.
- Reportes P0 and vendor-order Phase 01 are implemented in the draft branch.
- **Integrated staging functional parity and before/after performance measurements are intentionally deferred until the current optimization block is complete.**
- No merge/deployment to production is authorized before that validation block passes.

### Baseline reference

The isolated pre-optimization Reportes test reached approximately **432 MiB RSS in one PHP-FPM worker**, with approximately **586 MiB combined RSS** across the two observed production workers. The CloudLinux account limit is 1 GiB total physical memory and production has `pm.max_children=2`.
