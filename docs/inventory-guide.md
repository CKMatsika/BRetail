# Inventory guide — Phase 2

## Enable inventory

Open **Modules** and choose **Enable inventory**. An owner or system administrator can activate it. Inventory then appears in the navigation for permitted roles. Optional modules remain disabled until activated; future phases cannot be activated early. Existing data is preserved when inventory is disabled. Finish or cancel open counts first.

## Build the catalogue

1. In **Inventory → Setup**, create departments, then categories inside departments, then subcategories inside categories. You can leave a product unclassified.
2. In **Catalogue**, add a product name, brand, unit, classification, tax label, warranty months and optional custom attributes.
3. Add each **variant** with a unique SKU and optional unique barcode. For baby clothes, use names such as “Pink / 0–3 months” and add Size/Colour attributes. For computers, add RAM, Storage, Processor and Model attributes. Every variant has its own prices and stock thresholds.
4. Edit products or variants to update catalogue details. Deactivate items instead of deleting history. Changing a catalogue cost does not rewrite historical inventory valuation. Reactivate an item before posting any further stock movements or count variances against it.

Catalogue and variant definitions are company-wide. Warehouse stock, movements and counts respect branch access. Owners can create additional warehouses in Setup. Each warehouse belongs to an active branch.

## Record stock

Open **Stock ledger → Post movement**. Choose a warehouse, operation, reference and reason. Add one or more variants. Quantities accept three decimal places; incoming costs accept four.

- **Opening stock** establishes the starting quantity and cost. It must be positive and must precede every other movement for that SKU and warehouse.
- **Adjustment** adds stock with a positive quantity or removes stock with a negative quantity. Enter the incoming unit cost for additions. Removals use the current weighted-average carrying value automatically.
- Negative balances are rejected. If any line fails, no part of the document posts.
- Posted documents cannot be edited or deleted. Correct errors with a new, clearly referenced adjustment; use the original carrying cost when reversing an incoming error where appropriate.

**Stock on hand** shows ledger-derived quantity, average cost and value in the company base currency. **Reconcile** checks the balance projection against all posted movements. It detects differences without silently repairing them. Movement history shows 100 lines at a time; use Newest/Older movements to browse history.

These are inventory operations only. They do not create purchase orders, supplier invoices, customer sales or accounting journals. Those belong to later authorized phases.

## Physical counts

Start a count with a warehouse and reference. Leave the optional variant selection empty for all active variants, or select a group for a cycle count. Each snapshot accepts up to 2000 active variants. Split larger catalogues into smaller counts. Only one draft per warehouse is allowed.

Choose **Enter count**, enter every snapshot quantity including zero, then **Post count variances**. The system records the variance as an immutable stock document. Extra found stock uses the current warehouse average cost, or the variant's configured cost if no stock exists.

If any stock movement occurs in that warehouse after the snapshot, posting is rejected. Cancel the stale count, take a new snapshot and recount. Counts with no variance are finalized without zero-quantity ledger entries. Posted and cancelled counts cannot be posted again.

## CSV import and export

Download the CSV template from Import. CSV files can be edited in Excel; actual `.xlsx` files are not accepted. Keep the exact header order and use a UTF-8 file with comma separators. Preserve SKUs and barcodes as text when using Excel.

Columns: product_name, variant_name, sku, barcode, department, category, subcategory, brand, unit, cost_price, selling_price, wholesale_price, reorder_level, attributes_json.

One row creates one new variant. Repeated product names group variants under one product. Classification paths are created if needed. Duplicate SKUs/barcodes, invalid hierarchy, malformed attributes or invalid prices reject the entire file with the row number. Imports do not overwrite existing variants, rewrite product metadata or post stock. Ambiguous duplicate product names must be resolved in the editor.

Prices must be plain decimal values without currency symbols or thousands separators. `attributes_json` is a quoted JSON object, for example `{"Size":"3–6 months","Colour":"Blue"}`. Use your CSV editor's quoting when commas appear inside the value. Import up to 1000 rows / 2 MB per file. Enter opening quantities separately in Stock ledger.

Export CSV downloads the entire tenant catalogue. Text beginning with spreadsheet formula markers is prefixed with an apostrophe to prevent accidental formula execution. This protection is intentional and may require deliberate text cleanup when round-tripping unusual SKUs/names.

## Permissions and limits

Inventory Manager can configure the shared catalogue and manage stock/counts in assigned branches. Branch Manager can view and count stock in assigned branches but cannot post arbitrary adjustments. General Manager, Accountant, Purchasing Officer and Auditor have the configured read/export permissions. Cashier has no inventory access in Phase 2. Owners retain full company access.

This phase implements the core inventory checklist. Serial/batch lifecycle tracking, reservations, stock transfers, supplier/customer returns, product photos, label printing and advanced ageing reports are not included in this delivery. Unit costs and values are in base currency; exchange processing and accounting integration remain later-phase work.
