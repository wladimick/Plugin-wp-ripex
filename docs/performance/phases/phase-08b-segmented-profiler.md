# Phase 08.2 — Segmented profiler and minimal authenticated ping

Date: 2026-08-20
Candidate: RIPEX Portal 1.5.16

## Objective

Phase 08.2 separates the common WordPress/RIPEX request floor from endpoint-specific work before any new read-model tables, SQL indexes or infrastructure changes are introduced.

The existing Phase 08 `duration_ms` already uses `$_SERVER['REQUEST_TIME_FLOAT']`, so it represents PHP request time from the beginning of the PHP request through shutdown. Phase 08.2 adds fixed lifecycle markers inside that duration.

This does **not** measure time spent waiting for a free PHP-FPM worker before PHP starts. FPM queue/accept time remains an infrastructure measurement outside this plugin.

## Activation

The profiler is disabled by default and uses the same switch as Phase 08/08.1:

```php
define('RIPEX_PORTAL_OBSERVABILITY', true);
define('RIPEX_PORTAL_OBSERVABILITY_LOG', false);
```

When the first constant is false/undefined, the profiler and diagnostic ping are no-ops.

## Lifecycle markers

Each observed RIPEX AJAX request can include the following markers:

- `ripex_boot`: moment the profiler file is loaded by the RIPEX plugin bootstrap.
- `plugins_loaded`: end of the WordPress `plugins_loaded` hook.
- `init`: end of the WordPress `init` hook.
- `wp_loaded`: end of the WordPress `wp_loaded` hook.
- `admin_init`: end of the admin/AJAX `admin_init` hook.
- `ajax_callback`: immediately before the real `wp_ajax_<action>` RIPEX callback.
- `shutdown`: immediately before the Phase 08 metric is emitted.

Each marker records only:

- elapsed milliseconds from PHP request start;
- WordPress total query count at that marker;
- current allocated PHP memory in MiB.

The JSON also contains adjacent `segments_ms` and query deltas where both endpoints are available.

## Common-floor fields

Every Phase 08.2 sample retains the original Phase 08 fields and adds:

- `queries_before_ripex_boot`: queries already executed when RIPEX starts loading;
- `queries_since_ripex_boot`: existing compatibility field;
- `queries_total`: total WordPress query count at shutdown;
- `profile.request_to_ajax_callback_ms`: common bootstrap/auth/dispatch time before endpoint work begins;
- `profile.ajax_callback_to_shutdown_ms`: endpoint callback plus response/shutdown portion;
- `profile.request_total_ms`: profiler total, expected to closely match top-level `duration_ms`;
- `profile.markers`;
- `profile.segments_ms`;
- `profile.query_deltas`.

If `SAVEQUERIES` is already enabled by the environment, Phase 08.2 additionally exports only the aggregate `sql_time_ms_since_ripex_boot`. It never enables `SAVEQUERIES` itself and never exports SQL text.

## Minimal authenticated diagnostic ping

Phase 08.2 adds:

`ripex_portal_perf_ping`

It intentionally performs only:

1. normal WordPress/plugin bootstrap;
2. logged-in check;
3. `ripex_admin` role check;
4. normal RIPEX nonce validation;
5. a minimal JSON success response.

Unlike the Phase 08.1 status/clear/export control actions, `perf_ping` **is captured** by observability. It is intended to reveal the common request floor with almost no RIPEX business logic.

The Reportes observability UI adds `Ping diagnóstico ×5`, which executes five sequential authenticated pings so a single outlier does not drive the diagnosis.

## Runtime export diagnostics

The JSON `runtime` object now also reports aggregate diagnostics collected during the authenticated export request:

### Autoload options

- count of autoloaded options;
- approximate serialized bytes/MiB.

Option names and values are not exported.

### OPcache

When `opcache_get_status(false)` is available in the PHP-FPM context, the export records only aggregate values:

- enabled/cache-full/restart state;
- used/free/wasted OPcache memory;
- interned-string buffer usage;
- cached script/key counts;
- hits/misses/hit rate;
- OOM/hash/manual restart counters.

No cached script paths are requested or exported.

## Privacy boundary

Phase 08.2 does not add any of the following to metrics:

- user IDs;
- customer IDs;
- order IDs;
- product IDs;
- names;
- emails;
- RUTs;
- search terms;
- date ranges;
- POST/request payloads;
- transient keys;
- SQL text;
- SQL parameters;
- filesystem paths;
- fatal messages.

The authenticated JSON remains generated on demand and downloaded locally by the browser. No public JSON file is written to uploads.

## Staging validation protocol

After installing candidate 1.5.16 on staging:

1. Confirm `RIPEX_PORTAL_OBSERVABILITY=true` and logging remains disabled unless explicitly required.
2. Open Portal → Reportes as `ripex_admin`.
3. Click `Nueva medición`.
4. Click `Ping diagnóstico ×5`.
5. Exercise representative actions: Pedidos, detalle, Inventario, Clientes, Carritos and Reportes hit/miss/bypass as needed.
6. Download metrics JSON.
7. Verify schema version 2, plugin version 1.5.16, zero unexpected HTTP/fatal samples and populated `profile` fields.
8. Compare `request_to_ajax_callback_ms` against `ajax_callback_to_shutdown_ms`.

Interpretation examples:

- High `request_to_ajax_callback_ms` even for `perf_ping` indicates the dominant cost is WordPress/plugin/bootstrap infrastructure before endpoint business logic.
- Low ping/common-floor time but high `ajax_callback_to_shutdown_ms` on one endpoint indicates RIPEX endpoint-specific work.
- Similar query counts with materially different callback time can indicate CPU/contention or expensive non-SQL PHP work.
- High query deltas concentrated after `ajax_callback` identify endpoint-specific query pressure.

## Rollback

Code rollback is the prior 1.5.15 candidate. Operational rollback is immediate by disabling:

```php
define('RIPEX_PORTAL_OBSERVABILITY', false);
```

No schema migration, database table, index, cron, role, order, stock or customer mutation is introduced by Phase 08.2.
