# Phase 03 — Inventory backend pagination

Date: 2026-08-18  
Status: implemented in candidate `1.5.15`, pending integrated staging validation

## Objective

Reduce the cost of **Inventario** without removing products, variations, search, category filtering, sorting, stock visibility or admin edit access.

The production baseline showed that the inventory endpoint could build a large product/variation collection on every request. This phase changes the inventory tab from a large one-shot response to a paginated server-side view.

## Problem in 1.5.14

The original `inventory_query_products()` used `WP_Query` with:

- `post_type = product + product_variation` when no category was selected;
- up to **500 IDs** per query;
- separate SKU and WordPress text-search queries when a search term was present;
- `wc_get_product()` for every candidate ID;
- category/parent/stock resolution for every materialized product;
- final sorting in PHP after the full result collection was built.

A category filter intentionally changed the query to parent `product` posts only. This behavior is preserved.

The frontend then rendered the complete returned collection in one table.

## Phase 03 implementation

### Backend

A new bridge, `includes/class-ripex-portal-inventory-performance.php`, replaces only `ripex_portal_get_products`.

The new request flow is:

1. validate the existing AJAX login/role/nonce rules through the original portal helpers;
2. resolve search/category/sort in SQL before product hydration;
3. use WooCommerce `wc_product_meta_lookup` for SKU and stock search/sort data;
4. count the filtered set;
5. fetch **60 IDs per page**;
6. warm post meta only for the requested IDs and required variation parents;
7. warm product category term cache only for the requested page;
8. hydrate only those IDs with `wc_get_product()`;
9. return the original product row fields plus pagination metadata.

### Search semantics preserved

The current inventory text search is represented as:

- post title;
- post content;
- post excerpt;
- SKU.

This corresponds to the previous WordPress `s` query plus the separate SKU `LIKE` query, but avoids creating hundreds of WooCommerce objects before returning a page.

### Category behavior preserved

- no category: published `product` and `product_variation` records;
- category selected: published parent `product` records only.

This intentionally matches the 1.5.14 `tax_query` implementation rather than changing business/UI behavior during a performance phase.

### Sorting

The existing choices remain:

- Nombre A-Z / Z-A;
- SKU ascendente / descendente;
- Stock menor a mayor / mayor a menor.

Sorting is now executed by the database before pagination. SKU and stock use WooCommerce's product lookup table. Products without a numeric stock quantity remain at the end of stock-ordered result sets.

### Frontend

A dedicated `assets/js/inventory-pagination.js` is loaded after `portal.js` only on the portal page.

It takes ownership only of the Inventario tab controls and adds:

- 60 rows per request;
- previous/next pagination;
- current page / total pages / filtered product count;
- page reset when search/category/order changes;
- the existing 350 ms search debounce;
- stale-request protection so an older search response cannot overwrite a newer one;
- the same table columns and admin edit-product action.

The main `portal.js` remains untouched, which keeps this phase isolated and makes rollback straightforward.

## Response contract

The existing keys are preserved:

- `products`
- `categories`

Each product still returns:

- `id`
- `sku`
- `name`
- `stock`
- `stock_raw`
- `status`
- `status_label`
- `categories`

The following pagination metadata is added:

- `page`
- `per_page`
- `total`
- `total_pages`

## Expected performance effect

Before Phase 03, one inventory request could materialize up to roughly 500 products/variations and then sort them in PHP.

After Phase 03, one normal page hydrates at most **60 WooCommerce product objects**, plus any required parent objects for displayed variations. This should materially reduce PHP peak memory and response payload size, especially on the unfiltered inventory tab.

The actual reduction will be measured only during the integrated staging/production retest block.

## Integrated test checklist

During final staging validation verify:

1. unfiltered first page and next/previous navigation;
2. total product count and total page count;
3. search by exact and partial SKU;
4. search by product title;
5. search using text present in description/excerpt;
6. each product category filter;
7. all six sort options;
8. variations are present in the unfiltered inventory;
9. category-filter behavior remains equivalent to 1.5.14;
10. displayed category names for variations come from the parent product;
11. stock quantity and stock status labels match 1.5.14;
12. admin edit-product button remains available only to `ripex_admin`;
13. vendor/bodega inventory visibility remains unchanged;
14. rapid typing does not render stale search results.

Record for before/after comparison:

- AJAX duration for `ripex_portal_get_products`;
- response payload size;
- PHP-FPM worker RSS while opening Inventario;
- PHP-FPM worker RSS while searching;
- CPU during the same actions.

## Acceptance gate

Phase 03 passes when the complete filtered inventory remains navigable and functionally equivalent while a normal request no longer builds the previous ~500-object collection.

## Rollback

Remove the Phase 03 require from `ripex-portal.php`:

`includes/class-ripex-portal-inventory-performance.php`

The original `Ripex_Portal::ajax_get_products()` and existing `portal.js` inventory behavior then become active again. The dedicated Phase 03 JS will no longer be enqueued.
