# RIPEX Portal (Roles & Pedidos)

Custom WordPress/WooCommerce portal for RIPEX.

Current production baseline at the start of the performance program: **1.5.14**.  
Current performance candidate in the draft branch: **1.5.15**.

## Performance program

The repository keeps an auditable performance record so application and infrastructure improvements can be compared objectively. Multiple controlled code phases are accumulated in the draft PR; PHP/JavaScript/lifecycle-parity CI remains active after each phase, and the complete candidate will be tested in staging before merge or production deployment.

- [Baseline — 2026-08-18](docs/performance/2026-08-18-baseline.md)
- [Retest protocol](docs/performance/retest-protocol.md)
- [Optimization roadmap](docs/performance/optimization-roadmap.md)
- [Phase 01 — Vendor order scope](docs/performance/phases/phase-01-vendor-order-scope.md)
- [Phase 02 — Orders search](docs/performance/phases/phase-02-orders-search.md)
- [Phase 03 — Inventory pagination](docs/performance/phases/phase-03-inventory-pagination.md)
- [Phase 04 — Customers pagination](docs/performance/phases/phase-04-customers-pagination.md)
- [Phase 05 — Roles/capabilities lifecycle](docs/performance/phases/phase-05-roles-capabilities-lifecycle.md)
- [Phase 06 — Export batching](docs/performance/phases/phase-06-exports-batching.md)
- [Phase 07 — Cache and invalidation](docs/performance/phases/phase-07-cache-invalidation.md)
- [Phase 08 — Opt-in AJAX observability](docs/performance/phases/phase-08-observability.md)
- [Infrastructure recommendation](docs/infrastructure/recommended-architecture.md)
- [Changelog](CHANGELOG.md)

## Current status

- P0 Reportes: bounded-memory implementation complete; integrated staging validation pending.
- P0 vendor order scope: Phase 01 complete; integrated staging validation pending.
- P1 Pedidos search-before-pagination: Phase 02 complete; integrated staging validation pending.
- P1 Inventario backend pagination: Phase 03 complete; integrated staging validation pending.
- P1 Clientes backend pagination/filtering: Phase 04 complete; integrated staging validation pending.
- P1 Roles/capabilities lifecycle: Phase 05 complete; integrated staging validation pending.
- P1 Export batching: Phase 06 complete; integrated staging validation pending.
- P1 Cache/invalidation: Phase 07 complete; integrated staging validation pending.
- Observability: Phase 08 implemented, disabled by default, intended for the controlled staging measurement window.
- Next decision point: integrated staging parity/performance block before dedicated HPOS work.

Pre-optimization production measurement showed approximately **432 MiB peak RSS in a single PHP-FPM worker** for an isolated Reportes load, while the RIPEX CloudLinux account is limited to 1 GiB total physical memory and production has only two PHP workers.

## Safety rule

Maintain current RIPEX business behavior and role visibility. Performance changes are not deployed to production until the integrated staging parity/performance block passes and a rollback path is confirmed.
