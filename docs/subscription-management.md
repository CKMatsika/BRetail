# Platform organization and subscription management

Organization access is controlled from the separate Platform Administration area at `/platform/organizations`. It is not a tenant module and it does not appear in a customer's Modules settings. Access is restricted to explicitly granted platform staff.

## First platform administrator

Create or identify a BRetail user account, deploy the platform-subscription migration, then grant platform access through the application shell:

```sh
php artisan platform:staff admin@example.com
```

Sign in with that account to open the organization console. Revoke platform access with:

```sh
php artisan platform:staff admin@example.com --revoke
```

Keep the command restricted to trusted deployment operators. There is intentionally no self-service way to grant platform-admin access. These grants and revocations are recorded in the platform audit history.

## Manual subscription lifecycle

The platform console can record a plan label, status, start and end dates, trial end, payment grace end, amount, currency, billing reference and internal notes. Every change requires a reason and records the before/after values, actor and time.

Supported states are `pending`, `trialing`, `active`, `past_due`, `suspended` and `cancelled`. Trial and past-due states require their respective end dates. Active subscriptions without an end date do not expire automatically. A trial or active subscription is allowed through its end date; past-due access remains available through the grace-end date. Other states and expired subscriptions cannot access tenant web or API routes. Logout and the subscription-status page remain available. Suspending an organization never deletes or modifies its business records.

Existing organizations are migrated as active with the plan label `Existing`, so rollout does not interrupt them. New self-registrations begin as `pending` and see a subscription-status page until platform staff activates a trial or subscription. Set `PLATFORM_SUPPORT_EMAIL` to provide a contact link on that page.

This release records subscriptions manually. It does not charge customers, receive payment webhooks, issue invoices, or enforce plan-based module/branch/user quotas. Those require a selected payment provider and agreed plan definitions. Platform administrator accounts should use strong unique credentials and should be limited to named staff.
