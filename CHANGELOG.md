# Changelog

## 1.5.15 — candidate / performance P0

Date: 2026-08-18

### Performance

- Added a bounded-memory Reports implementation.
- Report-period orders are now filtered by `date_created` at the WooCommerce query layer instead of loading the full history and filtering dates only in PHP.
- Order history used for inactive customers is processed separately, limited to revenue statuses and read in batches.
- Published products used by report stock/no-movement metrics are processed in batches rather than one unbounded collection.
- Existing admin report transient behavior (10 minutes) and report response schema are preserved.

### Documentation

- Added the 2026-08-18 production performance baseline.
- Added repeatable browser/server retest protocol and functional-parity checklist.
- Added optimization roadmap.
- Added dedicated infrastructure recommendation and sizing rationale.

### Validation status

- PHP syntax of the new performance class was checked locally with PHP CLI before publication.
- GitHub Actions syntax checks for PHP 8.0 and 8.3 were added.
- **Staging functional parity and production before/after measurements are still required before merge/deployment.**

### Baseline reference

The isolated pre-optimization Reportes test reached approximately **432 MiB RSS in one PHP-FPM worker**, with approximately **586 MiB combined RSS** across the two observed production workers. The CloudLinux account limit is 1 GiB total physical memory and production has `pm.max_children=2`.
