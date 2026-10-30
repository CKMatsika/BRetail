# Phase 3 implementation report

Scope: core retail POS — cashier interface, sales, recorded payments, receipts, shifts, stock deduction and sales returns. **Training delivery only**: live checkout remains gated by the accepted Phase 4 accounting review requirement. No Phase 4 general ledger has been implemented.

Status: implemented and locally validated; ready for review on 29 October 2026.

## Delivered

- Touch-friendly register with catalogue/department cards, SKU and barcode search, quantities, discounts, customers and server-priced totals.
- Exact decimal tax/discount math, price/tax quote tokens and stale-price rejection. Company-configured tax only; no invented statutory rates.
- Cash, card, mobile-money and bank-transfer recording, split tenders, external references, exact change and payment validation. No provider processing.
- Open/close cashier shifts, currency/warehouse ownership, expected cash, counted cash and preserved variance. Managers can reconcile inaccessible employees' shifts with a separate closing-actor record.
- Immutable sales, item/payment snapshots, tenant-unique receipt sequences and request hashes; atomic sale/payment/stock commits and safe retries.
- Held carts with no stock/price reservation, one-time consumption and manager cancellation of abandoned carts.
- Customer quick creation/selection, customer name snapshots, printable sale/return receipts and authenticated receipt-number lookup/reprinting.
- Partial returns with original quantity/method limits, original carrying-value restoration, cumulative rounding, zero-value fractional returns and idempotent refunds.
- Daily training summaries by selected date/timezone and role/branch visibility; shift payment-method reconciliation.
- Backend role/module controls, cashiers' cost privacy, fresh authorization under the tenant write lock, production checkout lock and PostgreSQL immutability/source constraints.
- Guards against disabling POS with open shifts/held carts or deactivating branches with outstanding operations. POS remains an inventory dependency; checkout/returns invalidate old inventory-count snapshots.
- Additive migrations and existing-role grants preserving prior data, versioned REST/session routes, browser coverage and operating/API guides.

## Accounting boundary

The original roadmap explicitly gates live POS finalization until accounting is validated in Phase 4. `PosAccountingGate` permits only explicitly enabled local/testing training. Production stays locked even when the training flag is true, and database checks allow only training sale/return records. Receipt labels disclose the absence of financial posting. Current stock/payment/tax/cost snapshots prepare the interface for later journal integration, but no balanced journal is claimed and training records must never be replayed as live transactions.

The module declares Inventory as its training dependency and Accounting/Currencies as future live dependencies. Phase 4 must add and validate the atomic financial adapter and explicitly migrate the live-mode database constraints before live checkout can open. Multi-currency tendering remains Phase 6.

## Validation

Backend checks include cash/split settlement, change, under/overpayment, missing references, exact tax/discounts, stale quotes, safe retries, last-item rejection, return limits, original-cost restoration after average-cost changes, fractional rounding, no-money returns, inactive-item returns, shift closure, manager reconciliation, branch/tenant isolation, hidden costs, module gates and the production lock. Existing foundation/inventory tests are retained.

Two independent PHP workers were also run against isolated new development tenants. Competing last-item sales produced one committed sale/payment and one rejection. Identical simultaneous checkout requests returned the same sale with one stock/payment effect. POS and the original inventory race tests both reconciled. The scripts refuse non-local environments or databases other than the dedicated bretail_dev database.

| Final check | Result |
| --- | --- |
| PostgreSQL suite | 74 tests, 311 assertions passed |
| SQLite suite | 77 tests, 321 assertions; 74 passed, three PostgreSQL-only checks skipped |
| Full desktop/mobile browser regression | All six foundation, inventory and POS journeys passed |
| Two-process POS last-item race | One committed sale/payment, one rejected sale |
| Two-process identical checkout retry | Same sale ID, one payment/stock effect |
| Original inventory concurrency regression | Passed |
| Reconciliation after concurrency tests | Balanced; no differences |
| TypeScript and production build | Passed |
| PHP formatting, Composer validation, route caching | Passed |

The final browser journeys exercise customer creation, barcode scan, cart hold/resume, quantity change, split cash/card recording, cash change, receipt print/reprint, partial return, daily summary and drawer closure on desktop and a 390 px mobile viewport. Tests also exposed and resolved a JSON response-negotiation mismatch in the shared inventory web controller. Managers' inactive-cashier reconciliation and outstanding-branch-operation guards have backend regression coverage.

The CI workflow includes PostgreSQL, build and browser checks and explicitly enables training only against its isolated database. The remote GitHub workflow has not been dispatched from this workspace. Physical printer/scanner hardware, Safari/iPhone devices and external providers have not been certified by these Chromium-based browser tests.

Screenshots: [register desktop](screenshots/pos-register-desktop.png), [register mobile](screenshots/pos-register-mobile.png), [payment](screenshots/pos-payment-desktop.png), [training receipt](screenshots/pos-receipt-desktop.png), [shift reconciliation](screenshots/pos-shift-desktop.png).

## Boundaries

No actual payment/refund gateway, fiscalization, accounting journals, credit sales, exchange calculations, offline transactions, public/email receipt delivery, product photography, serial selection, native drawer command or ESC/POS integration is enabled. Product cards use visual initials/icons and existing active variants. Printing uses the browser/device print driver. Scope limits are shown in the UI/guides rather than hidden behind inactive buttons.

Quantities use three decimals, catalogue prices/carrying values four and payment/tax totals two. Tax is added to the catalogue price. Multiple currencies with differing precision need Phase 6 policy. Tenant-wide write serialization prioritizes correctness for the current SME deployment. The register loads product cards in groups of 48; data endpoints use the full active catalogue. Receipt lists show up to 50 matches with number search; shift lists show recent history and all open shifts.

## Handover and review

See [POS guide](pos-guide.md), [API](pos-api.md) and [architecture plan](phase-3-plan.md). Enable Inventory, then Point of sale, in a local demonstration company. Review cashier permissions, discounts/tax configuration, shift reconciliation, receipt layout, return policy and the accounting boundary before authorizing Phase 4. No production deployment or external account connection was performed.
