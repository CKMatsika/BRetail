# Administrator guide

Each registration creates a separate tenant, company, owner, 12 roles, first branch/warehouse and module settings in one database transaction. Login emails are globally unique so users cannot accidentally authenticate into a different company with the same address. Cross-company membership and email-verified enrollment require a future identity workflow.

Roles are predefined and permissions are backend enforced. System Administrator and Business Owner have all permissions **inside their own tenant**, with no cross-tenant override. General Manager, Accountant, Auditor and HR Officer have company-wide read access to their respective permitted screens. Branch Manager has assigned-branch read/edit access. Cashier and Sales Assistant can access the foundation dashboard only. Owners manage users and role assignments. Role definitions live in config/retail.php and are copied at onboarding; editing the config does not silently rewrite existing company roles. Plan reviewed migrations for role changes.

Audit entries capture company setup, branches, team access, company settings, logo changes and module preferences. They exclude passwords and tokens. Model-level audit entries reject updates/deletes; restrict direct database privileges to protect them from out-of-band modification. Use a separate controlled maintenance identity for restoration. Never expose the database login to end users.

Required core modules cannot be disabled. Planned optional modules remain unavailable even if a malformed client attempts activation. Module change operations lock the tenant row and validate dependencies. Every operational route must register the module middleware and permission/policy before future modules are introduced.

Tenant context fails closed for scoped business models. CLI jobs must establish an explicit tenant context and clear it after work; do not use unscoped queries for tenant operations. User authentication uses global email lookup; administration queries constrain users to the authenticated tenant. Composite keys protect same-tenant role assignments, warehouse branches and branch memberships.

No MFA, password reset delivery, self-service API token issuance, arbitrary role editor or multi-company membership UI is included in this foundation. Complete any required identity-provider or email workflow before enabling public production signup. Session cookies, login throttling, password hashing, CSRF and API authentication are implemented. Production hosting must use HTTPS, secure cookies, private storage and tested backups as described in deployment.md.

## Phase 2 inventory administration

Inventory can now be activated in Modules. Finish or cancel draft counts before disabling it. Role grants added by the inventory migration preserve existing permissions. PostgreSQL triggers protect posted stock documents and ledger lines from UPDATE/DELETE; balances remain controlled projections. See [inventory guide](inventory-guide.md) and [inventory API](inventory-api.md). The Phase 1 module availability statements above describe the original release.


## Phase 3 training POS

Enable POS_TRAINING_ENABLED only in local/testing demonstration environments. Production checkout is locked regardless of this flag until the Phase 4 financial adapter is validated. Training modifies local inventory and produces labelled non-fiscal receipts; never import training records as live transactions. Managers can close another authorized cashier's shift and cancel abandoned held carts, with the closing actor recorded. Branch deactivation and module disabling cannot strand open shifts/holds/counts. Access changes serialize with POS writes and authorization is refreshed under lock. See [POS guide](pos-guide.md) and [POS API](pos-api.md).



## Phase 4 accounting update

The Phase 3 training-only boundary above describes the earlier release. Phase 4 adds base-currency accounting, journals, periods, cashbook, supplier invoice posting, bank reconciliation and financial reports. Live POS requires explicit company activation after verified setup; existing training sales are never converted. Read [accounting-guide.md](accounting-guide.md) and [accounting-api.md](accounting-api.md). Payment-provider and fiscal integrations remain outside this release.
