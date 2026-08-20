# Phase 06 — bounded order exports

Date: 2026-08-18  
Status: implemented in candidate `1.5.15`, pending integrated staging validation

## Objective

Reduce peak PHP memory and unnecessary WooCommerce order hydration in the two export paths that still loaded unbounded order collections, while preserving current authorization, CSV columns, filenames, seller semantics and bodega export flags.

## Problem in 1.5.14

Two export endpoints still scale with the complete order history rather than the requested/eligible set:

### `ripex_portal_export_my_orders`

The seller export starts with:

- `WC_Order_Query`;
- `limit => -1`;
- `return => objects`;
- complete order history materialized as `WC_Order` objects;
- only afterwards `vendor_created_filter()` decides whether each order was created by the current seller.

The historic `vendor_created_filter()` considers an order seller-created when either:

1. `_ripex_seller_id` equals the seller user ID; or
2. the legacy `shop_order.post_author` equals the seller user ID.

This export is intentionally different from the broader seller ownership/scope used by the Pedidos list.

### `ripex_portal_export_orders_by_date`

The bodega/admin range export also starts with `limit => -1`, loads all orders, and only then checks whether each order timestamp falls inside `date_from` / `date_to`.

For a short export window this means historical orders outside the requested range still consume query/object/memory work.

## Phase 06 implementation

A new isolated bridge, `includes/class-ripex-portal-exports-performance.php`, replaces only:

- `ripex_portal_export_my_orders`;
- `ripex_portal_export_orders_by_date`.

Single-order export and explicitly selected-order export remain on the existing implementation because their input is already bounded by the user's selection.

## Seller “Mis pedidos” export

Phase 06 resolves only candidate IDs created by the seller using a bounded legacy query over:

- `shop_order.post_author = current seller`; UNION
- `_ripex_seller_id = current seller`.

Candidate IDs are returned in pages of **100**, ordered by order date descending, matching the existing export direction.

Only those IDs are hydrated with `wc_get_order()` one at a time. The original `vendor_created_filter()` is still executed as a final semantic/security check before a CSV row is written.

The following CSV columns remain unchanged and in the same order:

1. Pedido
2. Fecha
3. Cliente
4. Rut
5. Razón Social
6. Giro
7. Vendedor
8. Creador
9. Estado
10. Método de pago
11. Transporte
12. Dirección
13. Total

Filename remains:

`mis-pedidos-{user_id}.csv`

### HPOS note

The `post_author` fallback is an explicit legacy behavior of the existing plugin, so this candidate lookup still touches `wp_posts` / `wp_postmeta`. This is not being disguised as HPOS-compatible work. The dedicated HPOS phase must decide how to preserve/replace this legacy creator signal before direct order-table access is removed.

## Bodega/admin range export

The date export now applies the requested inclusive date range directly to `WC_Order_Query` using `date_created` and processes the result in pages of **100 orders**.

A second timestamp check remains in PHP to preserve the existing inclusive range behavior defensively.

The `only_pending_export` behavior remains based on the existing `_ripex_exported_to_bodega` order meta.

The existing item-level CSV layout remains unchanged:

1. Pedido
2. Fecha
3. Rut
4. Vendedor
5. Razón Social
6. Giro
7. Dirección
8. Transporte
9. SKU
10. Producto
11. Cantidad
12. Stock

Filename remains:

`pedidos-{date_from}-a-{date_to}.csv`

The browser response still contains:

- `filename`;
- base64 `csv`;
- `count_orders`.

After the complete export set is built, only selected order IDs are retained. Orders are then re-hydrated one at a time to preserve the legacy `_ripex_exported_to_bodega = 1` write without retaining the full selected object collection in memory.

## CSV memory behavior

Rows are written incrementally to a `php://temp` stream rather than repeatedly concatenating a growing PHP string during order processing.

The final AJAX contract still requires a base64 CSV string, so the final encoded payload necessarily exists in PHP memory before `wp_send_json_success()`. Phase 06 therefore removes the large WooCommerce object collection and repeated growing-string concatenation, but it does **not** claim constant-memory behavior for arbitrarily large CSV downloads.

A future export architecture could stream downloadable files directly, but that would change the frontend/API contract and is intentionally outside this parity-focused phase.

## Authorization preserved

- `ripex_portal_export_my_orders`: `ripex_vendedor` only.
- `ripex_portal_export_orders_by_date`: `ripex_admin` and `ripex_bodeguero` only.
- Existing AJAX login/role/nonce checks are retained through `check_ajax_access()`.

## Integrated staging checklist

### Seller export

1. export with a seller that has portal-created orders;
2. confirm orders with matching `_ripex_seller_id` appear;
3. confirm historic seller-created `post_author` orders still appear;
4. confirm assigned-but-not-created customer orders do **not** become part of “Mis pedidos” merely because the seller can view them;
5. compare row count and every CSV column against 1.5.14 for the same seller;
6. verify descending date order;
7. verify filename.

### Date export

1. one-day range;
2. multi-day range;
3. `only_pending_export` enabled;
4. `only_pending_export` disabled;
5. compare exact order/item row counts against 1.5.14;
6. verify `_ripex_exported_to_bodega` is set for exported orders;
7. repeat export with pending-only and confirm already-exported orders are excluded;
8. verify `count_orders` and filename.

### Performance capture

Record for both endpoints:

- browser Network duration;
- response payload size;
- PHP-FPM peak RSS;
- CPU during export;
- number of orders/items exported.

Use a representative larger date range as well as a normal operational range.

## Acceptance gate

Phase 06 passes when exported data and authorization are equivalent to 1.5.14 while:

- seller export never starts by materializing the complete WooCommerce order history;
- range export queries only the requested date range;
- WooCommerce order objects are processed in bounded batches / one-at-a-time final flag updates.

## Rollback

Remove the Phase 06 require from `ripex-portal.php`:

`includes/class-ripex-portal-exports-performance.php`

The original `Ripex_Portal::ajax_export_my_orders()` and `Ripex_Portal::ajax_export_orders_by_date()` callbacks become active again.
