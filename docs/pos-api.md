# POS API v1

Prefix `/api/v1/pos`. Authenticate with Sanctum, request JSON, and enable both POS and Inventory. Web counterparts under `/pos` use session authentication and CSRF and also negotiate JSON for the cashier interface. Tenant ownership comes only from the authenticated user. Prices, quantities and amounts are decimal **strings**.

All financial/stock checkout actions are **training only** and require explicitly enabled `local`/`testing` operation. Production and disabled training return HTTP 423 before creating any sale. Read routes remain permission controlled. No live accounting, payment or fiscal integration is implied.

| Method | Route | Permission |
| --- | --- | --- |
| GET | / | pos.view |
| POST | /quote | pos.sell |
| POST | /sales | pos.sell plus own open shift |
| GET | /sales/{id} | pos.view; own sale or authorized manager |
| POST | /sales/{id}/return-quote | pos.refund and sale access |
| POST | /sales/{id}/returns | pos.refund plus own original-warehouse shift |
| POST | /shifts | pos.shift |
| POST | /shifts/{id}/close | pos.shift; own or authorized manager |
| POST | /holds | pos.sell |
| POST | /holds/{id}/cancel | pos.sell; own or authorized manager |
| POST | /customers | pos.customer |

The data endpoint supplies safe catalogue fields, authorized warehouse quantities, customers, held carts, sessions, own open session, sales and a daily summary. Cost values are excluded. Optional query parameters: `date=YYYY-MM-DD` for the daily summary and `receipt=TEXT` for scoped receipt search. It returns up to 50 matching sales and 30 recent shifts plus every open shift. Cashiers cannot inspect other cashiers' sales/holds; managers are restricted to authorized branches.

JSON responses use `{data: ..., message?: ...}`. Creation uses 201, including idempotent replay of an already-created sale. Errors: 401 unauthenticated; 403 role/module restriction; 404 missing/foreign/inaccessible resource; 422 validation or request-ID conflict; 423 accounting/training lock; 429 API limit. The foundation's API rate limit remains 60/minute. Use web session endpoints for rapid scanner-driven quote updates; keep native clients' requests within the API limit.

## Quote and checkout

Quote:

```json
{
  "warehouse_id": 1,
  "customer_id": null,
  "lines": [{"variant_id": 1, "quantity": "2.000", "discount_percent": "0.00"}]
}
```

The server returns immutable-price candidates with item subtotals, discounts, tax, totals, currency and `quote_token`. Quotes do not reserve stock. They use current active catalogue data, tax settings, stock availability and discount permission. Up to 200 unique variants are accepted per sale; quantities support three decimals and discount percentages two.

Checkout adds:

```json
{
  "request_id": "38a1dd09-b9e7-4e5e-9aef-24b0f48564ab",
  "session_id": 1,
  "warehouse_id": 1,
  "customer_id": null,
  "hold_id": null,
  "quote_token": "SERVER_QUOTE_TOKEN",
  "lines": [{"variant_id": 1, "quantity": "2.000", "discount_percent": "0.00"}],
  "payments": [
    {"method": "cash", "amount": "30.00"},
    {"method": "card", "amount": "20.00", "reference": "EXTERNAL-REFERENCE"}
  ]
}
```

`amount` is tendered cash or the recorded noncash amount. The server computes change and stores applied payment separately from tendered payment. Methods are cash, card, mobile_money and bank_transfer, once each. Noncash requires a reference. Cashier prices/costs/total overrides are ignored; stale quotes are rejected. A positive overall sale total is required. Credit is not accepted.

Keep request IDs stable on retry. Canonical cart/payment hashes reject changed payloads under the same ID. A successful sale retry returns its original sale even after its shift closes; payment and stock are not repeated. Request IDs must not collide with earlier stock documents in the same tenant. Only server services can post the internal sale/customer-return stock types; public inventory endpoints reject these types and carrying-value overrides.

## Mixed-currency drawers

Multi-currency adds these session-authenticated endpoints (also available under `/api/v1/pos`):

| Method | Route | Payload |
| --- | --- | --- |
| POST | `/currency-drawers` | `request_id`, `session_id`, `safe_account_id`, `amount` |
| POST | `/currency-drawers/{id}/count` | `counted_amount` |

Funding moves recorded foreign cash from a same-branch safe into the cashier's own open shift. The safe must have enough original-currency balance. A shift can have one open drawer per currency. Count and close returns the counted amount to the linked safe and records the variance using the drawer's carrying value; an open foreign drawer blocks shift close.

Checkout may include `foreign_tenders: [{drawer_id, amount}]` alongside the usual base-currency `payments` array, plus `change_drawer_id` when physical change is returned in a foreign currency. Each tender stores original tender, change, net amount, rate snapshot and base equivalent. Foreign change is returned from the currency tendered and cannot exceed its remaining physical amount. Base-currency change follows the normal cash tender rules. Retries must use the same request ID and payload.

A return may include `foreign_refunds: [{source_drawer_id, drawer_id, amount}]` alongside its base-currency `payments`. The source identifies the original sale tender; the destination must be an open drawer of that currency in the cashier's shift. Refunds use the original sale's rate and original paid amount, while the destination drawer's current carrying value and any exchange difference are recorded separately. Physical drawer balances and base-currency shift cash are reconciled independently.
## Shifts and holds

Open: `{request_id, warehouse_id, opening_cash:"50.00"}`. Close: `{request_id, counted_cash:"70.00", note?:"..."}`. Both support idempotent replay; closed shifts cannot be modified. Another employee's shift may be closed only by a manager with shift permission and warehouse access. `closed_by` preserves the closing actor separately from the cashier.

Hold: the quote cart fields plus `{request_id, label}`. Held carts do not reserve stock or prices. Checkout may pass the own held cart ID to consume it once. Cancel requires ownership or manager permission in the warehouse. New customers accept `{name,email?,phone?}`; they have no credit or receivables ledger in this phase.

## Returns

Request a return quote using `{lines:[{sale_item_id:ID,quantity:"1.000"}]}`. It returns exact net/tax/total allocations and a new quote token. Costs are not exposed.

Post the return with the same lines plus `{request_id,session_id,reason,quote_token,payments:[...]}`. Payment methods/amounts follow checkout validation, except they must equal the refund total exactly and cannot exceed the remaining original method balances. Cash refunds must fit the current drawer. A zero-value return uses an empty payments array. Every return quantity must be positive and within the unreturned original quantity.

Cumulative allocation preserves original net/tax/cost totals across partial returns. Stock comes back at original carrying value, not today's selling price or average cost. Return request IDs prevent duplicate stock/refund effects. A competing return invalidates the prior quote. No accounting journal or external refund is created.

## Receipts

Authenticated web URLs: `/pos/sales/{id}/receipt` and `/pos/returns/{id}/receipt`. These render printable training HTML. They are not public share links or tax invoices. Sale data includes returnable quantities, remaining payment capacities and related training return receipts.


## Phase 4 accounting update

The Phase 3 training-only boundary above describes the earlier release. Phase 4 adds base-currency accounting, journals, periods, cashbook, supplier invoice posting, bank reconciliation and financial reports. Live POS requires explicit company activation after verified setup; existing training sales are never converted. Read [accounting-guide.md](accounting-guide.md) and [accounting-api.md](accounting-api.md). Payment-provider and fiscal integrations remain outside this release.
