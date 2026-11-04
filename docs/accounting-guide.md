# Accounting and finance — Phase 4

Enable Inventory, close all cashier shifts, then enable Accounting & finance in Modules. Owners and accountants initialize a named accounting period covering today. Setup creates the default chart, posting mappings and main bank record, and snapshots existing stock into the training ledger. Setup is atomic and repeatable. Accountants have company-wide finance permissions; managers/auditors have restricted reporting permissions. Cashiers cannot read the ledger.

## Training and live books

Select the book above the accounting tabs. Training and live journals never consolidate. Production training remains prohibited. Live checkout requires initialized accounting and explicit company activation under Configuration. Verify opening inventory and confirm that base-currency monetary amounts use two decimals. Resolve open shifts, held carts and draft counts before activation. A company containing training sales must use a fresh company for live operations; historical training transactions are preserved and never replayed as live sales. Activation creates a separate live opening inventory snapshot; enter verified opening cash, banks, liabilities and equity separately. Training cash is not copied into live books.

POS labels and printed receipts identify the operating mode. All settlements are manually recorded; this release does not contact payment providers or implement fiscal certification. Use company-approved tax rates and input-tax treatment. Multi-currency conversion belongs to Phase 6.

## Chart, journals and periods

Configuration lists the chart and posting roles. Add accounts, edit names/codes/groups, or deactivate unused non-control accounts. Cash classification and account type become fixed after use. Posting mappings become fixed after the first journal. Inventory and drawer accounts accept business postings through stock/POS workflows, rather than manual journals.

Journals require a branch, posting date, reference, description and balanced debit/credit lines. Values use four decimals; cash and bank lines use two. Choose operating, investing or financing when total cash changes; internal cash transfers use none. Mark verified opening financial balances with the opening checkbox. Posted journals are immutable. Reverse eligible manual, opening-financial or cashbook entries using a dated reason in an open period. Correct sales and inventory through their business workflows.

Periods cannot overlap. Locking a period blocks new dated entries and reversals in that period. Finish reconciliations and close shifts before locking the current period. Locks cannot be reopened through the application. Create the next period before the current one ends. Profit is included as unclosed earnings on the balance sheet; automatic year-end rollover is not performed.

## Cashbook and drawer controls

Cashbook receipts debit the chosen cash/bank account and credit the counterpart. Payments credit cash and debit the counterpart. Transfers credit the source cash account and debit the destination cash account, within one branch. Payments/transfers require recorded funds on the posting date. Enter owner funds as cash against equity with financing classification, and operating costs as cash against expense with operating classification.

POS opening floats transfer recorded safe funds into the cashier drawer. Insufficient safe funds reject opening. Sales recognize applied settlements, net revenue, tax and actual inventory carrying cost together. Returns restore original cost and reverse applicable settlements/revenue/tax. Closing transfers counted cash to the safe, clears the expected drawer amount and records shortages or overages. A financial posting failure rolls back its stock/payment/shift operation too.

## Supplier invoice posting

Purchases receives positive quantities and unit costs against a warehouse, supplier-name snapshot and unique supplier invoice number. It posts stock, input tax, payable or settlement atomically. Inventory uses exact four-decimal values; invoice settlement uses two decimals and any residual posts explicitly to rounding differences. Received inventory cannot be future-dated. This is the Phase 4 posting bridge: supplier master records, purchase orders, separate receipts/bills and allocations are Phase 5.

## Bank reconciliation

Register an unused active cash asset account for an additional bank. A bank can belong to one branch or the company; company bank reconciliation requires company-wide access. Register the bank before posting to its account.

Paste a CSV with exactly this header and signed, two-decimal amounts:

```csv
date,reference,description,amount
2026-10-29,DEP001,Owner deposit,100.00
2026-10-29,FEE001,Bank charge,-5.00
```

Provide statement start/end, opening and closing balances and confirm the verified baseline. The first opening must equal ledger balance before the start date. Record/verify older entries before establishing that baseline. Subsequent statements continue on the day after the last closed statement and use its closing balance. Opening plus statement movements must equal closing. One draft per bank/book is allowed; cancel a draft to replace an import without deleting its source history.

Select a statement line and one or more unmatched book lines with an equal signed total. Record omitted bank fees/receipts through the cashbook, then reopen the statement to refresh candidates. Unmatch is available while draft. Finalization requires every statement line matched and ledger balance equal to statement closing plus outstanding book entries. Outstanding deposits/cheques carry forward. Closed reconciliations and matches are immutable; later backdated postings through the cutoff are rejected. Correct reconciled history in a current open period.

## Reports and exports

Choose book, dates and authorized branches, then Run reports. Trial balance shows opening and movement debits/credits with signed closing balances. General ledger lists posted entries. Profit/loss summarizes the selected range; balance sheet includes cumulative earnings through the end date. Cash flow reconciles opening, classified movements and closing cash. Inventory reconciliation compares the inventory control account to current stock only when the report end is today; training stock freezes at live cutover. Each report snapshot holds the company posting lock for consistency.

Print reports or download trial-balance/general-ledger CSV. Exports require a separate permission and escape spreadsheet formula text. Historical inactive branches remain available to authorized finance reports. The journal list loads 100 entries at a time; use Load older journals for additional history.

## Report centre

Reports & analytics now contains a report catalogue: Business overview, POS sales, Credit sales register, Procurement, Debtors ageing, Creditors ageing, Profit & loss, Balance sheet, Trial balance, General ledger and Cash flow. Availability follows module and role permissions. Select the reporting dates, branch and training/live book, then Run report. CSV exports use the applied report scope; local text/account filters narrow the on-screen display only. Print follows the current visible report.

Credit sales show invoices, editing reversals and credit returns at historical base values. Returns and editing reversals reduce sales; customer receipts are separate settlement activity. POS totals remain separated by transaction currency and net VAT deducts refund tax. Daily POS activity counts completed sales; the top-items ranking uses gross sale quantity. Procurement separates bills, receipts and payments and includes a supplier summary and purchase orders dated in the period. Purchase order status is its current state, not a historical status snapshot. Orders are commitments rather than posted expenses.

Debtor/creditor ageing uses Through as its cutoff and includes all open documents issued on or before it, regardless of From. It selects the invoice version effective at the cutoff and deducts only allocations whose source document date is on or before the cutoff. Buckets use days past due: Current, 1–30, 31–60, 61–90 and over 90. Click a party to inspect its invoices/bills, or select overdue balances only. Settled documents are excluded. Original foreign-currency balances are shown alongside historical base carrying values; currencies are not summed together. Branch control reconciliation identifies differences including unallocated journals, rounding and currency revaluation. Investigate differences before relying on the ageing report for a control-account reconciliation.

Financial statements use posted journals. Profit/loss uses the selected period, while the balance sheet and cash closing balances run through the cutoff. Stock on the overview is explicitly current operational stock; it is not a historical stock valuation. Financial values export at ledger precision and display to two decimal places.
