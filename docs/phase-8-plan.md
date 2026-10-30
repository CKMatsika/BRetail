# Phase 8 — Mobile, offline and loyalty

Phase 8 follows the completed Zimbabwe HR/payroll work in Phase 7. The starting scope is the project architecture item: installable PWA, controlled offline operation, integrations and mobile journeys. Loyalty is included as a later workstream and must use a tenant-scoped append-only points ledger.

## Delivery sequence

1. **Installable PWA shell — implemented.** Add a web app manifest, brand icon, service worker, static app asset cache, connectivity notice and a safe offline fallback page. Do not cache authenticated HTML, API responses, employee data, prices, stock or transactions. Business workflows remain online-only.
2. **Mobile journeys — in progress.** The POS now offers camera barcode scanning where the browser supports `BarcodeDetector`, requests the rear camera only after the cashier taps Scan, stops the camera when the scan dialog closes, and falls back to hardware or typed barcode search. Review remaining touch targets, navigation, receipts and accessible responsive layouts across POS, inventory, transfers and administration.
3. **Device enrollment and control — first slice implemented.** Authorized managers can register devices to an assigned branch, issue a one-time Sanctum credential, view heartbeat status and revoke access. The credential has only the `device:heartbeat` ability, and API middleware blocks it from all existing business endpoints. Device enrollment/revocation is audited. Shared-device logout and lost-device procedures remain before any private offline snapshot is enabled.
4. **Controlled sync foundation.** Add a scoped, expiring server-issued sync snapshot and durable operation queue with request UUIDs, payload hashes, retry states, conflict review and audit trail. Enforce existing stock, quote, price, shift, drawer and period validations when operations reach the server. A retry must not create a second transaction.
5. **Offline POS — separate guarded rollout.** Do not enable offline checkout until the business accepts an explicit stock reservation or stale-stock policy, offline tender/change limits, receipt numbering, tax/price validity windows, device custody, and sync conflict and refund procedures. Offline work must never journal or claim fiscal completion on-device.
6. **Loyalty.** Add consent and customer identity rules, earning/redemption policy configuration, an append-only points ledger, idempotent sale/return postings, expiry/reversal treatment, branch and currency behavior, customer privacy controls, reports and mobile journeys.
7. **Acceptance and operations.** Validate install/update/uninstall, offline/online transitions, logout and user/tenant switching, revoked devices, storage pressure, concurrent sync, duplicate requests, stale prices/stock, lost connectivity during checkout, and loyalty reversals. Require PostgreSQL concurrency checks and human review before production activation.

## Current implementation status

The installable shell is implemented. The service worker caches only the unauthenticated offline page, manifest, icon and versioned Vite assets. Navigations use the network and fall back to a static offline page; authenticated pages, APIs and business data are not cached. The interface warns users that transactions require a live server connection. Device records are tenant-owned and branch-scoped; one-time credentials can only call the heartbeat endpoint and are revocable from the Devices workspace page.

See [device enrollment operations and API](device-enrollment.md) for the credential scope and revocation behavior.

Durable sync queues, private offline snapshots, offline checkout, loyalty and device integrations are not implemented yet. POS checkout remains online-only and continues to revalidate quote freshness and stock on the server. Phase 8 is in progress; do not enable offline transactions by changing a client-side flag.
