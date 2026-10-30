# Phase 2 implementation report

Scope: the Phase 2 core inventory checklist — product master, classifications, variants, stock movements, warehouses, stock counts and bulk import. No Phase 3 POS work has started.

Status: implemented, tested locally and ready for review on 29 October 2026. No production deployment was performed.

## Delivered

- Tenant-owned products, SKU/barcode variants, apparel/electronics custom attributes, warranty metadata, pricing and stock thresholds.
- Department/category/subcategory hierarchy with parent validation and composite tenant foreign keys.
- Accessible-branch warehouses and warehouse creation.
- Immutable stock documents and movements; derived balance projections; exact decimal quantities and weighted-average valuation; atomic opening/adjustment documents.
- Tenant-unique retry IDs with canonical payload hashes, negative-stock prevention and tenant locking for concurrent operations.
- Physical/cycle count snapshots, complete line validation, stale-snapshot rejection, atomic variance posting and cancellation.
- Atomic CSV catalogue import with row errors, classification creation, duplicate protection, downloadable template and spreadsheet-safe export.
- Ledger reconciliation, movement history paging, stock values and branch filtering.
- Backend module activation and route gates, dependency protection, open-count disable guard, existing-role permission migration and auditing.
- Responsive catalogue, stock, count, setup and import screens; user-friendly custom attribute fields; web and versioned REST routes.
- Additive migrations preserving Phase 1 data, expanded automated tests and updated operational/API documentation.

## Integrity verification

PostgreSQL tests cover costing, rounding, no-negative stock, atomic multi-line rollback, idempotency, tenant and branch isolation, catalogue hierarchy, stock counts, module controls, import rollback, streamed exports and direct ledger mutation protection. Existing Phase 1 tests remain in the suite.

A separate two-process test against an isolated development tenant verified that only one competing withdrawal of the final unit posts. It also verified that simultaneous identical request IDs return the same document and create one stock effect. The ledger reconciled after both scenarios. Run `php scripts/verify-inventory-concurrency.php` only with APP_ENV=local and the isolated bretail_dev PostgreSQL database; the script enforces these restrictions and creates a fresh test tenant rather than touching business records.

| Final check | Result |
| --- | --- |
| PostgreSQL suite | 44 tests, 183 assertions passed |
| SQLite suite | 45 tests, 192 assertions; 44 passed, one PostgreSQL-only trigger check skipped |
| Desktop/mobile browser regressions | All 4 journeys passed |
| Two-process last-unit competition | One withdrawal posted, one rejected; stock remained nonnegative |
| Two-process identical-request retry | Both workers returned the same document; one stock effect |
| Reconciliation after races | Balanced, no issues |
| TypeScript and production build | Passed |
| PHP formatting, Composer validation and route caching | Passed |

Browser journeys cover module activation, classification/product/variant setup, opening stock, damage adjustment, physical count, reconciliation, CSV export/import and the existing foundation/team/cashier workflow. The final regression also fixed the shared mobile navigation overlay hit area. Workflow CI now includes the PostgreSQL suite, TypeScript/build and browser tests; the remote GitHub workflow itself has not been run from this workspace.

Screenshots: [catalogue desktop](screenshots/inventory-catalogue-desktop.png), [catalogue mobile](screenshots/inventory-catalogue-mobile.png), [ledger desktop](screenshots/inventory-ledger-desktop.png), [ledger mobile](screenshots/inventory-ledger-mobile.png).

## Operational boundaries

Inventory remains optional: owners enable it under Modules. No existing company is silently activated and no operational stock is invented. Permissions are added without overwriting existing role grants. Historical records survive module disabling.

Inventory writes currently serialize per tenant for simple, predictable correctness. Future high-volume deployments can replace this with ordered warehouse/variant locking while retaining idempotency and reconciliation. Quantities use NUMERIC(18,3); prices and values use NUMERIC(24,4). Brick Math handles calculations; final withdrawals consume the remaining value to avoid rounding residue.

The UI reads the complete company catalogue, 100 movement lines per page, the latest 30 count records and all drafts. Counts accept up to 2000 selected active variants, and CSV imports up to 1000 rows / 2 MB. Large catalogue pagination and background import processing are future scale improvements. CSV supports Excel-edited files; native XLSX import is not implemented.

The broad inventory module's serial/batch lifecycle, reservations, transfers, returns, photos, label printing and advanced stock ageing remain outside this core delivery. POS, supplier purchasing flows, multi-currency valuation and accounting integration remain in their respective later phases. Do not treat stock documents as financial postings.

## Review gate

Review the catalogue workflow, module activation, branch permissions, stock adjustments, count procedure, CSV format and costing policy. Phase 3 requires separate authorization after Phase 2 review.
