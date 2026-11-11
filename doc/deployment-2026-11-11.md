# KreaProducts 4.24.0 production acceptance

Authorized deployment on 2026-11-11 used runtime source
[`f5c296b3291b8b668d9a37fa740bdb8dfc4b65c5`](https://github.com/kreativitat/kreaproducts/commit/f5c296b3291b8b668d9a37fa740bdb8dfc4b65c5).
Only four runtime files were installed: `class/api_kreaproducts.class.php`,
`class/KreaProductsFoodSetupService.class.php`,
`class/KreaProductsPurchasePriceService.class.php` and
`core/modules/modKreaProducts.class.php`. All installed hashes match that commit.
The existing deployed checkout contains unrelated changes and was preserved.

All required production columns already existed. No schema migration, module
reactivation, ERP permission change or business-record update was performed.
Candidate files passed PHP 8.1 lint. Offline native suites passed 52 food-setup
and 85 supplier-price assertions, including actual API factory wiring with
protected native authorization and denial tests.

The initial GET probe exposed a protected-helper visibility error; the source
files were rolled back immediately. The corrected API-scoped callback preserves
Dolibarr's protected authorization helper. After redeployment, production
food-setup GET and supplier-price GET succeeded. An unchanged supplier-price
dry-run PUT left the saved revision identical. Food and supplier-price MCP
mutations remain outside KreaMCP's initial six-tool curated allowlist.

Protected source backups, expected-hash manifests and a selective rollback script
remain privately outside the webroot. The rollback verifies installed hashes
before restoring the previous API/descriptor and removing only the new service
files; concurrent changes require review. Roll back these four files together.

Applied food/supplier-price writes, native triggers and live concurrency remain
**UNVERIFIED**. Acceptance used reads and previews and created no business records.
