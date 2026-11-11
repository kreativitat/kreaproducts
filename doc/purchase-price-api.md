# Owned supplier purchase-price API

These routes complement Dolibarr 24's native supplier-price lifecycle while
preserving fields omitted by its collection API:

- `GET /kreaproductsapi/production/products/{product_id}/purchase-prices/{price_id}`
- `PUT` to that same route for one existing identity.
- `POST /kreaproductsapi/production/products/{product_id}/purchase-prices` for a
  new identity.

All writes require explicit integer `entity_id`, `confirm: true`; `dry_run`
defaults to `true`. Only HT prices are supported. Product, supplier and price
must belong directly to the selected company. Native product read/create rights,
resource access and external-user supplier restrictions remain enforced.

GET returns a version-1 saved state: `price_id`, `product_id`, `entity`,
`fourn_id`, `ref_fourn`, `qty`, `buyprice`, `tva_tx`, `availability`,
`price_base_type`, `unitprice`, `preserved`, `revision`. Numbers are decimal
strings. `preserved` includes discounts, charges, default VAT code, local taxes,
NPR, delivery time, reputation, description, barcode, packaging, dynamic-price
identity, multicurrency fields, import key and status. Volatile timestamps/user
metadata are excluded from the revision.

PUT accepts patches to `buyprice`, `availability` and `tva_tx`. Optional
`fourn_id`, `ref_fourn`, `qty` must match the saved identity; this endpoint never
renames or reassigns a supplier-price row. Apply requires `dry_run: false` and
`expected_revision` from the reviewed GET/preview's `before.revision`. An
already identical patch is `unchanged`; other stale writes fail with 409.

POST requires `fourn_id`, `ref_fourn`, `qty`, `buyprice`, `availability` and
`tva_tx`. Minimum quantity must be positive. Exact duplicate identities with
the same values are unchanged; differing existing values require PUT. The
supplier-reference namespace follows native `productsupplierprice` sharing
rules and rejects a reference already attached to a different product. Product,
supplier and collision locks precede the transaction's first plain DAO read.
The database's native unique `(ref_fourn, fk_soc, quantity, entity)` constraint
remains the final concurrent-creation guard.

Successful POST/PUT returns `status` (`dry_run`, `unchanged`, `confirmed`),
`dry_run`, `before` and `after`. A create preview has `before: null` and
`after.price_id: null`; apply returns the actual saved ID. Preview performs no
native write or stub creation. Inputs must be representable exactly by the
configured native `MS` quantity and `MU` price precision; unrepresentable values
fail before a write instead of silently changing an identity or price.

Apply reuses native `add_fournisseur` to create a scoped stub, verifies its
parent/supplier identity, then calls `update_buyprice` with its verified bound
ID. Existing updates load that ID directly and pass every saved ancillary
argument, including packaging, to the native method. The unbound legacy
DELETE/upsert path is never entered. Native price history and trigger behavior
are retained. Full readback occurs before committing the outer transaction;
unexpected changes roll back price and native history together.

The endpoint deliberately refuses creation or changed-row updates while
multicurrency is enabled, historical noncanonical currency metadata, and price
changes controlled by a native dynamic expression. These need a dedicated
contract. Known ancillary representations that the native helper would change
(for example a NULL description, empty barcode or noncanonical packaging)
return 409 during preview, before triggers or history writes. An unchanged
no-op remains available. Unexpected ancillary drift during apply still rolls
back. No saved field is silently discarded to make an update succeed. The
supported VAT storage range is 0–999.9999 with at most four fractional places;
unrepresentable MS/MU precision is rejected before native persistence.

Deploy the API and `KreaProductsPurchasePriceService.class.php` together before
enabling dependent MCP mutations; refresh REST discovery and PHP opcode cache
through the existing deployment route. No schema migration or permissions
change is included. Roll back the changed source files together. Source push
does not deploy the module or verify live native triggers/concurrency.

Run `php tests/run_purchase_price_api_tests.php` and changed-file PHP lint.
The tests are offline native-lifecycle/database doubles, covering ownership,
namespace collisions, precision, preserved fields, locking order, history,
readback and rollback. Live Dolibarr 24/database integration remains deployment
acceptance; no production price is changed by these tests.
