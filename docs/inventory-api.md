# Inventory REST API

All routes require Sanctum authentication, an active inventory module and current role permissions. Prefix: `/api/v1/inventory`. Use `Accept: application/json` and `Authorization: Bearer TOKEN`. Tenant IDs are derived from the authenticated user. Send quantities and prices as **JSON strings**, never floating-point numbers. Web forms call corresponding `/inventory` paths with CSRF/session authentication.

| Method | Path | Permission |
| --- | --- | --- |
| GET | / | inventory.view |
| POST | /products | inventory.configure |
| PATCH | /products/{id} | inventory.configure |
| POST | /products/{product}/variants | inventory.configure |
| PATCH | /products/{product}/variants/{id} | inventory.configure |
| POST | /classifications | inventory.configure |
| POST | /warehouses | inventory.configure plus branch access |
| POST | /movements | inventory.adjust plus warehouse access |
| POST | /counts | inventory.count plus warehouse access |
| POST | /counts/{id}/post | inventory.count plus warehouse access |
| POST | /counts/{id}/cancel | inventory.count plus warehouse access |
| POST | /warehouses/{id}/reconcile | inventory.view plus warehouse access |
| POST | /import | inventory.import and inventory.configure |
| GET | /export | inventory.export |
| GET | /template | inventory.import |

Reads return `{data: ...}`. Mutation responses return `{data: record, message: string}`; product, variant, classification, warehouse and count creation use 201. CSV endpoints stream downloads. Validation errors return 422 with field errors; authentication 401; permissions/module gates 403; foreign/missing record IDs 404; throttling 429. All API routes remain under the foundation 60/minute throttle.

GET returns products with variants, classifications, accessible active-branch warehouses, balance projections, 100 latest movement lines, and recent counts plus every draft count. Use `?before_id=ID` to read older movement pages. The catalogue is shared company data; balances/history/counts are branch scoped. A count contains its snapshot lines. Count history retains the latest 30 records plus all drafts in the response; full posted history remains in the database.

## Stock posting

```json
{
  "request_id": "2a1ad951-47a3-4a51-8c4a-b1a12c690183",
  "warehouse_id": 1,
  "type": "opening",
  "reference": "OPEN-001",
  "reason": "Initial verified stock",
  "lines": [{"variant_id": 1, "quantity": "10.000", "unit_cost": "8.2500"}]
}
```

Public types are `opening` and `adjustment`. The `count` type is created only by the count service. Documents accept 1–2000 unique variants. Quantities accept up to 9 integer digits and 3 decimal places; costs accept 9 integer digits and 4 decimal places. Positive movements require a unit cost. Outgoing cost inputs do not override weighted-average valuation.

Keep the request UUID stable when retrying. The server locks the tenant, normalizes/sorts the payload and compares its hash. Identical retries return the original document; reuse with different data returns 422. Failed documents leave no ledger or balance changes. Successful posted records have no update/delete endpoints and PostgreSQL rejects direct updates/deletes.

Start a count with `{warehouse_id, reference, variant_ids?: [ID,...]}`. Empty/omitted variant IDs select all active variants. Post a snapshot with `{lines:[{variant_id:ID,counted_quantity:"9.000"},...]}`. Every snapshot line must appear once. Posting checks the warehouse movement watermark and rejects stale counts. Retrying an already finalized count is rejected without another stock effect.

## Catalogue

Products require name, unit, tax_class, warranty_months, attributes object/array and active flag. Optional description, brand, department_id, category_id and subcategory_id are validated against the tenant hierarchy. Variants require name, sku, attributes, cost_price, selling_price, wholesale_price, reorder_level, minimum_stock and active; barcode and maximum_stock are optional. Costs are catalogue defaults and do not mutate the stock ledger.

Classifications require name, level (`department`, `category`, `subcategory`) and the correct parent_id for child levels. Warehouses require name and branch_id. CSV import uses multipart field `file`; see inventory-guide.md for headers and limits.
