# Phase 05 — Roles/capabilities lifecycle

Date: 2026-08-18  
Status: implemented in candidate `1.5.15`, pending integrated staging validation

## Objective

Remove RIPEX role/capability reconciliation from the hot path of every WordPress request while preserving the exact portal hook behavior and the existing role permissions.

## Problem in 1.5.14

`Ripex_Portal::__construct()` begins with:

```php
self::ensure_roles_caps();
```

`ensure_roles_caps()` then:

- loads/creates `ripex_admin`;
- iterates the explicit admin capability list and calls `add_cap()` for each capability;
- reads `shop_manager` and copies every granted capability to `ripex_admin`;
- loads/creates `ripex_vendedor` and iterates its capability list;
- loads/creates `ripex_bodeguero` and ensures `read`.

The constructor is invoked when the portal singleton is first requested, so this lifecycle work occurs on normal frontend/AJAX/admin requests even though WordPress roles are persistent configuration.

WordPress can avoid a physical option write when the resulting serialized option is unchanged, but the repeated role lookup, capability iteration, mutation attempt, serialization/comparison path and shop-manager copying still do not belong in every request.

## Phase 05 implementation

A new bridge, `includes/class-ripex-portal-roles-lifecycle.php`, is loaded immediately after the original `Ripex_Portal` class and before all performance endpoint bridges.

### Constructor hot-path removal

The bridge creates the existing `Ripex_Portal` singleton with `ReflectionClass::newInstanceWithoutConstructor()` and installs that object into the original private static singleton property.

It then registers the exact hook contract that currently exists in `Ripex_Portal::__construct()`, excluding only the call to `ensure_roles_caps()`.

This means the same portal object and the same public methods continue to service:

- shortcodes;
- frontend assets;
- login/admin restrictions;
- WooCommerce email/payment/shipping filters;
- public product-code search;
- all RIPEX AJAX endpoints;
- stock repair admin page.

The Phase 01–04 callback replacement layers continue to work because they receive the same `Ripex_Portal::instance()` singleton and remove/replace the same original AJAX callbacks.

### Versioned capability migration

At `plugins_loaded` priority 1, Phase 05 computes a lightweight schema signature containing:

- Phase 05 role schema version;
- `RIPEX_PORTAL_VERSION`;
- a hash of the currently granted `shop_manager` capabilities.

The signature is stored in:

`ripex_portal_roles_schema_signature`

If the stored signature matches, no full capability reconciliation runs.

If the RIPEX version changes or the `shop_manager` granted capability set changes, `Ripex_Portal::ensure_roles_caps()` runs once and the signature is updated only after the reconciliation completes.

This preserves the historical RIPEX behavior where `ripex_admin` inherits granted `shop_manager` capabilities without copying them on every request.

### Repair safety

Even when the signature matches, Phase 05 performs cheap in-memory checks for the three RIPEX roles and critical sentinel capabilities.

A missing role or critical capability triggers a full reconciliation. This keeps basic self-healing without returning to the old every-request `add_cap()` loop.

Activation itself is unchanged: the original `Ripex_Portal::activate()` still calls `create_roles()`, which calls `ensure_roles_caps()`. Phase 05 intentionally does not pre-mark activation as complete; the first request after activation may reconcile once more and only then records the schema signature. This favors correctness if an activation request is interrupted.

## Hook-parity CI guard

Bypassing the constructor introduces a maintenance risk: a future developer could add a hook to `Ripex_Portal::__construct()` and forget to add the same hook to the lifecycle bridge.

To make that visible immediately, Phase 05 adds:

`tests/check-role-lifecycle-hook-parity.php`

The test tokenizes both PHP files, extracts all `add_action`, `add_filter` and `add_shortcode` registrations from:

- `Ripex_Portal::__construct()`; and
- `Ripex_Portal_Roles_Lifecycle::register_portal_hooks()`.

It normalizes the singleton variable/class references and fails CI when the two hook sets diverge.

The GitHub Actions matrix runs this guard under both PHP 8.0 and PHP 8.3 after PHP/JavaScript syntax checks.

## Expected performance effect

Phase 05 is not expected to produce the same dramatic peak-memory reduction as Reportes/Inventario. Its value is that it removes avoidable lifecycle work from **every request**:

- page views;
- `admin-ajax.php` calls;
- WooCommerce dynamic requests;
- portal tab requests;
- wp-admin requests where the plugin loads;
- cron/other WordPress bootstrap paths that load active plugins.

The expected benefit is lower constant PHP/WordPress bootstrap cost and fewer repeated option serialization/update attempts.

The actual request-duration/query reduction will be measured during the integrated staging test block rather than estimated as a measured gain now.

## Integrated test checklist

Validate in staging after deploying the complete candidate:

1. plugin activates cleanly with Phase 05 enabled;
2. `ripex_admin`, `ripex_vendedor`, `ripex_bodeguero` still exist;
3. admin can enter the portal and wp-admin as before;
4. vendor remains blocked from unrelated wp-admin screens and can edit only allowed RIPEX orders;
5. warehouse portal access remains unchanged;
6. create-order permissions remain admin/vendor only;
7. inventory edit link remains admin only;
8. WooCommerce order/product capabilities required by RIPEX admin remain available;
9. payment-to-credit behavior remains unchanged;
10. portal shortcodes/assets/AJAX endpoints continue to register once, without duplicate callback execution;
11. first request after candidate deployment creates/updates `ripex_portal_roles_schema_signature`;
12. subsequent requests do not repeatedly change that option;
13. CI hook-parity check remains green.

For a controlled repair test in staging, remove or alter a non-production test RIPEX role/capability only if a rollback is prepared; confirm the repair path and restore the exact baseline immediately. This is **not** required for production validation.

## Measurement

During the integrated retest compare a simple dynamic portal/AJAX request before/after and, if instrumentation is enabled, record:

- total PHP duration;
- query count;
- peak memory;
- whether `wp_user_roles` is updated during a normal request;
- request frequency/aggregate savings under repeated AJAX traffic.

The principal acceptance criterion is lifecycle correctness plus removal of repeated capability reconciliation from the normal request path.

## Rollback

Remove this require from `ripex-portal.php`:

```php
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-roles-lifecycle.php';
```

The original `Ripex_Portal::instance()` will again invoke its constructor and restore the legacy every-request `ensure_roles_caps()` behavior. The stored schema-signature option is harmless if left in the database because 1.5.14 does not read it.
