<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->

# KreaProducts for Dolibarr ERP/CRM

KreaProducts is an advanced product management module for the [Dolibarr ERP/CRM](https://www.dolibarr.org). It extends the Products module with nutrition, allergens, BOM/Technical Sheets, inventory, and cost/stock automations - built for hospitality and retail operations that need consistency, traceability, and accurate _food cost_.

## Features

### Nutrition and allergens

- Nutritional table with automatic calculation, validation, and updates.
- Nutrient propagation between parent/child products, including BOM (MRP) when enabled.
- Allergen management with propagation by percentage of total weight and trace marking.
- Support for non-food products (excluded from calculation).

### Product structure and BOM

- Complete product tree (associations + BOM/MRP, when enabled), with hierarchical navigation.
- Detailed view of product composition (components, quantities, and subproducts), with **cost price** totals.
- Clear identification of relationships between products and technical sheets, including **source packages** when applicable.
- Reverse view (_where used_): list of kits/technical sheets and menus where the item is used as a component, enabling the impact analysis of cost changes, substitutions, and raw-material normalization.
- Dismantling BOM with origin and relationships visible on the technical sheet.
- Automatic cascading cost recalculation based on components/technical sheets.
- Nested BOM support (BOM lines referencing another BOM), with correct propagation of costs, nutrition, and allergens.
- Multicompany: shared BOMs (entity=0) are available across entities, with priority given to the current entity BOM when it exists.
- Dismantling is enabled per product via the `kreap_dismantle` extra field.

### Correct stock and inventory dates (invoice date and value date)

By default, Dolibarr records many movements **on the date the document is posted/validated in the system** - which may not match operational reality. In environments with frequent purchasing, this difference creates deviations and noise in stock analysis.

KreaProducts addresses this limitation with two essential automations:

- **Stock entry by invoice date (suppliers):** products are posted to stock with the **invoice date/receipt date**, instead of the date the document is entered in Dolibarr. This eliminates discrepancies when the invoice is registered days later.
- **Inventory by value date (backdated):** inventory adjustment is applied based on the **inventory date (value date)**, not the validation date. This makes it possible to enter an inventory with a prior value date (for example, a week ago) and keep corrections and reports consistent - something the standard module does not guarantee.
- **Inventory anchored to counted stock:** recalculation uses the **counted quantity** (qty_stock) when available, falling back to qty_view only when needed, preventing drift on backdated movements.

### Smart packaging and unit cost management (automatic dismantling)

In hospitality, it is common to buy the same item in different packages - but for _food cost_ what matters is the **real unit cost** (e.g., EUR/L, EUR/kg, EUR/unit).

Typical example: **oil**. It can be purchased in **10L, 5L, 1L canisters** or **12x1L cases**. If these packages enter the system as \"different products\", inconsistencies in stock and unit cost quickly appear.

KreaProducts solves this using Dolibarr's **BOM module (Bills of Materials / Material Sheet - FM)**:

- Configure a material sheet for the package (e.g., _10L canister_), defining the conversion to the unit product (e.g., _10x 1L_).
- From then on, whenever a purchase of one of these packages is recorded, the system performs **automatic dismantling** to the unit product, **without user intervention**.

This process:

- creates the corresponding **stock movements**,
- keeps the **proportional cost** and traceability (origin -> destination),
- and ensures the unit product is ready for use in recipes, inventory, and margin calculations.

### Automatic cost and _food cost_ updates (cascade)

KreaProducts also automates **cost price** and **food cost** updates for finished products, based on their technical sheets (BOM/FM).

In practice:

- if a component (e.g., **oil**) has its purchase price updated,
- all products that use that component (e.g., **french fries**) have their **cost recalculated automatically**,
- ensuring that _food cost_ and margins always reflect reality, without manual adjustments.

This feature is especially relevant in operations with many recipes and frequent purchasing, where small cost variations must be reflected immediately in finished products.

### Productivity and lists

- Simplified product list with an option to hide items.
- Price simulator (Metrics and Markup) with test markup.
- Stock movement list by product shows **total stock**.

## Requirements

- Dolibarr >= 19
- PHP >= 7.0
- Required modules: Products, Stock, Suppliers, BOM/MRP
- Optional: Lots (productbatch)

## Installation

1. Copy the module to `custom/kreaproducts`.
2. Enable it in Configuration -> Modules/Applications -> KreaProducts.
3. Adjust the options on the configuration page.
4. If needed, import the scripts in `sql/`.

## Configuration (main constants)

| Constant | Description |
| --- | --- |
| `KREAPRODUCTS_DEFAULT_WEIGHT_LABEL` | Unit class for weight. |
| `KREAPRODUCTS_NUTRITIONAL_TABLE_TAB` | Show the nutritional table in the technical sheet tab. |
| `KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT` | Show the selector and button to copy average values per 100g. |
| `KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT` | Show the selector and button to copy allergens to another product. |
| `KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE` | Automatically propagate cost price (cascade recalculation). |
| `KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT` | Percentage of total weight to consider allergens present. |
| `KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT` | Percentage of total weight to mark allergens as traces. |
| `KREAPRODUCTS_STOCK_MOVEMENT_DATA` | Use invoice date for stock movements. |
| `KREAPRODUCTS_SUPPLIER_MOVE_TIME` | Time applied to supplier invoice movements. |
| `KREAPRODUCTS_INVENTORY_DEFAULT_TIME` | Default time when creating inventory. |
| `KREAPRODUCTS_INVENTORY_CATEGORY_ROOT` | Root category for inventory selection. |
| `KREAPRODUCTS_DISMANTLE_BOMTYPE` | BOM type used for dismantling. |
| `KREAPRODUCTS_DISMANTLE_WAREHOUSE` | Warehouse for dismantling movements. |
| `KREAPRODUCTS_SIM_ENABLE` | Enable the price simulator. |
| `KREAPRODUCTS_SIM_DEFAULT_MARKUP` | Default simulator markup. |
| `KREAPRODUCTS_REPLACE_PRODUCT_LIST` | Replace the standard product list. |
| `KREAPRODUCTS_DEBUG_LOG` | Enable KreaProducts debug logs. |

Note: allergen thresholds are percentages of the total recipe weight of the final product.

## Permissions

- Nutrition: read, write, delete.
- Allergens: read, write, delete.
- Inventory: view expected values.

## License

- GPL-3.0-or-later (see LICENSE and COPYING).
- Proprietary license available for commercial use or closed-source code; contact mail@kreativitat.com.

## Support and contributions

- GitHub: https://github.com/kreativitat
- Website: https://www.kreativitat.com
- Demo: https://dolibarr.kreativitat.com

## Disclaimer

Nutrition and allergen data is entered by the user or derived from their inputs and is not verified. It is provided for informational purposes only and does not constitute medical, dietary, or regulatory advice. The user is solely responsible for accuracy, labeling, and compliance with applicable laws. This module is provided "as is", without warranties of any kind, express or implied, including merchantability and fitness for a particular purpose. To the maximum extent permitted by law, the authors and distributors are not liable for any direct or indirect damages arising from the use of the data or the software.

## Screenshots

![KreaProducts - Screen 1](img/screenshot_1.png)

![KreaProducts - Screen 2](img/screenshot_2.png)
