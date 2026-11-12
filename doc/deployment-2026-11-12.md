# KreaProducts 4.24.3 production acceptance

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
