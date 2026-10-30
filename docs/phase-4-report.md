# Phase 4 implementation report

Delivered on 29 October 2026: base-currency accounting with chart/mappings, immutable balanced journals, reversals, accounting periods/locks, automatic stock and POS posting, supplier-invoice posting, cashbook, bank reconciliation and financial reports.

Live retail requires explicit company activation after opening-stock verification. Existing training sales are preserved and cannot be converted to live transactions. POS sale/payment/stock/journal effects commit atomically; returns restore original carrying cost. Opening floats use recorded safe funds and closing records actual cash differences. Payments remain manually recorded; no payment-provider or fiscal integration is claimed.

PostgreSQL enforces balanced posting, immutable journal lines/batches, fixed posted account classifications, period protection, bank cutoffs and finalized reconciliation protection. Company write serialization and source hashes prevent duplicate financial effects. Reports respect tenant/branch permissions and take a consistent posting snapshot. CSV exports escape formula text.

## Validation evidence

- Full PostgreSQL suite: 87 tests, 380 assertions passed.
- SQLite suite: 90 tests, 385 assertions; 85 passed and five PostgreSQL-specific checks skipped.
- Accounting desktop/mobile journeys passed: setup, live activation, funding, live POS receipt, financial/stock reconciliation and bank statement matching/finalization.
- Independent concurrent live POS workers: one last-item sale committed, competing sale rejected; identical retries returned one sale and one financial effect. Stock and balance-sheet differences were zero.
- TypeScript check, production asset build and route-cache compilation passed.
- Full desktop/mobile browser regression: all eight foundation, inventory, POS and accounting journeys passed in 5.9 minutes.

Status: Phase 4 implemented and locally validated; ready for review.

Migrations were applied additively to the isolated local development database. Test migrations use the disposable bretail_test database. No production deployment was performed. The build reports a bundle-size advisory; external payment services, hardware printers and non-Chromium devices were not certified.

See [operating guide](accounting-guide.md), [API](accounting-api.md), [desktop reports](screenshots/accounting-reports-desktop.png), [mobile reports](screenshots/accounting-reports-mobile.png) and [bank reconciliation](screenshots/accounting-bank-desktop.png). Phase 5 design is in [phase-5-plan.md](phase-5-plan.md); implementation and validation are recorded in [phase-5-report.md](phase-5-report.md).
