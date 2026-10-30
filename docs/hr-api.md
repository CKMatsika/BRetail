# Human resources API

All endpoints require authentication, tenant context and an enabled HR module.

## Directory

- `GET /api/v1/hr` — returns employees and active branches visible to the caller. Requires `hr.view`.
- `POST /api/v1/hr/employees` — creates an employee. Requires `hr.create` and an accessible active branch. Required fields: `employee_number`, `branch_id`, `legal_name`, `job_title`, `start_date`, `effective_date`; preferred name, email, phone and `manager_id` are optional.
- `PATCH /api/v1/hr/employees/{id}` — updates employee details and appends an effective-dated snapshot. Requires `hr.edit`. Termination requires `status=terminated` and `end_date`. Manager links must point to an active employee in the same branch and cannot create a cycle.
- `GET /api/v1/hr/employees/{id}/history` — returns the read-only employment snapshots. Requires `hr.history.view`.
- `GET /api/v1/hr/leave-calendar?month=YYYY-MM` — returns pending and approved leave overlapping the requested month, limited to visible branches. Requires `hr.view`.
- `POST /api/v1/hr/leave-types` — creates a company leave type with an annual entitlement and paid/unpaid setting. Requires `hr.leave.manage`.
- `POST /api/v1/hr/leave-requests` — records a branch-scoped leave request with an idempotency key. Requires `hr.leave.request`.
- `PATCH /api/v1/hr/leave-requests/{id}` — approves or rejects a pending request. Requires `hr.leave.approve`; the requester cannot decide their own request.

Employee numbers are unique within a tenant. Record lookup, branch assignment and history access are restricted to the caller's branch scope. All mutations write audit events.
