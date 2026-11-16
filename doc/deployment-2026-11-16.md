# KreaProducts production deployment — 2026-11-16

## 4.24.13

Deployed the authorized **Propagar Custo** change from pinned
[release `2573471`](https://github.com/kreativitat/kreaproducts/commit/2573471f317a9a0d089407dc21398519fae081f2),
including [fix `ec3e542`](https://github.com/kreativitat/kreaproducts/commit/ec3e5429162fc9a690618db589e8271dc8afbc4f),
to `fin.degema.pt` (Dolibarr 24.0.2, web/CLI PHP 8.1.34).

The selective overlay contains `class/ProductUpdater.class.php`,
`class/productDismantle.class.php`, `purchasePrice.php`, `VERSION`, `ChangeLog.md`
and the descriptor, installed last. All six before hashes matched 4.24.12;
all installed hashes match the pinned release. Three unchanged cost/trigger
dependencies and file ownership/modes were verified. The production Git HEAD
and unrelated checkout modifications were preserved.

### Verification

- [Release CI](https://github.com/kreativitat/kreaproducts/actions/runs/43856384629)
  passed PHP 7.3/8.2/8.4 lint, all PHP 8.2/8.4 regression runners and the stock-app
  build. The release ZIP passed integrity and changed-file/version byte checks.
- The isolated cost-chain and actual button-controller regressions passed on
  production PHP 8.1.34 without loading ERP credentials or business persistence.
  Staged and installed PHP lint passed.
- Authenticated internal-browser acceptance in DG99 rendered About **4.24.13**
  and the reported product's purchase-price page with **Propagar Custo** enabled;
  browser warning/error logs were empty. The live cost action was not submitted.
- Web OPcache timestamp validation is enabled with a two-second revalidation
  interval. Installation waits three seconds after updating services before
  publishing their caller; rollback restores the old caller before waiting and
  restoring services. No cache reset or service restart was required.
- Configuration, extrafield definitions, rights and user/group-grant fingerprints
  were unchanged; the module remains enabled in all 15 configured entities.
  No schema, permission, scheduler or business-data operation was performed.
- Both PHP error logs gained zero bytes. The initial application-log review
  covered 1,303,750 bytes with no fatal or cost-action errors. A follow-up found
  five lines outside the initial 20 MB baseline: native recipe-quantity warnings
  were confirmed in the prior 100 MB; a core search-filter warning was absent
  from that bounded window. Its purchase-page navigation expression is unchanged
  by this release. Other application warnings matched prior signatures; this
  deployment does not certify the whole ERP log as warning-free.

### Rollback

Protected directory: `/home/degemapt/backups/kreaproducts-4.24.13-20261116T113241Z`.
The six-file `runtime-before.tar.gz` has SHA-256
`1b24b03a573760e50fdb54d27ad5368e17cf9738cd4472ae5c1e8452d9d974a8`.
Archive integrity was verified. Run its `rollback.sh` for guarded source-only
restoration; before/after manifests, metadata, configuration fingerprints and
verification receipts are retained beside it. Isolated installation/rollback
rehearsals used real PHP 8.1 lint and verified preservation of unrelated files
and refusal to overwrite unknown concurrent source changes.

## 4.24.12

Authorized deployment installed pinned
[source `e9364e3`](https://github.com/kreativitat/kreaproducts/commit/e9364e34682b6b277b49735e1bde76e34fc87749)
on `fin.degema.pt` (Dolibarr 24.0.2, PHP 8.1.34). The release includes
[fix `1dec1f8`](https://github.com/kreativitat/kreaproducts/commit/1dec1f8abdef33ac3e20abab4cfa37d4ca75a429):
the unified nutrition/allergen save action now uses the shared manual-edit
policy, accepting legacy empty calculation flags while retaining food status,
product visibility, permissions, POST and CSRF checks.

The four-file overlay contains `associatedProducts.php`, `VERSION`,
`ChangeLog.md` and `core/modules/modKreaProducts.class.php`, installed last.
Before hashes matched the previously deployed source; all four installed
SHA-256 hashes match the pinned release. The unchanged policy, resolver and
native nutrition/allergen class hashes also matched. Atomic replacement
preserved ownership and modes. The existing production Git HEAD and unrelated
checkout modifications were retained.

### Verification

- All 25 new controller regressions passed with isolated doubles on PHP
  7.3, 8.2 and 8.4, and on production's PHP 8.1.34 without loading ERP
  credentials or business persistence. Staged and installed PHP lint passed.
- [Release CI](https://github.com/kreativitat/kreaproducts/actions/runs/43844360660)
  passed all PHP 7.3/8.2/8.4 lint, PHP 8.2/8.4 regression and stock-app build
  jobs. The local release ZIP passed integrity, version and controller-byte
  checks against the established package allowlist.
- Authenticated internal-browser acceptance in DG99 rendered the reported
  product's manual edit form and native About version **4.24.12**. Browser
  warnings/errors were empty. Normal timestamp revalidation loaded the new
  descriptor; no manual OPcache reset or service restart was needed.
- Deployment acceptance submitted no product form. A concurrent user save of
  the reported product invoked the native nutrition and allergen lifecycles;
  read-only readback confirmed saved rows with both legacy calculation flags
  still null. This verifies the formerly rejected case with live data.
- Module configuration, extrafield definitions, user grants and all 15 module
  enablement flags matched their pre-deployment fingerprints. Global permission
  definitions and group-grant fingerprints changed while a separate native
  group-permissions `addrights` action was logged. The deployment made no
  permission changes and preserved that concurrent administration.
- Both PHP error logs gained zero bytes. Review covered 43,910,968 new
  application-log bytes and found no fatal lines. LDAP, password-history trigger,
  menu-expression and hook-loading warnings were present in the prior log
  window. Additional menu/hook and stored-credential decryption signatures were
  also observed globally; some exact signatures were absent from the bounded
  30 MB prior window. These issues remain outside this four-file patch; this
  record does not claim the whole ERP log is warning-free.

No schema migration, module reactivation, REST-discovery refresh, scheduler,
configuration or permission operation was performed by deployment.

### Rollback

Protected directory outside the webroot:
`/home/degemapt/backups/kreaproducts-4.24.12-20261116T095918Z`.

The selective `runtime-before.tar.gz` contains the four original files;
its SHA-256 is
`ee67e1e96e05f35de087c033a2591784828598fbe2b630aa36052b3927237a9a`.
Archive integrity and saved-file hashes were verified. Before/after manifests,
metadata, configuration fingerprints, log findings and the final acceptance
receipt remain beside it.

Run `rollback.sh` from that directory for guarded source restoration. It accepts
known installed/original hashes, resumes partial restoration, and refuses to
overwrite unknown drift. The installer records recovery status and restores
other known files independently if one file cannot be restored. Isolated
rehearsals covered installation, partial rollback, injected failure with concurrent
drift and preservation of unrelated files. Rollback restores source only and
must not undo subsequent business or permission activity.
