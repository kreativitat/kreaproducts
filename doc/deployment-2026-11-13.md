# KreaProducts production acceptance — 2026-11-13

## 4.24.10

Authorized deployment installed pinned source
[`4fb41d910fe84b85077783282aa67aa92ae18c5c`](https://github.com/kreativitat/kreaproducts/commit/4fb41d910fe84b85077783282aa67aa92ae18c5c)
on `fin.degema.pt`. The three-file overlay contains `associatedProducts.php`,
`ChangeLog.md` and `core/modules/modKreaProducts.class.php`, installed last.

All three live files matched the expected 4.24.9 baseline before replacement.
Installed SHA-256 hashes match the pinned source. Both PHP files passed staged
and installed production PHP 8.1.34 lint. Atomic replacement preserved ownership
and permissions; the existing dirty production checkout and Git HEAD were
preserved. The descriptor reports 4.24.10.

Authenticated internal-browser acceptance in DG99 verified product 3612 and
BOM 385. The MRP table now uses automatic layout and gives names 377px at the
1280px viewport, previously 39px. Name text matches the kit table at 13.536px;
both example names fit on one line. The two component rows, displayed values,
lot states and totals remain unchanged, with all nine footer columns aligned.

At 390px, the 750px MRP table scrolls within its 371px native responsive wrapper.
Horizontal scrolling reached the last column and retained readable amounts and
aligned totals. No browser console errors were reported. A normal reload served
the new HTML/CSS without an opcode-cache reset or service restart. The design
reference remains the kit component table and Dolibarr's installed table guidance.

A protected three-file backup, before/after manifests and drift-guarded rollback
script remain outside the webroot at
`/home/degemapt/backups/kreaproducts-4.24.10-20261113T150213Z`.
Backup SHA-256:
`5dee8be9e76ba0c0fd1a622c5bfa675f03c992b26184d5859ef23fe066b89b1a`.
Run `rollback.sh` from that directory to restore the saved files only when all
three installed hashes still match this release. No migration, module
reactivation, configuration/permission change, form save or business-record
mutation was performed.
