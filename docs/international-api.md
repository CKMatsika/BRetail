# Multi-currency and branch transfer API

The web workspace uses authenticated session routes at `/international`. Authenticated API clients use the same routes under `/api/v1/international`. Tenant identity comes from the authenticated user. Accounting, Inventory and Multi-currency must be enabled. Amounts use decimal strings; rates mean base-currency units per one foreign-currency unit.

`GET /data` returns currencies, approved/proposed rates, authorized foreign accounts and original/base balances, visible transfer events, branches, warehouses and active variants. A transfer appears only when the viewer can access both endpoint warehouses. Currency account balances include original-currency units and base carrying value for the selected book.

POST `/currencies` adds `code`, `name` and `minor_units`. POST `/rates` proposes `currency_id`, `effective_on`, `rate` and `source`; POST `/approve-rate/{id}` authorizes the immutable rate. A transaction uses the latest approved rate whose effective date is on or before its document date. Posted snapshots retain the applied rate and date.

POST `/accounts` creates a branch/currency account with `name`, `branch_id`, `currency_id` and `kind` (`bank`, `mobile` or `safe`). POST `/entries/{account}` records a foreign balance increase/decrease with `request_id`, `date`, `reference`, `direction`, original `amount`, `offset_account_id` and `cashflow`. POST `/revalue/{account}` posts period-end valuation; `/reverse/{entry}` reverses the latest eligible revaluation. Posted foreign entries and approved rates are immutable; retries require the same request ID and payload.

## Branch transfers

| Method | Route | Purpose |
| --- | --- | --- |
| POST | `/transfers` | Create a draft from `source_id`, `destination_id`, `date`, `reference`, `items` and `request_id` |
| POST | `/approve-transfer/{id}` | Approve a draft |
| POST | `/cancel-transfer/{id}` | Cancel before dispatch |
| POST | `/dispatch/{id}` | Dispatch the approved quantities with event date and request ID |
| POST | `/receive/{id}` | Receive quantities at the destination; partial receipts are allowed |
| POST | `/loss/{id}` | Resolve in-transit loss with quantities and a required reason |
| GET | `/transfers/{id}/print` | Authenticated printable transfer and event history |

Users need access to the source warehouse to create/approve/dispatch and destination warehouse to receive. A cross-branch receipt also requires authority for both branches because it posts the paired branch clearing journals. Dispatch moves original inventory carrying value into transit. Receipt releases that same value at destination; loss records the verified amount against inventory loss. Stable request IDs make dispatch, receipt and loss retries idempotent. Events are chronological and the same dispatched quantity cannot be received or lost twice.

Branch transfers are internal stock movements: they do not create revenue or change company-wide inventory value. Use the printable transfer record for quantities, events, reason, and base carrying value. Financial reporting across branch transfers and independent concurrent-worker verification remain part of the wider Phase 6 acceptance work.