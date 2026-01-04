<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->
Stock Movement Cases (Inventory and Supplier Invoice)
This file provides an exhaustive explanation of the four stock movement cases
handled by KreaProducts triggers.

Scope and shared rules
- Trigger context: STOCK_MOVEMENT for origintype = inventory or invoice_supplier.
- Main tables touched: stock_mouvement, inventory, inventorydet, product_stock,
  product, and product_batch (if productbatch is enabled).
- Movement sums only include non-inventory movements: origintype <> 'inventory'.
- Dates are normalized to DB datetime strings before comparison.
- Inventory anchors use qty_stock when available, with qty_view as fallback.
- Batch handling:
  - If a batch is set, queries filter by that batch.
  - If batch is empty, queries match NULL or empty batch.
  - If productbatch is enabled, product_batch is updated before product_stock.

Case 1 - Inventory inserted before an existing inventory (inventory backdated)
Condition
- A new inventory movement is created with origintype = 'inventory'.
- There is a later recorded inventory for the same product/warehouse/batch.
  (The next inventory movement is after this inventory date.)

Goal
- Only adjust the next inventory movement value so the next inventory remains
  correct relative to the inserted inventory.
- Keep product_stock.reel unchanged overall (neutralize the inserted inventory
  movement stock impact).

Process
1) Load the current inventory line qty_stock (fallback qty_view).
2) Find the next inventory movement (origintype = 'inventory') and read its
   inventory line qty_stock (fallback qty_view).
3) Sum all non-inventory movements between the current inventory date and the
   next inventory date.
4) Compute the expected stock at the current inventory date:
   expected = next_qty - moved_between.
5) Compute the delta: delta = current_qty - expected.
6) Update the next inventory movement value:
   value = value - delta.
7) Undo the stock impact of the inserted inventory movement
   (product_stock.reel, product.stock totals, and product_batch if used).

DB writes
- stock_mouvement: update the next inventory movement value.
- product_stock, product, product_batch: only to undo the inserted inventory
  movement impact so the net reel remains unchanged.

Case 2 - Normal inventory (no later inventory exists)
Condition
- origintype = 'inventory'.
- There is no later recorded inventory for the same product/warehouse/batch.

Goal
- Recalculate stock directly from this inventory snapshot.

Process
1) Use the current inventory line qty_stock (fallback qty_view) as the anchor.
2) Sum all non-inventory movements after date_inventory.
3) Compute new reel:
   reel = anchor_qty + moved_after.
4) Update product_stock.reel, and product.stock totals.
5) If batch is used, update product_batch first, then product_stock.

DB writes
- product_stock, product, product_batch (if enabled).

Case 3 - Supplier invoice inserted before a later inventory
Condition
- origintype = 'invoice_supplier'.
- The invoice movement datetime is aligned to the invoice date/time.
- There is a recorded inventory after the invoice date for this
  product/warehouse/batch (a later inventory exists).

Goal
- Only adjust the next inventory movement value by the invoice delta.
- Do not recalculate current stock; undo the invoice movement stock impact so
  the net reel stays unchanged.

Process
1) Align the movement datem to the invoice date/time
   (use configured time if only a date is provided).
2) Find the next inventory anchor after the invoice datetime.
3) Update the next inventory movement value:
   value = value - invoice_qty.
4) Undo the invoice movement stock impact (reel and totals), and stop.

DB writes
- stock_mouvement: update the next inventory movement value.
- product_stock, product, product_batch: only to undo the invoice movement
  impact (net reel unchanged).

Case 4 - Supplier invoice after the latest inventory (no later inventory)
Condition
- origintype = 'invoice_supplier'.
- The invoice movement datetime is aligned to the invoice date/time.
- There is no recorded inventory after the invoice datetime for this
  product/warehouse/batch.

Goal
- Recalculate current stock using the latest prior inventory snapshot as anchor.
- Do not modify any inventory movement rows.

Process
1) Find the latest recorded inventory anchor with date_inventory <= invoice
   datetime.
2) Sum all non-inventory movements strictly after the anchor date:
   moved = SUM(value) WHERE datem > anchor_date.
3) Compute new reel:
   reel = anchor_qty + moved.
4) Update product_stock.reel and product.stock totals.
5) If batch is used, update product_batch first, then product_stock.

DB writes
- product_stock, product, product_batch (if enabled).
- No changes to inventory movement rows.

Important precedence rule for supplier invoices
- Case 3 takes precedence whenever there is a later inventory after the invoice
  datetime. In that situation, the system adjusts the next inventory movement
  and does not recalculate reel.
