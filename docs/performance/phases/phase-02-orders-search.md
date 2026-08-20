# Phase 02 — Orders search before pagination

Date: 2026-08-18
Status: implemented in candidate `1.5.15`, pending integrated staging validation

## Objective

Correct and optimize the Portal Pedidos search flow so the search is resolved across the complete filtered order set **before** pagination. Preserve the exact visible search fields and seller-access rules from production 1.5.14.

## Problem in 1.5.14

The original `ajax_get_orders()` first requested one page of approximately 30 WooCommerce orders and only then built a PHP search string for those already-paginated objects.

Consequences:

- a valid order on page 10 could appear not to exist while the user was on page 1;
- totals and pagination represented the unsearched set rather than the true search result set;
- vendor search inherited the same post-pagination limitation;
- changing page was sometimes required to discover a known order/customer.

The historic searchable string contains:

- order ID (`#ID`);
- billing full name;
- billing company;
- billing email;
- RIPEX RUT;
- RIPEX razón social;
- RIPEX giro;
- RIPEX vendedor;
- payment method title.

RIPEX AFREG values use current customer usermeta first and fall back to the order snapshot only when customer usermeta is empty.

## Phase 02 implementation

A new bridge, `includes/class-ripex-portal-orders-search-performance.php`, replaces only searched orders-list requests.

### No-search requests

When `search` is empty, the request is delegated directly to the Phase 01 orders bridge. This avoids changing the normal orders-list path in this phase.

### Search requests

The new flow is:

1. resolve broad candidate IDs in SQL using only the historic searchable fields;
2. preserve `get_customer_afreg()` precedence by joining customer AFREG usermeta first and order snapshot metadata second;
3. support legacy/guest orders whose `_customer_user` is empty but billing email maps to an existing WordPress user;
4. apply status and date filters before WooCommerce order objects are created;
5. apply the existing `vendor_mine_filter()` before pagination for seller users;
6. validate the exact historic PHP search string before pagination;
7. calculate `total` and `total_pages` from the real matching set;
8. materialize only the requested final page for response building;
9. keep the final seller-scope check as defense in depth.

## Behavior intentionally preserved

- 30 orders per page;
- same response JSON keys;
- same search fields;
- same status/date filters;
- admin and warehouse can search the global filtered scope;
- vendor can never search outside `vendor_mine_filter()` scope;
- warehouse totals/currency remain hidden;
- edit/export flags are still calculated by the existing portal helpers.

## Performance characteristics

This phase is primarily a correctness fix that also reduces unnecessary page-by-page searching.

For a search request, SQL first narrows candidates using only relevant order/customer fields. Candidate WooCommerce objects are then validated before pagination to guarantee parity with the previous PHP `strpos(strtolower(...))` behavior. Broad one-character searches can still produce a larger candidate set; this is accepted for compatibility and can be revisited after production search telemetry is available.

No-search traffic receives no additional search overhead.

## HPOS note

The candidate resolver intentionally uses the current legacy `wp_posts/wp_postmeta` order storage because RIPEX HPOS state has not yet been validated. Direct order-table access is scheduled for the dedicated HPOS phase. This change must not be interpreted as an HPOS compatibility declaration.

## Integrated test checklist

During the final staging test block, verify searches for:

- exact order ID with and without `#`;
- partial order ID;
- first name / last name / full billing name;
- company;
- billing email;
- RUT;
- razón social;
- giro;
- vendedor label;
- payment method title;
- status + search;
- date range + search;
- vendor scope + search;
- search result located well beyond page 1 in production 1.5.14.

For each case record:

- result IDs;
- `total`;
- `total_pages`;
- browser Network duration;
- final page size;
- any seller-scope mismatch.

## Acceptance gate

Phase 02 passes when:

1. a matching order is found regardless of which unfiltered page previously contained it;
2. search totals/pagination represent the actual matching set;
3. all historic search fields return equivalent results;
4. no vendor sees another vendor's order;
5. PHP syntax CI remains green on 8.0 and 8.3.

## Rollback

Remove the Phase 02 require from `ripex-portal.php`. The Phase 01 orders bridge remains intact and resumes handling `ripex_portal_get_orders`.
