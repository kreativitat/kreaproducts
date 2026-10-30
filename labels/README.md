# KreaProducts Label Templates

Bundled label templates live in this directory as JSON files.

Purpose:
- Keep designer-ready template definitions in version control.
- Ship sample layouts with the module.
- Separate bundled templates from future user-saved templates.

Template storage:
- Bundled templates: `custom/kreaproducts/labels/`
- Future entity-scoped custom templates: `DOL_DATA_ROOT/kreaproducts/<entity>/labels/`

Schema notes:
- Coordinates use millimeters.
- A template may have one or more `pages`.
- Optional top-level `inputs` define editable UI fields by source (`text`, `textarea`, `date`, `datetime`, `number`).
- Optional top-level `computed_fields` define derived context values (for example `add_days` from validity days to expiry date).
- Supported block types for the current schema draft are:
  - `text`
  - `barcode`
  - `rect`
  - `image`
- `content_mode` can be:
  - `static`
  - `dynamic`
  - `asset`

Dynamic source conventions:
- `product.*` for product fields
- `company.*` for company/entity identity
- `batch.*` for lot, packed, frozen, and expiry values
- `label.*` for preformatted long text sections such as ingredients or nutrition

Current bundled example:
- `degema_chapata_agua_e6un_2085.json`
  - Two-sided label
  - Mapped from the supplied PDF extraction
  - Uses typed inputs and computed expiry date rules
  - Includes editable DB-backed ingredients, allergens, and nutrition sections
- `degema_normal.json`
  - Same generic schema with typed inputs for another DeGema model

## Barcode registration (4.24.0)

Saving label fields, saving the default layout, or generating labels registers the
resolved barcode blocks in KreaFlow's `dolizsynch_zsproduct.produtos_codigosbarras`.
The standard layout uses its native product barcode/reference. Computed template
barcodes use the same resolver as printing; missing dynamic values never register
placeholder text. Barcode strings retain leading zeros and must fit KreaFlow's
1–20 printable-character limit, without spaces.

Registration requires native product/service write permission and uses native
`getEntity('product')` sharing. Shadow rows belong to their native product, including
shared products; their `entity` column is not an independent ownership filter.
The scanner's database lock serializes label saves and scanner associations across
overlapping sharing groups. A code already used by another accessible product or
service is refused, including primary and JSON-list codes.

Label values, validity days, barcode rows, and tracking commit together. The saved
label JSON gains `barcode_links` and `managed_barcodes`; no schema migration is
needed. Existing payloads are read unchanged. Changes remove superseded codes only
when label saves originally created them and no other saved label references them.
Existing/manual codes remain, and their quantities and totals are preserved. New
barcode rows use quantity 1 and total 0; label weight does not redefine POS units.

After commit, KreaFlow's existing `ZSProductSynch::updateZsbmsProduct()` publishes
the committed product to its configured stores. Existing synchronization flags,
restricted state, store selection, and variable prices remain authoritative. A new
shadow row starts restricted; saving a label never enables synchronization. Missing
modules or disabled synchronization produce an explicit warning. A remote failure
keeps the committed local state and displays a retry warning; saving again retries
without inserting another barcode. This uses the existing full-product synchronizer,
including its price mirroring; it does not introduce a separate barcode API or queue.

Validation:

```sh
php tests/run_label_barcode_tests.php
```

The isolated regression suite uses SQLite transactions with lifecycle/synchronizer
doubles. It checks resolution, duplicates, updates, shared-product conflicts,
permission denial, transaction failures, local rollback, and post-commit remote retries.
Real POS API acceptance must be checked in an authorized integration environment.

A local Dolibarr 24.0.2 / PHP 8.2.35 / MariaDB probe also verified the native
Product/extrafield lifecycle, duplicate-free repeated saves, tracking persistence,
and transaction closure using connection-local temporary tables. It did not make
POS requests or change persistent business data.
