# Trade API

Session routes use `/trade`; authenticated API routes use `/api/v1/trade`. Existing authentication, tenant, branch, module and role checks apply. JSON success responses contain `data`; validation errors use HTTP 422 with `errors`. Read the operating guide before posting financial documents.

`GET /data` returns available warehouses, parties, orders, documents, active variants, settlement accounts, aging and reconciliation for the operating book. Results depend on enabled modules and viewing permissions. Order/document lists return the latest 200 entries.

`GET /print/order/{id}` and `GET /print/document/{id}` return authenticated printable documents with currency and rate snapshots when present.

POST action paths:

| Path | Purpose | Main fields |
| --- | --- | --- |
| `/suppliers/{id?}` | Create/update supplier | code, name, terms_days, active, optional contacts |
| `/orders` | Draft purchase order | request_id, supplier_id, warehouse_id, date, optional currency, items with variant_id, quantity, unit_cost |
| `/approve/{order}` | Approve draft | no body |
| `/cancel/{order}` | Cancel unfinished remainder | no body |
| `/receipts/{order}` | Receive goods | request_id, date, reference, items with variant_id and quantity; inherits order currency and stock carrying value |
| `/bills/{receipt}` | Match bill | request_id, date, due_on, reference, tax_rate, items with variant_id and quantity; inherits receipt currency |
| `/customers` | Create customer | name, optional email and phone |
| `/credits` | Configure credit | customer_id, credit_limit, terms_days, active |
| `/quotes` | Create quotation | request_id, warehouse_id, customer_id, date, due_on, reference, optional currency and notes, items with variant_id, quantity, unit_price, discount_percent |
| `/invoices/{quote}` | Accept and invoice | request_id, date, accepted=true |
| `/settlements/{document}` | Pay bill/receive payment | request_id, date, amount, account_id, reference |
| `/returns/{invoice}` | Return unpaid credit items | request_id, date, reference, notes, items with variant_id and quantity |
| `/openings` | Allocate existing control balance | request_id, kind=opening_payable or opening_receivable, warehouse_id, party_id, date, due_on, reference, amount |

Use UUID request IDs and decimal strings for quantities, prices and amounts. Retry a posting with the same request ID and identical payload; changed payloads are rejected. Dates use YYYY-MM-DD. Receipt, bill, invoice, return and settlement postings are atomic. Goods receipts debit inventory and credit receipt accrual; bills clear accrual and credit payables. Credit invoices post receivables, sales, tax, inventory and cost of sales together.

Permissions are defined in `config/trade.php`. Supplier/order creation, order approval, receiving, billing and payments have separate permissions. Credit settings and opening allocation require company-wide authority. Services reject cross-book settlement, future dates, over-receipts, excessive bill quantities, duplicate supplier invoice numbers and excessive allocations.

## Foreign-currency purchase orders and bills

When Multi-currency is enabled, `/orders` accepts an active non-base `currency`; item unit costs use that currency. An approved order-date rate is stored on the order and converted to base carrying cost for inventory receipts. A matched bill inherits the receipt currency and snapshots the approved rate for its own date. Any difference between received stock carrying value and bill-date supplier liability posts to the configured exchange gain/loss account. Partial receipts, bills and allocations retain their original amounts and rates.

## Foreign-denominated customer invoices

When Multi-currency is enabled, `/quotes` accepts `currency` set to an active non-base currency. Item `unit_price` values and quotation totals are in that currency. Accepting the quote preserves the quote-date rate and original item amounts on the invoice; credit limits and stock cost remain measured in base currency. `/returns/{invoice}` reverses only unpaid original-currency value. Printed documents include original totals, historical rate and base totals.

A foreign-denominated invoice must be paid through a same-branch foreign bank, safe or mobile account in that currency using `foreign_account_id` and `original_amount`. Partial allocations track original and base amounts. The invoice's historical base carrying value clears receivables; payment-date valuation recognizes any realized exchange difference.

## Foreign-account settlement of base-currency documents

POST `/settlements/{document}` also accepts request_id, date, reference, foreign_account_id and original_amount. The account must be a bank, safe or mobile account in the document branch. Multi-currency and currencies.post permission are required alongside the normal trade settlement permission. For a base-currency document, the approved posting-date rate converts the original amount to base currency and the result must be exact cents. The conversion cannot exceed the open balance. Settlement and allocation commit together.