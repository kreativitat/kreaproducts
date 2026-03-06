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
