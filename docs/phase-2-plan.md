# Phase 2 inventory plan

Scope: department/category/subcategory hierarchy; products with independent SKU/barcode variants and configurable attributes; warehouse management; opening stock and signed adjustments; immutable stock ledger and derived balances; weighted-average costing; physical counts; atomic CSV catalogue import/export; inventory REST endpoints and responsive UI. No POS, purchasing invoices, accounting postings or inter-branch transfers.

Ownership: all catalogue, document, count and ledger tables have tenant IDs. Composite foreign keys protect relationships. Warehouse access is limited to assigned branches unless the role is company-wide. Module and permission checks apply to web and API paths.

Precision: quantities use NUMERIC(18,3), prices/costs and values NUMERIC(24,4). Brick Math performs decimal calculations, never PHP floating point. Outbound stock carries the warehouse weighted-average value; the final withdrawal consumes the remaining value to avoid residual rounding balances. No negative quantities are allowed.

Concurrency: tenant-row locking serializes inventory and module writes initially. Each posted stock document has a tenant-unique UUID and canonical payload hash; retries return the original document, while changed payloads are rejected. Opening stock is permitted only before any movement for that variant/warehouse. Counts snapshot a warehouse movement watermark and include zero-stock variants; posting rejects stale snapshots and posts all variances atomically. Posted movements and documents are immutable, including PostgreSQL triggers against update/delete.

Module disabling preserves history and prevents operations. Inventory cannot be disabled while a count is open. A future dependent transactional module will also prevent disabling through the registry dependency graph.

Validation: decimal costing, idempotent retries/conflicts, negative-stock rejection, tenant/branch isolation, disabled routes, hierarchy validation, count staleness and variance reconciliation, atomic CSV validation, database ledger immutability, catalogue/warehouse UI and desktop/mobile browser journeys. Existing Phase 1 tests remain regression coverage.
