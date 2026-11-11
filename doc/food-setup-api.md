# Saved food-information API

`GET /kreaproductsapi/production/products/{id}/food-setup` returns version `1`,
the product/entity identity, `nutrition`, `allergens`, `manual_edit_allowed`,
`revision` and `available_allergens`. It reads saved declarations; it does not
generate labels or infer that missing data means no allergens.

`nutrition: null` means no saved row. Existing rows contain `is_food` and these
per-100-g fields: `energy_kcal`, `energy_kj`, `fat`, `saturates`, `carbohydrates`,
`sugars`, `protein`, `salt`, `fiber`. Values are canonical decimal strings or
`null`; explicit zero is `"0"`. Inputs support the native non-negative range
through `999999.9999`, with at most four fractional places. Native kcal/kJ
consistency validation is retained. No nutrition values are invented.

`PUT` to the same route accepts a patch:

```json
{
  "version": 1,
  "entity_id": 8,
  "confirm": true,
  "dry_run": true,
  "nutrition": {"protein": "3.5", "salt": "0"},
  "allergens": [
    {"allergen_id": 7, "declaration": "contains"}
  ]
}
```

The IDs above are illustrative; read the product owner and dictionary first.
Omitted nutrients and allergen relationships are preserved. Allergen operations
are `contains`, `traces` or an explicit `absent` removal, capped at 100 distinct
dictionary IDs. Active dictionary entries are required for contains/traces;
`absent` removes only the named link and additionally requires the native delete
right. The inactive historical no-allergens sentinel cannot be added.

`confirm=true` and an explicit integer `entity_id` are required. Preview defaults
to `dry_run=true` and writes nothing. Apply requires `dry_run=false` plus
`expected_revision` from the reviewed GET/preview's `before.revision`. A stale
revision refuses changes; an already identical patch is an unchanged no-op.
Responses contain `status` (`dry_run`, `unchanged`, `confirmed`), `before` and
`after`. Malformed/duplicate existing records fail closed for manual review.

Native product/service read and both module declaration read rights are required.
Patches additionally require the affected module write rights and manual mode
through `KreaProductsNutritionEditPolicy`. Writes require the product owner to
equal the selected Dolibarr company; native shared-catalog visibility is retained
for reads. Parent, calculation-mode and declaration locks protect apply; native
object methods run in one transaction with readback before commit. The endpoint
does not change calculation modes, alias/ingredients, prices, templates, PDFs,
stock or fiscal records.

Deploy the API and service files together before enabling KreaMCP's
`get_kreaproducts_product_food_setup` / `set_kreaproducts_product_food_setup`.
Refresh installed-version REST discovery/cache and PHP opcode cache using the
existing deployment mechanism. No database migration or module reactivation is
needed. Roll back all changed API/service files together. Source publication does
not certify deployed behavior; production permission changes and writes need
their own authorization.

Validate offline with `php tests/run_food_setup_api_tests.php`, changed-file PHP
lint and the existing nutrition tests. Live Dolibarr/DB concurrency, native
triggers and target-runtime behavior remain installation acceptance checks.
