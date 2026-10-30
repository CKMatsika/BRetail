# Accounting API

Session-authenticated routes start at `/accounting`; Sanctum equivalents start at `/api/v1/accounting`. Both apply tenant, module and service permission checks. Send `Accept: application/json`, and CSRF protection for session writes. Decimal amounts are strings. Responses wrap results in `data`; validation errors return 422. Foreign tenant IDs return 404, permission/module failures 403, and missing accounting initialization 423.

| Verb and path | Purpose / permission |
| --- | --- |
| GET `/data` (API also supports its root) | Chart, periods, recent journals, banks, statements, purchases; accounting.view; optional book/before_id |
| POST `/setup` | Period name, starts_on, ends_on; accounting.configure + company-wide |
| POST `/activate` | Accepted opening_stock_confirmed and base_currency_precision_confirmed; accounting.configure + company-wide |
| POST `/accounts/{id?}` | Create/edit code, name, type, report_group, is_cash, active; accounting.configure |
| POST `/mappings` | role, account_id; accounting.configure, before any journals |
| POST `/periods` | name, starts_on, ends_on; accounting.periods |
| POST `/lock/{id}` | Lock period; accounting.periods |
| POST `/journals` | Balanced dated manual journal; accounting.post |
| POST `/reverse/{id}` | date and reason; accounting.reverse |
| POST `/cashbook` | Receipt/payment/transfer; accounting.post |
| POST `/purchases` | Supplier invoice plus stock; accounting.purchase |
| POST `/banks` | name, account_id, optional branch_id; accounting.bank |
| POST `/statements` | Import dated statement; accounting.bank |
| GET `/statements/{id}` | Statement plus unmatched bank candidates; accounting.bank |
| POST `/match/{id}` | statement_line_id, journal_line_ids array; accounting.bank |
| POST `/unmatch/{id}` | statement_line_id; accounting.bank |
| POST `/close/{id}` or `/cancel/{id}` | Finalize reconciliation or cancel draft; accounting.bank |
| GET `/reports` | book, from, to, optional branch_id; accounting.view |
| GET `/export` | Same filters, report=trial_balance or general_ledger; accounting.export |

Manual journal example:

```json
{"request_id":"550e8400-e29b-41d4-a716-446655440000","book":"live","branch_id":1,"date":"2026-10-29","reference":"CAPITAL","description":"Verified owner funds","opening":true,"cashflow":"financing","lines":[{"account_id":10,"debit":"100.00","credit":"0"},{"account_id":20,"debit":"0","credit":"100.00"}]}
```

Account/branch IDs come from tenant-scoped GET data; example IDs are illustrative. Source UUIDs plus canonical hashes prevent duplicates. Retry the original payload and UUID after an uncertain response. Changing an already posted payload returns 422. Generic journal reversal is restricted to manual/opening-financial/cashbook sources. Source POS, purchase and stock effects have their own immutable source links.

Cashbook shares book, branch_id, date, reference, description, cashflow and request_id. Add kind=`receipt|payment|transfer`, cash_account_id, offset_account_id and positive amount. Transfers require two cash accounts and zero net cash. Purchases use request_id, warehouse_id, supplier_name, invoice_number, date, tax_rate, settlement=`payable|cash_safe|bank|card|mobile_money`, and items containing variant_id, quantity and unit_cost. Purchase book follows the current company operating mode.

Statements use request_id, bank_account_id, book, starts_on, ends_on, opening_balance, closing_balance, baseline_confirmed and either CSV text in `csv` or a `lines` array of date/reference/description/signed amount. CSV is limited to 2 MB and 5,000 rows. Matching is by whole ledger line, with groups of up to 200 lines per statement line. The operating guide explains baseline, continuity and outstanding-item rules.
