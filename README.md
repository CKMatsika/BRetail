# BRetail

Modular retail ERP. **Phases 1–6 complete; Phase 7 workforce and payroll is in progress.** Laravel 13, PHP 8.4.1+, PostgreSQL, React 19, TypeScript, Inertia 3 and Tailwind 4.

Start with [architecture and ER design](docs/architecture.md), [deployment](docs/deployment.md), [API](docs/api.md), [user guide](docs/user-guide.md), and [administrator guide](docs/administrator-guide.md). Finance instructions are in the [accounting guide](docs/accounting-guide.md) and [accounting API](docs/accounting-api.md).

## Local setup

Install PHP 8.4.1+, Composer, Node 20.19+ and PostgreSQL 16+. Enable ctype, curl, dom, fileinfo, filter, gd, hash, intl, mbstring, openssl, pcre, PDO, pdo_pgsql, session, tokenizer, xml and zip. SQLite test runs also require pdo_sqlite.

```powershell
composer install
npm ci
Copy-Item .env.example .env
php artisan key:generate
```

Create a new empty PostgreSQL database and set its credentials in `.env`. Then:

```powershell
php artisan migrate
npm run build
php artisan serve
```

Open `http://127.0.0.1:8000/register` and create your business. No default credentials or production seed accounts exist. For a demo company use `php artisan retail:demo` in a local environment; it creates a separate company with a generated password.

This workspace includes an ignored portable PHP 8.4 runtime at `.runtime/php/php.exe`, and an isolated PostgreSQL development cluster on loopback port 55432. Existing XAMPP and PostgreSQL databases were not changed. `.env` targets `bretail_dev` in this cluster. Run `./scripts/dev.ps1` to start it again on this machine.

## Validation

```powershell
php vendor/phpunit/phpunit/phpunit
php vendor/phpunit/phpunit/phpunit -c phpunit.pgsql.xml
npm run typecheck
npm run build
npm run test:e2e
```

The PostgreSQL test configuration targets **only** the disposable `bretail_test` database on port 55432. Tests run schema setup there; never point test settings at a live business database. Browser journeys require a running app backed by a disposable development database and installed Playwright Chromium (`npx playwright install chromium`).

Implemented: owner registration and guided company setup, secure login/logout, tenant and branch access boundaries, 12 predefined roles, branches with default warehouses, staff access administration, required module protection, future module preferences, audit events, private logo upload, company preferences and responsive light/dark UI. Core inventory is implemented: see [inventory guide](docs/inventory-guide.md), [inventory API](docs/inventory-api.md) and [Phase 2 report](docs/phase-2-report.md). Training POS is implemented: see [POS guide](docs/pos-guide.md), [POS API](docs/pos-api.md) and [Phase 3 report](docs/phase-3-report.md). Phase 4 adds balanced books and bank reconciliation; Phase 5 adds trade documents and settlement. Phase 6 multi-currency and multi-branch is complete; see the [phase 6 status](docs/phase-6-status.md) and [plan](docs/phase-6-plan.md). Phase 7 HR and Zimbabwe payroll is implemented; see the [HR guide](docs/hr-guide.md), [HR API](docs/hr-api.md), [payroll guide](docs/payroll-guide.md), [payroll API](docs/payroll-api.md) and [Phase 7 plan](docs/phase-7-plan.md). Phase 8 Reports & analytics has its first release; the installable shell and device enrollment are also implemented. Offline sync/checkout, loyalty and live integrations remain on the roadmap; see the [Phase 8 plan](docs/phase-8-plan.md).



