# KreaProducts production deployment — 2026-11-15

## 4.24.11

Installed pinned [source `daf73b0`](https://github.com/kreativitat/kreaproducts/commit/daf73b03922c16cb2b06dc47d12884212d694bf4) on `fin.degema.pt` (Dolibarr 24.0.2; web and CLI PHP 8.1.34).
The price synchronization commits retain their history through merges `bf7979c`
and `5d101b6`; release bookkeeping is `0351354`. Root `VERSION` now matches the
module descriptor, changelog and release package. README product images use
current interface excerpts with illustrative demo data and reproducible frames.

## Installed source

- `ChangeLog.md`
- `ajax/toggle_hideproduct.php`
- `class/KreaProductsSupplierPriceSyncService.class.php`
- `class/ProductUpdater.class.php`
- `core/triggers/interface_99_modKreaProducts_KreaProductsTriggers.class.php`
- `langs/en_US/kreaproducts.lang`
- `langs/pt_PT/kreaproducts.lang`
- `README.md`
- `VERSION`
- `img/screenshot_1.png`
- `img/screenshot_2.png`
- `doc/showcase/index.html`
- `doc/showcase/README.md`
- `doc/showcase/capture-workspace.jpg`
- `doc/showcase/capture-labels.jpg`
- `core/modules/modKreaProducts.class.php`

All 16 installed SHA-256 hashes match the pinned source. The descriptor was
installed last. The existing production Git checkout, AGENTS.md and unrelated
build-allowlist edits were preserved. Existing file ownership/modes were retained.
No schema migration, module reactivation, setting, permission or extrafield change.

## Rollback

Protected release directory: `/home/degemapt/backups/kreaproducts-4.24.11-20261115T225254Z-retry1`.

- Full module source: `module-source-before.tar.gz`, SHA-256
  `d0649cc230e13cc9d89253fedc162fc9ec685822dd3bd83a070c7b4fdec3c2fd`. Excludes `.git` and `node_modules`.
- Selective original source: `runtime-before.tar.gz`, SHA-256
  `d378daee2cd19320d9605227ec8b84256ff0779ae85232775b68c15a251de892`.
- Guarded restore: `rollback.sh`. Verifies installed hashes before restoring
  existing files and removing only the VERSION/showcase paths added by this release.
- Before/after manifests, configuration fingerprints and log offsets are retained
  privately beside the archives. Gzip/archive checks and a 16-path isolated
  installation/rollback rehearsal passed; unrelated source was preserved.

The first guarded attempt was rolled back after an intentionally broad log gate
matched missing-hook warnings. Both `removeconf` and `dropdownmenu` warning types
were reproduced with the restored 4.24.10 baseline. A fresh guarded attempt then
compared post-deployment entries with those exact baseline signatures. It found
56 pre-existing warning lines and no additional warning/error signatures. No
unrelated module or hook configuration was changed.

## Verification

- All required supplier-price, transactional movement/propagation, default and
  large price-chain, purchase-price API and food-setup API commands passed with
  isolated doubles. All 14 PHP runners passed on PHP 8.2/8.4. Production staged
  and installed PHP lint passed for all five runtime PHP files.
- [Source CI](https://github.com/kreativitat/kreaproducts/actions/runs/43773397460)
  passed PHP 7.3/8.2/8.4 lint, PHP 8.2/8.4 isolated runners and Node 20 build.
- The release ZIP contains the matching VERSION, README and screenshot bytes.
- Authenticated native About rendered **4.24.11**, proving web opcode timestamp
  revalidation loaded the new descriptor. Web OPcache is up, with timestamp
  validation enabled and a two-second revalidation interval. No manual OPcache
  reset or PHP/service restart was performed; this follows the established
  KreaProducts/KreaBank timestamp-revalidation route.
- Authenticated GETs of native API status and `explorer/swagger.json` succeeded
  in explicit entity 1. Discovery includes 94 KreaProducts paths, including the
  food-setup and supplier-price contracts. Existing owner credentials were used
  only in server memory with native decryption; no credentials were changed.
- KreaProducts remains enabled in all 15 configured entities. Configuration,
  extrafield definitions, rights definitions and user/group grant fingerprints
  match the read-only pre-deployment snapshot.
- Log inode/offset checks covered 7,958,093 new application-log bytes. Both PHP
  logs gained zero bytes; no new fatal errors were found. The two existing
  missing-hook warning categories remain. Entity 9's discovery-cache directory
  was not writable during preflight; its permissions/settings were left unchanged.

No invoice, product, supplier-price, payment or stock record was created, edited
or validated for acceptance. Applied price synchronization and real invoice/stock
workflows remain unverified; the owner will perform the functional check.
