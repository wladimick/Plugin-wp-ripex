# RIPEX infrastructure recommendation

## Executive recommendation

RIPEX should move from a shared 1-vCPU-equivalent / 1-GiB LVE to **dedicated production compute**. The public traffic volume alone does not demand a large server; the recommendation is driven by WooCommerce dynamic workload, authenticated Portal AJAX, commercial criticality, three WordPress environments, cron/Action Scheduler and the measured memory/CPU behavior of current requests.

The application should be optimized before final right-sizing, but current measurements are already enough to conclude that the existing shared LVE has insufficient operational margin.

## Current constraint

Production today effectively has:

- CloudLinux CPU quota: ~1 logical CPU worth of time;
- account physical memory: 1 GiB shared across production/staging/test processes;
- production PHP-FPM max workers: 2;
- I/O quota: 1 MiB/s;
- 99 historical account OOM events;
- measured Reportes worker peak: ~432 MiB RSS;
- shared-host CPU contention during the isolated report test.

This creates queueing before traffic is objectively high: one heavy report can occupy one of two production workers and a large fraction of account RAM.

## Sizing tiers

### Minimum dedicated production target

Suitable only after P0/P1 application optimizations are validated:

- **2 dedicated vCPU**
- **8 GiB RAM**
- Premium SSD / NVMe-class storage
- target at least ~100 MB/s practical storage throughput
- persistent Redis object cache
- PHP 8.3 + OPcache
- MariaDB/MySQL tuned for WooCommerce
- production only; staging/test elsewhere

This is the cost-conscious floor, not the preferred business-critical target.

### Recommended production target

For the current RIPEX workload and growth margin:

- **4 vCPU**
- **8–16 GiB RAM**; on Azure, a 4-vCPU / 16-GiB general-purpose VM is a clean standard shape
- Premium SSD-class managed disk
- Redis persistent object cache
- PHP-FPM capacity initially conservative and tuned from measured post-optimization worker RSS
- OPcache enabled and sized correctly
- MariaDB/MySQL buffer pool sized from real workload
- real system cron for WP-Cron, as currently used
- off-host backups
- CDN/WAF for public static/cacheable traffic
- monitoring for PHP-FPM queue, CPU, memory, disk latency, DB latency and 5xx errors

### If production + staging + test must share one dedicated VM

Use **4 vCPU / 16 GiB as the minimum recommended shared-environment shape**, with explicit resource controls for non-production. Prefer separating staging/test so they cannot consume production PHP/DB capacity during tests, scans or scheduled jobs.

## Storage

The current CloudLinux I/O cap is only 1 MiB/s. Azure Premium SSD is appropriate for production ecommerce because it provides predictable IOPS/throughput and low latency. Select the disk by measured latency/throughput rather than capacity alone; RIPEX only occupies a few GiB today.

A practical starting point is a Premium SSD tier in the ~100 MB/s throughput class or better, while monitoring database and PHP log latency. Do not buy large capacity solely to gain space RIPEX does not need.

## PHP-FPM

Do not copy the current `pm.max_children=2` blindly to a dedicated target, and do not raise it on the existing 1-GiB LVE before code optimization.

After P0 optimization:

1. measure typical and p95 PHP worker RSS;
2. reserve memory for OS, DB, Redis and filesystem cache;
3. size `pm.max_children` from the remaining memory and CPU concurrency;
4. validate under controlled concurrency.

If optimized workers settle near 120–180 MiB, a 4-vCPU / 8–16-GiB server has room for substantially more than two PHP workers, but CPU/DB measurements should determine the final count.

## Database

The known aggregate DB footprint (~0.30 GiB across three databases) is small. The issue is query pattern, not storage volume. Keep DB local on the production VM initially unless monitoring demonstrates a reason to split it. A managed external DB adds cost/network latency/operational complexity and is not justified by current traffic alone.

Priorities:

- inspect slow queries during controlled diagnostics;
- review `wp_options` autoload size;
- review `wp_postmeta`/`wp_usermeta` hot queries;
- review Action Scheduler tables;
- validate HPOS state and migrate custom order access to WooCommerce APIs before major WooCommerce upgrades.

## Cache strategy

Use Redis as persistent WordPress object cache after compatibility validation. It helps repeated WooCommerce metadata/options lookups, but is **not** a substitute for fixing unbounded queries. Full-page caching/CDN mainly helps logged-out traffic; the authenticated RIPEX portal and `admin-ajax.php` still require efficient PHP/DB execution.

## Environment separation

Preferred topology:

- **Production:** dedicated VM/resources.
- **Staging/Test:** separate lower-cost VM or tightly capped non-production service.
- Block/noindex non-production public access unless specifically required.
- Disable unnecessary crawlers, audits and scheduled jobs on non-production.

## Why 4 vCPU / 8–16 GiB

The recommendation is intentionally not based on pageviews alone. 2026 traffic averages are modest, but RIPEX has bursty authenticated ecommerce work. The measured pre-optimization report consumed ~432 MiB in one worker and production has only two workers today. Four vCPU provides concurrency headroom, while 8–16 GiB gives safe room for PHP-FPM, MariaDB, Redis, OS/cache and transient spikes without reproducing the current 1-GiB LVE pressure.

The final size should be revisited after the first optimization releases. If p95 CPU/RAM remain low, right-size downward; if concurrent portal use grows, scale upward without redesigning the application.

## External reference points

- Microsoft Azure Premium SSD: https://learn.microsoft.com/azure/virtual-machines/disks-types
- Azure Premium storage performance: https://learn.microsoft.com/azure/virtual-machines/premium-storage-performance
- WooCommerce memory guidance: https://woocommerce.com/document/increasing-the-wordpress-memory-limit/
- WooCommerce cache documentation: https://woocommerce.com/document/woocommerce-product-search/settings/cache/

## Decision

**Recommended client proposal:** after P0 application optimization, migrate RIPEX production to dedicated compute targeting **4 vCPU and 8–16 GiB RAM with Premium SSD and Redis**, and isolate non-production. Retest with the same baseline protocol before/after migration so the client can see the gains from code and infrastructure separately.
