# Plan: CR-SET-66 customs feedback improvements (WooCommerce)

> Date: 2026-07-28 · Spec: [spec.md](spec.md) · Branch: `CR-SET-66` (per user instruction, no feature branch)

## Architecture impact classification

**Business-case-only.** No new layers, boundaries, or wiring patterns: the change moves data capture
between existing WordPress hook surfaces (checkout/order billing → customer profile), extends the
existing platform-driven mapping option lists, and follows the established meta/attribute read
patterns in `Shop_Order_Service`. One documentation correction rides along: `DESIGN.md`'s module map
is missing the existing `Components/Customs` module — added as a doc-only fix (no diagram changes:
the component fits the existing "Components → core" dependency arrows).

## Shared contract (all tasks code against this)

Constants on `Customs_Mapping_Service` (added by T1):

```php
const USER_TAX_ID_META      = 'packlink_tax_id';   // customer-profile user meta
const USER_VAT_META         = 'packlink_vat';      // customer-profile user meta
const PREFIX_ATTRIBUTE      = 'attr:';             // product attribute (global or custom), by name
const PREFIX_PRODUCT_META   = 'meta:';             // product meta key
const PREFIX_ORDER_META     = 'order:';            // order meta key
const PREFIX_USER_META      = 'user:';             // customer user meta key
```

`PRODUCT_HS_CODE_META` / `PRODUCT_COUNTRY_OF_ORIGIN_META` unchanged. `BILLING_TAX_ID_META` /
`BILLING_VAT_META` stay temporarily (deleted by T4 cleanup once no task references them).

## Task graph

| Task | Scope | Files (disjoint per wave) | blockedBy |
|---|---|---|---|
| T1 | Mapping-service: constants, namespaced option lists (HS + new country-of-origin selects with global+custom attributes, transient-cached scan; tax-ID/VAT selects with curated keys) + its tests | `src/Components/Services/class-customs-mapping-service.php`, `src/tests/test-customs-mapping-service.php` | — |
| T2 | Handler/plugin: remove checkout + admin-order fields, add customer-profile fields, fix seed (empty tariff, `purchase_or_sale`, namespaced targets), rename upgrade script + its tests | `src/Components/Customs/class-customs-handler.php`, `src/class-plugin.php`, `src/upgrade/upgrade-4.2.0.php` → `upgrade-4.3.0.php`, `src/tests/test-customs-customer-fields.php`, `src/tests/test-customs-migration.php` | — |
| T3 | Order-sync resolution: namespaced read helpers (attr/meta/order/user + legacy fallback), country-of-origin mapped step, tax/VAT via customer profile with guest fallback + its tests | `src/Components/Order/class-shop-order-service.php`, `src/tests/test-shop-order-service-customs.php` | — |
| T4 | Cleanup (`BILLING_*` constants removal), `DESIGN.md` module-map row, gate (lint + suite), commits | cleanup + docs | T1, T2, T3 |

Waves: T1–T3 run in parallel (subagents, disjoint files, no commits — the main session commits one
commit per task in dependency order T1 → T3 → T2 → T4, authored `Implementator`). T1 commits first so
the constants exist before commits that reference them.

## Gate

1. `php -l` on every touched PHP file.
2. WPCS check on touched files (`vendor/bin/phpcs --standard=WordPress --severity=10`) if installed.
3. WP integration suite (`src/tests/`) if the WP tests SDK + DB are available locally; otherwise the
   gap is reported explicitly and CI covers it.
