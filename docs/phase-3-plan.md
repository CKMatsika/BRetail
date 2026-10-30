# Phase 3 — retail POS

Scope: touchscreen catalogue/search/barcodes, server-priced carts, controlled discounts/tax, split recorded payments, cash change, customer selection/creation, held carts, cashier shifts, stock deduction, partial returns/refunds, receipt printing/reprinting, daily shift reconciliation, web/API permissions and idempotency tests.

## Accounting gate

The accepted roadmap requires live finalization to remain gated until Phase 4 accounting is implemented and validated. Phase 3 therefore implements **training-mode transactions only**, explicitly enabled in local/testing environments. Production checkout is locked even if a training environment variable is accidentally set. Training receipts are not fiscal/tax invoices. No gateway is contacted, no statutory rate is supplied, and no financial posting is claimed. Training sales alter the local company's inventory ledger, so use demonstration companies and never import live operational data for training.

POS depends on active inventory for training; accounting and currencies remain explicit live dependencies. The future live adapter must commit payment, journal and inventory effects atomically before opening production checkout. No Phase 4 general ledger or Phase 6 exchange calculation is introduced here.

## Data and integrity

Tenant-owned sessions, customers, held carts, sales/items/payments, returns/items/refund payments. Composite tenant keys protect references. Sale and return records are immutable; source prices, tax rate, receipt identity, currency and carrying costs are snapshotted. Cost fields are not exposed to cashiers.

Tenant-row locking serializes checkout, return, shift closure and inventory writes. Request UUIDs and canonical hashes prevent duplicate sales, return effects and shift opens. Requests are priced and validated on the server; payment overages are cash change only. Noncash methods record external payments manually and require references. Credit sales and offline checkout are unavailable.

Stock leaves at ledger weighted-average value. Returns cannot exceed the original quantities or recorded payment-method balances. Refund net/tax/cost allocations use cumulative decimal rounding so full returns match original totals exactly. Returned stock restores original carrying value, including residual rounding, even when the current average differs. Counts become stale after POS stock effects.

Shifts are cashier/warehouse scoped. Expected cash = opening float + applied cash sales − cash refunds. Cash change is excluded from applied receipts. Closed sessions cannot accept sales/refunds. Held carts reserve neither stock nor price; checkout reprices current catalogue data. Cashier actions are limited to own sessions/holds/sales; manager visibility is branch scoped and refunds require explicit permission.

## Delivery checks

Backend regression suite plus tests for tax/discount arithmetic, split payments/change, cart repricing, retry conflicts, last-item competition, tenant/branch isolation, disabled modules, shift closure, partial returns, rounding/cost restoration, receipt permissions, held carts and production checkout lock. Desktop/mobile browser journeys cover the complete training checkout and return workflow. Phase 3 report and user/API guides accompany delivery; Phase 4 requires review and separate authorization.
