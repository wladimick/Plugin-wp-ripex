# RIPEX Portal (Roles & Pedidos)

Custom WordPress/WooCommerce portal for RIPEX.

Current production/repository baseline at the start of the performance program: **1.5.14**.

## Performance program

The repository now keeps an auditable performance record so application and infrastructure improvements can be compared objectively.

- [Baseline — 2026-08-18](docs/performance/2026-08-18-baseline.md)
- [Retest protocol](docs/performance/retest-protocol.md)
- [Optimization roadmap](docs/performance/optimization-roadmap.md)
- [Infrastructure recommendation](docs/infrastructure/recommended-architecture.md)

## Current priority

P0 is the Reportes endpoint. Pre-optimization production measurement showed approximately **432 MiB peak RSS in a single PHP-FPM worker** for an isolated report load, while the RIPEX CloudLinux account is limited to 1 GiB total physical memory and production has only two PHP workers.

Changes should be validated first in staging with functional parity by role and then measured using the documented baseline protocol before production rollout.
