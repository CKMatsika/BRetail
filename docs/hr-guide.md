# Human resources guide

Enable **Human resources** from Modules after Branches is enabled. HR records are separate from team login accounts: an employee does not need a BRetail user account.

People & HR lets authorized users add employees with a unique employee number, home branch, legal and preferred names, contact details, job title and start date. Changes take an effective date and append an employment history snapshot. Offboarding requires a final employment date; terminated records remain visible and cannot be reactivated through ordinary editing.

Date of birth is an optional confidential field visible and editable only with the separate `hr.personal.view` and `hr.personal.manage` permissions. It is omitted from general directory and employment-history snapshots and is used by payroll to check NSSA's age cutoff.

HR administrators can configure leave types with a paid/unpaid flag and an annual day entitlement. Entitlements are company policy settings, not assumed statutory values. Requests record a date range, requested workdays and reason, then require a different authorized user to approve or reject. The system prevents overlapping pending/approved requests, rejects approval beyond the employee's configured balance, and serializes competing decisions. Requests must stay within one calendar year; enter the workday count according to the business work schedule.

Employee visibility follows the user's branch access. System administrators, business owners and HR officers can manage records across the company. General managers can view records and history. Payroll officers can view the directory. The history panel requires the separate `hr.history.view` permission.

Employees can report to one active employee in the same branch. Self-management, cross-branch manager links and reporting cycles are rejected. Before moving or offboarding a manager, reassign their direct reports. The Leave section includes a month-by-month calendar of pending and approved requests within the viewer's branch access.

Zimbabwe is the selected first payroll jurisdiction. See the [Payroll guide](payroll-guide.md) for source-backed rule setup, monthly calculation scope and posting limitations.
