# Purchasing and customer credit guide

Enable Inventory, Accounting, and Purchasing or Sales & customers in Modules. Initialize accounting before opening these workspaces. Training and live histories remain separate.

## Purchasing

1. Create a supplier with a unique code, contact details and payment terms.
2. In Orders, choose the supplier, warehouse and order currency, then enter quantities and unit costs in that currency. For a foreign currency, the approved order-date rate establishes stock's base carrying cost. A user with approval permission approves the order.
3. Choose Receive goods and enter only quantities delivered. Partial deliveries are supported; quantities above the remaining order are rejected. Cancel an unfinished order to close its undelivered remainder.
4. Choose Match supplier bill from the receipt. Use the supplier invoice number as Reference, enter the quantities billed and input tax rate. The bill inherits the receipt currency and snapshots the approved bill-date rate. It clears the receipt accrual and creates a payable without receiving stock again; exchange movement between receipt and bill dates is recorded separately.
5. Choose Pay bill and record a partial or full settlement. A foreign-denominated bill is paid in its original currency from a same-branch foreign bank, safe or mobile account with enough recorded funds.

## Customer credit

Create the customer, then have a company-wide authorized user configure an active credit limit and terms. Create a quotation with its expiry, items, prices and discounts. Quotes do not reserve stock or post journals.

Choose **Unit prices: Include VAT** when entering VAT-inclusive prices; VAT is extracted from the discounted line amount rather than added again. **Exclude VAT** retains the existing behavior. Zero-rated products remain untaxed in either mode. **Preview totals** calculates net, VAT and total on the server without saving or moving stock.

Use **Edit quotation** on an uninvoiced quote to correct prices, items, customer, warehouse or dates. **Save revised quotation** creates a linked revision and keeps the old record for audit history. Only the latest version may be invoiced. The **Accept and invoice** screen displays the invoice preview with an option to return to editing before posting. **Preview invoice** displays a posted invoice, and Print shows its price basis and tax breakdown.

Posted invoices remain immutable. **Create corrected quote** copies an invoice into a new quotation; it does not reverse the original invoice. Reverse eligible unpaid items through the existing return workflow before posting a replacement. Paid or partially settled corrections require a separately reviewed workflow.

With Multi-currency enabled, choose the quotation currency and enter prices in that currency. On acceptance, the invoice preserves the quote's original item values and historical rate. Credit limits and inventory carrying cost remain measured in base currency.

After customer acceptance, choose Accept and invoice. The server checks expiry, available stock, overdue balances and the credit limit before posting the invoice and stock cost together. Receive payment in the invoice currency using a same-branch bank, safe or mobile account. The invoice's historical base carrying value is allocated; settlement-date exchange differences are recorded.

Return unpaid items reverses the original invoice amounts and stock cost. For a foreign-denominated invoice, returns are limited to unpaid original-currency value. Cash refunds for paid credit invoices are outside this workflow.

## Balances and controls

Aging shows due dates, outstanding balances and age bands. Control account reconciliation compares branch payable/receivable ledger balances with party balances. Unallocated amounts require investigation. Authorized company-wide users can allocate verified existing control balances through Openings; this does not post those amounts a second time.

Documents can be printed through authenticated Print links. Foreign documents show original currency, historical rate and base values. Posted documents are immutable. Period locks, bank cutoffs, tenant permissions and branch assignments still apply. Finish outstanding orders, unmatched receipts and balances before disabling modules, closing branches or activating live operations.

## Settling a base-currency document from foreign funds

When Multi-currency is enabled, a base-currency bill or invoice can be settled from a same-branch foreign bank, safe or mobile account. Enter the amount in the account currency and the posting date. The approved rate converts it to exact base cents. Supplier payments require enough recorded foreign funds.