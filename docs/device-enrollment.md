# Device enrollment API and operations

Device management is available to roles with `devices.manage` while the Access module is enabled. The Devices page lists only records in branches available to the current manager.

## Enroll

`POST /devices` (authenticated web session + CSRF)

```json
{
  "device_uuid": "client-generated UUID",
  "branch_id": 12,
  "name": "Front counter tablet",
  "platform": "android"
}
```

The response includes a randomly generated Sanctum credential once. Store it securely; do not put it in a shared document or screenshot. The database stores the token hash through Sanctum. Device enrollment and revocation events are audited without recording the credential. BRetail does not have a first-party sync client yet, so do not distribute credentials for normal retail work.

## Heartbeat and scope

`POST /api/v1/devices/heartbeat` with `Authorization: Bearer <credential>` updates the last-seen timestamp. The credential's only ability is `device:heartbeat`; middleware rejects it from all existing API endpoints. It does not provide a data snapshot or permit transactions.

## Revoke

`POST /devices/{id}/revoke` deletes the associated Sanctum token and records the revocation. Branch managers can manage devices assigned to branches they can access. Company-wide device managers can manage devices across the tenant.

Offline sync payloads, local private-data storage and offline POS are not part of this endpoint set yet. Keep using the ordinary authenticated app for business operations.
