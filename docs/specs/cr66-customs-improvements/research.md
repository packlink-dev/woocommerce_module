# Research: CR-SET-66 customs feedback improvements (WooCommerce)

> Date: 2026-07-28 · Branch: `CR-SET-66` · Input: `docs/change-requests/2026-06-cr66-customs-support/improvements.md`
> Method: findings verified directly against the code in this session (feedback → spec → implementation cross-check),
> plus repo mechanics checked for the implementation below.

## 1. Core dependency — already satisfied

- `src/composer.json` pins `packlink/integration-core: dev-CR-SET-66-Customs`; `src/composer.lock`
  resolves to `74b7d3e` (core head). The vendored tree contains the new core surface:
  `CustomsMapping::$mappingCountryOfOrigin` (`mapping_country_of_origin` in `$fields`/`fromArray`/`toArray`),
  `CustomsService::isInventoryComplete()` (skips the invoice with a logged warning when an item resolves
  neither tariff number nor country of origin), and the when-present-only tariff validation.
- `composer` `post-update-cmd` runs `src/copy-resources.php`; the copied UI resources
  (`src/resources/packlink/js/CustomsController.js`, `templates/customs.html`) are byte-identical to the
  vendored core resources. **No vendor/resource work needed.**
- Core renders mapping selects generically from `CustomsMappingService::getMappingFieldsOptions()`
  (`MappingFieldOptions[]`: `{field, label, options: [{value, name}]}`); anything WC returns becomes a
  select, and the saved value round-trips through `CustomsMapping`.

## 2. Current WC customs surface (verified)

| Concern | Where | State |
|---|---|---|
| Product HS code / country-of-origin capture | `Customs_Handler::render_product_fields()/save_product_fields()`, meta `_packlink_hs_code`, `_packlink_country_of_origin` | Keep as-is |
| Checkout Tax ID / VAT fields | `Customs_Handler::add_billing_fields()` via `woocommerce_billing_fields` (`class-plugin.php:879`) | **Remove** (decision: profile-only; also invisible on block checkout) |
| Admin order Tax ID / VAT fields | `Customs_Handler::add_admin_billing_fields()` via `woocommerce_admin_billing_fields` (`class-plugin.php:880`) | **Remove** (decision: profile-only) |
| Customer profile fields | — | **Add** via `woocommerce_customer_meta_fields` (WC admin auto-renders and auto-saves declared fields; verified in local WC source `includes/admin/class-wc-admin-profile.php`) |
| Mapping option lists | `Customs_Mapping_Service::getMappingFieldsOptions()` | Tax ID: dedicated only; HS code: dedicated + global attribute taxonomies (`wc_get_attribute_taxonomies()`, `pa_*`); VAT: dedicated + `_billing_company` (company *name* — drop); country of origin: **missing** |
| Order sync resolution | `Shop_Order_Service::resolve_item_tariff_number()/resolve_item_country_of_origin()/resolve_order_tax_id()/resolve_order_vat_number()` | tariff: mapped→dedicated meta; country: dedicated meta only (no mapped step); tax/VAT: mapped order meta→dedicated order meta (to be re-based on profile meta) |
| Mapped-field reading | `Shop_Order_Service::read_product_field()` | `pa_*` → `WC_Product::get_attribute()`, everything else → product meta. Per-product custom attribute names would be misread as meta keys — option values need namespacing |
| Seeding | `Customs_Handler::seed_default_customs_mapping()` from `Plugin::init_config()` (fresh installs) + `src/upgrade/upgrade-4.2.0.php` | Seeds fabricated default HS `61091000` (now unnecessary — core accepts empty) and points tax/VAT mappings at the order-billing metas being removed |

## 3. Defect found: upgrade script can never run

`Version_File_Reader` runs `upgrade-<v>.php` only when `<v>` is **greater** than the stored DB version
(`version_compare($this->version, $file_version, '<')`). The customs seed lives in `upgrade-4.2.0.php`,
but released stores already run 4.2.3 — on upgrade the seed is skipped everywhere. The customs work is
unreleased (exists only on this branch), so the file can simply be renamed to the next release version.
Related lockstep note: `src/composer.json` says `"version": "4.2.0"` while the plugin header/readme say
4.2.3 — `packlink-build.sh` reads the composer version, so this must be aligned at release time.

## 4. Per-product custom attributes (feedback item 2)

WooCommerce stores per-product ("custom") attributes serialized in the `_product_attributes` post meta;
global attributes are taxonomies (`pa_*`) enumerable via `wc_get_attribute_taxonomies()`. There is no
registry of custom attribute names — enumeration requires scanning `_product_attributes` meta values
(SQL over `wp_postmeta`, parse names, distinct). `WC_Product::get_attribute($name)` resolves both kinds
by name. Option values therefore need explicit namespacing so the read side picks the right accessor.

## 5. Test infrastructure

- `src/tests/` are WordPress-integration tests: bootstrap requires the WP tests SDK (`WP_TESTS_DIR` or
  `/tmp/wordpress-tests-lib`) + MySQL + WooCommerce in `WP_PLUGIN_DIR`. `/tmp/wordpress-tests-lib` is
  absent on this machine; whether MySQL/docker equivalents are available must be checked at gate time
  — if the suite cannot run locally, that is reported honestly and syntax/lint checks + CI stand in.
- Existing customs tests: `test-customs-customer-fields.php`, `test-customs-mapping-service.php`,
  `test-customs-migration.php`, `test-customs-product-fields.php`, `test-shop-order-service-customs.php`
  — all touched by these changes.
- The tests bootstrap has a modern/legacy PHPUnit compat shim (mirrors core), so a modern PHPUnit can
  drive them if the WP SDK is present.

## 6. Open decisions carried to the spec interview

1. Sources offered in the customer Tax ID / Company VAT mapping selects (curated list vs dedicated-only).
2. Enumeration approach for per-product custom attributes (scan+cache vs global-only).
3. Next release version (drives the upgrade-script rename).
4. Delegation of implementation tasks to subagents (default yes).
