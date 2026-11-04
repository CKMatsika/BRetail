# Deployment and operations

1. Provision PHP 8.4.1+, PostgreSQL 16+, Node 20.19+ for asset builds and an HTTPS web host. The locked dependencies require PHP 8.4.1 or newer. Deploy from a reviewed commit, retaining both lockfiles.
2. Run `composer install --no-dev --optimize-autoloader`, `npm ci`, and `npm run build` during the release build. Do not ship `.runtime`, `.env`, tests, dev database files or browser artifacts publicly.
3. Create a dedicated database and application login with only required privileges. Use a separate migration identity for schema changes. Set APP_ENV=production, APP_DEBUG=false, APP_URL to the HTTPS origin, SESSION_SECURE_COOKIE=true, SESSION_ENCRYPT=true, a unique APP_KEY, and PostgreSQL credentials. Preserve APP_KEY across releases and in secure recovery storage.
4. Serve **only `public/`**, never the repository root. Only storage and bootstrap/cache should be writable by the web process. Company logos are saved in the database and served through authenticated tenant middleware. Private files under storage/app/private are an optional compatibility cache. The logo persistence migration copies existing readable logo files into the database; run it while the previous upload storage is still available. Database backups include saved logo images.
5. Back up the existing database before `php artisan migrate --force`. Review incremental migrations first. Do not run fresh/wipe/reset commands on production. Run `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache` after environment configuration. Restart workers on deployment.
6. Run queue workers under a supervisor for future asynchronous jobs. Phase 1 uses no business queues. Use PostgreSQL-backed sessions/cache initially; add Redis when measured load warrants it. Shared deployments need shared private file storage or an authenticated object-storage adapter.
7. Check `/up`, then create an owner account and perform a smoke journey: sign in, create branch, create a cashier, verify cashier cannot read company settings, sign out. Review error logs without exposing sensitive data publicly.

## Platform subscriptions

Review [platform organization and subscription management](subscription-management.md) before deployment. Existing organizations remain active; new registrations start pending until a platform administrator activates them. After migration, grant the first named administrator with `php artisan platform:staff admin@example.com`. Configure `PLATFORM_SUPPORT_EMAIL` so organizations with inactive subscriptions know where to contact you.

## Backups and restoration

Use managed PostgreSQL point-in-time recovery plus encrypted daily dumps, and versioned private file backups. Monitor backup success and define retention and recovery objectives with the owner. Example dump: `pg_dump --format=custom --file=bretail-backup.dump DATABASE_NAME` using credentials supplied through secure tooling. Never store backups under public/.

Restore into a **new, empty recovery database** with `pg_restore --dbname=RECOVERY_DATABASE bretail-backup.dump`. Restore matching files and APP_KEY, configure an isolated app against that database, validate tenant/branch counts and sampled audit events, and run business-owner checks. Only switch production connection settings after validation. Keep the previous database until the cutover has been accepted.

## Delivery pipeline

CI runs PostgreSQL-backed foundation tests, TypeScript and the asset build. Browser tests can run after provisioning a disposable dev database and HTTP server. Deployment is intentionally separate from CI: configure hosting credentials and a reviewed release job for your chosen provider. No hosting or payment credentials are stored in source.

## Local runtime

The included local PostgreSQL cluster uses trust authentication on 127.0.0.1:55432 for disposable development data. It is not a deployment configuration. Stop it with `pg_ctl -D .runtime/pgdata stop` using PostgreSQL 16 tools. The dev script starts the server and PHP in the foreground shell without modifying Windows services.


## Phase 3 release boundary

Do not enable live POS checkout in this release. POS_TRAINING_ENABLED defaults to false and is honored only for local/testing environments. Production is locked until Phase 4 accounting is validated; a flag alone cannot unlock it. Training receipts are non-fiscal, payments are simulated/manual records, and training sales affect only the demonstration company's local inventory. CI explicitly enables training against its isolated test database. Close shifts and resolve held carts before disabling POS, and keep prior release migrations/data intact.



## Phase 4 accounting update

The Phase 3 training-only boundary above describes the earlier release. Phase 4 adds base-currency accounting, journals, periods, cashbook, supplier invoice posting, bank reconciliation and financial reports. Live POS requires explicit company activation after verified setup; existing training sales are never converted. Read [accounting-guide.md](accounting-guide.md) and [accounting-api.md](accounting-api.md). Payment-provider and fiscal integrations remain outside this release.
