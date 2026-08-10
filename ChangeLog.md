<!-- Copyright (C) 2024-2026       Kreativität Works       <mail@kreativitat.com> -->
# CHANGELOG MODULE KREAPRODUCTS FOR DOLIBARR ERP CRM

## [4.10.5] - 2026-08-10

### Changed

- Rendered allergen icons in white inside the grey pills.

## [4.10.4] - 2026-08-10

### Changed

- Matched allergen pills to the Dolibarr action-button colors.

## [4.10.3] - 2026-08-10

### Changed

- Flattened calculated nutrition and allergens into one square-edged table.

## [4.10.2] - 2026-08-10

### Fixed

- Restored the detailed component nutrition table in calculated mode.

## [4.10.1] - 2026-08-10

### Fixed

- Aligned nutrition and allergen actions with native Dolibarr buttons.

## [4.10.0] - 2026-08-10

### Changed

- Unified nutrition and allergens in one table with one mode selector and common actions.
- Moved nutrition and allergen copying into a modal.
- Preserved saved food data when a product is marked as non-food.

### Security

- Enforced POST, CSRF, product rights, and both data-write permissions on unified mutations.

## [4.9.1] - 2026-08-10

### Fixed

- Repaired invalid legacy nutritional creation dates before updating reviewed AI values.

## [4.9.0] - 2026-08-10

### Changed

- Generated low-confidence nutrition estimates from general food-composition knowledge when exact label values are unavailable.
- Kept allergen and trace suggestions limited to explicit product evidence.

## [4.8.4] - 2026-08-10

### Fixed

- Increased the AI provider connection timeout and reported DNS or timeout failures clearly.

### Security

- Prevented provider authorization headers from being written to Dolibarr logs.

## [4.8.3] - 2026-08-10

### Fixed

- Reported empty provider suggestions as insufficient evidence instead of invalid nutrition or allergen data.

## [4.8.2] - 2026-08-10

### Fixed

- Fixed the AI modal launcher JavaScript failing to parse on the product page.

## [4.8.1] - 2026-08-10

### Changed

- Moved the AI nutrition and allergen workflow into a modal opened beside the nutrition Save action.

## [4.8.0] - 2026-08-10

### Added

- Added review-first AI suggestions for manual product nutrition and allergens through OpenAI, Claude, OpenRouter, or Ollama.

### Security

- Enforced encrypted credentials, structured-output validation, CSRF, write permissions, product entity scope, atomic replacement, and private-only Ollama endpoints.

## [4.7.2] - 2026-08-10

### Fixed

- Preserved native stock management when creating or updating products.

## [4.7.1] - 2026-08-08

### Fixed

- Restored every KreaProducts API route after supplier-invoice validation route registration failed.

## [4.7.0] - 2026-08-07

### Added

- Added supplier-wide validation for all draft supplier invoices.

## [4.6.0] - 2026-08-07

### Added

- Added trigger-safe supplier invoice validation with entity-default warehouse fallback through the KreaProducts API.

### Security

- Enforced supplier validation rights, warehouse scope, and invoice entity isolation.

## [4.5.16] - 2026-08-07

### Changed

- Made the future invoice datetime tolerance configurable with a 30-minute default.

## [4.5.15] - 2026-08-07

### Changed

- Dated customer stock movements at the authoritative invoice datetime.

### Fixed

- Rejected future customer invoice datetimes before stock reconciliation.

## [4.5.14] - 2026-08-06

### Fixed

- Preserved active inventory count corrections during stock reconstruction.
- Kept automatic dismantling movements on the supplier movement server time.

## [4.5.13] - 2026-08-06

### Fixed

- Loaded Dolibarr from both standard and custom module locations in the inventory auto-close runner.

## [4.5.12] - 2026-07-31

### Changed

- Made every product participating in an automatic dismantling MO stock-managed before execution.

### Fixed

- Restored mandatory stock movements for all automatic dismantling MO execution lines.

## [4.5.11] - 2026-07-31

### Fixed

- Recorded non-stock-managed dismantling outputs as completed MO execution lines without requiring an impossible stock movement.
- Preserved intentional non-stock MO execution lines during retry and orphan cleanup checks.

## [4.5.10] - 2026-07-31

### Fixed

- Created automatic dismantling MO movements directly on declared products without triggering a second kit-child stock cascade.
- Allowed declared kit-parent outputs to receive their MO stock movement without changing the global composed-product configuration.

## [4.5.9] - 2026-07-31

### Fixed

- Returned the Dolibarr trigger success code after stock movement processing.
- Prevented transactional cost updates from starting synchronous WooCommerce synchronization.

## [4.5.8] - 2026-07-17

### Fixed

- Restored recursive cost propagation through product-association recipes.
- Preserved changed product costs as authoritative cascade inputs.
- Selected multiple active manufacturing BOMs automatically from production history.

## [4.5.7] - 2026-07-16

### Fixed

- Restored automatic selling-price markup updates after every module-managed product cost change.

## [4.5.6] - 2026-07-16

### Fixed

- Preserved Dolibarr's kilogram weight scale in native and KreaProducts product forms.
- Defaulted new product weight selection to kilograms.

## [4.5.5] - 2026-07-12

### Changed

- Synchronized the mobile package version with the module release.

### Fixed

- Made every product cost cascade fail closed on calculation or persistence errors.
- Preserved raw dismantling output quantities with one value-preserving unit cost.
- Removed the direct SQL fallback from dismantling cost updates.

### Security

- Hid internal database and exception details from REST and mobile responses.

## [4.5.4] - 2026-07-12

### Changed

- Raised the minimum PHP version to 7.3.
- Made the release builder consume the production allowlist.

### Fixed

- Restricted cost cascades to unambiguous manufacturing BOMs.
- Rejected cyclic BOM cost graphs before product updates.
- Removed the obsolete product-association sync setup utility.

### Security

- Enforced POST and CSRF validation for association-to-BOM writes.
- Hid internal label generation errors from REST responses.

## [4.5.3] - 2026-07-12

### Changed

- Declared MySQL or MariaDB as the supported database.
- Replaced broad release packaging with a production allowlist.
- Normalized remaining module authorship and GPL notices.

### Fixed

- Made supplier price, product cost, cascade, and selling-price synchronization fail validation atomically.
- Made module activation fail when required product extrafields cannot be installed.
- Included globally activated entities in mobile Google login discovery.
- Removed custom helper execution from the product tab descriptor.
- Hid internal exception details from mobile API responses.

### Security

- Limited every label generation request to a hard maximum of 1000 labels.
- Excluded internal maintainer, test, source-build, and deployment files from release packages.

## [4.5.2] - 2026-07-12

### Fixed

- Allowed authorized counters to delete initiated inventories without close or reversal permission.
- Prevented Dolibarr core draft-delete permission detection from blocking the custom initiated-inventory deletion flow.

## [4.5.1] - 2026-07-12

### Changed

- Completed the European Portuguese translation across module interfaces, permissions, inventory messages, documentation, and label templates.
- Routed user-facing setup and mobile inventory messages through Dolibarr translation keys.

## [4.5.0] - 2026-07-12

### Added

- Added an inventory-category overview with current Dolibarr virtual stock for every countable product.
- Added the stock overview to the inventory left menu for authorized analysis users.

## [4.4.2] - 2026-07-12

### Fixed

- Replaced mixed-product inventory graphs with selectable 15-day graphs for each product.

## [4.4.1] - 2026-07-12

### Changed

- Restricted virtual stock, deviations, and inventory statistics to the inventory analysis permission.

### Security

- Removed expected stock from unauthorized inventory API responses and blocked direct statistics access.

## [4.4.0] - 2026-07-12

### Added

- Added 15-day inventory product consumption and intake statistics with daily and product graphs.

## [4.3.0] - 2026-07-12

### Added

- Restored virtual stock and absolute and relative deviation columns on the inventory page.

## [4.2.0] - 2026-07-12

### Changed

- Moved the mobile stock link to DeGema Utilities and Tools Utilities.

## [4.1.4] - 2026-07-12

### Changed

- Linked the left menu to the integrated KreaProducts mobile stock application.

## [4.1.3] - 2026-07-12

### Fixed

- Removed obsolete KreaStock and KreaProducts mobile menu records during activation.

## [4.1.2] - 2026-07-12

### Changed

- Updated the mobile stock left-menu destination.

## [4.1.1] - 2026-07-12

### Fixed

- Made allergen replacement transactional and corrected its failure handling.
- Fixed deletion of all quantity-price rows.
- Removed duplicate product synchronization execution.

### Security

- Enforced product edit permissions on supplier costs, selling prices, nutrition, allergens, and synchronization actions.
- Removed schema mutations from the product association page.

## [4.1.0] - 2026-07-12

### Added

- Added an isolated runner for automatic inventory closure.
- Added the product-allergen product lookup index.

### Changed

- Limited cost cascades to one unambiguous manufacturing BOM.
- Normalized BOM costs by line efficiency and header quantity.
- Moved all label-storage schema changes to module activation.

### Fixed

- Locked inventory headers before reversal ledger rows.
- Limited scheduled audit users to the current or shared entity.
- Separated allergen and nutritional numbering settings.
- Required explicit POST confirmation for maintenance schema changes.

### Security

- Centralized mobile mutation CSRF enforcement.
- Required the BOM helper token and hardened local redirects.

## [4.0.1] - 2026-07-12

### Changed

- Moved production trace schema upgrades to module activation.

### Fixed

- Made the complete production and trace lifecycle transactional.
- Made automatic dismantling validation and idempotency checks fail closed.
- Allowed administrator-run scheduled inventory closure without mobile UI rights.
- Limited post-close correction state to the current business day.

### Security

- Enforced entity and user access for production third parties and projects.

## [4.0.0] - 2026-07-12

### Added

- Added automatic closure of due initiated inventories 15 minutes before the configured entry cutoff.

### Changed

- Blocked new category inventories while another managed inventory remains initiated.
- Limited production API posting to validated, unprocessed manufacturing orders.
- Preserved mobile value dates in offline drafts.

### Fixed

- Prevented future inventory anchors from posting stock before their value time.
- Prevented production retries from reposting manufacturing-order quantities.
- Made production stock and trace writes atomic.
- Propagated dismantling failures and preserved total multi-output valuation.

### Security

- Enforced warehouse and product entity scope in production, nutrition, and allergen operations.

## [3.2.0] - 2026-07-11

### Added

- Added editable value dates to initiated inventories in Dolibarr and mobile.

### Changed

- Renamed the inventory field label to `Data valor` in Portuguese.

### Fixed

- Blocked stock movement generation when a recorded inventory already exists for the selected category, warehouse, and value date.

## [3.1.0] - 2026-07-11

### Changed

- Used padded Dolibarr provisional references while inventories are initiated.
- Assigned the final `YYYYMMDD_CATEGORY` reference only when recording stock movements.
- Normalized existing initiated technical KPS references when they are reopened.

## [3.0.2] - 2026-07-11

### Changed

- Displayed inventory value dates without their internal ordering time in Dolibarr and mobile views.

## [3.0.1] - 2026-07-11

### Changed

- Selected Yes by default in inventory-closing confirmations.

## [3.0.0] - 2026-07-11

### Changed

- Changed new inventory references to the `YYYYMMDD_CATEGORY` business format.
- Moved new managed-inventory ownership detection to the hidden Dolibarr import key while preserving legacy references.

## [2.41.1] - 2026-07-11

### Fixed

- Saved current count entries before opening inventory movement confirmation.

## [2.41.0] - 2026-07-11

### Changed

- Moved product references to the first inventory column and linked them to product cards in new tabs.

## [2.40.12] - 2026-07-11

### Fixed

- Standardized all inventory action typography across buttons and links.

## [2.40.11] - 2026-07-11

### Fixed

- Enforced identical category button dimensions.
- Equalized inventory action heights while preserving automatic widths.

## [2.40.10] - 2026-07-11

### Fixed

- Restored native compact Dolibarr sizing for inventory detail actions.
- Scoped equal-width styling to category actions only.
- Removed the redundant initiated-status row from inventory details.

## [2.40.9] - 2026-07-11

### Changed

- Added the native one-tab Dolibarr fiche bar to the category selector.

## [2.40.8] - 2026-07-11

### Changed

- Added the native Dolibarr pagination return control to the category selector.

## [2.40.7] - 2026-07-11

### Fixed

- Standardized inventory action height and increased spacing below product search.

## [2.40.6] - 2026-07-11

### Changed

- Restored the native Dolibarr fiche bar with one active Inventory tab.

## [2.40.5] - 2026-07-11

### Changed

- Moved Save into the single Dolibarr action row and removed the saved-status block.
- Widened inventory action buttons and prevented multiline labels.

## [2.40.4] - 2026-07-11

### Changed

- Replaced the custom list button with Dolibarr banner return and previous/next navigation.

## [2.40.3] - 2026-07-11

### Changed

- Used light green for new inventory actions and light yellow for already-started inventory actions.

### Fixed

- Fixed expired-token errors when confirming inventory deletion, closure, or reversal.

## [2.40.2] - 2026-07-11

### Fixed

- Standardized all unified inventory action buttons to the same width.

## [2.40.1] - 2026-07-11

### Fixed

- Replaced category cards with a native Dolibarr category table.
- Separated category names, product totals, and actions.
- Hid unused lot columns, aligned search, and removed the mobile shortcut.

## [2.40.0] - 2026-07-11

### Changed

- Combined category selection, counting, saving, and closure on one page.
- Generated inventory metadata automatically and displayed the value date without time.
- Added dynamic product filtering, progress, and unsaved-change feedback.
- Added dated warnings when correcting an already recorded daily inventory.

## [2.39.0] - 2026-07-11

### Changed

- Replaced the copied Dolibarr inventory sheet with a dedicated KreaProducts page.
- Kept ordinary inventories on the native Dolibarr inventory page.

## [2.38.2] - 2026-07-11

### Fixed

- Added a bottom save button to every editable mobile inventory.

## [2.38.1] - 2026-07-11

### Fixed

- Locked counted open inventories after their business-day cutoff.
- Reconciled delayed customer and supplier movements selected by actual movement time.
- Restored corrections for audited legacy kit-parent inventory lines.
- Added a bottom save action to the mobile correction screen.

## [2.38.0] - 2026-07-11

### Added

- Added append-only audit records for same-day physical-count corrections.

### Changed

- Limited inventories to one per template, warehouse, and business day.
- Reused the recorded day inventory for corrections until the counting cutoff.
- Stopped module activation from changing Dolibarr composed-product stock settings.
- Required invoice value dating before starting or changing managed inventories.

### Fixed

- Value-dated customer invoice movements before the following inventory anchor.
- Blocked overlapping product anchors and equal-time inventory conflicts.
- Reversed count corrections together with their inventory.

## [2.37.1] - 2026-07-11

### Changed

- Excluded kit parents whose stock is not maintained by normal Dolibarr movements.
- Assigned the business-day value date when the first physical count is saved.
- Used append-only corrections for legacy and mobile inventory anchors.

### Fixed

- Prevented duplicate open inventories from concurrent starts.
- Aligned Dolibarr inventory buttons with KreaProducts count and close permissions.
- Removed remaining historical stock-movement value rewrites.
- Preserved reversal support for kit-parent corrections recorded by version 2.37.0.

## [2.37.0] - 2026-07-11

### Changed

- Made the mobile inventory list always available.
- Routed KreaProducts inventory closure in Dolibarr through the same audited ledger as mobile.
- Added append-only rebasing for late supplier and production movements.

### Fixed

- Fixed inventory closure for counted kit-parent products without changing kit children.
- Standardized inventory count-save and close locking order.
- Made stock recalculation database failures abort the triggering transaction.

## [2.36.2] - 2026-07-11

### Changed

- Treated blank inventory quantities as not counted while preserving explicit zero counts.
- Added confirmation before closing mobile or Dolibarr inventories with uncounted products.

### Fixed

- Kept stock unchanged for uncounted inventory lines.

## [2.36.1] - 2026-07-11

### Fixed

- Fixed mobile API routing and service-worker scope.
- Made inventory and reversal movement failures block closure.
- Excluded reversal movements from inventory reconstruction.
- Normalized existing supplier movements before delayed inventory calculations.
- Made stock and anchor query failures stop inventory processing.

## [2.36.0] - 2026-07-11

### Added
- Merged the KreaStock mobile counting application into KreaProducts.
- Added business-day inventory dating, an adjustment ledger, and reversible corrections.

### Changed
- Assigned counts entered from 20:00 until the next 20:00 to one minute after the configured business-day close.
- Preserved later non-inventory movements when closing a delayed physical count.

## [2.35.27] - 2026-07-06

### Fixed
- Kept the KreaProducts buying-prices page and product tab available when products are disabled for purchase while preserving product, service, and supplier permission checks.

## [2.35.26] - 2026-06-23

### Changed
- Added supplier VAT fields to the `GET purchase_prices` API export.

## [2.35.25] - 2026-06-22

### Added
- Added the KreaProducts `GET purchase_prices` API endpoint to export supplier-scoped product references from `product_fournisseur_price`.

## [2.35.24] - 2026-06-11

### Fixed
- Restricted custom selling-price tabs to products enabled for sale.
- Restricted custom buying-price tabs and direct `purchasePrice.php` access to products enabled for purchase.

## [2.35.23] - 2026-06-09

### Removed
- Removed the separate BOM and associated-product traceability section from the product tree page.

## [2.35.22] - 2026-06-09

### Changed
- Allowed parent tree ancestry to continue through BOM and association links after the first filtered parent level.

## [2.35.21] - 2026-06-09

### Fixed
- Prevented compact product tree rows from clipping references by allowing horizontal expansion.

## [2.35.20] - 2026-06-09

### Changed
- Displayed associated subproduct usage before BOM parent usage in the product tree.

## [2.35.19] - 2026-06-09

### Changed
- Renamed association-based product tree labels from parents to associated subproducts.

## [2.35.18] - 2026-06-09

### Changed
- Split the parent kits tree into separate BOM-parent and association-parent trees.
- Restored combined upstream usage rows in the traceability table.

## [2.35.17] - 2026-06-09

### Changed
- Split BOM usage and associated-product usage into separate product traceability tables.

## [2.35.16] - 2026-06-09

### Changed
- Forced product tree rows to stay on one line with compact ellipsis columns.

## [2.35.15] - 2026-06-09

### Changed
- Reverted the inverted where-used product tree to the compact upstream parent tree.

## [2.35.14] - 2026-06-09

### Changed
- Made product tree tables compact with collapsible JavaScript branches.

## [2.35.13] - 2026-06-09

### Changed
- Rebuilt the product parent tree as an inverted where-used tree with parent compositions expanded separately.

## [2.35.12] - 2026-06-09

### Fixed
- Labeled upstream product tree rows as BOM or association parents instead of subproducts.

## [2.35.11] - 2026-06-09

### Fixed
- Included upstream BOM and associated-product usage in product tree traceability.

## [2.35.10] - 2026-06-09

### Added
- Added BOM, sub-BOM, and associated-product traceability to the product tree tab.

## [2.35.9] - 2026-06-03

### Changed
- Reordered product label template fields into a stable logical sequence and labeled production order display fields as MO instead of inferring a duplicate lot label.

## [2.35.8] - 2026-06-03

### Changed
- Made template product labels editable from the product label screen while keeping the product short label as the fallback when no override is entered.

## [2.35.7] - 2026-06-03

### Fixed
- Enforced a larger safe text area for all DeGema second-page composition fields and replaced character-count wrapping with proportional-width wrapping for previews and PDFs.

## [2.35.6] - 2026-06-03

### Fixed
- Added a right safety margin and tighter small-text wrapping to DeGema second-page composition blocks so ingredients and nutrition text no longer touch the label border.

## [2.35.5] - 2026-06-03

### Fixed
- Improved small-text wrapping in DeGema label previews and PDFs so ingredients use the available full-width line area more accurately.

## [2.35.4] - 2026-06-03

### Changed
- Moved second-page DeGema ingredients below the logo and expanded them to the full label width while preserving vertical flow for following sections.

## [2.35.3] - 2026-06-03

### Fixed
- Made second-page DeGema composition sections flow vertically from rendered text height so long ingredients no longer overlap allergens, nutrition, or conservation.

## [2.35.2] - 2026-06-03

### Changed
- Removed the standalone nutrition copy selector and made the allergen copy action copy nutritional values together with allergens.

## [2.35.1] - 2026-06-03

### Fixed
- Restricted the allergen copy selector to products using inserted allergen tables instead of showing it for calculated allergen modes.

## [2.35.0] - 2026-06-03

### Added
- Added a product Ingredients extra field in Other characteristics for explicit label ingredient declarations.

### Changed
- Product labels now use the explicit Ingredients field as the default ingredients text, falling back to calculated kit ingredients when it is empty.

## [2.34.31] - 2026-06-03

### Fixed
- Excluded kit components with the KreaProduction lot flag unchecked from generated product label ingredients.

## [2.34.30] - 2026-06-03

### Changed
- Added the KreaProduction lot flag column to the kit component list and saved it with composed-product component updates.

## [2.34.29] - 2026-06-03

### Changed
- Further reduced associated-products nutrition weight and nutrient columns to make the table fit more compactly.

## [2.34.28] - 2026-06-03

### Changed
- Made the associated-products nutrition table more compact with tighter column widths, smaller typography, and reduced cell padding while preserving horizontal overflow fallback.

## [2.34.27] - 2026-06-03

### Fixed
- Constrained second-page DeGema ingredients to the upper-left text zone so they no longer overlap the enlarged logo.

## [2.34.26] - 2026-06-03

### Fixed
- Constrained second-page DeGema product names to the upper-left text zone so they no longer overlap the enlarged logo.

## [2.34.25] - 2026-06-03

### Changed
- Rebalanced second-page DeGema layout without borders so ingredients stay close to product names and lower sections use the full width below the logo.

## [2.34.24] - 2026-06-03

### Fixed
- Increased second-page DeGema vertical clearance below wrapped product names.

## [2.34.23] - 2026-06-03

### Fixed
- Increased second-page DeGema spacing between product names and ingredients content.

## [2.34.22] - 2026-06-03

### Fixed
- Added vertical separation between second-page DeGema product names and ingredients sections.

## [2.34.21] - 2026-06-03

### Fixed
- Constrained second-page DeGema composition text away from the brand logo and enlarged the logo by 50%.

## [2.34.20] - 2026-06-03

### Fixed
- Tightened DeGema product label name width so wrapped text stays clear of the product reference border.

## [2.34.19] - 2026-06-03

### Fixed
- Allowed DeGema product label names to wrap across two bounded lines without overlapping reference or front-page fields.

## [2.34.18] - 2026-05-20

### Changed
- Changed production lot generation to use `YYMM + MO` as the canonical inventory code and produced batch.

## [2.34.17] - 2026-05-20

### Fixed
- Fixed weight-unit template fields so product labels render the configured selectable unit options.
- Moved the second-page label logo away from ingredients content in normal, frozen, and weight templates.

## [2.34.16] - 2026-05-19

### Added
- Added the food product checkbox to the native product card KreaProducts section.

## [2.34.15] - 2026-05-19

### Changed
- Weight label templates now render a single composed `Peso: 0.000 kg` line instead of separate weight and unit blocks.
- Added MO display information to the front page of normal, frozen, and weight label templates.

## [2.34.14] - 2026-05-19

### Changed
- Aligned weight value and unit closer to the `Peso:` label in weight templates.
- Disabled human-readable text inside front-page barcodes to prevent barcode/code overlap.

## [2.34.13] - 2026-05-19

### Changed
- Updated normal and frozen DeGema label barcode blocks to use standard EAN-13 product barcodes instead of Code128 internal-reference barcodes.

## [2.34.12] - 2026-05-19

### Changed
- Updated normal and frozen DeGema label templates to use the same two-page visual design as their weight-label counterparts.

## [2.34.11] - 2026-05-19

### Changed
- Added the optional brand logo to the second page of normal and frozen weight label templates.

## [2.34.10] - 2026-05-19

### Changed
- Weight label unit fields now render as selectable units with kg as the default instead of free text.
- Weight label barcodes are now generated automatically as EAN-13 variable-measure barcodes and are no longer exposed as editable template fields.

## [2.34.9] - 2026-05-19

### Changed
- Split weight value and unit into separate visual blocks in normal and frozen weight label templates.

## [2.34.8] - 2026-05-19

### Changed
- Matched conservation text size to the other composition text sections in normal and frozen weight label templates.

## [2.34.7] - 2026-05-19

### Changed
- Normalized normal weight label composition page body sections to a consistent printer-readable font size.

## [2.34.6] - 2026-05-19

### Changed
- Added vertical separation between nutritional information and conservation instructions in normal and frozen weight label templates.

## [2.34.5] - 2026-05-19

### Changed
- Enlarged the conservation footer block in normal and frozen weight label templates and updated the frozen default text to Portugal Portuguese wording.

## [2.34.4] - 2026-05-19

### Changed
- Shortened the default conservation instructions in normal and frozen weight label templates to improve fit at printer-readable font size.

## [2.34.3] - 2026-05-19

### Changed
- Matched conservation text rendering in normal and frozen weight label templates to the allergens block font rules, removing auto-fit shrink behavior that could print below the supported text size.

## [2.34.2] - 2026-05-19

### Changed
- Moved conservation text from the front page to the composition page in normal and frozen weight label templates while preserving the approved reference and barcode layout.

## [2.34.1] - 2026-05-19

### Changed
- Increased the conservation text font in normal and frozen weight label templates to match the lot/date text size and prevent auto-fit from shrinking below the surrounding label text.

## [2.34.0] - 2026-05-19

### Added
- Added normal and frozen DeGema weight label templates with 3-decimal kg display, weight barcode, company data, packaging symbols, and a second page for ingredients, allergens, and nutritional information.

## [2.33.28] - 2026-05-19

### Fixed
- Fixed product weight edit units to preserve the saved unit and default to kilograms.

## [2.33.27] - 2026-05-19

### Fixed
- Fixed the product labels page banner to show the native product label instead of the label alias.

## [2.33.26] - 2026-05-19

### Fixed
- KreaProduction label API calls now use saved product template values as defaults, including manually edited ingredients.

## [2.33.25] - 2026-05-19

### Fixed
- Fixed label PDF generation for template layouts that define a valid label size without a Dolibarr format hint.

## [2.33.24] - 2026-05-18

### Fixed
- Fixed product list sorting for price columns with guarded sortable field mapping.

## [2.33.23] - 2026-05-17

### Fixed
- Fixed nutritional calculation warnings for missing product type and update statistics keys.

## [2.33.22] - 2026-05-14

### Fixed
- Persisted calculated nutrition table values to product nutritional records in calculated mode without requiring separate module write rights.
- Added product entity filtering to calculated nutrition persistence.

## [2.33.21] - 2026-04-30

### Changed
- Removed time from `Data de compra` display in the `Compras` table (`purchasePrice.php`), keeping date-only format.

## [2.33.20] - 2026-04-30

### Changed
- Updated `Compras` table links in `purchasePrice.php` to open supplier invoice and supplier card in a new browser tab.
- Added clickable supplier name links in the `Fornecedores` column.

## [2.33.19] - 2026-04-30

### Changed
- Made `Fatura` values in the `Compras` table (`purchasePrice.php`) clickable links to the supplier invoice card.
- Added supplier-invoice entity filtering to the purchases query to enforce multicompany-safe invoice listing.

## [2.33.18] - 2026-04-30

### Changed
- Updated the product name filter input in `custom/kreaproducts/product_list.php` to fill the full label column width, matching the product name cells in the list.

## [2.33.17] - 2026-04-30

### Fixed
- Reduced excessive horizontal stretch in the calculated nutritional table by switching to content-width table sizing and a tighter width profile while preserving product label visibility.

## [2.33.16] - 2026-04-30

### Fixed
- Restored visibility of the product label column in the calculated nutritional table by assigning an explicit name-column width and enforcing a minimum table width with horizontal scroll.

## [2.33.15] - 2026-04-30

### Changed
- Rebalanced the calculated nutritional table (`Tabela nutricional`) column widths by data type in `KreaProductsNutritionalCalculator.class.php` to improve readability for reference, product name, quantity/weight, and nutrient values.
- Added responsive horizontal wrapping and mobile-friendly cell behavior for the nutritional table.

## [2.33.14] - 2026-04-30

### Changed
- Rebalanced all component table columns by data type in `associatedProducts.php` (reference, name, costs, stock, quantity, weight, controls) and aligned editable quantity input width with the new layout.
- Applied the same width profile to the MRP BOM components table for consistent readability across both lists.

## [2.33.13] - 2026-04-30

### Changed
- Increased `Qtd.` column and editable quantity input width by 80% from the reduced size to improve readability in the kit components table.

## [2.33.12] - 2026-04-30

### Changed
- Reduced `Qtd.` column width by half in the kit components table and narrowed the editable quantity input to match.

## [2.33.11] - 2026-04-30

### Changed
- Rebalanced kit component table widths in `associatedProducts.php` by reducing `Ref.`, `Stock`, `Qtd.`, and `Custo comp.` columns and expanding the `Nome` display area.

## [2.33.10] - 2026-04-26

### Changed
- Mirrored DoliZSynch sell-price summary controls in KreaProducts `sellPrice.php`, including compact no-customer layout, automatic sell-price toggle, and inline markup edit.
- Normalized `sellPrice.php` license header to GPL-3.0-or-later.

## [2.33.9] - 2026-04-26

### Changed
- Optimized inventory line prefill by loading post-value-date stock movements in one grouped query instead of one query per line.
- Added idempotent performance indexes for stock movement, inventory line, and inventory anchor date lookups.
- Normalized touched module and inventory service headers to GPL-3.0-or-later.

## [2.33.8] - 2026-04-26

### Changed
- Expanded `admin/about.php` to document the complete KreaProducts feature scope and setup controls.
- Normalized the About page license display and header to GPL-3.0-or-later.

## [2.33.7] - 2026-04-26

### Changed
- Updated `admin/about.php` to document the prices updater, including ProductUpdater cascade recalculation, supplier invoice cost updates, and per-product price controls.

## [2.33.6] - 2026-04-26

### Changed
- Optimized automatic cost and sell-price cascade updates by batching hierarchy recalculation, preloading cost-sync flags, and suppressing duplicate trigger recursion during supplier invoice cost updates.

## [2.33.5] - 2026-04-26

### Changed
- Extended supplier invoice validation trigger to compute weighted HT purchase cost per product line and synchronize `product.cost_price` directly when per-product buy-price sync is enabled.

### Fixed
- Fixed missing automatic cost/sell synchronization when supplier invoice lines have no supplier reference or no existing supplier price-card row (`product_fournisseur_price`).

## [2.33.4] - 2026-04-25

### Changed
- Normalized remaining module author signatures to `Kreativität Works <mail@kreativitat.com>` in nutritional and product-allergen source/SQL files.

### Security
- Sanitized repository content for public publication by removing tracked runtime artifacts (`log.log`, `tmp/wordpress_about.html`, `.DS_Store`) and legacy packaged ZIP bundles containing those artifacts.

## [2.33.3] - 2026-04-25

### Changed
- Product card input for `kreap_updatesellpricepct` now uses a numeric stepper behavior (`step=0.01`) and normalizes values to two decimals.

### Fixed
- Preserved `KREAPRODUCTS_AUTO_SYNC_SELL_PRICE_FROM_COST` across module disable/enable by setting `deleteonunactive=0` in module constants registration.

## [2.33.2] - 2026-04-25

### Changed
- Kept sell-price synchronization percentage fully product-level via `product_extrafields.kreap_updatesellpricepct`.

### Fixed
- Fixed `kreap_updatebuyprice` visibility on product card view mode by normalizing product sync extrafields visibility (`list = 1`).
- Added one-time setup normalization (`KREAPRODUCTS_PRICE_SYNC_FIELDS_UI_NORMALIZED`) to enforce consistent UI/display metadata and non-null defaults for price-sync extrafields.

## [2.33.1] - 2026-04-24

### Fixed
- Preserved `KREAPRODUCTS_PRODUCT_REF_SUFFIXES` and `KREAPRODUCTS_PRODUCT_LIST_MARGIN_ENABLED` across module disable/enable by setting `deleteonunactive=0` in module constants registration.

## [2.33.0] - 2026-04-24

### Added
- Added product extrafields `kreap_updatesellprice` and `kreap_updatesellpricepct` to control automatic selling price synchronization when cost price changes.

### Changed
- Added setup toggle `KREAPRODUCTS_AUTO_SYNC_SELL_PRICE_FROM_COST` to enable or disable automatic sell-price synchronization.
- Automatic selling price synchronization now uses the product-level percentage field instead of a global percentage.

### Removed
- Removed global setup percentage constant `KREAPRODUCTS_AUTO_SYNC_SELL_PRICE_PERCENT`.

## [2.32.2] - 2026-04-24

### Changed
- Displayed only the product list margin percentage with two decimals.

## [2.32.1] - 2026-04-24

### Changed
- Rounded product list margin amount and percentage to two decimals and added a formula tooltip.

## [2.32.0] - 2026-04-24

### Added
- Added setup-controlled product margin column on `product_list.php` using `KREAPRODUCTS_PRODUCT_LIST_MARGIN_ENABLED`.

## [2.31.4] - 2026-04-24

### Changed
- Displayed product suffix filter checkbox labels in uppercase and updated setup examples to uppercase.

## [2.31.3] - 2026-04-24

### Fixed
- Changed product suffix filtering in `product_list.php` from product reference to product label suffixes, matching the actual suffix location.
- Updated suffix checkbox parameter names and setup wording to use product suffix semantics.

## [2.31.2] - 2026-04-24

### Fixed
- Changed product reference suffix filtering in `product_list.php` to strict end-of-string comparison using `RIGHT(TRIM(p.ref), length)` for each selected suffix.

## [2.31.1] - 2026-04-24

### Added
- Added setup field `KREAPRODUCTS_PRODUCT_REF_SUFFIXES` in `admin/setup.php` to configure comma-separated reference suffix filters.

### Fixed
- Removed hardcoded fallback suffix list from `product_list.php`; when `KREAPRODUCTS_PRODUCT_REF_SUFFIXES` is empty or missing, no suffix checkboxes are shown.
- Updated suffix filtering to use `LOWER(TRIM(p.ref))` for stable end-of-ref matching.

## [2.31.0] - 2026-04-24

### Added
- Added configurable product reference suffix filter checkboxes on `product_list.php` using global constant `KREAPRODUCTS_PRODUCT_REF_SUFFIXES` (comma-separated values, default `rv,cf,il`).

### Changed
- Applied OR-based suffix filtering on `p.ref` and preserved selected suffixes across sorting, pagination, and hide-toggle actions.

## [2.30.25] - 2026-04-20

### Changed
- Deactivated labels setup toggle usage: labels tab/page no longer depend on `KREAPRODUCTS_LABELS_TAB_ENABLED`.
- Removed labels toggle field from `admin/setup.php`.

### Fixed
- Normalized module signature metadata from corrupted `Kreativit채t` text to `Kreativität Works`.

## [2.30.24] - 2026-04-20

### Fixed
- Removed custom labels-tab helper wrapper and restored Dolibarr-native tab condition using `!empty($conf->global->KREAPRODUCTS_LABELS_TAB_ENABLED)`.
- Removed module-descriptor helper dependency from `product_labels.php` and restored direct constant check for tab/page enable state.

## [2.30.23] - 2026-04-20

### Fixed
- Removed labels-tab read permission checks from tab condition and aligned visibility with core product tab flow.
- Removed redundant `$conf->kreaproducts->enabled` gate from labels-tab condition to avoid multicompany false negatives.
- Kept write actions controlled by native product/service create rights on labels page.

## [2.30.22] - 2026-04-20

### Fixed
- Removed custom `kreaproducts` labels permission gate from labels tab visibility and aligned it with native product/service read rights.
- Aligned labels page read/write checks with native product/service permissions (`lire`/`creer`) instead of module-specific labels rights.

## [2.30.21] - 2026-04-20

### Fixed
- Replaced labels-tab condition static-call syntax with a safe wrapper function compatible with Dolibarr `dol_eval` restrictions.
- Prevented false-hidden labels tab in multicompany contexts where `::` is blocked in tab condition evaluation.

## [2.30.20] - 2026-04-20

### Fixed
- Restored product labels tab visibility in multicompany by resolving `KREAPRODUCTS_LABELS_TAB_ENABLED` across shared and legacy per-entity constant rows (including entity `8`).
- Unified labels page access check with the same toggle resolver used by module tab visibility logic.

## [2.30.19] - 2026-04-14

### Changed
- `product_labels.php` now prioritizes `product_extrafields.kreap_alias` as the effective product name in the label header and label output context.

## [2.30.18] - 2026-04-14

### Fixed
- Fixed `Data too long` errors when saving label JSON by upgrading `product_extrafields.kreap_default_label_layout` storage to `LONGTEXT`.
- Added runtime auto-migration before label save to keep existing environments writable without manual SQL intervention.

## [2.30.17] - 2026-04-14

### Fixed
- Decoupled label editable data persistence from template files by storing per-product label data as JSON in `product_extrafields.kreap_default_label_layout`.
- Read-only bundled templates now allow saving label field values and descriptions without forcing template copy creation.

## [2.30.16] - 2026-04-14

### Fixed
- Added per-field save buttons beside reset in label template fields.
- Saving from a field save button now persists only the clicked field value.

## [2.30.15] - 2026-04-14

### Fixed
- Synchronized `batch.validity_days` with `llx_product.lifetime` when saving template values and when generating labels.
- Label template context now hydrates `Validade (dias)` from `product.lifetime` so updates persist consistently.

## [2.30.14] - 2026-04-13

### Fixed
- Added a reset button on `associations_to_bom.php` to clear persisted source/target/BOM input fields.

## [2.30.13] - 2026-04-13

### Fixed
- Excluded associated products marked with `llx_kreaproducts_nutritional.is_food = 0` from the nutritional composition table and its totals/averages.

## [2.30.12] - 2026-04-09

### Fixed
- Restricted tree cost synchronization triggers to real `cost_price` changes only; removed fallback behavior that assumed change when no previous cost snapshot exists.
- `PRODUCT_PRICE_MODIFY` now follows the same strict cost-delta check before launching cascade recalculation.

## [2.30.11] - 2026-04-09

### Fixed
- Preserved `KREAPRODUCTION_ENABLE` across module disable/uninstall by setting `deleteonunactive=0` in module constants registration.
- This prevents accidental deletion of the global toggle from `llx_const` when `KreaProducts` is deactivated, keeping `/admin/const.php?mainmenu=home` configuration stable.

## [2.30.10] - 2026-04-09

### Fixed
- `KREAPRODUCTS_AUTO_SCALE_RECIPE_UNITS` now defaults to enabled when constant is missing (first run/legacy installs), ensuring recipe unit auto-scaling works immediately without requiring setup page save.

## [2.30.9] - 2026-04-09

### Added
- Added setup option `KREAPRODUCTS_AUTO_SCALE_RECIPE_UNITS` (default `1`) to enable automatic component unit display scaling.

### Changed
- Recipe API component lines now expose display-safe scaled fields (`qty_display`, `component_unit_display`, `component_unit_code_display`, `component_unit_label_display`) using the rule `>= 1 => kg/l` and `< 1 => g/ml`.
- Kept base recipe quantities and base unit fields unchanged to preserve production and stock calculation integrity.

## [2.30.8] - 2026-04-09

### Fixed
- Fixed `production/run` failures for non-stockable finished products (`stockable_product=0`) by extending MO line normalization to set `disable_stock_change=1` for both `toconsume` and `toproduce` lines when stock movement IDs cannot be produced by Dolibarr core.
- Preserved the existing subproduct safeguard on consume lines while preventing false `produceAndConsume` rollbacks that deleted auto-created MOs.

## [2.30.7] - 2026-04-09

### Changed
- Updated Portuguese `kreap_lot_help` text to: `Ativar a introdução do lote do produto no quiosque do módulo KreaProduction.`

## [2.30.6] - 2026-04-09

### Changed
- Updated MRP `kreap_lot` column short header from `Mostrar` to `Lote`.

## [2.30.5] - 2026-04-09

### Changed
- Updated MRP component `kreap_lot` checkbox rendering to match Dolibarr disabled checkbox visual style (as in product extrafields view).
- Editable MRP checkbox now uses disabled/read-only visual rendering and toggles by clicking the checkbox area.

## [2.30.4] - 2026-04-09

### Changed
- Updated MRP `kreap_lot` column header from initials to a short word label (`Mostrar`).
- Removed the MRP component `kreap_lot` pencil edit icon and kept only checkbox toggle interaction.
- Tuned native checkbox accent to keep dimmed grey style with white check mark.

## [2.30.3] - 2026-04-09

### Changed
- Reverted custom-drawn MRP `kreap_lot` checkbox style and restored native checkbox rendering.

## [2.30.2] - 2026-04-09

### Changed
- Updated MRP `kreap_lot` checkbox rendering to use a dedicated grey checkbox style with white check mark, matching the requested visual behavior.

## [2.30.1] - 2026-04-09

### Fixed
- Fixed MRP component checkbox state in `associatedProducts.php`: `kreap_lot = NULL` is now rendered as off when an extrafields row exists.

### Changed
- Updated MRP component checkbox visual style to a dimmed grey tone.
- Shortened MRP column header for `Mostrar em OP (KreaProduction)` to `OP` with tooltip help.

## [2.30.0] - 2026-04-09

### Added
- Added per-component `Mostrar em OP (KreaProduction)` checkbox in MRP components table on `associatedProducts.php` with direct edit link.

### Changed
- MRP component checkbox column is shown only when `KREAPRODUCTION_ENABLE=1`.
- Added component toggle action on `associatedProducts.php` to persist `kreap_lot` for each component product.

## [2.29.2] - 2026-04-09

### Fixed
- Fixed component `kreap_lot` resolution for recipe lines when Dolibarr stores unchecked booleans as null/empty: these values are now normalized to disabled (`0`) instead of falling back to enabled (`1`).

## [2.29.1] - 2026-04-09

### Fixed
- Fixed component `kreap_lot=0` handling in recipe API lines by replacing `!empty(...)` checks with `isset(...)`, so disabled values (`"0"`) are no longer coerced back to enabled (`"1"`).

## [2.29.0] - 2026-04-09

### Changed
- Recipe API lines (`production/products/{product_id}/recipe`) now include component `kreap_lot` (`component_kreap_lot`, `kreap_lot`, and `array_options.options_kreap_lot`) sourced from product extrafields.
- Updated module version after introducing `KREAPRODUCTION_ENABLE` setup toggle and `kreap_lot` visibility gating.

## [2.28.1] - 2026-04-09

### Changed
- Added global toggle `KREAPRODUCTION_ENABLE` (default `0`) so KreaProduction-specific product fields are off on first install.
- `kreap_lot` product extrafield is now shown only when `KREAPRODUCTION_ENABLE=1` (set in `/admin/const.php?mainmenu=home`).

## [2.28.0] - 2026-04-09

### Added
- Added product extrafield `kreap_lot` in module init/update flow (`modKreaProducts`) with default value `1` to control MO availability in KreaProduction.
- Added upgrade-safe backfill during module init to set `kreap_lot=1` on existing `product_extrafields` rows where value is null.

### Changed
- Added `kreap_lot` translation keys in `en_US` and `pt_PT` language files.

## [2.27.7] - 2026-04-06

### Changed
- Added explicit empty-section defaults for labels: `INGREDIENTES: Sem ingredientes declarados` and `DECLARACAO NUTRICIONAL: Sem valores nutricionais declarados`.
- Added composition back-page suppression: when ingredients, allergens, and nutrition have no data, only the first label page is generated/printed.
- Added context flags for section data presence and override-aware evaluation to keep page suppression consistent across PDF and TSPL generation paths.

## [2.27.6] - 2026-04-06

### Changed
- Updated TSPL back-label composition layout to flow `Ingredients`, `Allergens`, and `Nutritional declaration` sections sequentially from rendered text height with one blank-line gap between sections.
- TSPL section flow now uses template block `source` metadata for robust section detection while preserving existing PDF rendering behavior.

## [2.27.5] - 2026-04-06

### Changed
- Updated DeGema front static captions to short forms (`Emb.:` and `Cong.:`) to prevent clipping on thermal output.
- Refined TSPL centered-text positioning to use glyph-height centering for single-line blocks, improving optical vertical centering of product ref inside bordered boxes.

## [2.27.4] - 2026-04-06

### Changed
- TSPL text output now enforces ASCII-safe transliteration and symbol normalization (including `º`/`°` replacement) to avoid non-printable/mis-encoded characters on thermal printers.
- TSPL text block rendering now applies vertical centering for centered single-line fields (for example product ref inside bordered box).
- TSPL barcode bitmap rendering now stretches active bars to use full template barcode width (removing excess quiet margins).

### Fixed
- Fixed stale template date defaults leaking from database: date/datetime fields now default to label generation date/time at render time unless explicitly overridden by request values.

## [2.27.3] - 2026-04-06

### Changed
- Updated TSPL template rendering to honor block alignment (`left`/`center`/`right`) and improved per-block font sizing so printed typography is closer to PDF layout.
- TSPL linear barcodes now render as width-constrained bitmap blocks, stretching to use the full template barcode area.

### Fixed
- Fixed missing product-ref border box in TSPL output by ensuring `rect` blocks are rendered even when they have no dynamic value.
- Reduced text overlap/superimposition by enforcing tighter text clipping/truncation to template block dimensions.

## [2.27.2] - 2026-04-06

### Changed
- Improved TSPL text rendering to better match template typography by mapping template font sizes to multiple TSPL fonts and applying stricter line-height/line-wrap fitting per block.
- Reduced TSPL text overlap risk by clipping wrapped lines to block height with truncation when needed.

### Fixed
- Fixed inverted TSPL logo/symbol output by adjusting BITMAP bit polarity for XP-365B thermal printer rendering.

## [2.27.1] - 2026-04-06

### Changed
- Improved TSPL template fidelity for API label generation by rendering additional block types (`rect`, wrapped `text`) with template coordinates.
- Updated TSPL defaults to `DIRECTION 0` and explicit `REFERENCE 0,0` to reduce 90-degree rotation issues on thermal printers.

### Added
- Added TSPL image block support using printer `BITMAP` commands with packed monochrome raster data.
- Added bundled PNG fallbacks for DeGema template symbols (`degema_bw`, `green_dot_symbol`, `eu_food_contact_material_symbol`) to ensure image rendering when SVG runtime conversion is unavailable.

## [2.27.0] - 2026-04-06

### Added
- Added a new TSPL label API endpoint: `POST production/products/{product_id}/labels/tspl`.
- Added a TSPL compatibility endpoint: `GET production/products/{product_id}/labels/tspl`.
- Added TSPL serialization in `KreaProductsLabelService` to generate raw printer commands from existing standard/template label records.

### Changed
- Kept existing PDF label endpoints (`labels/pdf` and compatibility `labels`) unchanged while introducing TSPL as an additional output path.

## [2.26.28] - 2026-03-30

### Fixed
- Restricted dismantle valuation/cost-price recalculation to supplier-invoice origin (`originType = invoice_supplier`) in `productDismantle`.

## [2.26.27] - 2026-03-20

### Changed
- Removed the BOM reference column from `ProductParentList` in `associatedProducts.php` while keeping quantity formatted with 3 decimals and `un`.

## [2.26.26] - 2026-03-20

### Changed
- Filtered BOM links in `ProductParentList` (`associatedProducts.php`) to applicable assemble BOMs (`bomtype = 0`) and matched BOM selection by parent/quantity when multiple BOMs exist.

## [2.26.25] - 2026-03-20

### Changed
- Added BOM reference link column to `ProductParentList` in `associatedProducts.php` and formatted quantity as 3 decimals with `un` unit suffix.

## [2.26.24] - 2026-03-20

### Changed
- Restricted `BOMExistsAndOriginProduct` query in `associatedProducts.php` to `bom_bom.bomtype = 1` only.

## [2.26.23] - 2026-03-18

### Changed
- Added setup ON/OFF option `KREAPRODUCTS_AUTO_SYNC_SUPPLIER_PRICE_FROM_PURCHASE` to control supplier-price auto-sync from validated supplier invoices.

## [2.26.22] - 2026-03-18

### Changed
- Added supplier-invoice trigger sync to update `product_fournisseur_price.unitprice` and `price` from validated purchase lines, matched by current entity, supplier, product, and supplier code (`ref_fourn`).

## [2.26.21] - 2026-03-18

### Changed
- Updated Portuguese title `BOMExistsAndOriginProduct` to `MRP - Nomenclaturas que originam este produto`.

## [2.26.20] - 2026-03-18

### Changed
- Added `Stock`, `Qtd.` (3 decimals), `Peso (kg)`, and `Custo comp.` columns to the `BOMExistsAndOriginProduct` MRP table in `associatedProducts.php`.

## [2.26.19] - 2026-03-18

### Changed
- Standardized quantities to 3 decimal places in the MRP BOM list and kit components tables in `associatedProducts.php`.

## [2.26.18] - 2026-03-18

### Changed
- Added `Peso (kg)` column to the MRP BOM list and kit components tables in `associatedProducts.php`, converting product weight to kilograms with 3 decimals.
- Added per-table total weight sums (`kg`) alongside existing totals in both tables.

## [2.26.17] - 2026-03-18

### Changed
- Added a total row to the MRP BOM list table in `associatedProducts.php`, summing the `Custo comp.` column per BOM section.

## [2.26.16] - 2026-03-18

### Changed
- Rebalanced desktop column width distribution for the MRP BOM list table in `associatedProducts.php` to improve spacing and readability.

## [2.26.15] - 2026-03-18

### Changed
- Updated the `ComponentsOfProduct` title format in `associatedProducts.php` to `MRP - Lista de Materiais <BOM_REF>` (no dash before BOM ref).

## [2.26.14] - 2026-03-18

### Changed
- Styled the BOM reference link in the `ComponentsOfProduct` title to inherit title visuals (same color, no underline) while keeping it clickable and opening in a new tab.

## [2.26.13] - 2026-03-18

### Changed
- Removed the `Stock +/-` column from the MRP dismantling table rendered in `associatedProducts.php` (`ComponentsOfProduct` section).

## [2.26.12] - 2026-03-18

### Fixed
- Fixed `ComponentsOfProduct` SQL in `associatedProducts.php` by using BOM line position (`bom_bomline.position`) instead of non-existent `rang`, restoring rendered rows in the MRP dismantling table.

## [2.26.11] - 2026-03-18

### Changed
- Reworked `ComponentsOfProduct` rendering in `associatedProducts.php` to use the same field set as the kit components table (`Pos.`, `Ref.`, `Nome`, `Custo ingr.`, `Stock`, `Qtd.`, `Custo comp.`, `Stock +/-`).
- Removed the BOM column from that table and moved BOM identification to the section title using `bom_bom.ref` as a clickable link to the BOM card (opens in a new tab).

## [2.26.10] - 2026-03-18

### Fixed
- Added server-side product reference resolution in `associations_to_bom.php` so typed refs (for example `1453`) are accepted when combo search does not return the product.
- Preserved typed source/target product search text across validation redirects on the associations-to-BOM page.

## [2.26.9] - 2026-03-18

### Fixed
- Allowed `associations_to_bom.php` to copy associations into a draft BOM when source and target are the same product.

## [2.26.8] - 2026-03-16

### Fixed
- Aligned `degema_normal` and `degema_congelado` template physical size defaults to `75.6 x 49.9 mm` (matching legacy production PDFs used in Epson TM-L90 flows).
- Normalized front/back page size usage in template PDF generation so near-identical extracted page sizes no longer drift between pages and trigger unstable thermal-printer scaling.

## [2.26.7] - 2026-03-16

### Fixed
- Removed outer label boundary frames from generated product-label PDFs so silent and regular printing no longer draw limit borders around each label.

## [2.26.6] - 2026-03-16

### Changed
- Label PDF and production payload endpoints now resolve a missing `template_code` from product extrafield `kreap_default_label_layout`, with optional global fallback `KREAPRODUCTS_LABELS_DEFAULT_TEMPLATE_CODE`.

### Fixed
- Removed bundled-template loader reference to undefined `$entityId` to keep template resolution stable in strict PHP error-handling environments.

## [2.26.5] - 2026-03-16

### Fixed
- Added `GET production/products/{product_id}/labels/pdf` compatibility route delegating to the same PDF generator used by POST, so label generation still works when upstream rewrites POST calls to GET.

## [2.26.4] - 2026-03-16

### Fixed
- Added API door compatibility class alias `Kreaproducts` so `/api/index.php/kreaproducts/...` resolves the same KreaProducts API class as `kreaproductsapi`.
- Added `POST production/products/{product_id}/labels` as a backward-compatible alias to the `labels/pdf` generator endpoint.

## [2.26.3] - 2026-03-16

### Fixed
- Synced `product_extrafields.kreap_alias` updates to `dolizsynch_zsproduct.descricaocurta` on `PRODUCT_MODIFY` when the DoliZSynch table/column exists.

## [2.26.2] - 2026-03-16

### Changed
- Added native product-card module separator injection (`KreaProducts`) before `kreap_*` fields, using transparent background (no gray fill).

## [2.26.1] - 2026-03-15

### Changed
- Added `kreap_alias` to production category product payloads (`GET production/categories/{id}/products` and `GET production/categories/{id}/tree`) with `array_options.options_kreap_alias`.
- Label payload and PDF generation now prefer `kreap_alias` as product label when the extrafield is filled.

## [2.26.0] - 2026-03-15

### Changed
- Renamed product extrafield mirror from `kreap_zs_descricaocurta` to `kreap_alias`.
- Setup migration now ensures `kreap_alias` exists and copies legacy `kreap_zs_descricaocurta` values when the new field is empty.

## [2.25.0] - 2026-03-15

### Added
- Added product extrafield `kreap_zs_descricaocurta` (varchar 255) to mirror ZoneSoft short description independently from DoliZSynch module state.

### Changed
- KreaProducts setup now enforces creation/update of `kreap_zs_descricaocurta` on existing installations.

## [2.24.4] - 2026-03-15

### Added
- Added setup toggle `KREAPRODUCTS_SERVICE_CATEGORIES_LINK_ENABLED` (default ON) to control visibility of the Products menu shortcut to service categories.

### Changed
- Products menu shortcut `KREAPRODUCTS_SERVICE_CATEGORIES_LINK` is now shown only when `KREAPRODUCTS_SERVICE_CATEGORIES_LINK_ENABLED=1`.

## [2.24.3] - 2026-03-15

### Added
- Added a new Products left-menu shortcut (`KREAPRODUCTS_SERVICE_CATEGORIES_LINK`) pointing to `https://fin.degema.pt/categories/categorie_list.php?mode=hierarchy&type=service`.

## [2.24.2] - 2026-03-14

### Changed
- `POST production/products/{product_id}/labels/pdf` now resolves the produced lot from `produced_batch` (or normalized `inventorycode`) and injects it into `template_values.batch.lot_number` before rendering.
- `POST production/run` now injects the canonical produced lot into label payload template values so returned label payloads stay aligned with printed labels.

### Fixed
- Fixed label lot output truncation in production label flows where lot text could fall back to date-only (`AAAAMMDD`) instead of the canonical `AAAAMMDDHH + fk_mo` value.

## [2.24.1] - 2026-03-14

### Added
- Added canonical MO history endpoints for kiosk consultation:
  - `GET production/mos/created`
  - `GET production/mos/created/{id}`

### Changed
- Kept existing `GET production/boms/created` and `GET production/boms/created/{id}` as backward-compatible aliases that now delegate to the canonical MO endpoints.
- Updated API error/description wording for created-history consultation from BOM to MO semantics.

## [2.24.0] - 2026-03-14

### Added
- Added pagination and history-range controls to `GET production/boms/created` via `page`, `limit`, and `days_back`, with response metadata (`total_count`, `total_pages`).
- Added immutable created-BOM detail endpoint `GET production/boms/created/{id}` returning created header data plus traced component lines (qty/batch/line linkage).

### Changed
- `GET production/boms/created` now returns created record `ref` from MO reference (`mo_ref`) and keeps source BOM identity in dedicated `bom_ref` / `bom_label` fields.
- Expanded created-BOM list payload with `mo_label` and page/range metadata for kiosk pagination and printing workflows.

## [2.23.0] - 2026-03-14

### Added
- Added `GET production/boms/created` to expose produced BOM history from KreaProducts production trace, including date, BOM ref/label, batch (`inventorycode`), quantity, and linked product/MO ids for consultation flows.

### Fixed
- `POST production/run` now fills MO header fields consistently on auto-created draft MOs: label, planned start/end dates, and optional `fk_soc` / `fk_project` when provided.
- Existing draft MOs passed to `POST production/run` now receive missing label/planned dates before validation when those fields are empty, preventing incomplete MO headers (for example missing label/planned dates in produced MOs).

## [2.22.7] - 2026-03-13

### Changed
- Updated associations-to-BOM success links to open BOM card in a new browser tab (`target="_blank"` with `rel="noopener noreferrer"`).

## [2.22.6] - 2026-03-13

### Changed
- Updated standalone associations-to-BOM success flow to keep `setEventMessages($langs->trans("RecordSaved"), null, 'mesgs')` with BOM link and also drive a guaranteed sticky green inline alert from URL success flags.
- Removed direct consumption of `$_SESSION['dol_events']['mesgs']` in the helper page to avoid hiding Dolibarr standard event rendering.

## [2.22.5] - 2026-03-13

### Changed
- Updated standalone associations-to-BOM success message to use Dolibarr `RecordSaved` via `setEventMessages(..., 'mesgs')` and include a direct link to the BOM card in the same alert.

## [2.22.4] - 2026-03-13

### Changed
- Reworked `associations_to_bom.php` success notification to follow Dolibarr event-message flow (`setEventMessages` / `dol_events`) and render it as a sticky green inline alert with BOM link.
- Kept BOM label and produced quantity fallback defaults when inputs are left empty, and set a non-empty default label placeholder on initial load.

## [2.22.3] - 2026-03-13

### Changed
- Updated `associations_to_bom.php` success flow to use a session flash payload so the sticky green success alert is reliably shown after redirect, with direct link to the BOM card.
- Enforced fallback behavior for empty BOM label/produced quantity inputs, so save always uses current BOM defaults (or helper defaults) when fields are left blank.

## [2.22.2] - 2026-03-13

### Changed
- Updated `associations_to_bom.php` with optional BOM label and produced quantity inputs; when empty, placeholders use current draft BOM values (or helper defaults).
- Replaced the generic success flash with a sticky green success alert that includes a direct link to the created/updated BOM card.

## [2.22.1] - 2026-03-13

### Changed
- Updated release notes after standalone helper rollback: `associations_to_bom.php` keeps the origin selector as the standard product list.

## [2.22.0] - 2026-03-13

### Added
- Added a standalone helper page `associations_to_bom.php` (URL access) to choose source and target products and copy source `product_association` lines into a draft manufacturing BOM (`bomtype=0`) for the target product.
- Added transactional BOM line upsert (update existing direct lines, create missing lines) so repeated helper runs do not duplicate components.

## [2.21.6] - 2026-03-13

### Fixed
- Preserved `KREAPRODUCTS_LABELS_TAB_ENABLED` on module disable by keeping the constant in `llx_const` (`deleteonunactive=0`), so your configured value is no longer reset after deactivate/reactivate.

## [2.21.5] - 2026-03-11

### Changed
- `POST production/run` now enforces `inventorycode` in format `AAAAMMDDHH` + `fk_mo` (example: `202409301299` for MO `99`), keeping stock movement code and persisted trace code aligned with the production order id.
- Production batch trace schema now keeps `inventorycode` as `varchar(128)` and applies runtime column widening for existing installations so appended MO ids always fit.

## [2.21.4] - 2026-03-11

### Changed
- Minimized `llx_kreaproducts_mo_batch` storage by removing redundant `fk_bom`, `fk_product`, `produced_batch`, and `inventorylabel` columns (data is available through linked MO/product tables).
- Added runtime schema minimization for legacy `llx_kreaproducts_mo_batch` installs, including removal of obsolete BOM index/columns during trace table initialization.
- `POST production/run` now normalizes `inventorycode` to `AAAAMMDDHH` (`YYYYMMDDHH`) by extracting the first valid 10 digits from payload values or falling back to current server date/hour.

### Fixed
- Production run now enforces the produced stock batch code to the normalized `inventorycode`, so lot code and inventory movement code stay consistent for label printing workflows.

## [2.21.3] - 2026-03-11

### Changed
- Reduced production trace storage in `llx_kreaproducts_mo_component_batch` by keeping only IDs and transactional values (removed persisted `component_ref`, `component_label`, `fk_mo`, and `fk_bom` fields from trace lines).
- Added automatic schema minimization for legacy installs: deprecated component trace columns are dropped on runtime table initialization.

### Fixed
- `POST production/run` no longer stores duplicated component text payload values when saving component lot trace lines.

## [2.21.2] - 2026-03-11

### Fixed
- `POST production/run` now auto-disables stock change on MO consume lines when components are non-stockable or handled through subproduct associations, preventing false `api_mos` failures (`stock move id = 0`).
- Fixed kiosk production failures with rollback on BOM components that use subproduct stock rules (example flow that was failing with inventorycode `KP-20260311223104-6eb0209ccd614b2991f12a24d53ee28d`).

## [2.21.1] - 2026-03-11

### Fixed
- `POST production/run` now forces a rollback when core `Mos::produceAndConsume` throws before rollback, avoiding transient partial state on the same request connection.
- Auto-created MOs are now deleted when production fails before any `consumed`/`produced` lines are persisted.
- Production execution failures now return an actionable `409 Conflict` message with `inventorycode` context, instead of an opaque generic `500` for this workflow.

## [2.21.0] - 2026-03-11

### Added
- Added production trace tables for lot tracking: `llx_kreaproducts_mo_batch` and `llx_kreaproducts_mo_component_batch`.

### Changed
- `POST production/run` now accepts `produced_batch` and `component_lots` payload fields.
- Production consume payload now supports component quantity overrides per MO/BOM line.
- Production trace is persisted with links to `mrp_mo`, `bom_bom`, and `bom_bomline` when production succeeds.

## [2.20.12] - 2026-03-08

### Fixed
- `POST production/run` now accepts missing `warehouse_id` when defaults are available, resolving warehouse by: payload -> MO warehouse -> product default warehouse -> entity `MAIN_DEFAULT_WAREHOUSE`.
- Updated production run validation error to fail only when no warehouse can be resolved from any supported source.

## [2.20.11] - 2026-03-08

### Fixed
- Updated bundled DeGema label templates to use `product.ref` (text and barcode blocks) so printed code matches the actual product reference.

## [2.20.10] - 2026-03-08

### Fixed
- Corrected product reference input sanitization in `product_labels.php` to preserve full refs (numbers/symbols) when loading by `ref`.

## [2.20.9] - 2026-03-08

### Changed
- Translated bundled DeGema label template texts to Portuguese in `labels/degema_congelado.json` and `labels/degema_normal.json` (description, page labels, asset labels, and notes).

## [2.20.8] - 2026-03-08

### Fixed
- Decoded HTML-entity encoded Labels UI texts in `product_labels.php` so preview/layout metadata renders accented strings correctly.
- Normalized template/format preview strings in both PHP and JS render paths to avoid visible entity codes (for example `&atilde;`, `&eacute;`).

## [2.20.7] - 2026-03-08

### Changed
- Updated `admin/about.php` with a feature highlights block for Labels and Auto Dismantle.

## [2.20.6] - 2026-03-08

### Fixed
- Auto dismantle now records execution lines (`consumed`/`produced`) in `llx_mrp_production` with linked `fk_stock_movement`, so `/mrp/mo_production.php` shows the movements.
- Auto dismantle stock movements are now posted with `origintype='mo'` for the generated MO, so `/mrp/mo_movements.php` lists them.
- Added backdated stock recalculation for auto-dismantle MO movements to keep stock history consistency.

## [2.20.5] - 2026-03-08

### Fixed
- Auto BOM dismantle now registers an MRP disassembly order per source stock movement, so runs appear in `/mrp/mo_list.php`.
- Added idempotent import-key tracking and origin linking to avoid duplicate auto-dismantle MOs on retriggered movements.

## [2.20.4] - 2026-03-08

### Changed
- Replaced the Labels-tab refresh button icon with circular two arrows (`fa-sync-alt`).

## [2.20.3] - 2026-03-08

### Changed
- Moved default-layout save action to the "Modelo de etiqueta" selector row, side-by-side with refresh.
- Replaced the text save action with an icon-only button (`save`) using tooltip/aria label for "Guardar layout de etiqueta por defeito".

## [2.20.2] - 2026-03-08

### Changed
- Unified "Modelo de etiqueta" and product default label layout into one selector in Labels tab.
- Added a single "Save Layout de etiqueta por defeito" action that stores the currently selected template as `options_kreap_default_label_layout`.
- Labels tab now preselects the saved default template on first load and keeps explicit "Layout padrão" selection stable during refreshes.

## [2.20.1] - 2026-03-08

### Changed
- Added a save control for product default label layout (`kreap_default_label_layout`) directly in the Labels tab.
- Labels tab now persists `options_kreap_default_label_layout` through the product extrafields save flow.

## [2.20.0] - 2026-03-08

### Added
- Added product extrafield `kreap_default_label_layout` to persist the product default label template code for touch-app printing flows.
- Added recipe line unit metadata (`component_unit`, `component_unit_code`, `component_unit_label`) in `GET production/products/{product_id}/recipe`.

### Changed
- Production product payloads now expose `default_label_layout` and `array_options.options_kreap_default_label_layout` in category listing endpoints used by KreaProduction.
- Recipe line API now enriches BOM/association components with unit information resolved from product unit configuration when available.

## [2.19.0] - 2026-03-08

### Added
- Added `recipe_text` and `product.kreap_recipe` fields to `GET production/products/{product_id}/recipe`, sourced from `llx_product_extrafields.kreap_recipe`.

### Changed
- Recipe API now delivers both structured component lines and free-text preparation instructions in one payload for touch-app split-layout rendering.

## [2.18.1] - 2026-03-08

### Changed
- `GET production/products/{product_id}/recipe` now returns `source` metadata (`bom` or `association`) in the response payload.

### Fixed
- Added recipe fallback to `llx_product_association` when a product has no active BOM and no explicit `bom_id` is requested.
- Prevented recipe endpoint hard-failure for touch app recipe view on non-BOM products by returning association-based component lines when available.

## [2.18.0] - 2026-03-07

### Added
- Added `GET production/products/{product_id}/recipe` to return active BOM recipe lines (component ref/label, quantity, position, and stock-change flag) for touch-app recipe preview.
- Added optional `bom_id` query parameter to force recipe payload from a specific active BOM in scope.

## [2.17.1] - 2026-03-07

### Fixed
- Fixed `POST production/products/{product_id}/labels/pdf` HTTP 500 on some API contexts by making generated-file cleanup compatible when `dol_delete_file()` is not preloaded.
- Added safe fallback cleanup (`unlink`) so PDF payload generation no longer fails after successful file creation.

## [2.17.0] - 2026-03-07

### Added
- Added `POST production/products/{product_id}/labels/pdf` to generate one labels PDF from KreaProducts templates and return it as a base64 payload for touch apps.

### Changed
- Label-PDF API generation now supports template-mode and standard-mode payloads with `production_qty`, `units_per_label`, `labels_count`, `template_code`, `template_values`, and optional `langcode`.
- API now removes temporary generated label files after payload extraction to avoid accumulating transient PDF files in module documents.

## [2.16.1] - 2026-03-07

### Fixed
- Fixed production category-tree/product SQL for broader Dolibarr compatibility by selecting `llx_product.tobatch` as `status_batch` instead of querying a non-existent `status_batch` column.

## [2.16.0] - 2026-03-07

### Added
- Added `GET production/categories/{category_id}/tree` to return a full product-category subtree with associated products for each category node.
- Added tree response totals (`categories_count`, `products_count`, `producible_products_count`) for touch-app rendering and pagination decisions.

### Changed
- Updated BOM entity filtering in production category/product APIs to include shared BOMs (`entity = 0`) in addition to current entity scope.
- Updated BOM resolution for production run to accept shared BOMs when selecting default/requested BOMs.

## [2.15.0] - 2026-03-07

### Added
- Added a new `KreaProductsApi` REST class with touch-flow endpoints for production categories, products by category, label payload, and one-step production run.
- Added label payload responses with standard fields, available formats, available templates, selected template metadata, and computed recommended labels count.

### Changed
- Production run endpoint now complements core MRP API by orchestrating MO create/validate/produce with core `Mos::produceAndConsume`.
- Enforced API-side guards for entity-scoped BOM resolution, category-product consistency, and permission checks for MRP and labels access.

## [2.14.10] - 2026-03-07

### Changed
- Updated module About page with a latest-release summary block parsed from `ChangeLog.md` (version, date, and release bullets).
- Added About-page translation keys for changelog labels in English and Portuguese (`pt_PT`, `pt_BR`).

## [2.14.9] - 2026-03-07

### Fixed
- Fixed missing template images in generated PDFs by overlaying all image blocks with native TCPDF image rendering after SVG page rendering.
- Added shared template render geometry computation to keep SVG and native image overlays aligned in position/scale.

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
