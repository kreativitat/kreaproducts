# Repository Memory

For every meaningful code change in this repository:

1. Log the change in `ChangeLog.md`.
2. Keep changelog text short and simple (no long descriptions).
3. Use semantic versioning and include the release date (`YYYY-MM-DD`) in changelog entries.
4. Update the module version in `modDoliZSynch.class.php`:
   - `$this->version = 'X.Y.Z';`
5. Keep changelog version and module class version aligned.

6. When i say 'commit' commit to github the full changes

## Framework Integrity Guardrails (Dolibarr)

7. Do not inject custom global wrapper/helper functions in `core/modules/mod*.class.php`.
8. Do not use custom helper function calls or custom static class calls inside module tab condition strings.
   - Keep tab conditions Dolibarr-native (for example `!empty($conf->global->CONST_NAME)` and core rights checks).
9. Do not add page-level dependencies to module descriptor classes (for example `dol_include_once('/.../core/modules/modX.class.php')`) just to evaluate feature flags.
10. If a fix requires tab visibility changes, solve it with Dolibarr-native constants/permissions flow first, not custom evaluation layers.

Example style:

```md
# CHANGELOG MODULE KREABANK FOR DOLIBARR ERP CRM

## [1.5.8] - 2026-03-01

### Changed

- Enforced mandatory amount evidence (amount or amount_pending) for reconciliation suggestions.

### Fixed

- Fixed date-only suggestions (for example Pontuacao 30date) showing unrelated supplier invoices/payments in open documents.
```

## Purpose & Scope

- 2026-07-11: KreaProducts extends Dolibarr stock handling with value-dated inventories, supplier-invoice movement dating, automatic dismantling into MRP movements, production API posting, and product cost/sell-price cascades.

## Architecture Decisions

- 2026-08-10: Native product-card creation and updates leave `stockable_product` entirely to Dolibarr's `Product::create()` and `Product::update()` lifecycle. KreaProducts must not parse the checkbox value with `GETPOST(..., 'int')` or issue a parallel direct SQL update; an enabled HTML checkbox submits `on`, and integer parsing can incorrectly convert it to disabled stock management.

- 2026-08-07: `POST /api/index.php/kreaproducts/suppliers/{supplier_id}/invoices/validate` selects all draft invoices for one supplier in the active supplier-invoice entity scope and validates each through the trigger-safe single-invoice endpoint. Each invoice commits or rolls back independently; the response reports every validated and failed invoice so one business failure does not conceal prior successful stock postings.

- 2026-08-07: `POST /api/index.php/kreaproducts/supplier-invoices/{id}/validate` is the trigger-safe supplier-invoice validation boundary. It mirrors the native supplier-invoice card's validation right and warehouse preconditions, falls back to the current entity's `MAIN_DEFAULT_WAREHOUSE` when no warehouse is supplied, locks the entity-scoped draft invoice, wraps `FactureFournisseur::validate()` in an outer transaction, and always passes `notrigger=0`. API callers cannot suppress `STOCK_MOVEMENT`, `BILL_SUPPLIER_VALIDATE`, or other enabled business triggers.

- 2026-08-07: Customer invoice datetimes may be ahead of the server clock by `KREAPRODUCTS_INVOICE_DATETIME_FUTURE_TOLERANCE_MINUTES`, defaulting to 30 minutes and constrained to 0-1440 in setup. Values beyond the configured tolerance fail closed before movement retiming or inventory reconciliation.

- 2026-08-07: Customer `facture` movements use the authoritative invoice datetime. KreaProducts prefers the entity-scoped DoliZSynch `datahora_zs`, falls back to core `facture.datec`, and refuses datetimes more than five minutes in the future. It never moves a sale to the following day's business-close marker. Historical retiming continues through append-only inventory rebases.
- 2026-08-06: Stock reconstruction uses the latest active `kreaproducts_inventory_correction.corrected_counted_qty` as the inventory anchor when one exists. Count-correction movements remain excluded from operational movement sums, preventing both double counting and loss of append-only corrections. Automatic dismantling parses the source `stock_mouvement.datem` through `DoliDB::jdate()` because core SQL DATETIME values are server-local, not GMT.

- 2026-07-31: Every product participating in an automatic dismantling MO must be stock-managed. Before execution, KreaProducts permanently changes `stockable_product` to `Product::ENABLED_STOCK` inside the caller's transaction, verifies the persisted state, and then requires a real stock movement for every consume/produce execution line.
- 2026-07-31: Automatic dismantling owns the exact MO consume/produce movement set. Each generated movement disables Dolibarr's separate composed-product child propagation. When a declared MO product is itself a kit parent and global parent movements are disabled, the operation temporarily enables the parent movement only for that call and restores the entity configuration immediately afterward.
- 2026-07-31: `STOCK_MOVEMENT` returns `0` after successful processing and a negative value on failure, following Dolibarr's trigger contract. Module-managed transactional cost updates mark their internal `Product::update()` calls with `skip_kreawoo_realtime_sync` so irreversible WooCommerce requests cannot execute before the local stock and valuation transaction commits.
- 2026-07-17: A product cost change is an authoritative cascade input and is never recalculated during the batch it initiated. Every transitive dependent is recalculated. An active manufacturing BOM is the authoritative recipe for its parent; when several exist in the effective entity scope, the BOM with the latest non-cancelled `mrp_production` `produced` line is selected automatically. If none has production history, the newest validated active BOM is selected automatically. Entity-local BOMs take precedence over global BOMs. Product associations are used only when no active manufacturing BOM exists. Dismantling BOMs remain excluded, and any cycle in the selected association/BOM cost graph aborts before the first write.
- 2026-07-16: Every module-owned `Product::cost_price` writer calls `ProductUpdater::prepareProductCostUpdate()` before mutating the product. The helper preserves Dolibarr's database-backed `oldcopy`, allowing `PRODUCT_MODIFY` to detect the final cost change and apply the configured product selling-price markup. Existing valid `oldcopy` snapshots are never overwritten.
- 2026-07-16: Product weight forms use Dolibarr scale values directly. Scale `0` is kilograms. In the tested Dolibarr 23 source, `CUnits::fetchAll()` maps that zero scale to `NULL`, so scale-based selectors render kilograms with an empty option value; KreaProducts normalizes empty submissions back to `0` and applies a product-card hook so native create/edit forms select kilograms instead of the first unit.
- 2026-07-12: Every `ProductUpdater` caller treats collected cascade errors as a transaction failure. Product triggers, supplier stock processing, supplier-invoice validation, and manual purchase-price actions must fail closed instead of committing a partial product-cost graph.
- 2026-07-12: Manual and supplier-driven dismantling use the same raw BOM output quantities and common output unit cost: parent package cost divided by total output quantity. All output products must be available in the active product entity scope, and cost persistence uses `Product::update()` only inside the caller's transaction.
- 2026-07-12: REST and mobile boundaries expose business-safe validation messages but never raw database, object, or unexpected exception details. Technical diagnostics are written to Dolibarr logs, and mobile boundaries catch every `Throwable`.
- 2026-07-12: Association-to-BOM copies are POST-only and require an explicit Dolibarr CSRF token even when global CSRF enforcement is disabled.
- 2026-07-12: Label PDF, TSPL, API payload, and production label generation share one server-side maximum. `KREAPRODUCTS_LABEL_MAX_COUNT` may lower the limit, but the hard safety ceiling is 1000 labels per request.
- 2026-07-12: Supplier invoice price synchronization is fail-closed. Configured supplier-price, direct product-cost, BOM cascade, and selling-price failures return a negative trigger result so Dolibarr can roll back invoice validation instead of committing partial valuation changes.
- 2026-07-12: Module activation fails when a required product extrafield cannot be created or upgraded. Partial idempotent DDL may remain after a failed attempt and is completed on the next successful activation.
- 2026-07-12: Initiated managed inventories may be deleted by users with normal inventory count permission. Deletion does not require close/reversal permission because initiated inventories have no generated stock movements; the locked status check still rejects recorded inventories.
- 2026-07-12: The dedicated inventory controller uses explicit stock-read gates instead of `restrictedArea()` because Dolibarr interprets `confirm_delete=yes` as a core draft deletion and performs an unrelated core stock-write check before the KreaProducts service authorization runs. All mutations remain authorized inside the service.
- 2026-07-12: Permission `kreaproducts->inventory->expected` (`15655021`) is the inventory analysis gate. Without it, Dolibarr and mobile API responses omit expected stock, the count page omits virtual stock and both deviations, and the Statistics tab is hidden and server-rejected. Count saving and closure permissions remain independent.
- 2026-07-12: Product-page mutations are authorized server-side with the edit permission matching the current product or service type. CSRF tokens and hidden UI controls are not authorization. Product extrafields are created or upgraded only during module activation, never while rendering a product page.
- 2026-07-12: Manufacturing component quantities follow Dolibarr cost semantics: line quantity divided by line efficiency and BOM header quantity. Dismantling continues to allocate purchased parent value to outputs in `productDismantle.class.php`.
- 2026-07-12: Inventory reversal locks the entity-scoped inventory header before adjustment, correction, rebase, and stock rows. This preserves the common header-first lock order and prevents cutoff-time reversal/correction races.
- 2026-07-12: Mobile JSON routes intentionally bypass Dolibarr's form-token precheck because they use `X-CSRF-Token`; every mutating action is listed in one central POST and CSRF gate before dispatch.
- 2026-07-12: Product label requests perform read-only schema readiness checks. Label-storage column upgrades run only during module activation; maintenance scripts require an explicit administrator POST with a valid token before DDL.
- 2026-07-12: Production execution begins its outer transaction before any MO header, status, or line mutation. MO preparation, core stock posting, trace persistence, and label-payload preparation now succeed or roll back as one unit.
- 2026-07-12: Production trace schema creation and legacy-column cleanup run only during module activation. API requests perform a read-only schema readiness check and never execute DDL.
- 2026-07-12: Automatic dismantling fails closed when its MO cannot be loaded or validated, when idempotency cannot be queried, or when orphan cleanup cannot be completed.
- 2026-07-12: Scheduled inventory closure uses a dedicated administrator-only service entry point because Dolibarr cron users may not inherit KreaProducts mobile UI permissions. Interactive closure continues to require all normal module and stock rights.
- 2026-07-12: Category inventory creation is globally serialized through the configured root-category row. An existing initiated KreaProducts inventory may be reopened, but no new category inventory may be created until every initiated managed inventory is recorded.
- 2026-07-12: A one-minute Dolibarr scheduled job closes initiated managed inventories with incomplete-count confirmation enabled once their value-date calendar reaches 15 minutes before `KREAPRODUCTS_INVENTORY_ENTRY_CUTOFF_TIME`. Missed executions are retried after the threshold; blank lines remain unchanged.
- 2026-07-12: A next-window inventory may legitimately have a future 06:01 value timestamp, but stock movements cannot be generated until that timestamp is reached. Explicit value dates are capped at the current counting-window anchor.
- 2026-07-12: `POST production/run` accepts only validated MOs with no committed execution movements. The MO row is locked before posting, production warehouses are checked through the current stock-entity scope, and core stock posting plus KreaProducts trace persistence commit atomically.
- 2026-07-12: Automatic dismantling is part of the supplier stock transaction. MO registration, component movements, execution lines, MO status, and required cost updates must all succeed or the source supplier movement is rolled back. Multi-output valuation uses one common unit cost so total output value equals the consumed package value.
- 2026-07-12: Nutritional and product-allergen tables remain scoped through `fk_product` rather than gaining duplicate entity columns. Every list, fetch, update, and delete path must join or validate the linked product against `getEntity('product')`.
- 2026-07-12: Mobile offline inventory drafts persist both count values and the editable calendar value date, with backward-compatible reading of the earlier counts-only draft format.
- 2026-07-11: KreaProducts allows one inventory per template, warehouse, and normalized business-day value date. Starting the same inventory again reopens that day's record; templates that overlap on a product are blocked from creating a second same-day anchor.
- 2026-07-11: A recorded inventory remains correctable until the configured 20:00 counting cutoff. Corrections update the displayed physical count but preserve prior values in an append-only correction audit and create only delta stock movements; blank correction fields leave the prior count unchanged.
- 2026-07-11: An initiated inventory remains editable and closable even when its value date is outside the current counting window. Authorized users may change its calendar value date; the service stores that date at the configured close plus one minute.
- 2026-07-11: Every editable mobile inventory has a bottom save action. It displays `Guardar` for initiated inventories and `Guardar correções` for recorded inventories in correction mode.
- 2026-07-11: Module activation no longer enables `PRODUIT_SOUSPRODUITS`; composed-product stock behavior remains an explicit Dolibarr administrator setting.
- 2026-07-11: The former KreaStock mobile workflow is integrated under `/kreaproducts/stock_mobile.php`. New initiated inventories use padded `(PROV000000)` references and receive `YYYYMMDD_CATEGORY` only inside the recording transaction before stock movements are created. Ownership is marked with core `inventory.import_key='KPS'`; legacy technical `KPS-*` and `KS-*` references remain readable and closable.
- 2026-07-11: Opening a pre-3.1 initiated technical `KPS-*` inventory performs a locked one-time normalization to `(PROV000000)` and sets `inventory.import_key='KPS'`. Recorded references and legacy `KS-*` references are never changed by this compatibility path.
- 2026-07-11: Mobile count entries use half-open windows starting at the configured cutoff. With cutoff 20:00 and close 06:00, entries from 20:00 D1 through 19:59:59 D2 are value-dated 06:01 D2.
- 2026-07-11: Mobile inventory correction is append-only: `expected = live quantity - net non-inventory movements after value date`, `adjustment = counted - expected`, and current stock becomes `counted + later movements`.
- 2026-07-11: Recorded mobile inventories are not edited or deleted. Reversal creates opposite stock movements and retains both the original inventory and correction movements.
- 2026-07-11: Inventory reconstruction excludes both inventory correction movements and their reversal movements. A reversal cancels an anchor correction and is never treated as an operational purchase or sale.
- 2026-07-11: Before a delayed inventory is reconstructed, matching supplier-invoice movements from the inventory calendar date onward are normalized to `KREAPRODUCTS_SUPPLIER_MOVE_TIME` using `facture_fourn.datef`; only `stock_mouvement.datem` changes.
- 2026-07-11: Delayed customer and supplier normalization selects movements by document value-date eligibility or by an actual `stock_mouvement.datem` after the inventory anchor. Every retimed movement runs through the append-only rebase path.
- 2026-07-11: Physical count values have three distinct states: blank/`NULL` means not counted and leaves stock unchanged, numeric zero means explicitly counted as zero, and positive numbers are absolute physical counts. Closing with blank lines requires explicit confirmation in both mobile and Dolibarr interfaces.
- 2026-07-11: Import-key-managed inventories and legacy `KPS-*`/`KS-*` inventories use the same adjustment-ledger closure service from both mobile and Dolibarr; the native card must not maintain a second calculation path for these records.
- 2026-07-11: `/custom/kreaproducts/inventory.php` is a dedicated KPS/KS controller and view. Save, close, delete, and reversal actions delegate exclusively to `KreaProductsMobileInventoryService`; the page contains no native movement calculation branch.
- 2026-07-11: The Dolibarr managed-inventory workflow is one page: category selection starts or reopens the current inventory with the configured default warehouse, then the same page handles value-date editing, counting, saving, closure, correction, deletion, and reversal. Reference, title, and warehouse remain automatic.
- 2026-07-11: Recorded inventories in same-day correction mode show a prominent calendar-date warning in both Dolibarr and mobile. Corrections are explicitly limited to counts belonging to that displayed day.
- 2026-07-11: The Dolibarr category selector uses a native `noborder`/`liste_titre` table with one standard action button per category. Category names, product totals, and actions remain in separate columns.
- 2026-07-11: Inventory detail actions retain native Dolibarr `butAction` and `butActionDelete` colors, share a scoped 38-pixel height and identical inherited typography, and keep automatic widths. Category start and continue buttons retain the native `button` class with an exact shared 160-by-38-pixel size.
- 2026-07-11: The Generate movements and close action submits and persists the current count form before redirecting to the confirmation step. It must never navigate away through a plain link because unsaved browser values would be lost.
- 2026-07-11: The managed inventory detail summary shows warehouse, value date, and count progress; it omits the redundant initiated-status row.
- 2026-07-11: Dolibarr inventory detail/list views and the mobile inventory history display only the calendar value date. The stored timestamp and its 06:01 ordering time remain unchanged for stock calculations.
- 2026-07-11: Category action buttons use subtle state colors while retaining Dolibarr button classes: light green means a new inventory will be started, and light yellow means the current inventory will be reopened.
- 2026-07-11: Managed inventory detail uses `dol_banner_tab()` for the standard Back to list and previous/next pagination controls. The return URL preserves inventory-list filters with `restore_lastsearch_values=1`; the category selector has no duplicate List action.
- 2026-07-11: The category selector has no record object, so it renders Dolibarr's native `pagination paginationref` return control without Previous/Next links. It returns to inventory history with `restore_lastsearch_values=1`.
- 2026-07-11: The category selector is framed by a native one-tab Dolibarr fiche bar using `dol_get_fiche_head()` and `dol_get_fiche_end()`; its pagination return control sits inside that frame.
- 2026-07-11: Inventory close, delete, and reversal confirmations use non-AJAX `Form::formconfirm` POST forms. AJAX confirmation uses GET in the tested Dolibarr version and conflicts with the page's POST-only write contract.
- 2026-07-11: Inventory-closing confirmations select Yes by default, including incomplete-count warnings. Destructive delete and reversal confirmations continue selecting No by default.
- 2026-07-11: A product is countable only when normal Dolibarr operations maintain stock on that same product. When composed-product mode redirects movements to children and parent movements are disabled, kit parents are excluded from templates and new inventory lines.
- 2026-07-11: Reversal retains a narrow compatibility path for parent-only corrections created by version 2.37.0. It temporarily enables parent movement only while creating the opposite movement, without propagating to children.
- 2026-07-11: Same-day correction retains the same narrow compatibility for an existing kit-parent line only when the exact inventory line has an active adjustment linked to its original `inventory` movement. New kit-parent inventory lines remain prohibited.
- 2026-07-11: The inventory value date defaults from the counting window and is finalized automatically on the first non-blank count unless an authorized user explicitly selects another calendar date while the inventory remains initiated.
- 2026-07-11: Closing is refused before any movement or final-reference write when another recorded inventory exists for the same entity, template category, warehouse, and calendar value date. This check includes ordinary Dolibarr inventories, not only KreaProducts-managed records.
- 2026-07-11: Inventory creation locks the template category and warehouse before checking for an existing open inventory, serializing concurrent starts for the same scope.
- 2026-07-11: Late operational movements before an immutable ledger anchor are balanced with new `kreaproducts_inventory_rebase` movements. Reversal creates `kreaproducts_inventory_rebase_reversal` movements; original inventory and operational movements are never rewritten.
- 2026-07-11: Stock reconciliation is synchronous. Configured supplier and inventory hours are value-date ordering markers; stock movements are posted immediately when closure runs.
- 2026-07-11: `STOCK_MOVEMENT` delegates to `KreaProductsStockMovementService`; supplier invoices are processed by changing the core-created movement timestamp and reconciling it against recorded inventory anchors.
- 2026-07-11: Inventory creation delegates to `KreaProductsInventoryService`, which prefills expected quantities at `date_inventory` and immediately changes a new inventory from Draft to Validated/Started.
- 2026-07-11: Supplier invoice validation first posts core stock movements, then runs supplier price-row synchronization and direct/cascaded product cost and optional sell-price updates.

## Database & Schema Notes

- 2026-07-17: `product_association` has no entity column. Cost-cascade association queries isolate both `fk_product_pere` and `fk_product_fils` through the active `getEntity('product')` scope. Association recipes are excluded for parents with an applicable active manufacturing BOM.
- 2026-07-12: `kreaproducts_productallergens` is scoped through its product parent and has `idx_kreaproducts_productallergens_fk_product` installed idempotently for cascade and display lookups.
- 2026-07-12: `llx_kreaproducts_mo_batchtrace_upgrade.sql` idempotently widens the production inventory code and removes legacy trace columns during activation; production requests require the installed current schema.
- 2026-07-11: `kreaproducts_inventory_correction` is entity-scoped and stores every recorded-inventory count change with previous count, corrected count, delta, movement, author, and reversal linkage. It is created idempotently by the module SQL loader during module reactivation or upgrade.
- 2026-07-11: `kreaproducts_inventory_adjustment` is entity-scoped and stores one immutable audit row per inventory line, including live, post-value-date, expected, counted, adjustment, original movement, and reversal movement values.
- 2026-07-11: Rebase movements are stored in core `stock_mouvement`, scoped through the anchor inventory and entity-valid product/warehouse, and use deterministic inventory codes for idempotency and reversal linkage.
- 2026-07-11: `inventory` is entity-scoped. `inventorydet` is scoped through its inventory parent. `stock_mouvement`, `product_stock`, and `product_batch` have no direct entity column and must be isolated through entity-valid product and warehouse references.
- 2026-07-11: A recalculation anchor requires a recorded inventory plus either an active KreaProducts adjustment row or a matching legacy inventory movement for the same product, warehouse, and batch.
- 2026-07-11: `inventory.date_inventory` is a DATETIME in the tested Dolibarr 23.0.3 source tree; `setup_inventory_datetime.php` remains a manual compatibility utility for older schemas.

## Hooks & Triggers

- 2026-07-11: `STOCK_MOVEMENT` handles customer `facture` movements at their authoritative invoice datetime, supplier `invoice_supplier` movements at the configured supplier time, and inventory-ledger reconciliation through append-only rebases.
- 2026-07-11: Relevant trigger actions are `STOCK_MOVEMENT`, `INVENTORY_CREATE`, `INVENTORY_MODIFY`, `INVENTORY_RECORDED`, and `BILL_SUPPLIER_VALIDATE` in `core/triggers/interface_99_modKreaProducts_KreaProductsTriggers.class.php`.
- 2026-07-11: Inventory card/list hooks redirect native pages to the custom inventory implementations and override the Validated status label to Started.
- 2026-07-11: The native inventory-sheet hook redirects import-key-managed inventories and legacy `KPS-*`/`KS-*` references to the dedicated page. Ordinary inventory references remain on Dolibarr core, and the custom inventory list links each type to its owning page.
- 2026-07-11: New-inventory menu and list actions open the unified category page. `inventory_card.php` is only a compatibility redirect; existing ordinary inventories remain owned by Dolibarr core.

## Integration Points

- 2026-08-06: The standalone inventory auto-close runner tries `../../main.inc.php` first for a module installed directly under the Dolibarr document root, then `../../../main.inc.php` for an installation under `custom`, as required by Dolistore package validation.
- 2026-07-12: Production releases are built with `build/build-release.sh`. The release archive includes runtime module files and compiled `stock_frontend` assets while excluding `AGENTS.md`, `ChangeLog.md`, `bin`, `build`, `stockapp`, tests, local launchd files, and workspace metadata.
- 2026-07-12: `/kreaproducts/inventory_stock_overview.php` displays one table per direct child of the configured inventory root category. It lists the same countable products as the inventory templates and obtains current virtual stock through Dolibarr `Product::load_stock('nobatch')`. The page and its Products/Stock left-menu entry require stock read plus inventory analysis permission.
- 2026-07-12: Inventory statistics graph each product independently over 15 daily intake and consumption buckets selected from a product dropdown. Quantities from different products are never added together because their units may not be comparable.
- 2026-07-12: The inventory Statistics tab reports the current inventory products and warehouse over the last 15 calendar days. Positive operational stock movements are intake, negative movement magnitudes are consumption, and inventory correction and rebase origins are excluded.
- 2026-07-12: The mobile stock shortcut opens `/custom/kreaproducts/stock_mobile.php` from both `DeGema > Utilities` (`degema_utilidades`) and `Tools > Utilities` (`tools_utilidades`). It is not registered under Products/Stock. Module activation deletes entity-scoped legacy KreaStock and prior KreaProducts mobile-menu records while preserving both current destinations.
- 2026-07-12: `scripts/run_inventory_auto_close.php` executes only active `KreaProductsInventoryCron::closeDueInventories` rows, honors each entity's job condition, uses a shared administrator for Dolibarr cron auditing, and serializes invocations with a file lock. The local macOS environment runs it every 60 seconds through `com.kreativitat.kreaproducts-inventory`.
- 2026-07-11: KreaStock settings are copied once from legacy `KREASTOCK_*` constants when the KreaProducts setup page is opened. Existing user/group close-right assignments must be reviewed because KreaProducts uses new permission ids 15655041-15655043.
- 2026-07-11: The former private `kreativitat/KreaStock` GitHub repository and local sibling checkout were deleted after the mobile workflow was merged. KreaProducts is the sole maintained implementation.
- 2026-07-11: KreaProducts stock movement triggers continue to date supplier-invoice movements using `KREAPRODUCTS_SUPPLIER_MOVE_TIME`. Ledger-managed inventory and reversal movements carry an internal context flag so the legacy direct-recalculation branch does not rewrite them.
- 2026-07-11: The mobile service worker is delivered by `stock_mobile.php?kps_action=service_worker`, which gives it `/kreaproducts/` scope while keeping generated Workbox files under `stock_frontend/`.
- 2026-07-11: Supplier stock posting depends on Dolibarr `STOCK_CALCULATE_ON_SUPPLIER_BILL`; KreaProducts does not create a supplier stock movement when core supplier-invoice stock posting is disabled.
- 2026-07-11: Automatic dismantling creates or reuses an MRP MO and posts generated movements with `origintype='mo'` through Dolibarr `MouvementStock`.
- 2026-07-12: `POST production/run` delegates actual consumption/production posting to Dolibarr `Mos::produceAndConsume` inside an outer transaction that also persists KreaProducts trace data.

## Multicompany Notes

- 2026-08-07: Supplier-wide invoice validation requires access to the supplier in the active third-party scope and selects draft `facture_fourn` rows only from `getEntity('supplier_invoice')`; every selected invoice is re-authorized by the single-invoice boundary before validation.
- 2026-08-07: Supplier-invoice API validation checks Dolibarr resource access, locks `facture_fourn` within `getEntity('supplier_invoice')`, and accepts only active warehouses within `getEntity('stock')`.

- 2026-07-12: The inventory stock overview filters categories through `getEntity('category')`, products through `getEntity('product')`, and relies on core `Product::load_stock()` to apply the active stock-sharing scope.
- 2026-07-12: Optional production third-party and project references are accepted only when the record is in the active sharing scope and the API user can access it.
- 2026-07-11: The merged mobile workflow resolves products, categories, warehouses, inventories, configuration, and adjustment-ledger rows within the active entity or the corresponding Dolibarr sharing scope.
- 2026-07-12: Inventory, production, MO-line, and automatic-dismantling warehouse IDs are validated against the active `getEntity('stock')` scope before stock posting.

## Known Pitfalls & Gotchas

- 2026-08-08: Restler reflects protected methods while registering a Dolibarr API class. API helper parameters must not use constructor-dependent object type hints such as `FactureFournisseur`; Restler tries to instantiate the type without the required database argument and terminates every route for that API door with an empty HTTP 500 response.
- 2026-07-17: The local entity-8 runtime has a separate KreaWoo schema drift: `llx_kreawoo_product_site_data` lacks `wc_stock_status`, while the installed KreaWoo trigger writes that column. A normal cross-module `Product::update()` can therefore fail until KreaWoo's idempotent activation migration is applied. KreaProducts correctly treats that trigger failure as transactional and rolls back its cost cascade; KreaWoo schema changes remain outside this repository.
- 2026-07-12: KreaProducts supports MySQL and MariaDB only. Module activation rejects other Dolibarr database drivers before running module SQL.
- 2026-07-12: The KreaProducts inventory analysis permission hides analysis from this module and its mobile API. Users who retain broader Dolibarr product/stock permissions may still access stock information through other core pages; fully blind counting also requires reviewing those core rights.
- 2026-07-11: Same-day correction access ends exactly at the configured entry cutoff. At 20:00, the next business-day window begins and the prior inventory becomes read-only.
- 2026-07-11: Exactly 20:00 starts the next counting window. With a 06:00 close, 19:59 maps to 06:01 of the same calendar date and 20:00 maps to 06:01 of the next calendar date.
- 2026-07-11: A backdated inventory is refused when a later active adjustment anchor already exists for the same product, warehouse, and batch; the later inventory must be reversed first.
- 2026-07-12: The supplier time defaults to 10:00 and the legacy native inventory form still has a 10:30 default. The merged mobile flow derives 06:01 from the configured 06:00 business close; future anchors remain editable but cannot post movements before 06:01.
- 2026-07-11: Inventory UI fields use `qty_stock` as the expected/value-date snapshot and `qty_view` as the physical counted quantity. Existing `CASES.md`, README, and historical changelog text describe these names in the opposite order.
- 2026-07-11: The inventory count table hides the lot/serial column unless at least one line has a batch value. A displayed lot/serial identifies the exact lot-specific stock line being counted.
- 2026-07-11: The inventory count table places the product reference in its first column. Each reference opens the corresponding Dolibarr product card in a new browser tab.
- 2026-07-12: The inventory count table displays `qty_stock` as virtual stock, signed absolute deviation as `qty_view - qty_stock`, and relative deviation as that difference divided by `abs(qty_stock)`. Blank counts show no deviation, and zero virtual stock has no relative percentage.
- 2026-07-11: The merged KPS/KS flow persists every counted line in `kreaproducts_inventory_adjustment`, including zero adjustments. Non-KPS native zero-difference inventories remain outside this ledger.
- 2026-07-11: Recorded inventories may legitimately show fewer counted lines than total lines. Uncounted lines remain `NULL`, generate no movement or adjustment row, and are preserved in the closed inventory for audit visibility.
- 2026-07-11: Count saving and closure lock the entity-scoped inventory header before inventory lines. Keep this lock order for every new write path to avoid deadlocks and close/save races.
- 2026-07-11: Auto-dismantle movements use `KREAPRODUCTS_DISMANTLE_WAREHOUSE`, falling back to `MAIN_DEFAULT_WAREHOUSE`; they do not inherit the source supplier movement warehouse.
- 2026-07-12: Automatic inventory closure requires the Dolibarr Scheduled Jobs module and an operational cron runner. Module version 4.0.0 declares `modCron` as a dependency and enables the closure job at one-minute frequency.

## Environment & Configuration

- 2026-08-10: Version 4.7.2 passed the focused stock suite on PHP 7.3, 8.1, and 8.4, plus all 65 source PHP lint checks on each available runtime. The 179-entry release ZIP contains 64 lint-clean PHP files, excludes internal maintainer/test files, and has SHA-256 `dcde4010c4247ed5dfcc531e1c9d5eb8f529f62412599e7458f897e87ca541f8`. Live installation remained pending because the browser session was unauthenticated and SSH had no available identity.

- 2026-08-07: Version 4.7.0 passed the focused stock/API suite and all 65 source PHP lint checks on PHP 8.1.20 and 8.4.5. An unauthenticated HTTP probe reached the registered KreaProducts batch API class without executing validation. The final rebuilt 179-entry release ZIP includes the supplier-wide validation route, excludes internal maintainer/test files, and has SHA-256 `324e0e3f8998d77ad0eca007143bd70483f30fb9e827fb360af721d2622efd35`.
- 2026-08-07: A read-only live database check found active `MAIN_DEFAULT_WAREHOUSE` targets for entities 1-11 (entity 8 resolves to warehouse 49, `DG99`). Entities 12 and 13 currently have value `0`; supplier-invoice API validation that requires stock will fail closed for those entities unless a warehouse is supplied or their entity default is configured.
- 2026-08-07: Version 4.6.0 passed the focused stock/API suite and all 65 source PHP lint checks on PHP 8.1.20 and 8.4.5. The 179-entry release ZIP includes the trigger-safe supplier-invoice validation endpoint and entity-default warehouse fallback, excludes internal maintainer/test files, and has SHA-256 `fe02ad2c2d697e69be040201fd0800fad4830ed759c08d221d3933f8e9296d1a`.

- 2026-07-17: Version 4.5.8 passed the focused suite and all 65 source PHP lint checks on PHP 7.3.33, 8.1.20, and 8.4.5. Entity-8 live resolution selected one automatic manufacturing BOM for each of 22 active BOM parents without errors. Rollback-only product-update probes propagated source 2308 to dependents 2775, 4154, 4155, 4156, 4157, and 9788; preserved recipe-owning source 7855 while updating dependent 4154; and recalculated product 10830 from cost 7.00 to selling price 8.05 at 15 percent markup. The 179-entry release ZIP contains 64 PHP files linted on all three PHP versions and has SHA-256 `f689f0c53bf413579621731dabb3000349e4af02c28493cf342fb17008b60934`.
- 2026-07-16: Version 4.5.7 passed the focused suite and all 65 source PHP lint checks on PHP 7.3, 8.1.20, and 8.4.5. A read-only entity-8 probe on product `8497` verified that `prepareProductCostUpdate()` retains the original cost `6.00`, does not replace an existing snapshot, and makes a simulated cost `7.00` detectable by `hasCostPriceChanged()`. The rebuilt 179-entry release ZIP contains 64 PHP files that passed lint on all three PHP versions.
- 2026-07-16: Version 4.5.6 passed the focused suite and all 65 source PHP lint checks on PHP 7.3, 8.1.20, and 8.4.5. Live PHP 8.1 rendering verified that the KreaProducts selector and native product-card hook select kilograms for scale `0` without overriding gram products. The 179-file release ZIP passed all 64 packaged PHP lint checks.
- 2026-07-12: Version 4.5.5 passed the focused suite and all 65 source PHP lint checks on PHP 7.3.33, 8.1.20, and 8.4.5. An entity-8 rollback-only test on BOM 149 allocated parent cost 25.384 across output quantities 4.5 and 3 at common unit cost 3.38453333, preserved total value, and restored both original costs after rollback. The rebuilt 179-file ZIP passed all 64 packaged PHP lint checks, and the production npm dependency audit reported zero vulnerabilities.
- 2026-07-12: Release versions must remain synchronized across `core/modules/modKreaProducts.class.php`, `stockapp/package.json`, and both root-package version fields in `stockapp/package-lock.json` before rebuilding the frontend and ZIP artifact.
- 2026-07-12: KreaProducts requires PHP 7.3 or newer. The release archive is built by `build/build-release.sh` exclusively from entries in `build/makepack-kreaproducts.conf`.
- 2026-07-12: The local isolated inventory runner is loaded from `~/Library/LaunchAgents/com.kreativitat.kreaproducts-inventory.plist`; its tracked deployment template is `scripts/launchd/com.kreativitat.kreaproducts-inventory.plist`, and logs are written under `/Users/marceloaraujo/Documents/Code/php/logs/`.
- 2026-07-11: `KREAPRODUCTS_STOCK_MOVEMENT_DATA` must remain enabled for KPS/KS count creation, saving, and closure. The inventory service refuses writes when value dating is disabled because customer-sale ordering cannot otherwise be guaranteed.
- 2026-07-11: Mobile stock defaults are timezone Europe/Lisbon, business close 06:00, entry cutoff 20:00, and supplier movement time 10:00. Inventory value time is derived as close plus one minute and is not independently configured.
- 2026-07-11: The merged React/PWA source is under `stockapp/`; the deployable build is under `stock_frontend/` and is produced with `npm run build:module`.
- 2026-07-11: `js/kreaproducts_inventory.js` adds client-side category opening feedback, product filtering, live counted progress, dirty-state Save control, and an unsaved-change warning. Server-side service validation remains authoritative.
- 2026-07-11: Static review used PHP 8.4.5 and the adjacent Dolibarr 23.0.3 source tree. All repository PHP files passed `php -l`.
- 2026-07-12: Version 4.5.4 passed all 65 PHP-file lint checks and the focused stock suite on PHP 7.3.33 and PHP 8.1.20. A read-only entity-8 live graph probe loaded 22 active manufacturing BOM parents into 46 product nodes with zero association edges, cascade errors, or cycles.
- 2026-07-11: Focused stock regression tests are in `tests/run_stock_logic_tests.php`; they cover business-day boundaries, supplier time, delayed reconstruction, correction, and reversal ordering.
- 2026-07-11: Live database verification from the host checkout is unavailable because the active Dolibarr configuration resolves core paths inside `/var/www/html`.
- 2026-07-11: Live rollback-only verification is available through the `lamp-php81` container. Inventory 4341 proved kit-parent closure, list visibility, append-only rebasing, and rebase reversal without persisting test changes.
- 2026-07-11: After module restart, the live database contains both inventory adjustment and correction audit tables. A rollback-only correction of inventory 4341 line 108480 proved that audited legacy kit parent 4215 creates one parent-only correction and no component movements.

## Technical Debt / Open Issues

- 2026-07-11: Non-KreaProducts inventories that retain the legacy native recording path have no persistent anchor for zero-difference counts. `KPS-*` and `KS-*` inventories use the adjustment ledger in both interfaces.

## Conventions

- 2026-07-12: Module descriptor tab definitions use only Dolibarr-native labels, constants, and permission expressions. Dynamic helper callbacks are not allowed in descriptor tab strings.
- 2026-07-12: `langs/en_US/kreaproducts.lang` is the canonical module catalog. `langs/pt_PT/kreaproducts.lang` must preserve exact key and placeholder parity with it; the Portuguese completion release validated 689 unique keys with no missing, extra, duplicate, or placeholder-mismatched entries. Other locale catalogs remain unchanged until they are translated explicitly.
- 2026-07-12: User-facing setup labels, permission descriptions, AJAX responses, and mobile inventory errors must use Dolibarr translation keys. Framework-neutral calculation classes may retain English exception text only when the localized boundary translates it before display.

## Deprecated Knowledge

- 2026-08-07: The 2026-07-11 rule that moved customer invoice day D to the configured close on D+1 is obsolete as of 4.5.15. Customer movements now retain their authoritative invoice datetime.
- 2026-07-31: The 4.5.11 design that allowed non-stock-managed MO products to create execution lines without stock movements is obsolete as of 4.5.12. MO participation now makes stock management mandatory and updates the product accordingly.
- 2026-07-17: The version 4.5.4 rule that treated multiple active manufacturing BOMs as unresolved became obsolete in version 4.5.8. Selection is now automatic: latest completed production first, then newest validation when production history is absent.
- 2026-07-17: The version 4.5.4 rule excluding all product associations from cost valuation became obsolete in version 4.5.8. Associations are again supported as the recipe fallback when a dependent product has no active manufacturing BOM.
- 2026-07-16: The version 4.5.6 defect that skipped selling-price markup updates after module-managed cost changes became obsolete in version 4.5.7 when every cost writer began preserving Dolibarr's pre-update `oldcopy`.
- 2026-07-12: The purchase-price page's standalone dismantling helper became obsolete in version 4.5.5 because it assigned the full parent value independently to every output and bypassed entity-scoped framework persistence.
- 2026-07-12: `setup_sync_columns.php` was removed because `product_association.syncprice` is obsolete and runtime synchronization is controlled by product extrafields. Existing legacy columns are intentionally not dropped automatically.
- 2026-07-12: The version 4.4.0 aggregate daily and cross-product graphs became obsolete in version 4.4.2 because summing quantities from products with different units is misleading.
- 2026-07-12: The version 2.40.6 one-tab inventory fiche became obsolete in version 4.4.0 when the read-only 15-day Statistics tab was added. The separate native Ficha workflow remains removed.
- 2026-07-12: The version 4.1.4 Products/Stock placement became obsolete in version 4.2.0 when the mobile shortcut moved to DeGema Utilities and Tools Utilities.
- 2026-07-12: The version 4.1.3 external KreaStock left-menu destination became obsolete in version 4.1.4 when the shortcut was redirected to the integrated KreaProducts mobile application.
- 2026-07-12: The inactive-runner blocker became obsolete when the isolated macOS LaunchAgent was loaded. The unrelated global Dolibarr scheduler remains intentionally untouched.
- 2026-07-12: The pre-4.1 cost cascade that merged manufacturing and dismantling BOMs and ignored efficiency/header quantity became obsolete when cost propagation was aligned with Dolibarr BOM cost semantics.
- 2026-07-12: The version 3.2.0 behavior allowing production posting on In-Progress MOs became obsolete in version 4.0.0 because it could duplicate full planned quantities.
- 2026-07-12: The pre-4.0 production trace transaction and multi-output dismantling valuation became obsolete when stock/trace atomicity and value-preserving output allocation were added.
- 2026-07-12: The pre-4.0 rule allowing multiple category inventories to remain initiated concurrently became obsolete when the global open-inventory gate and automatic cutoff closure were added.
- 2026-07-12: The 2026-07-11 statement that there was no scheduled inventory posting became obsolete when the one-minute automatic closure job was introduced. Movement creation itself remains synchronous inside each closure transaction.
- 2026-07-11: The pre-3.2 rule that locked initiated inventories outside the current counting window became obsolete when initiated value dates became explicitly editable.
- 2026-07-11: Version 3.0.0 assigned `YYYYMMDD_CATEGORY` at inventory creation. Version 3.1.0 delays this final reference until inventory recording and uses padded Dolibarr provisional references while initiated.
- 2026-07-11: The technical `KPS-entity-category-timestamp-random` reference format became obsolete in version 3.0.0. New references use `YYYYMMDD_CATEGORY`, with module ownership stored separately in `inventory.import_key`.
- 2026-07-11: The version 2.40.0 decision to remove the fiche tab bar became obsolete in version 2.40.6. A single active Inventory tab now provides native Dolibarr framing without a second workflow.
- 2026-07-11: The version 2.40.2 fixed 220-pixel action width became obsolete in version 2.40.5 because the Portuguese close label wrapped; actions now use 300 pixels with `white-space: nowrap`.
- 2026-07-11: The version 2.40.5 fixed 300-by-46-pixel action size became obsolete in version 2.40.10 because it stretched the detail action bar. Detail actions now use native Dolibarr sizing.
- 2026-07-11: The version 2.40.10 reliance on native detail-action heights became obsolete in version 2.40.11 because Dolibarr applies different spacing to anchor and button elements. A scoped shared height now aligns them without fixing their widths.

- 2026-07-11: The earlier statement that no automated test suite existed became obsolete when focused stock regression tests were added in version 2.36.1.
- 2026-07-11: The earlier statement that the mobile service rejected incomplete inventories became obsolete in version 2.36.2; incomplete closure is now allowed only after explicit confirmation and blank lines remain unchanged.
- 2026-07-11: The earlier technical-debt note that supplier/inventory recalculation SQL failures were not propagated became obsolete in version 2.37.0; reconciliation failures now return a negative trigger result.
- 2026-07-11: The earlier note that KreaProducts-managed native closure lacked zero-adjustment anchors became obsolete in version 2.37.0; `KPS-*` and `KS-*` closure now delegates to the adjustment ledger in both interfaces.
- 2026-07-11: The version 2.37.0 rule that temporarily forced inventory movements onto kit parents became obsolete in version 2.37.1 because normal Dolibarr sales did not maintain those parent stocks.
- 2026-07-11: The earlier legacy recalculation paths that rewrote `stock_mouvement.value` became obsolete in version 2.37.1; operational moves now use append-only rebases and backdated inventory movements remain immutable.
- 2026-07-11: The version 2.38.0 cutoff defect that left counted validated inventories editable became obsolete in version 2.38.1; only empty inventories can move forward on their first count.
- 2026-07-11: The version 2.38.0 invoice-normalization filters based only on document dates became obsolete in version 2.38.1; actual post-anchor movement position is also selected and rebased.
- 2026-07-11: The version 2.38.0 correction footer and missing live correction-table notes became obsolete in version 2.38.1 after the bottom save action was added and module restart installed the table.
