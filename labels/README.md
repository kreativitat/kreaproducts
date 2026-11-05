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

## Barcode rendering

SVG previews, PDF labels, and TSPL bitmaps share EAN/UPC validation. Non-numeric,
oversized, or incorrect-check-digit values use Code128 without changing the value.
Short EAN/UPC-A values retain TCPDF's zero padding and calculated check digit.
UPC-E requires a compressible 11-digit UPC-A payload or its 12-digit form including
the check digit. Unsupported number systems, lossy compression, and compact UPC-E
inputs use Code128. This fallback does not turn the value into a retail EAN/UPC.
An encoding failure never produces decorative barcode bars in template previews.

Run the regression suite with an installed Dolibarr/TCPDF tree; it uses no business
database or network. The optional second argument saves synthetic PDF/SVG fixtures:

```sh
php tests/run_label_rendering_tests.php /path/to/dolibarr/htdocs
php tests/run_label_rendering_tests.php /path/to/dolibarr/htdocs --failing-encoder
```
