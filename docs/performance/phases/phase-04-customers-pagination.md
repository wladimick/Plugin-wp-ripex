# Phase 04 — Customer directory backend pagination

Date: 2026-08-18  
Status: implemented in candidate `1.5.15`, pending integrated staging validation

## Objective

Reduce the cost of **Clientes** while preserving RIPEX commercial visibility, customer search, Ciudad/Vendedor filters and the existing customer-history drawer.

## Problem in 1.5.14

The original `ajax_get_customers()` could execute several independent `WP_User_Query` passes and then hydrate/filter the combined set in PHP:

- principal user query: up to 300 users for vendor and up to **1,000** for admin;
- additional metadata search query when a search term was present: up to 300/1,000 users;
- admin fallback query: up to **1,000** users with RIPEX commercial metadata;
- deduplication after the queries;
- per-user vendor-scope verification;
- repeated `get_user_meta()` calls for city, region, RUT, razón social, giro, vendedor and phone;
- final PHP search/filter/sort;
- only after all of that, `array_slice(..., 0, 1000)`.

This made request cost grow with the customer directory rather than with the page being viewed.

## Phase 04 implementation

A new bridge, `includes/class-ripex-portal-customers-performance.php`, replaces only `ripex_portal_get_customers`.

### Admin scope preserved

The admin candidate scope remains the historic union of:

- `customer`;
- `default_wholesaler`;
- `super-mayorista`;
- users that have non-empty RIPEX commercial metadata in the fields used by the old fallback query.

The role lookup is performed against the WordPress capabilities usermeta; commercial fallback eligibility is resolved without creating `WP_User` objects.

### Vendor scope preserved

Vendor customers are resolved from `afreg_additional_42207` using the same normalized vendor labels used by the existing portal helpers.

`customer_assigned_to_vendor()` remains active as defense in depth before a customer can enter the seller result set.

### Lightweight customer index

Instead of running several large `WP_User_Query` objects and then performing N+1 metadata reads, Phase 04:

1. resolves eligible customer IDs;
2. fetches basic `users` fields in bounded ID chunks;
3. fetches only the metadata keys used by the customer list;
4. orders metadata by `umeta_id` and retains the first value per key, matching `get_user_meta($id, $key, true)` behavior;
5. applies the existing city/vendor normalization rules and the existing visible search string against scalar arrays;
6. sorts the true matching set by display name;
7. returns **50 customers per page**.

No WooCommerce order objects are loaded by the customer directory itself.

## Search and filter behavior

Customer text search continues to cover the fields represented in the historic final search string:

- display name;
- email;
- RUT;
- razón social;
- giro;
- city;
- vendor.

City resolution keeps the existing precedence:

1. `billing_city`;
2. `shipping_city`;
3. `afreg_additional_city`;
4. `city`.

Region resolution keeps the equivalent existing precedence for state/region fields.

Admin vendor filtering still uses the same normalized text-key comparison. Vendor users cannot broaden their own commercial scope through request parameters.

## Frontend

A dedicated `assets/js/customers-pagination.js` is loaded after `portal.js` on the portal page.

It takes ownership only of the Clientes tab controls and adds:

- 50 customers per page;
- previous/next pagination;
- current page / total pages / filtered customer count;
- page reset on search/filter changes;
- stale-request protection;
- the same table columns;
- the same customer-history action and drawer content.

The large base `portal.js` remains untouched, keeping rollback isolated.

## Response contract

Existing response keys remain:

- `customers`
- `role`
- `count`
- `cities`
- `vendors`

Existing customer fields remain:

- `id`
- `name`
- `email`
- `phone`
- `city`
- `region`
- `rut`
- `razon_social`
- `giro`
- `vendedor`

Added pagination metadata:

- `page`
- `per_page`
- `total`
- `total_pages`

`count` continues to represent the filtered total and is therefore equivalent to `total` in Phase 04.

## Expected performance effect

The old path could create up to several thousand query-result objects across its principal, metadata-search and fallback passes and then perform repeated metadata reads for every deduplicated user.

Phase 04 uses a small number of set-oriented ID/scalar queries and limits browser payload/rendering to 50 rows per request. The remaining scalar filtering over the eligible directory is intentional in this phase because it preserves RIPEX's normalized city/vendor rules without adding schema/index migrations.

The actual PHP memory, SQL duration and AJAX reduction will be measured in the integrated staging retest.

## Integrated test checklist

Validate with both admin and seller roles:

1. customer count and page count;
2. next/previous navigation;
3. exact and partial name search;
4. email search;
5. RUT search;
6. razón social search;
7. giro search;
8. city search and city filter;
9. admin vendor filter;
10. vendor sees only assigned customers;
11. accented/case/spaced vendor assignments remain accepted according to existing normalization;
12. city and region values match 1.5.14;
13. customer-history button opens the same drawer data;
14. no customer outside seller scope can be opened from the seller directory.

Record:

- AJAX duration for `ripex_portal_get_customers`;
- response payload size;
- PHP-FPM RSS while opening Clientes;
- PHP-FPM RSS during searches/filters;
- request query count when instrumentation is enabled later.

## Acceptance gate

Phase 04 passes when the complete eligible customer directory remains navigable and role-equivalent while one browser request no longer returns/processes the previous up-to-1,000-row result payload.

## Rollback

Remove the Phase 04 require from `ripex-portal.php`:

`includes/class-ripex-portal-customers-performance.php`

The original `Ripex_Portal::ajax_get_customers()` becomes active again and the dedicated Phase 04 JavaScript is no longer enqueued.
