# Foundation API v1

Base: `/api/v1`. Return JSON. Authenticate with `Authorization: Bearer TOKEN`. Tokens are issued through Laravel Sanctum via trusted server administration; there is no public token-minting endpoint in Phase 1. Tokens inherit current user tenant, active state, role and branch access. Revoke tokens when devices are retired. Disabling a user deletes their tokens and database sessions.

| Method | Endpoint | Access | Response |
| --- | --- | --- | --- |
| GET | /me | Active user, access module enabled | `{data: {user: {id,name,email},role}}` |
| GET | /branches | branches.view and branches module | `{data: [{id,tenant_id,name,code,address,active,warehouses}]}` |
| GET | /branches/{id} | Branch policy and branches module | `{data: branch}` |

Responses: 401 unauthenticated; 403 forbidden, inactive or disabled module; 404 missing or foreign-tenant resource; 429 rate limit. API throttling is 60 requests/minute per authenticated identity. The client never supplies a tenant ID.

Web forms use session cookies and CSRF through Inertia. Routes: POST /register, POST /login, POST /logout, POST /branches, PATCH /branches/{id}, POST /team, PATCH /team/{id}, PATCH /company, POST /company/logo, PATCH /modules/{key}. Validation returns field errors for Inertia or 422 JSON when negotiated. Unauthorized writes return 403 before validation.

Registration requires owner name/email/password confirmation, company name, industry template (`general`, `apparel`, `electronics`), three-letter base currency, first branch name/code, warehouse name, financial-year start (`MM-DD`), tax label/rate and invoice prefix. Optional company registration/contact details, accepted currencies and future module preferences are stored. Tax settings are configuration only; Phase 1 calculates no taxes.

Module preferences accept `{enabled:false,preferred:true}` for later modules. Later modules cannot be activated; required foundation modules cannot be disabled. A registry change is locked and audited transactionally.

Inventory write and read endpoints are now available in Phase 2: see [inventory-api.md](inventory-api.md).


Phase 3 training POS endpoints: [pos-api.md](pos-api.md). Financial checkout is still gated pending Phase 4.



## Phase 4 accounting update

The Phase 3 training-only boundary above describes the earlier release. Phase 4 adds base-currency accounting, journals, periods, cashbook, supplier invoice posting, bank reconciliation and financial reports. Live POS requires explicit company activation after verified setup; existing training sales are never converted. Read [accounting-guide.md](accounting-guide.md) and [accounting-api.md](accounting-api.md). Payment-provider and fiscal integrations remain outside this release.

## Purchasing and customer credit

See [the trade API](trade-api.md) for authenticated purchasing, credit sales, settlements, printing and reconciliation endpoints.
