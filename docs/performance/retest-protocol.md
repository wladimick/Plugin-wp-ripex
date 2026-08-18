# RIPEX performance retest protocol

Use this protocol before and after each performance release so improvements are measured objectively.

## Safety rules

- Read-only server observation only.
- `CAMBIOS=NO`.
- `REINICIOS=NO`.
- Do not run synthetic load against production during business hours.
- Run one controlled user journey at a time.
- Keep date range and portal role identical between before/after comparisons.

## Browser capture

Open DevTools → Network and preserve the log. Record for every tested action:

- request/action name;
- HTTP status;
- total duration;
- TTFB/waiting if available;
- response size;
- timestamp.

Priority actions: initial Pedidos, next page, order detail, Inventario, Reportes 30 days, Clientes, Carritos, customer/product search in Crear pedido.

## Server capture — normal navigation

```bash
echo "===== RIPEX PERF CAPTURE ====="
echo "Inicio: $(date '+%Y-%m-%d %H:%M:%S %Z')"

for i in $(seq 1 30); do
    echo
    echo "===== SAMPLE $i/30 — $(date '+%H:%M:%S') ====="
    uptime
    free -m | head -3
    lvectl list-user 2>/dev/null | awk 'NR==1 || $1=="ripex"'
    ps -u ripex -o pid,%cpu,%mem,rss,etimes,cmd --sort=-rss 2>/dev/null | head -12
    sleep 3
done

echo "CAMBIOS=NO"
echo "REINICIOS=NO"
```

Navigation sequence: **Pedidos → next page → detail → Inventario → Reportes → Carritos**.

## Server capture — isolated Reportes

```bash
echo "===== RIPEX — PRUEBA AISLADA REPORTES ====="
echo "Inicio: $(date '+%Y-%m-%d %H:%M:%S %Z')"

for i in $(seq 1 25); do
    echo
    echo "===== SAMPLE $i/25 — $(date '+%H:%M:%S') ====="
    ps -u ripex -o pid,%cpu,%mem,rss,etimes,cmd --sort=-rss 2>/dev/null | \
      awk 'NR==1 || /php-fpm: pool ripex_cl/'
    vmstat 1 2 | tail -1
    sleep 2
done

echo "CAMBIOS=NO"
echo "REINICIOS=NO"
```

Start the command, wait ~5 seconds, then open only **Portal Pedidos → Reportes** and wait for completion.

## Comparison table

| Metric | Baseline 2026-08-18 | New measurement | Improvement |
|---|---:|---:|---:|
| Report AJAX duration | not captured | | |
| Report worker peak RSS | ~432 MiB | | |
| Concurrent PHP RSS | ~586 MiB | | |
| Pedidos AJAX duration | not captured | | |
| Inventario AJAX duration | not captured | | |
| Clientes AJAX duration | not captured | | |
| OOM/faults during window | not available | | |

## Functional parity checklist for Reportes

Compare old/new results for the same user role and exact date range:

- revenue KPI;
- order count;
- average ticket;
- customers;
- units/items;
- stock KPI;
- active sellers;
- low-stock count/table;
- sales-by-day series;
- top products;
- seller ranking;
- transport ranking;
- order-status counts;
- company ranking;
- inactive customers;
- products without movement.

Any difference must be explained before production deployment.
