# POS guide — Phase 3 training release

## What is available

Phase 3 implements the core POS workflow for local training: catalogue/barcode search, carts, tax and discounts, split recorded payments, receipts, customers, held sales, shifts, returns and daily summaries. **Live checkout remains locked until Phase 4 accounting is implemented and validated**, as required by the accepted roadmap. Production cannot be unlocked with a training setting alone.

Training changes the selected local company's inventory and records simulated payments. Use a demonstration company with demonstration stock. No bank, card or mobile-money provider is contacted. Every receipt is labelled TRAINING, NOT A TAX INVOICE and NO FINANCIAL POSTING. These records must not be imported as live financial transactions.

## Start training

The project-local development environment has `POS_TRAINING_ENABLED=true`. The example environment defaults to false. Training is allowed only when the application environment is `local` or `testing`.

As an owner, enable **Inventory**, then **Point of sale** in Modules. Register active product variants with selling prices and post opening stock in an accessible warehouse. The cashier's assigned branch must include that warehouse. Point of sale will appear in navigation for authorized users.

Cashier and Sales Assistant can sell, open/close their own shifts and create customers. Branch/General Manager can also approve larger discounts, process returns, see authorized branch transactions and reconcile another cashier's shift. Accountants and auditors have the configured read access; they cannot sell or close drawers without the corresponding permissions.

## Open a shift and sell

1. Choose the selling warehouse and open a cashier shift. Enter the counted cash float in the company base currency. One open shift per cashier is allowed.
2. Tap product cards or scan into the search box. Keyboard-wedge barcode scanners work with Enter. SKU entry works too. **F2** focuses search.
3. Select a customer or use Walk-in customer. Quick customer creates a name with optional email/phone. Customer credit is not available in this phase.
4. Adjust quantities and optional line discount percentages. The server verifies current stock, prices, tax and permissions. The default cashier discount ceiling is 10%; only a role with discount override can exceed it.
5. Review the server-calculated totals. **Prices are before tax.** Standard products use the company's configured tax rate; zero/exempt classifications use zero. Unknown tax classifications are rejected. No statutory rates are supplied by the application.
6. Choose **Take payment** or **F9**. Enter cash tender, or manually record card/mobile money/bank transfer with an external reference. Add split payments if needed. Each method may appear once. Noncash overpayment is rejected; overpayment may only become cash change.
7. Complete the training sale. Stock, sale items and payment records commit together. Underpayment, insufficient stock or any other validation error leaves no partial sale or payment. Print or reprint the authenticated training receipt from the confirmation or Transactions.

When catalogue prices/tax change before completion, checkout rejects the old quote and requires a fresh review. The browser retains a stable request ID for an uncertain network retry. Do not submit a second payment through an external provider; this release does not integrate providers. Credit sales, offline checkout and device cash-drawer commands are unavailable. Multi-currency tenders are available when Currencies is enabled.

## Foreign-currency drawers

When Multi-currency is enabled, select a funded foreign safe and enter the opening amount to move a currency float into your own shift. The POS shows the approved rate and original-currency balance. A shift supports one open drawer per currency. Foreign tender can be combined with base cash or recorded noncash payments. If a sale is overpaid, choose base cash change or return change from the foreign currency tendered; foreign change cannot exceed that sale's tender in the same currency.

For a return, select the original foreign tender and an open drawer in that currency. Refund at most the amount originally paid, using the original sale rate. Count and close each foreign drawer before closing the base-currency shift. The system records original amounts, base carrying values and any count variance, and the printed receipt shows physical tenders and change.
## Hold and resume

Give the cart a label and select Hold sale. Held carts reserve neither stock nor price. Resume your own cart from Held sales; current availability and prices are checked again. Hold or clear the current cart before resuming another. A cart from another warehouse needs a shift in that warehouse. Managers may cancel abandoned held carts in authorized branches, including carts left by deactivated staff; they do not silently assume another cashier's cart ownership.

## Returns and refunds

An authorized manager opens the original sale in Transactions, then selects Return items. Use receipt-number search for an older sale. Enter positive return quantities and a reason. Returns must use your own open shift in the original warehouse and currency.

The server shows the refund total and remaining original payment-method balances. Enter refund amounts that sum exactly to the total. You cannot refund more units than were sold or more money through a method than was originally collected through that method. Cash refunds must fit the current drawer's expected cash. A tiny fractional or free-line return with a zero monetary total restores stock without a fake payment.

Returns restore the original carrying value, even if current average cost or selling price changed. Cumulative rounding makes a complete set of partial refunds equal the original sale amounts. Inactive products can still be returned. Repeated requests cannot create duplicate refunds or stock. Training refund receipts can be printed from the original sale's return links. Actual external card/mobile/bank refunds are not performed.

## Close and reconcile

Cashier shifts shows sales, refunds and method totals. Expected cash equals opening float plus applied cash payments minus cash refunds; returned cash change is excluded. Enter physical counted cash and a closing note to close a shift. Variance is preserved. Closed shifts accept no new sales/refunds and cannot be reopened or edited.

Managers with shift and management permission can reconcile open shifts belonging to staff in their authorized branches. The original cashier remains recorded and the closing manager is captured separately. This lets a manager close a deactivated employee's drawer without restoring that employee's access.

The daily summary uses the selected date and configured report timezone (`POS_REPORT_TIMEZONE`, default Africa/Harare). It shows recorded training sales, sales tax, refunds and net recorded value, not profit or a general ledger report. Cashiers see their own activity; managers see their authorized branches.

Close all shifts and resolve held carts before disabling POS. Inventory cannot be disabled while POS depends on it. Branch deactivation is blocked while shifts, held carts or draft stock counts need reconciliation. POS stock movements invalidate an earlier stock-count snapshot in that warehouse.

## Printing and limits

Receipts use browser printing with an approximately 78 mm print layout; printer drivers and paper settings remain device-specific. Print/reprint links require an authenticated user with access to the sale. Email/public sharing, fiscalization, product photographs, serial-number selection, payment-provider processing and native ESC/POS integrations are not included.

The register shows active variants and available quantities. Product cards load in groups of 48. Transactions lists the latest 50 matching receipts; receipt-number search finds older records. Shifts lists recent history plus every open shift. Source records and prior inventory remain preserved.


## Phase 4 accounting update

The Phase 3 training-only boundary above describes the earlier release. Phase 4 adds base-currency accounting, journals, periods, cashbook, supplier invoice posting, bank reconciliation and financial reports. Live POS requires explicit company activation after verified setup; existing training sales are never converted. Read [accounting-guide.md](accounting-guide.md) and [accounting-api.md](accounting-api.md). Payment-provider and fiscal integrations remain outside this release.
