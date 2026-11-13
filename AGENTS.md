# AGENTS.md — KreaProducts

KreaProducts is a GPL-3.0-or-later external Dolibarr module. Source PHP lives in
the module root, `class/`, `lib/` and `core/`; the stock PWA lives in `stockapp/`.
The descriptor declares PHP 7.3+ / Dolibarr 19+. Verify API behavior against the
target installation; current integration work targets Dolibarr 24.0.2.

- Technical-sheet MRP tables follow the kit components table in
  `associatedProducts.php`: content-sized numeric columns, flexible names at
  90% body text size, and native responsive wrappers. Follow the installed
  [Dolibarr table guidance](https://fin.degema.pt/admin/tools/ui/content/tables.php).
- Preserve native Product, Nutritional, ProductAllergens, supplier-price and MRP
  lifecycles, triggers, permissions and parent-based entity sharing. Never treat
  visibility of a shared catalog product as permission to modify its owner.
- REST routes live in `class/api_kreaproducts.class.php`. The food-setup workflow
  is isolated in `class/KreaProductsFoodSetupService.class.php`; it preserves
  missing declarations, saved decimal text and omitted values/relationships.
  Manual writes require `KreaProductsNutritionEditPolicy::canEdit()`, explicit
  owner entity, confirmation, reviewed revision, parent/row locks and readback.
- Supplier purchase prices use `class/KreaProductsPurchasePriceService.class.php`
  and [the purchase-price contract](doc/purchase-price-api.md). Preserve the
  verified bound native price ID, supplier reference namespace, all ancillary
  fields, history and triggers. Never invoke the unbound `update_buyprice`
  fallback: it can delete another product's supplier-price row. Reject unsupported
  FX/rounding/native-normalization cases before calling the mutator. Lock supplier
  before price rows consistently; no plain reads may establish a stale snapshot
  before current locks in an applying transaction.
- Run PHP lint on changed files and `php tests/run_food_setup_api_tests.php` for
  the food API. Existing nutrition checks are
  `php tests/run_nutrition_ajax_policy_tests.php`,
  `php tests/run_nutrition_copy_tests.php`,
  `php tests/run_nutrition_copy_values_tests.php` and
  `php tests/run_nutrition_scope_warning_tests.php`. Tests use isolated doubles;
  never provide live ERP credentials or exercise fiscal/stock records as tests.
  Run `php tests/run_purchase_price_api_tests.php` for supplier-price contracts.
- Record unreleased behavior in `ChangeLog.md`; a source push is not a release
  or deployment. Deploy native endpoint source before enabling dependent MCP
  tools; preserve the current module/configuration and rollback source. Read
  [the food-setup API contract](doc/food-setup-api.md) before changing it.
- No schema migration or module disable/re-enable is needed for food-setup API
  source changes. Refresh the REST API discovery/cache for the installed version
  and restart/reload PHP opcode cache through the established deployment route.
  Production writes, permission changes and deployment require separate authority.
