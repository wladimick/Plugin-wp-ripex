# Phase 08.1 — JSON performance capture/export

Date: 2026-08-18  
Status: implemented in candidate `1.5.15`, disabled unless Phase 08 observability is enabled, pending staging validation

## Objective

Make the Phase 08 measurements easy to collect and analyze after a controlled staging test without depending exclusively on PHP error-log extraction.

The plugin now keeps a bounded, non-sensitive performance capture and lets a `ripex_admin` download one JSON file containing:

- capture/session metadata;
- runtime versions/context;
- automatically calculated summaries;
- the individual retained samples.

## Security design

RIPEX does **not** write a public JSON file into `wp-content/uploads`.

That would create a risk that a diagnostic file could be downloaded directly if its URL became known, and `.htaccess` protection would not be portable to every web-server stack.

Instead:

1. samples are stored in a bounded WordPress option;
2. the option is explicitly non-autoload;
3. only an authenticated `ripex_admin` with the normal RIPEX AJAX nonce can access status/clear/export actions;
4. the JSON file is generated only when the admin asks to download it;
5. the browser receives the JSON through the authenticated AJAX response and creates the local `.json` download.

No persistent public diagnostic file is created on the server.

## Activation

Phase 08.1 uses the same explicit switch as Phase 08:

```php
define('RIPEX_PORTAL_OBSERVABILITY', true);
```

If that constant is absent/false:

- no samples are appended;
- no measurement buttons are shown;
- the control AJAX actions reject access;
- normal RIPEX behavior remains unchanged.

The intended use remains **staging only during the controlled measurement window**.

## Capture buffer

Storage option:

`ripex_portal_observability_buffer_v1`

Properties:

- WordPress option with autoload disabled;
- default maximum: **500 retained samples**;
- oldest samples are discarded when the cap is exceeded;
- `total_captured` records how many samples the session attempted to retain;
- `dropped_samples` records how many old samples were removed by the ring-buffer cap.

The cap can be changed for a controlled environment with:

```php
define('RIPEX_PORTAL_OBSERVABILITY_MAX_SAMPLES', 1000);
```

The implementation clamps the value between 50 and 5000 samples to prevent accidental unbounded storage.

### Concurrency note

The buffer uses the standard WordPress option API and is designed for a controlled staging test, not as a high-volume APM backend. Very high concurrent writes could theoretically race and lose a sample. External PHP-FPM/browser/server measurements therefore remain part of the final benchmark.

## Portal controls

When observability is enabled and the current user is `ripex_admin`, the Reportes action area gains:

- **Nueva medición**
- **Descargar métricas JSON**
- a retained-sample counter

`Nueva medición` clears the previous capture and starts a new session identifier/timestamp.

The status/clear/export control requests use the `ripex_portal_perf_*` action prefix and are explicitly excluded from Phase 08 measurement so the diagnostic controls do not contaminate their own dataset.

## JSON filename

Generated files use UTC timestamping:

`ripex-performance-YYYYMMDD-HHMMSS.json`

## JSON structure

Top-level structure:

```json
{
  "schema_version": 1,
  "generated_at_utc": "...",
  "capture": {},
  "runtime": {},
  "summary": {},
  "samples": []
}
```

### `capture`

Contains only measurement-session metadata:

- random session ID;
- start/update UTC timestamps;
- configured maximum retained samples;
- total captured count;
- retained count;
- dropped count.

### `runtime`

Contains technical context useful for reproducibility:

- RIPEX plugin version;
- WordPress version;
- WooCommerce version;
- PHP version;
- PHP SAPI;
- WordPress environment type;
- WordPress timezone;
- whether an external persistent object cache is active.

It does not contain credentials, filesystem paths, hostnames, customer data or account identifiers.

### `samples`

Each retained Phase 08 sample can contain:

- `captured_at_utc`;
- AJAX `action`;
- RIPEX `role`;
- `duration_ms`;
- `peak_memory_mib`;
- `memory_delta_mib`;
- `queries_since_ripex_boot`;
- `http_status`;
- cache component state;
- fatal PHP type code only when applicable.

The same Phase 08 privacy boundary applies: no IDs, RUT, names, emails, companies, ranges, search terms, payloads, SQL text or fatal messages/paths are stored.

## Automatic summary

The export calculates groups by:

`AJAX action + RIPEX role + cache signature`

This keeps Reportes cold/warm/bypass samples separated when their cache state differs.

For every group the JSON includes:

- sample count;
- average duration;
- median duration;
- p95 duration;
- maximum duration;
- average/max PHP peak memory;
- average/max query delta;
- HTTP error count;
- fatal count.

This means the downloaded file can be analyzed immediately without first transforming every raw sample.

## Recommended staging procedure

1. deploy candidate `1.5.15` to staging;
2. enable `RIPEX_PORTAL_OBSERVABILITY` under the agreed config-change/rollback procedure;
3. enter Portal → Reportes as `ripex_admin`;
4. click **Nueva medición**;
5. execute the complete test flow: Pedidos, search/detail/create/edit as applicable, Inventario, Clientes, Reportes cold/warm/bypass, exports and representative seller/bodega flows;
6. return to Reportes as `ripex_admin`;
7. verify the sample counter;
8. click **Descargar métricas JSON**;
9. retain the downloaded JSON together with browser Network and server/FPM captures;
10. disable observability when the measurement window ends.

For isolated tests, start a new capture immediately before the scenario. Example:

- capture A: Reportes cold;
- capture B: Reportes warm;
- capture C: Inventario navigation/search;
- capture D: Clientes navigation/search;
- capture E: integrated role journey.

This makes client-facing comparisons easier to audit.

## What the JSON can prove

The file can directly show, for the optimized candidate:

- request duration distribution by endpoint/role/cache state;
- PHP peak allocated memory by endpoint;
- query delta by endpoint;
- cache hit/miss/bypass behavior;
- HTTP/fatal failures observed during the capture.

It does **not** replace external evidence for:

- PHP-FPM process RSS;
- CloudLinux/LVE CPU/memory faults;
- whole-host CPU contention;
- swap/I/O behavior;
- network/browser timing.

Those remain separate layers in the final RIPEX performance report.

## Client comparison workflow

The final documentation can keep the original production baseline alongside one or more exported JSON captures and populate a table such as:

| Endpoint | Baseline | Candidate JSON | External server check | Result |
|---|---:|---:|---:|---|
| Reportes cold peak memory | ~432 MiB FPM RSS observed before | PHP peak from JSON | new FPM RSS | compare |
| Reportes cold duration | browser/server baseline where available | median/p95 | Network timing | compare |
| Reportes warm | no generation cache equivalent | median/p95 + hit | FPM/Network | demonstrate cache benefit |
| Inventario | previous up-to-500 object flow | page/search metrics | FPM/Network | compare |
| Clientes | previous up-to-1000 directory flow | page/search metrics | FPM/Network | compare |

PHP allocated peak and FPM RSS are different metrics and must not be presented as if they were identical.

## Rollback / cleanup

Operational rollback remains simply disabling/removing:

`RIPEX_PORTAL_OBSERVABILITY`

To clear stored staging samples, use **Nueva medición** before disabling, or delete the option later through an explicitly authorized maintenance procedure.

The buffer is not autoloaded, and no server JSON file requires filesystem cleanup.
