<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->
# CHANGELOG MODULE KREAPRODUCTS FOR DOLIBARR ERP CRM

## [2.14.8] - 2026-03-07

### Changed
- Bundled-copy templates are now created with unique `_copy`-style template codes instead of reusing bundled codes.
- Custom-template listing/loading now handles legacy bundled-copy code collisions through automatic migration to unique custom codes.

### Fixed
- Fixed bundled JSON edits being shadowed by legacy custom bundled-copy files with the same `template_code`.
- Fixed template selection ambiguity where one code could point to different JSON sources.

## [2.14.7] - 2026-03-07

### Changed
- Removed template-specific runtime layout mutations so block geometry/styles are now taken directly from each template JSON.
- Removed template-specific page mutation/injection logic so pages/blocks are rendered exactly as defined in template files.

### Fixed
- Fixed non-deterministic preview/PDF composition caused by hardcoded `degema_normal` runtime overrides.
- Fixed template JSON edits not being reflected when overridden by runtime normalization code.

## [2.14.6] - 2026-03-07

### Changed
- Rebalanced DeGema front/back top text block Y coordinates to add safe top padding in generated output.
- Kept back ingredients block as a single multiline area with non-truncating behavior in runtime normalization.

### Fixed
- Fixed top-line clipping in generated template PDFs by changing SVG text baseline strategy for TCPDF compatibility.
- Fixed preview/PDF drift on legacy DeGema copies by applying the same updated front/back geometry at runtime.

## [2.14.5] - 2026-03-07

### Changed
- Switched template PDF rendering to prioritize inline SVG output generated from the same preview engine for page-level parity.
- Reused a prebuilt template context/SVG per page copy during PDF generation to keep preview/PDF content consistent.

### Fixed
- Added anti-clipping text fitting fallback for template text blocks to avoid forced ellipsis/cut when content still overflows at configured font limits.
- Added automatic fallback to legacy block renderer when SVG rendering fails in TCPDF.

## [2.14.4] - 2026-03-07

### Changed
- Tuned DeGema front/back text fitting with bounded auto-fit (`min 6.2pt`) to keep print readability while reducing clipping risk.
- Enlarged back-page ingredients multiline area and adjusted allergens block position to improve full-text fitting on thermal output.
- Increased ingredients editor input to a larger multiline textarea for easier full-content editing in template fields.

### Fixed
- Fixed legacy DeGema fallback storage instructions to preserve explicit line breaks in preview and PDF output.

## [2.14.3] - 2026-03-07

### Changed
- Added detailed frozen-product handling instructions on the first page and refactored wording to fit the printable label area.
- Repositioned `LOTE`, lot value, storage instructions, and internal-code box to a more central front-page layout.
- Increased front internal-code barcode height to a near-double footprint while keeping it inside page bounds.

### Fixed
- Fixed DeGema default storage instructions fallback for legacy templates still using the old short one-line storage text.
- Tightened back-page ingredients/allergens/nutrition geometry to prevent ingredient text box overflow beyond printable area.

## [2.14.2] - 2026-03-07

### Changed
- Moved `LOTE`, lot value, storage line, and internal code area upward on the first page to a more central readable position.
- Increased first-page internal-code barcode height to approximately double while keeping it inside label bounds.
- Rebalanced back-page text zones again to keep ingredients inside printable area.

### Fixed
- Removed company identity/address/logo from back-page runtime rendering and template layout to free space for readable food information.
- Aligned legacy DeGema runtime normalization with the new front/back geometry so old template copies render consistently.

## [2.14.1] - 2026-03-07

### Changed
- Moved mandatory Green Dot and EU food-contact symbols to the first label page (`front`) and removed back-page placement.
- Standardized `Embalado em`, `Congelado em`, and `Validade` label/value typography to the same font size in DeGema layout.
- Rebalanced back-page text blocks and removed duplicated company/logo blocks to maximize readability for ingredients, allergens, and nutrition.

### Fixed
- Fixed template PDF text wrapping when non-truncated blocks requested unlimited lines (`maxLines=0`), restoring proper multiline rendering.
- Added text auto-fit support for template blocks (`style.auto_fit` + `style.min_font_size_pt`) for future template tuning.
- Added runtime migration for older DeGema copies so icon location and block geometry are aligned in both preview and PDF output.

## [2.14.0] - 2026-03-07

### Added
- Added mandatory back-label symbols (`green_dot_symbol.svg` and `eu_food_contact_material_symbol.svg`) to the DeGema template layout.

### Changed
- Refactored `degema_normal` front and back block geometry to improve text flow and readability.
- Updated DeGema default template metadata and notes for the new arrangement.

### Fixed
- Added bundled-asset fallback so `templates/assets/*` references can resolve from module `labels/` when not present in documents.
- Applied runtime compatibility normalization for older `degema_normal` copies so preview/PDF keep the improved arrangement and required symbols.

## [2.13.20] - 2026-03-07

### Changed
- Updated `degema_normal` front layout by removing the lot/date barcode block and enlarging the internal/ref code display for better readability.
- Expanded the back ingredients block area and disabled clipping in template style to keep full ingredient text visible.

### Fixed
- Aligned template barcode preview rendering with TCPDF barcode geometry so preview and generated PDF barcode widths match.
- Prevented stale percentage fragments in editable ingredients text by normalizing persisted `label.ingredients_section` values on render.

## [2.13.19] - 2026-03-07

### Changed
- Removed ingredient percentage suffixes from label ingredients text to reduce truncation and keep only ingredient names.

## [2.13.18] - 2026-03-06

### Fixed
- Fixed standard-layout preview refresh when changing "Formato da etiqueta" (including Select2-driven changes).
- Hid "Campos do modelo" when "Layout padrão" is selected.

## [2.13.17] - 2026-03-06

### Changed
- Wrapped "Modelo de etiqueta" and "Descrição do modelo" controls in the same card-style container used by "Campos do modelo".

## [2.13.16] - 2026-03-06

### Changed
- Localized allergen names in label default content to the active system language using allergen dictionary codes with DB label fallback.
- Added missing English allergen code translations for full dictionary coverage in labels.

## [2.13.15] - 2026-03-06

### Changed
- Updated label template selector to display template filenames from documents/module files instead of JSON `label`.
- Removed template `label` persistence from uploaded/custom templates and kept filename as model identity.
- Replaced editable "Nome do modelo" with editable "Descrição do modelo" persisted in template JSON.

### Fixed
- Hardened template JSON upload validation to accept valid uploaded temp files and derive `template_code` from uploaded filename.

## [2.13.14] - 2026-03-06

### Changed
- Removed manual "Validade" editable input when the value is computed from "Embalado em" and "Validade (dias)".

## [2.13.13] - 2026-03-06

### Changed
- Adjusted model-field ordering to place "Imagem da marca" immediately below "Morada".

## [2.13.12] - 2026-03-06

### Changed
- Reordered "Campos do modelo" inputs by template visual flow (front to back, top to bottom) so field sequence matches label reading order.

## [2.13.11] - 2026-03-06

### Changed
- Reordered labels setup table so "Modelo de etiqueta" and "Nome do modelo" are always shown first, before "Campos do modelo".

## [2.13.10] - 2026-03-06

### Changed
- Reorganized labels configuration table to place "Campos do modelo" at the top.
- Applied top vertical alignment to table columns/rows for clearer field-title positioning.
- Improved model-field readability with separated field cards and spacing between inputs.

## [2.13.9] - 2026-03-06

### Changed
- Removed `product.internal_code` from editable template inputs so it no longer appears in model fields.

### Fixed
- Fixed template image preview `<img>` errors by resolving module asset previews to local data URIs before falling back to `viewimage.php`.

## [2.13.8] - 2026-03-06

### Fixed
- Fixed template SVG preview image rendering by adding `xlink:href` compatibility and local asset data-URI fallback.
- Avoided preview failures for document-stored assets referenced through `viewimage.php` inside nested SVG `<image>` tags.

## [2.13.7] - 2026-03-06

### Fixed
- Fixed missing small-height text fields in generated template labels by fitting font size to block height during PDF rendering.
- Preserved bounded text behavior while restoring visibility for compact blocks (product header/company/date/lot lines).

## [2.13.6] - 2026-03-06

### Changed
- Replaced template image field file-input with a controlled dropdown picker sourced only from module documents assets.
- Restricted image selection to `DOL_DATA_ROOT/kreaproducts/<entity>/labels/templates/assets` entries (no navigation outside module folder).
- Enforced image asset value sanitization to accept only `templates/assets/<file>` references with allowed image extensions.

## [2.13.5] - 2026-03-06

### Changed
- Kept template controls always visible on labels page (template name and model fields row), including standard mode.
- Updated client-side mode switching to always refresh the model-fields panel while still toggling standard format/quantity/content options.

## [2.13.4] - 2026-03-06

### Changed
- Added editable template name input in label UI and persisted template label updates into custom template JSON.
- Read-only template copies now default to a label name with suffix `(Copy)`.

### Fixed
- Fixed template PDF text blocks disappearing when block height was smaller than computed line height.
- Kept template PDF text bounded to block area while preserving visible single-line output for tight blocks.

## [2.13.3] - 2026-03-06

### Fixed
- Aligned template PDF text layout with preview block bounds to prevent multiline overflow and overlap between sections.
- Truncated overflowing text with ellipsis inside block height so generated labels now match preview clipping behavior.

## [2.13.2] - 2026-03-06

### Fixed
- Fixed template-fields rendering by using server-rendered field markup per model, preventing empty "Campos do modelo" rows.
- Fixed model field panel refresh on template changes so each selected model shows its own editable inputs consistently.

## [2.13.1] - 2026-03-06

### Fixed
- Fixed empty "Campos do modelo" cell when template mode is visible client-side by rendering model fields into the row when the cell is blank.

## [2.13.0] - 2026-03-06

### Changed
- Kept bundled templates in `/labels` as read-only examples while restoring their editable field form for generation-time overrides.
- Rebuilt `degema_normal.json` input definitions with explicit `editable` flags for all user-editable fields.

### Fixed
- Fixed missing editable fields on read-only bundled templates (for example `degema_normal`).
- When generating from a read-only bundled template, now auto-creates an editable custom copy in documents and applies the edited values before PDF generation.

## [2.12.0] - 2026-03-06

### Added
- Added labels-tab setup toggle (`KREAPRODUCTS_LABELS_TAB_ENABLED`) with default value OFF.
- Added template library management on product labels: upload JSON templates, upload template images, and list/download/delete both from module documents.

### Changed
- Switched custom template storage to `DOL_DATA_ROOT/kreaproducts/<entity>/labels/templates` and template assets to `.../labels/templates/assets`.
- Marked bundled templates as read-only examples while keeping uploaded/custom templates rewritable.

### Fixed
- Fixed template-mode post-generation redirect to keep the selected template active, so template fields no longer disappear after successful generation.

## [2.11.1] - 2026-03-06

### Changed
- Enabled reset controls for editable template fields whenever a database/system or template default baseline exists, including empty DB defaults.

### Fixed
- Fixed image-field reset behavior to also restore/hide the brand-badge preview and clear the pending file selection.

## [2.11.0] - 2026-03-06

### Added
- Added per-field reset controls to restore editable label fields from database/system defaults.
- Added brand badge image picker/upload support for template-driven labels.

### Changed
- Made company identity/address and storage note text editable in DeGema label templates.

### Fixed
- Synced template selection to full page reload so preview/data stay aligned with server-rendered values.

## [2.10.0] - 2026-03-06

### Added
- Added template-value persistence to entity JSON templates when refreshing in model mode.

### Fixed
- Fixed model refresh flow so saved field changes are reloaded and reflected in the label preview.

## [2.9.1] - 2026-03-06

### Fixed
- Fixed label refresh to force a new server reload and reset local model field overrides in preview/forms.

## [2.9.0] - 2026-03-06

### Added
- Added a refresh icon button on product labels to reload model/product data while keeping the current selection context.

## [2.8.0] - 2026-03-06

### Added
- Added generic template `inputs` and `computed_fields` support so label models can define typed editable fields (`date`, `datetime`, `number`, `textarea`).
- Added DB-backed default label sections: ingredients from `llx_product_association`, allergens from `llx_kreaproducts_productallergens`, and nutrition from `llx_kreaproducts_nutritional`.

### Changed
- Updated template field rendering in `product_labels.php` to use typed controls (date picker, datetime picker, numeric input, textarea).
- Refactored DeGema JSON templates to the generic schema and computed validity flow (`days -> expiry date`).
- Removed the front-side shelf-life-days printed block from DeGema templates and kept final validity date rendering.

### Fixed
- Fixed a fatal error on `product_labels.php` caused by a nutrition translation string exceeding Dolibarr `trans()` placeholder limits.

## [2.7.0] - 2026-03-06

### Added
- Added editable model field inputs under the label template selector (for example packed/frozen/expiry/lot values) when a template is selected.

### Changed
- Template preview and generation now accept user-provided dynamic model values for editable template sources.

## [2.6.4] - 2026-03-06

### Changed
- Moved bundled label templates from `templates/labels/` to `labels/` and switched entity custom-template storage to `DOL_DATA_ROOT/kreaproducts/<entity>/labels/`.
- Removed raster preview metadata from the DeGema template and kept vector preview as the single preview flow.

## [2.6.3] - 2026-03-06

### Fixed
- Fixed template PDF text blocks being hidden when block height was smaller than computed line height.

## [2.6.2] - 2026-03-06

### Fixed
- Synced label preview updates with Select2 template selection changes by binding Select2 events and rendered-label fallback sync.

## [2.6.1] - 2026-03-06

### Fixed
- Merged bundled and entity-scoped label templates so model selection and preview use the full template set for the current entity.

## [2.6.0] - 2026-03-06

### Added
- Added a standard-layout preview that updates from the selected format, quantity, and printed fields when no bundled model is selected.

### Changed
- The label template selector now defaults to the standard layout and hides the manual format, quantity, and content options when a bundled model is selected.

## [2.5.0] - 2026-03-06

### Added
- Made selected bundled label templates drive the generated PDF layout so the output matches the preview structure.

### Fixed
- Fixed custom template-size output using the wrong page orientation for landscape labels.

## [2.4.0] - 2026-03-06

### Added
- Added a vector SVG viewer for bundled product label templates on the labels page.
- Added an option to generate labels using the selected template size as a custom 1x1 output format.

## [2.3.0] - 2026-03-06

### Added
- Added bundled label template previews on the product labels page.

## [2.2.0] - 2026-03-06

### Added
- Added a bundled `templates/labels/` folder with a first JSON label template mapped from the extracted chapata PDF.
- Added label template discovery helpers to prepare future designer/import flows.

## [2.1.5] - 2026-03-06

### Fixed
- Replaced silent redirects on invalid label page access with a clear message explaining that a valid product context is required.

## [2.1.4] - 2026-03-06

### Fixed
- Redirected label page requests without a valid product context to the product list instead of showing a generic access denied screen.

## [2.1.3] - 2026-03-05

### Fixed
- Allowed Dolibarr admins to open and use product labels before the new label rights are assigned.

## [2.1.2] - 2026-03-05

### Fixed
- Delayed label PDF class loading to prevent `product_labels.php` from crashing on page load.

## [2.1.1] - 2026-03-05

### Fixed
- Added fatal error logging for the product labels page and removed PHP-version-sensitive label format selection logic.

## [2.1.0] - 2026-03-05

### Added
- Added a product labels tab with PDF generation, saved files, and selectable content fields using Dolibarr label formats.

## [2.0.128] - 2026-03-03

### Fixed
- Fixed Ref sorting in `custom/kreaproducts/product_list.php` to use numeric order for numeric refs (for example `1, 2, 11`), while keeping text refs sortable.

## [2.0.127] - 2026-03-03

### Changed
- Added VAT column display in `custom/kreaproducts/product_list.php`.
- Category filter now shows full family path in Select2 (for example `FABRICA >> (RV) REVENDA >> (RV) REFATURACAO`).

## [2.91] - 2026-01-04

### Changed
- Stock recalculation now anchors on counted inventory quantity (qty_stock), falling back to qty_view when needed to prevent drift on backdated movements.

## [2.77] - 2026-01-04

### Changed
- Nested BOM lines (fk_bom_child) are now handled across cost, nutrition, allergen, and product tree flows.
- Multicompany: shared BOMs (entity=0) are visible across entities, with preference for the current entity BOM when available.
- Stock dismantle movements now persist origin references for traceability.

## [2.76] - 2025-12-28

### Changed
- Refactored trigger logic into inventory and stock movement services.
- Inventory header reference now uses configured category root labels (no hardcoded names).
- Documentation updated and headers standardized to 2024-2026.

## [1.0] - 2024-06-29

### Added
- Initial version.
