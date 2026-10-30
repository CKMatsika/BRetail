# Phase 1 implementation report

Status: implemented and locally validated; **ready for user review**. Phases 2–8 have not started. No deployment or external integration was performed.

## Delivered

- Laravel 13.39 foundation with locked Composer/npm dependencies and an explicit PHP 8.4.1+ requirement matching the resolved dependencies.
- React/TypeScript/Inertia application shell, responsive mobile navigation, light/dark preference and accessible labelled forms/dialogs.
- Session login/logout, password hashing, session rotation, CSRF, login/registration throttles and Sanctum token authentication.
- Atomic three-step company onboarding with three industry templates, currencies, financial-year/tax preferences, receipt/invoice preferences and first branch/warehouse.
- Tenant-scoped business models that fail closed without context; tenant-bound admin queries and composite foreign keys for warehouse, role and branch membership integrity.
- Twelve predefined roles; server permissions and branch policies; staff creation, role/branch reassignment and deactivation with session/token revocation.
- Branch creation/editing, default warehouses, company settings and private validated company logos.
- Sixteen-module registry with dependency checks, serialized configuration, protected core modules and saved future-module preferences. Unimplemented modules cannot activate.
- Append-only audit behavior in the application model; scoped activity viewer.
- Versioned read APIs, migrations, production-safe empty default seeder, local demo command, CI checks, architecture/ER plan, deployment and backup instructions, and user/admin guides.

## Validation performed

| Check | Result |
| --- | --- |
| PostgreSQL 16 feature suite | 23 tests, 103 assertions passed |
| SQLite quick suite | 24 tests, 104 assertions passed |
| TypeScript check | Passed |
| Vite production build | Passed |
| Playwright desktop journey, 1440 × 1000 | Passed |
| Playwright mobile journey, 390 × 844 | Passed |
| Composer validation | Passed |

Browser journeys cover onboarding, branch creation, module preferences, staff creation, logout, cashier login, mobile navigation, restricted menu visibility, forbidden company settings and viewport overflow. The backend suite verifies tenant isolation, branch restrictions, API authentication, role restrictions, disabled module APIs, onboarding rollback, upload validation and access revocation.

Browser testing caught and resolved premature final-step submission in the wizard. Backend testing caught and resolved a missing in-memory active-user default immediately after registration. Review also removed company settings from dashboard props for roles without company-view permission.

## Boundaries for review

This is the foundation, not a functioning transactional ERP. Inventory, POS, accounting, payroll, fiscal/payment integrations, exchange calculations, PWA installation and offline sync are intentionally deferred. Base currency and tax entries are stored preferences only. No statutory rates were supplied.

Roles are predefined, not editable through a custom role designer. API v1 currently exposes authenticated read endpoints; web administration uses server-controlled Inertia actions. MFA, email verification, password-reset delivery and self-service API-token management are not included. Audit immutability is enforced by application models; production database permissions must also prevent unapproved direct writes. Full production hardening, provider-specific deployment and operational recovery drills remain installation responsibilities.

The test database is a new disposable project-local PostgreSQL cluster. Existing databases were untouched. Demo credentials are generated locally and stored in the ignored `.runtime/demo-credentials.txt` for this workspace. No fixed production password exists.

## Review gate

Review the setup journey, role defaults, branch scope, database design and hosting requirements. Resolve any foundation changes before authorizing Phase 2. Later transaction modules must introduce ledger idempotency, concurrency controls, decimal currency rules, posting immutability and reconciliation tests described in architecture.md.
