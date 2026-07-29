# Spec: CR-SET-66 customs feedback improvements (WooCommerce)

> Date: 2026-07-28 · Status: Approved (interview 2026-07-28) · Branch: `CR-SET-66`
> Research: [research.md](research.md) · Source: `docs/change-requests/2026-06-cr66-customs-support/improvements.md`
> Core dependency: `packlink/integration-core` `dev-CR-SET-66-Customs` @ `74b7d3e` — **already vendored**.

> **Refinement (2026-07-28, post-implementation):** customer Tax ID and Company VAT are ONE field
> and ONE mapping (`mapping_receiver_tax_id`), not two. The core routes the single value to the
> customs `tax_id` or `vat_number` attribute based on the configured receiver user type
> (`CustomsService::getReceiver()`), and both share one default (`default_receiver_tax_id`).
> UC-1 has a single "Tax ID / VAT number" profile field (`packlink_tax_id`); UC-4's VAT select and
> UC-5's separate VAT resolution are dropped — `Shop_Order_Service` feeds the one resolved value to
> both `Order::setTaxId()` and `Order::setVatNumber()`. WC no longer emits a `mapping_company_vat`
> definition (the core model field stays, unused by WC).

## Decisions (from interview)

| # | Decision | Choice |
|---|---|---|
| D1 | Customer Tax ID / VAT mapping sources | Dedicated profile field + curated known order-meta keys (no meta scanning) |
| D2 | Product attribute enumeration | Global attribute taxonomies + per-product custom attribute names scanned from `_product_attributes` (transient-cached) |
| D3 | Next release version | 4.3.0 (upgrade script named now; header/readme lockstep at release) |
| D4 | Delegation | Yes — parallel subagent tasks, one commit per task |

## Assumptions

- The customs feature is unreleased (branch-only), so renaming meta keys, upgrade scripts, and seeded
  values requires **no data migration**.
- PHP 7.0 floor: `array()` syntax, no PHP 7.1+ features in plugin code.
- Guest orders have no customer profile; the profile step of the resolution chain is skipped for them.

## Mapping-value namespace (contract for all tasks)

Mapping select option values are namespaced strings; constants live on `Customs_Mapping_Service`:

| Prefix | Meaning | Read accessor |
|---|---|---|
| `attr:{name}` | Product attribute (global `pa_*` taxonomy or per-product custom attribute name) | `WC_Product::get_attribute(name)` |
| `meta:{key}` | Product meta | `WC_Product::get_meta(key)` |
| `order:{key}` | Order meta | `WC_Order::get_meta(key)` |
| `user:{key}` | Customer user meta | `get_user_meta(customer_id, key, true)` |

Legacy un-namespaced values (saved before this change): `pa_*` → attribute, anything else → meta of the
context's entity (product/order) — preserved as fallback in the read helpers.

Dedicated field keys: product `_packlink_hs_code`, `_packlink_country_of_origin` (unchanged);
customer user meta `packlink_tax_id`, `packlink_vat` (new; replace the removed
`_billing_packlink_tax_id` / `_billing_packlink_vat` order metas).

## Use cases & acceptance criteria

### UC-1 — Tax ID / Company VAT live only on the admin customer profile
- `Customs_Handler::add_billing_fields()` and the `woocommerce_billing_fields` hook are removed — no
  storefront checkout fields.
- `Customs_Handler::add_admin_billing_fields()` and the `woocommerce_admin_billing_fields` hook are
  removed — no per-order admin fields.
- New: `Customs_Handler::add_customer_meta_fields( $fields )` on the `woocommerce_customer_meta_fields`
  filter adds a "Packlink customs" section with **Tax ID** (`packlink_tax_id`) and **Company VAT**
  (`packlink_vat`) text fields; WC admin renders and saves them on the user-edit screen automatically.

### UC-2 — Country of origin gets a mapping select
- `getMappingFieldsOptions()` gains a `mapping_country_of_origin` definition: dedicated
  `meta:_packlink_country_of_origin` first, then the product-attribute options (D2 list).
- `Shop_Order_Service::resolve_item_country_of_origin()` resolves mapped field → dedicated meta → ''
  (core applies `default_country`, or skips the invoice per the core guard).

### UC-3 — Product attribute options: global + custom
- Attribute options = global taxonomies (`wc_get_attribute_taxonomies()`, value `attr:pa_{name}`,
  label "Product attribute: {label}") + distinct per-product custom attribute names parsed from
  `_product_attributes` meta (value `attr:{name}`, label "Product attribute: {name}").
- The custom-name scan is one SQL query over `wp_postmeta`, results cached in a transient
  (`packlink_product_attribute_names`, TTL 300s); used by both HS-code and country-of-origin selects.

### UC-4 — Customer mapping options: dedicated + curated keys
- Tax ID select: `user:packlink_tax_id` ("Packlink customer tax ID", first) + curated order-meta keys.
- Company VAT select: `user:packlink_vat` ("Packlink company VAT", first) + the same curated keys.
- Curated keys (single constant array, labels "Order meta: {key}"): `_vat_number`,
  `_billing_vat_number`, `_billing_eu_vat_number`, `_billing_nif`. `_billing_company` is dropped
  (holds the company name, not a VAT number).

### UC-5 — Resolution chain in `Shop_Order_Service`
- Tariff: mapped product field → dedicated `_packlink_hs_code` → '' .
- Country of origin: mapped product field → dedicated `_packlink_country_of_origin` → '' .
- Tax ID: mapped field (`order:`/`user:` per namespace) → dedicated `packlink_tax_id` user meta (via
  `$wc_order->get_customer_id()`; skipped when 0/guest) → '' .
- Company VAT: mapped field → dedicated `packlink_vat` user meta → '' .
- Read helpers honor the namespace contract incl. legacy fallback; per-product custom attribute names
  resolve through `get_attribute()` (not misread as meta keys).

### UC-6 — Seeding and upgrade
- `seed_default_customs_mapping()`: `defaultTariffNumber` seeded **empty** (core now accepts it and
  skips invoices with a warning when nothing resolves); `defaultReason` fixed to `purchase_or_sale`
  (current `sale_of_goods` is not a valid option in the core template's enum); mappings point at
  `user:packlink_tax_id`, `meta:_packlink_hs_code`, `user:packlink_vat`,
  `meta:_packlink_country_of_origin`; still idempotent (never overwrites a saved mapping).
- `src/upgrade/upgrade-4.2.0.php` renamed to `upgrade-4.3.0.php` so the seed runs for stores upgrading
  from 4.2.3 (Version_File_Reader only executes scripts with version greater than the stored one).
- Release lockstep (header 4.3.0, readme stable tag, `src/composer.json` version — currently
  inconsistently `4.2.0`) is done at release via the version-bump flow, not in this change.

### UC-7 — Tests
- Existing customs tests updated to the new behavior: profile fields (render registration + resolution),
  removed checkout/order fields (asserted absent), namespaced option lists incl. custom-attribute scan
  and curated keys, resolution order incl. guest fallback, seed contents, upgrade rename.
- Gate: `php -l` on every touched file; the WP integration suite runs if the local WP tests SDK is
  available, otherwise its absence is reported explicitly and CI covers it.

## Out of scope
- Core changes (all landed: `74b7d3e`).
- Release version bump of plugin header/readme/composer (release flow).
- Migration of data written by the unreleased order-meta fields.
