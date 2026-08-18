# Phase 01 — Vendor order scope

Date: 2026-08-18  
Candidate: RIPEX Portal 1.5.15  
Status: implemented in draft branch; validation deferred to the integrated staging test block.

## Objective

Reduce the server cost of loading the Pedidos list for `ripex_vendedor` while preserving the commercial visibility rules already used in production.

## Pre-change behavior

The 1.5.14 vendor resolver performed a query over the complete legacy `shop_order` set, LEFT JOINed relevant `wp_postmeta`, grouped every order and only then decided whether each order belonged to the current vendor. It separately read every non-empty `afreg_additional_42207` usermeta row to resolve customer fallback ownership.

The order page itself shows only 30 rows, so the ownership preparation work scaled with historical order volume rather than with the page being displayed.

## Business rules that must not change

Ownership uses strict precedence:

1. if `_ripex_seller_id` is non-empty, it is authoritative;
2. otherwise, if `_ripex_vendedor` is non-empty, that label is authoritative;
3. only when neither explicit order vendor field exists may the order fall back to the customer's `afreg_additional_42207` assignment;
4. the final page still runs `vendor_mine_filter()` as defense in depth before returning an order to the browser.

`post_author` is not used as a commercial-vendor fallback by the effective production filter and is therefore not introduced in this optimization.

## Implementation

A new bridge class `Ripex_Portal_Orders_Performance` replaces only the `ripex_portal_get_orders` AJAX callback.

The vendor ID resolver now performs targeted passes:

- `_ripex_seller_id`: query only this meta key and return orders whose grouped explicit seller equals the current vendor;
- `_ripex_vendedor`: query only this meta key for orders that do not have an explicit seller, normalize the stored label and compare against the existing vendor labels;
- customer fallback: read the RIPEX customer-vendor usermeta key, identify assigned customer IDs, then query only `_customer_user` order meta for orders with no explicit seller/vendor.

This removes the previous all-orders / all-relevant-postmeta GROUP BY query while retaining the same precedence semantics.

## Deliberately not changed in this phase

- Admin/bodeguero order query path.
- The existing 30-row page size.
- Order row response JSON.
- Status/date filters.
- Search behavior. In 1.5.14 search is applied after the current page has already been fetched, which can omit matches from other pages. That is scheduled for Phase 02 so the functional correction is isolated and reviewable.
- HPOS compatibility. This phase optimizes the current legacy posts/postmeta storage path; HPOS migration remains a separate later phase.

## Expected effect

- Lower SQL rows examined for vendor page loads.
- Less PHP memory needed to build the vendor ownership candidate set.
- Reduced latency growth as historical order volume increases.
- No change to which orders a vendor can see.

## Integrated acceptance tests

Run during the final staging test block for this optimization package:

1. compare the first three Pedidos pages for at least one active vendor between 1.5.14 and candidate;
2. compare total order count for that vendor;
3. filter by status and date range and compare counts/results;
4. verify a known order with `_ripex_seller_id` assigned to another vendor is never visible;
5. verify an order with explicit `_ripex_vendedor` follows that field even when its customer is assigned elsewhere;
6. verify customer-assignment fallback works when the order has neither explicit vendor field;
7. capture PHP worker RSS and AJAX duration for initial vendor Pedidos load.

## Rollback

Remove `includes/class-ripex-portal-orders-performance.php` from the plugin bootstrap (or revert this phase commits). The original `Ripex_Portal::ajax_get_orders()` remains intact in the main class, so rollback does not require reconstructing the legacy implementation.
