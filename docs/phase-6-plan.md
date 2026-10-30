# Phase 6 — Multi-currency and multi-branch plan

Prepared 30 October 2026 after Phase 5 implementation. Phase 6 is complete.

## Currency foundation

Maintain configurable currencies, minor-unit precision, effective-dated exchange rates, rate sources and authorized approval. Use decimal arithmetic throughout. Define rates consistently as base-currency units per one transaction-currency unit. Never overwrite rates on posted transactions. Preserve original currency, amount, rate, base amount and rounding difference for each posting. Existing base-currency entries retain rate 1 and their historical totals.

## Currency accounting

Add currency-specific bank/mobile/safe accounts and drawers. Record original balances alongside balanced base-currency journals. Add explicit realized gain/loss and rounding mappings. Foreign invoices retain their original-currency open amounts and historical carrying value; settlement differences post realized exchange gains/losses. Define and implement an approved period-end revaluation policy with immutable runs, reversals and no repeated revaluation of the same exposure.

Extend credit quotations, invoices, supplier bills, settlements and POS payments in stages. Mixed-currency tenders must preserve each tender's rate and original amount; change and shift reconciliation must identify the physical currency. Rate permissions and cash-drawer permissions are distinct. Historical returns use original transaction amounts and stock carrying cost. Existing training/live separation and reconciliation guards remain mandatory.

## Branch transfers

Introduce draft, approved, dispatched/in-transit, partially received, completed and cancelled transfer states. Validate source and destination access independently. Dispatch moves original carrying value from source stock to in-transit inventory; receiving releases that value into destination stock. Partial receipts, lost/damaged goods and discrepancies require explicit authorized resolution. Cancel only undispatched quantities. Serialize competing operations and make dispatch/receipt retries idempotent. Do not create company-wide inventory value or revenue from internal transfers.

## Reports and UI

Provide branch stock and in-transit views, branch financial statements, original/base currency balances and consolidated company reports. Internal transfers eliminate at company level. Reports respect branch visibility and show the rates and valuation dates used. Add responsive currency/rate administration, transfer dispatch/receipt screens, printable documents, API contracts and operating guides.

## Delivery order and acceptance

1. Currency schema, rate permissions, decimal conversion rules and historical backfill.
2. Foreign accounts, journals, settlements, realized differences and revaluation.
3. Foreign trade and POS tender/drawer workflows.
4. Branch dispatch/in-transit/receipt workflow and discrepancy handling.
5. Branch/consolidated reports and responsive screens.
6. Regression, independent concurrent workers, migration verification and operating documentation.

Required checks include rate-date boundaries, missing rates, currency precision, rounding, partial foreign settlements, realized/unrealized differences, reversals, original-rate returns, multi-currency drawer reconciliation, partial transfer receipt, last-unit dispatch races, duplicate retries, transit valuation, tenant/source/destination permissions, period/bank locks and branch/consolidated reconciliations. Run desktop/mobile workflows and the complete regression suite before declaring completion.

Status: currency/rate schema, approvals, foreign ledger and revaluation are implemented. Customer document currency snapshots, original-value returns and partial settlements now work; mixed-currency POS drawers, change, refunds and counts are implemented. Branch transfer posting and responsive administration are also implemented. Wider supplier-document currency, transfer browser/print/concurrency, reporting and final documentation work remains. See [phase-6-status.md](phase-6-status.md).

## Transfer implementation checkpoint

The internal transfer service and workspace support draft/approval/cancellation before dispatch, full dispatch, partial receipts, exact original carrying value, paired branch clearing journals and explicit loss recording. Transfer routes, permission provisioning, immutability guards, branch/module/live-cutover protection, authenticated printing, desktop/mobile browser coverage, concurrent dispatch verification and branch authorization checks are implemented.

## Current implementation status

See [phase-6-status.md](phase-6-status.md) for delivered functionality and validation evidence. Phase 6 is complete.
