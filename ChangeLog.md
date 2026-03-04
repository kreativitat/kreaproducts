<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->
# CHANGELOG MODULE KREAPRODUCTS FOR DOLIBARR ERP CRM

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
