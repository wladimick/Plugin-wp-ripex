# Changelog

## 1.5.16 — candidate / Phase 08.2 profiler

Date: 2026-08-20

### Performance / observability

- Added Phase 08.2 segmented RIPEX AJAX profiling while keeping observability opt-in and disabled by default.
- Each observed request now records fixed lifecycle markers for RIPEX bootstrap, `plugins_loaded`, `init`, `wp_loaded`, `admin_init`, AJAX callback start and shutdown.
- JSON samples include `queries_before_ripex_boot`, `queries_since_ripex_boot`, total queries, segment durations and query deltas without recording SQL text or request payloads.
- Added an authenticated `ripex_portal_perf_ping` endpoint that performs only normal bootstrap plus login/role/nonce validation and a minimal JSON response, allowing the common WordPress/RIPEX request floor to be measured separately from business endpoints.
- Reportes observability controls now include `Ping diagnóstico ×5` for five sequential minimal samples.
- JSON runtime context now includes aggregate autoload option count/serialized size and aggregate PHP-FPM OPcache usage/hit/restart counters when available, without exporting option names/values or cached script paths.
- If `SAVEQUERIES` is already enabled, Phase 08.2 records only aggregate SQL time since RIPEX boot; the plugin does not enable `SAVEQUERIES` itself.
- Existing status/clear/export control requests remain excluded from observability; only the diagnostic ping is intentionally included.
- No database tables, indexes, cron jobs, roles, order/customer/product mutations or cache semantics are changed by this phase.

### Documentation

- Added `docs/performance/phases/phase-08b-segmented-profiler.md` with marker definitions, privacy boundary, interpretation guidance, staging validation and rollback.

### Validation status

- Candidate requires syntax/CI validation and controlled staging installation before performance interpretation.
- Production remains unchanged by the plugin candidate; Phase 08.2 is intended for staging validation first.

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
- Added Phase 08 opt-in AJAX observability.
- Observability is disabled by default and activates only when `RIPEX_PORTAL_OBSERVABILITY` is explicitly true.
- Enabled RIPEX AJAX requests emit one non-sensitive `[RIPEX PERF]` metric with action, RIPEX role, request duration, PHP peak memory, memory delta, query delta, HTTP status and cache state when available.
- No user/customer/order/product IDs, RUTs, names, emails, selected ranges, search terms, payloads, SQL text or fatal error messages are included in the metric.
- Report and Inventory category cache probes classify hit/miss/bypass while priming cache hits for the real callback so the same transient lookup is not repeated.
- A full Reportes cache hit does not probe the inactive-customer component, avoiding unnecessary observer work on the warm path.
- Best-effort response timing headers are available when headers have not already been committed; the structured log line remains the authoritative Phase 08 signal.
- Added Phase 08.1 bounded JSON performance capture/export.
- Phase 08 metrics are retained in a non-autoload WordPress option with a default 500-sample ring-buffer cap; oldest samples are dropped and counted rather than allowing unbounded growth.
- No public diagnostic JSON is written to `uploads`; an authenticated `ripex_admin` generates the JSON only when downloading it through the normal RIPEX nonce-protected AJAX flow.
- Reportes gains `Nueva medición`, `Descargar métricas JSON` and a retained-sample counter while observability is enabled.
- Control actions are excluded from observability so status/clear/export operations do not contaminate their own dataset.
- JSON exports include runtime context, raw minimized samples and automatic action+role+cache summaries with average/median/p95/max duration, peak memory and query statistics.

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
- Added Phase 08 observability metric definitions, privacy boundary, activation/rollback, cache probes and staging measurement plan.
- Added Phase 08.1 JSON capture format, buffer limits, secure export controls, automatic summaries and staging workflow documentation.

### Validation status

- GitHub Actions checks PHP syntax on 8.0 and 8.3, JavaScript syntax and Phase 05 constructor/lifecycle hook parity.
- Reportes P0 and Phases 01–08.1 are implemented in the draft branch.
- Phase 08/08.1 remain disabled by default until the controlled staging measurement window.
- **Integrated staging functional parity and before/after performance measurements are intentionally deferred until the current optimization block is complete.**
- No merge/deployment to production is authorized before that validation block passes.

### Baseline reference

The isolated pre-optimization Reportes test reached approximately **432 MiB RSS in one PHP-FPM worker**, with approximately **586 MiB combined RSS** across the two observed production workers. The CloudLinux account limit is 1 GiB total physical memory and production has `pm.max_children=2`.
