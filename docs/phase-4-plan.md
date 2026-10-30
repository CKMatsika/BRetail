# Phase 4 accounting plan

Implement base-currency double entry, default/custom chart of accounts and mappings, posting periods and locks, journals/reversals, POS and inventory posting, a purchase-posting bridge, cashbook, manual statement reconciliation and financial reports. Full supplier/PO/customer-credit workflows remain Phase 5; FX remains Phase 6.

## Integrity

- Journals are created as drafts and posted atomically with their lines. Posted batches/lines cannot be changed/deleted; PostgreSQL validates balance on posting and rejects later line insertion. Debit/credit values use NUMERIC(24,4) and Brick Math, never floating point.
- Tenant-row serialization covers financial, inventory, POS, access and period changes. Period locks and reconciled bank cutoffs block backdated writes. Source keys/hashes prevent duplicate financial postings. Reversal journals preserve originals; stock/POS sources must use business returns/adjustments rather than free-standing financial reversals.
- One branch per journal keeps branch trial balances balanced. Company reports consolidate authorized branches. Composite keys protect all ownership references; cashiers cannot access the ledger.
- Training and live books are separate. Existing training POS remains immutable and is never replayed into live books. A company containing training POS transactions cannot activate live retail; use a fresh company. Explicit live activation snapshots verified opening inventory without deleting stock/history.
- Live POS requires an active configured accounting module, valid mappings/period and explicit activation. Session mode is pinned at opening. Safes fund cashier floats; closure returns counted cash and posts the difference. Noncash tenders remain manually recorded; no gateway/fiscal integration is introduced.

## Accounts and integrations

Default asset/liability/equity/income/expense accounts cover safe, till, bank, card/mobile settlement, inventory, AR/AP, taxes, capital/opening equity, sales/returns, COGS, inventory changes, cash differences, expenses and rounding. Codes/names/reporting groups are editable. Types/cash classification cannot change after use. Mapping changes are allowed before posting; inventory/till control accounts cannot be manually distorted.

All new stock documents receive balanced inventory/equity, inventory gain/loss or purchase entries when accounting is configured. POS posts settlement/revenue/tax and actual ledger COGS; returns reverse applicable amounts using original values. Purchase posting recognizes exact inventory value, input tax, payables or settlement and explicit rounding differences. It supplies a foundation for Phase 5 instead of implementing a purchase-order workflow early.

## Reconciliation and reports

Cashbook receipts/payments/transfers create journals, not arbitrary balances. Bank statements import signed decimal CSV lines, match whole ledger entries (including grouped matches), preserve outstanding items and finalize only when adjusted statement and ledger agree. Initial reconciliation uses an explicitly confirmed ledger opening baseline; subsequent statements must continue the closed statement chain. Closed reconciliations freeze matched history and block new bank postings through their end date.

Reports: trial balance, general ledger, profit/loss, balance sheet including unclosed earnings, direct cash flow by batch classification, bank/cash balances and inventory-to-ledger reconciliation. All reports filter by book/date/authorized branch; printable views and safe CSV exports accompany responsive screens.

## Validation and release

Test balance/immutability, duplicate posting, reversals, periods, tenant/branch restrictions, stock/POS atomicity and carrying costs, live/training separation, floats/variance, purchases/rounding, cashbook, bank matching/outstanding/cutoffs, financial equations and concurrent posting. Run existing foundation/inventory/POS regressions, desktop/mobile journeys and production build. Keep live activation behind company configuration; no production deployment or external account connection is performed by this phase.
