# RIPEX Portal (Roles & Pedidos)

Custom WordPress/WooCommerce portal for RIPEX.

Current production baseline at the start of the performance program: **1.5.14**.  
Current performance candidate in the draft branch: **1.5.15**.

## Performance program

The repository keeps an auditable performance record so application and infrastructure improvements can be compared objectively. Multiple controlled code phases are accumulated in the draft PR; PHP CI remains active after each phase, and the complete candidate will be tested in staging before merge or production deployment.

- [Baseline — 2026-08-18](docs/performance/2026-08-18-baseline.md)
- [Retest protocol](docs/performance/retest-protocol.md)
- [Optimization roadmap](docs/performance/optimization-roadmap.md)
- [Phase 01 — Vendor order scope](docs/performance/phases/phase-01-vendor-order-scope.md)
- [Infrastructure recommendation](docs/infrastructure/recommended-architecture.md)
- [Changelog](CHANGELOG.md)

## Current status

- P0 Reportes: first bounded-memory implementation complete; integrated staging validation pending.
- P0 vendor order scope: Phase 01 complete; integrated staging validation pending.
- Next planned phase: Pedidos search-before-pagination.

Pre-optimization production measurement showed approximately **432 MiB peak RSS in a single PHP-FPM worker** for an isolated Reportes load, while the RIPEX CloudLinux account is limited to 1 GiB total physical memory and production has only two PHP workers.

## Safety rule

Maintain current RIPEX business behavior and role visibility. Performance changes are not deployed to production until the integrated staging parity/performance block passes and a rollback path is confirmed.
