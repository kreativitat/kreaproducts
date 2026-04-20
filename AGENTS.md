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
