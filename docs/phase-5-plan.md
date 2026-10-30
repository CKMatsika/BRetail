# Phase 5 — Purchasing and back office

Authorized by the user after Phase 4 validation. Implementation follows the existing tenant-scoped services, branch permissions, immutable stock documents and accounting source links.

## Delivery sequence

1. Supplier master records with contacts, terms, active status and tenant-unique codes.
2. Purchase orders with draft, approved, partially received, completed and cancelled states. Preserve ordered quantities and approved prices; authorize approval separately from creation.
3. Goods received notes supporting partial deliveries. Serialize receipt quantities under the company lock, reject over-receipts, and post inventory against goods-received-not-invoiced clearing.
4. Supplier bills matched to received quantities, with duplicate invoice protection, input tax, rounding and payable postings. Bill matching must clear receipt accruals without receiving stock again. Supplier payments allocate to open bills and cannot exceed outstanding amounts.
5. Sales quotations with price/tax snapshots, expiry and explicit acceptance. Quotes reserve no stock and create no journals.
6. Customer credit profiles, credit limits, due dates, invoicing, receipt allocation and aging. Credit sales atomically post receivables, revenue, tax and carrying cost. Concurrent invoices must not bypass limits or oversell stock.
7. Responsive purchasing/back-office screens, authenticated printable documents, REST endpoints, permissions, operating/API guides and completion report.

## Integration decisions

Use base currency in this phase; exchange rates and branch transfers remain Phase 6. The Phase 4 supplier-invoice bridge remains historical and must never be reposted as a new receipt or bill. New matched receipt/bill sources use distinct identifiers. Introduce a dedicated receipt-accrual mapping without changing existing mappings or posted journals.

Track supplier/customer subledger entries and allocations alongside their journal source links. Report and reconcile their totals to payable/receivable controls. Existing manual control balances require explicit opening allocation before enabling party-level credit operations; do not silently assign them to a supplier or customer.

Training/live books retain separate histories. Business documents pin their book when created. Outstanding orders, receipts, bills and credit balances must prevent unsafe module disabling or live cutover. All posted documents preserve party, item, tax and price snapshots.

## Required validation

Partial receipts, duplicate and concurrent receipts, over-receipt rejection, bill/receipt matching, duplicate supplier invoices, partial payments, over-allocation rejection, expired quotations, credit limits under concurrency, overdue balances, original-cost returns, tenant/branch isolation, disabled-module APIs, period locks, bank cutoffs, balanced journals and stock/subledger reconciliation. Run desktop/mobile journeys and existing regression suites before declaring completion.

Status: implemented. See [completion report](phase-5-report.md) for validation evidence and limits.
