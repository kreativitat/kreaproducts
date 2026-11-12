# KreaProducts production acceptance — 2026-11-12

## 4.24.9

Authorized deployment installed pinned source
[`ccf52c68d6ba25e86d1e682da0a0f063f28f8faa`](https://github.com/kreativitat/kreaproducts/commit/ccf52c68d6ba25e86d1e682da0a0f063f28f8faa)
on `fin.degema.pt`. The four-file overlay contains `product_list.php`,
`product_labels.php`, `ChangeLog.md` and the descriptor, installed last. It includes
the completed 4.24.8 label layout and 4.24.9 product-row spacing changes.

All four files matched the expected deployed 4.24.7 baseline before replacement.
Installed SHA-256 hashes match the pinned source; the descriptor reports 4.24.9.
All three PHP files passed staged and installed production PHP 8.1.34 lint.
Atomic replacement preserved ownership and permissions. The existing dirty
production checkout and Git HEAD were preserved. `associatedProducts.php` already
matched the pinned source and was verified without replacement.

Authenticated internal-browser acceptance confirmed 5px vertical cell padding.
The same ten DG01 search results increased from 37.796875px to 41.796875px per
row; text and purchase/sale filters remained present. A DG99 list check also
confirmed the new padding. The DG99 label page used two columns at a 1280px
viewport with 937px available content width. At 390px it used one column, ordered
preview, settings, then documents/uploads. Both technical-sheet search filters
retained their 10px bottom spacing. No console errors were reported on these
pages. Live rendering confirmed the new source without a cache reset or restart.

A protected four-file backup, before/after manifests and guarded rollback script
remain at `/home/degemapt/backups/kreaproducts-4.24.9-20261112T191531Z`, outside the
webroot. Backup SHA-256:
`b2aaf1111a0d6bc638f1fe1f3b8d4d88e3e4245c134dd31a57fd35ef9e5f7f05`.
Rollback refuses concurrent file changes. No migration, module reactivation,
configuration/permission change, form save, label generation or business-record
mutation was performed.

## 4.24.7

Authorized deployment installed pinned source
[`9f51c73c339c35bb86090d2034513b79c2a57049`](https://github.com/kreativitat/kreaproducts/commit/9f51c73c339c35bb86090d2034513b79c2a57049)
on `fin.degema.pt`. The three-file overlay contains `associatedProducts.php`,
`ChangeLog.md` and `core/modules/modKreaProducts.class.php`, installed last.
It includes the 4.24.6 search-filter spacing fix.

All three files matched their expected deployed baselines before replacement,
and installed SHA-256 hashes match the pinned release. Both PHP files passed
staged and installed production PHP 8.1.34 lint. Atomic replacement preserved
file ownership and permissions; the existing dirty checkout and Git HEAD were
preserved.

Authenticated Chrome acceptance in DG99 confirmed 28px Save/Cancel icon buttons
for Type, Weight and Nature. Buttons remained on one row, retained translated
tooltips and accessible labels, and the weight selector did not overlap them.
View mode contained no inline edit forms. Both search filters had the expected
10px bottom spacing. No browser console errors were reported. This confirms
web PHP served the new source without a cache reset or service restart.

A protected three-file backup, manifests and guarded rollback script remain
outside the webroot. Backup SHA-256:
`4c4d46d4768dbf8cd1ce545eddc65a87d844001f354da85a5cdfaa362cd898ae`.
No migration, module reactivation, form save or business-record mutation was
performed. Live phone rendering was not exercised; isolated 390px rendering and
native Save/Cancel/Enter submission checks passed before deployment.

## 4.24.5

Authorized deployment installed pinned source
[`b4772dda183052c2c7d667e12e4fa2cc4ea43ed4`](https://github.com/kreativitat/kreaproducts/commit/b4772dda183052c2c7d667e12e4fa2cc4ea43ed4)
on `fin.degema.pt`. The four-file overlay contains `product_list.php`,
`product_labels.php`, `core/modules/modKreaProducts.class.php` and `ChangeLog.md`.
It includes the completed 4.24.4 label-layout fix. Newer concurrent work was
excluded by packaging Git objects from that pinned commit.

All four live files matched the 4.24.3 baseline before deployment. All four
installed SHA-256 hashes match the release, and the descriptor reports `4.24.5`.
The three PHP files passed staged and installed production PHP 8.1.34 lint.

Authenticated Chrome acceptance of the same product-name search showed ten rows
at 39px, previously 87–134px. The name column grew from 86px to 331px. The native
category/text filters and hidden-product toggle remained present; purchase/sale
filters retained values `-1`/`0`/`1` with translated Yes/No labels. ZSBMS badges
wrapped without increasing the tested rows. The label settings and preview did
not overlap, and visible form inputs stayed within their cells. No browser
console errors were reported on either checked page.

A protected four-file backup, manifests and guarded rollback script remain
outside the webroot. Backup SHA-256:
`fc65716ef6660f6b98cff8765676336ef32f1b25a91e6cb643e7aa03e01aec7e`.
The existing dirty production checkout and its Git HEAD were preserved.
No migration, module reactivation, label generation, form save or business-record
mutation was performed. Live phone rendering was not exercised; the completed
source had passed isolated 390px layout checks before deployment.

## 4.24.3

Authorized deployment on 2026-11-12 installed source
[`14f5943a4fc42d386eb4db77eef87c197c9261ff`](https://github.com/kreativitat/kreaproducts/commit/14f5943a4fc42d386eb4db77eef87c197c9261ff)
on `fin.degema.pt`. All eight installed SHA-256 hashes match that source:
`associatedProducts.php`, `product_list.php`, `admin/setup.php`,
`core/modules/modKreaProducts.class.php`, both `en_US`/`pt_PT` language files,
`README.md` and `ChangeLog.md`. The descriptor reports `4.24.3`.

All six runtime files matched the expected 4.24.0 baseline before replacement.
The older deployed README/changelog were reviewed for drift; neither contained
unique production additions. The existing dirty checkout and its Git HEAD were
preserved. Only these eight release files were replaced, with the descriptor last.

A protected eight-file backup, before/after manifests and guarded rollback script
remain outside the webroot. The backup SHA-256 is
`3078b311b1443006968e6bf1617a9439cbfd0c873571093c8898bd1cfe787a27`.
Rollback refuses concurrent changes before restoring the saved files.

All four PHP files passed staged and installed PHP 8.1.34 lint. Authenticated
Chrome checks in DG99 verified empty and populated component tables, readable
names at 90% font size, fitting quantity inputs and aligned totals. The changed
live HTML/CSS confirmed that web PHP was serving the deployed source.

The product list ignored a legacy suffix parameter, rendered no suffix controls,
and retained category/text filters, pagination and the hidden-product toggle.
The setup page retained the margin setting and no longer exposed suffix settings.
No browser console errors were reported on the checked pages.

Live narrow-screen rendering remains **UNVERIFIED**: Chrome did not apply the
requested viewport override. The earlier isolated 390px rendering check passed.
No migration, module reactivation, configuration/permission change or business
record mutation was performed during deployment or acceptance.
