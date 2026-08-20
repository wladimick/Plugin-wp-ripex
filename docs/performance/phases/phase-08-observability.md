# Phase 08 — opt-in AJAX observability

Date: 2026-08-18  
Status: implemented in candidate `1.5.15`, disabled by default, pending integrated staging validation

## Objective

Add enough application-level telemetry to measure the optimized RIPEX portal during the staging comparison without introducing a permanent monitoring dependency or logging customer/order payloads.

Phase 08 is intentionally **off by default**. Normal production behavior and log volume do not change unless observability is explicitly enabled.

## What is measured

For authenticated RIPEX AJAX actions whose action name starts with `ripex_portal_`, one compact metric is emitted at request shutdown:

- AJAX action name;
- RIPEX role (`ripex_admin`, `ripex_vendedor`, `ripex_bodeguero` when applicable);
- request duration in milliseconds;
- PHP peak allocated memory in MiB;
- current allocated-memory delta since RIPEX plugin bootstrap;
- WordPress query-count delta since RIPEX plugin bootstrap;
- HTTP status;
- cache component state when Phase 08 can determine it (`hit`, `miss`, `bypass`, `off`);
- fatal PHP error type code only, if the request ends in a fatal error.

### Metric interpretation

`duration_ms` starts from PHP's `REQUEST_TIME_FLOAT` when available, so it approximates full PHP request duration rather than only time inside the final callback.

`peak_memory_mib` comes from `memory_get_peak_usage(true)` and therefore represents the PHP request peak, which is the metric most useful when comparing with PHP-FPM RSS captures.

`queries_since_ripex_boot` is **not** presented as the absolute total number of SQL queries since the first instruction of WordPress. The baseline is captured when the RIPEX plugin loads, so this field is specifically a repeatable RIPEX-era query delta for before/after comparison.

`memory_delta_mib` is also relative to RIPEX plugin bootstrap and is secondary to `peak_memory_mib`.

## Privacy / data minimization

The structured metric intentionally does **not** include:

- user IDs;
- customer IDs;
- order IDs;
- product IDs;
- RUT;
- names;
- email addresses;
- companies;
- search terms;
- dates/ranges selected by the operator;
- request bodies;
- response payloads;
- SQL text;
- PHP fatal error messages/file paths.

The action name and broad RIPEX role are sufficient to group performance samples without introducing customer/business data into performance logs.

## Activation

The implementation lives in:

`includes/class-ripex-portal-observability.php`

It is loaded early by `ripex-portal.php`, but exits immediately unless the following constant is explicitly true:

```php
define('RIPEX_PORTAL_OBSERVABILITY', true);
```

Logging is enabled by default once observability is enabled. It can be disabled while keeping best-effort response headers with:

```php
define('RIPEX_PORTAL_OBSERVABILITY_LOG', false);
```

For the RIPEX performance program the intended workflow is to enable observability **only in staging during the controlled measurement window**, collect the required samples, and disable/remove the constant afterwards.

Changing `wp-config.php` is a server configuration change and is therefore outside this code-only phase. It must be performed later under the agreed staging change/rollback protocol.

## Output format

When logging is enabled, one line is written through PHP `error_log()`:

```text
[RIPEX PERF] {"action":"ripex_portal_get_reports","role":"ripex_admin","duration_ms":842.7,"peak_memory_mib":118,"memory_delta_mib":24,"queries_since_ripex_boot":63,"http_status":200,"cache":{"reports_result":"miss","inactive_customers":"hit"}}
```

This example is illustrative only; it is not a measured RIPEX result.

Best-effort response headers are also attempted when headers have not already been committed:

- `X-Ripex-Perf-Duration-Ms`
- `X-Ripex-Perf-Peak-MiB`
- `X-Ripex-Perf-Queries`
- `X-Ripex-Perf-Cache`
- `Server-Timing: ripex;dur=...`

`wp_send_json()` can commit headers before the shutdown observer executes, so the structured log line remains the authoritative Phase 08 source. Browser Network duration remains an independent external measurement.

## Cache hit/miss probes

Phase 08 provides specific cache state for the two Phase 07 areas where the information materially helps the test:

### Reportes

Before the report callback runs, observability resolves the exact current-generation result key from:

- role;
- internal current user ID (used only to reproduce the key, never logged);
- selected range (used only to reproduce the key, never logged);
- `orders`, `products` and `customers` generations.

If the full report cache is a hit, the value is primed through WordPress's dynamic `pre_transient_{key}` filter so the real callback consumes the same value without a second cache read. The observer then stops; it does not unnecessarily probe the inactive-customer component because the endpoint itself will return immediately.

If the full report is a miss, Phase 08 probes the independent inactive-customer component and primes a hit in the same way.

`force_refresh` is reported as `bypass` and no cache read is performed by the observer.

### Inventario categories

The one-hour category-definition transient is probed and, on hit, primed for the actual endpoint callback. Inventory rows and stock are not cached or probed.

## Expected overhead

When disabled, Phase 08 only incurs the cost of loading a small PHP class and checking the opt-in constant; it performs no AJAX measurement or logging.

When enabled:

- every RIPEX AJAX request performs timestamp/memory/query bookkeeping and writes one short log line unless logging is disabled;
- Reportes/Inventario may perform one preflight transient lookup to classify cache state;
- a cache hit is primed so the endpoint does not perform the same transient lookup again;
- a full Reportes hit does not probe the inactive-customer component.

Because observability itself has non-zero overhead, client-facing results must continue to include browser Network timings and the independent PHP-FPM/server capture. Phase 08 is an application diagnostic signal, not the sole benchmark.

## Staging measurement plan

After the complete candidate is installed in staging and Phase 08 is explicitly enabled:

1. log in as RIPEX Admin;
2. load Pedidos page 1 and another page;
3. perform a known Pedidos search;
4. open a detail drawer;
5. load Inventario page 1, next page and a filtered/search result;
6. load Clientes page 1, next page and a filtered/search result;
7. load Reportes with a cold current-generation cache;
8. repeat the same Reportes request to capture a warm `reports_result=hit`;
9. use force refresh and verify `bypass`;
10. change only report range after the result cache misses and verify whether `inactive_customers=hit` is reused;
11. repeat representative seller operations with a `ripex_vendedor` account;
12. test bodega export flows with `ripex_bodeguero`;
13. compare application metrics with browser Network duration and the server RSS/CPU capture.

For each representative endpoint record at least:

- role;
- action;
- cold/warm status where relevant;
- median duration over repeated equivalent runs where practical;
- peak memory;
- query delta;
- browser Network duration;
- PHP-FPM RSS peak from the external capture.

## Reportes before/after target table

The final client document should be able to populate a table such as:

| Metric | 1.5.14 production baseline | 1.5.15 staging/prod after | Change |
|---|---:|---:|---:|
| Reportes worker/RSS peak | ~432 MiB observed | to measure | % |
| Reportes PHP peak memory | not instrumented | to measure | — |
| Reportes cold duration | to establish | to measure | % |
| Reportes warm duration | no equivalent generation cache | to measure | — |
| Reportes query delta | not instrumented | to measure | — |
| Combined PHP worker RSS | ~586 MiB observed | to measure | % |

The existing server baseline remains the authority for pre-optimization RSS because Phase 08 did not exist in production 1.5.14.

## Log-volume rule

RIPEX already has historically large PHP error logs. Phase 08 must therefore **not** be left enabled indefinitely just because it is available.

Use it for the controlled staging test window, collect the required lines, then disable it. A future dedicated monitoring/APM system should use a separate telemetry backend rather than growing the PHP error log permanently.

## Acceptance gate

Phase 08 passes when staging confirms that:

- leaving the opt-in constant absent/false produces no `[RIPEX PERF]` lines;
- enabling it records only RIPEX AJAX actions;
- no customer/order/product identifiers or payload data appear in metrics;
- Reportes cache hit/miss/bypass states match the expected Phase 07 behavior;
- Inventory category hit/miss states match the expected Phase 07 behavior;
- metrics are emitted for the three RIPEX roles during representative flows;
- application metrics can be correlated with browser and PHP-FPM/server captures.

## Rollback

The safest operational rollback is simply to remove/set false the staging `RIPEX_PORTAL_OBSERVABILITY` constant.

The code can remain loaded because it is a no-op while disabled.

If Phase 08 code itself must be removed, delete the observability `require_once` from `ripex-portal.php`; no database migration or cleanup is required.
