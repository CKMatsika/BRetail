# Human resources guide

Enable **Human resources** from Modules after Branches is enabled. HR records are separate from team login accounts: an employee does not need a BRetail user account.

People & HR lets authorized users add employees with a unique employee number, home branch, legal and preferred names, contact details, job title and start date. The employment section records NEC, general or management group, an optional grade for graded staff, and annual leave entitlement (default 22 working days/year). Management is kept ungraded. Leave entitlement is editable per employee to match the applicable NEC agreement or contract. Changes take an effective date and append an employment history snapshot. Offboarding requires a final employment date; terminated records remain visible and cannot be reactivated through ordinary editing.

Date of birth is an optional confidential field visible and editable only with the separate `hr.personal.view` and `hr.personal.manage` permissions. It is omitted from general directory and employment-history snapshots and is used by payroll to check NSSA's age cutoff.

HR administrators can configure leave types with a paid/unpaid flag and an annual day entitlement. Add a paid type named **Annual Leave** to link the leave request and balance screens to payroll accruals. Each approved payroll run accrues the employee's annual entitlement divided by 12; partial employment months are prorated by employed calendar days. The HR balance screen reports approved payroll accrual, approved leave used and remaining days for the year. Annual leave approvals cannot exceed leave accrued to date. Draft payroll does not increase the available balance. The payslip and payroll register show the current payroll accrual and year-to-date accrued days. Other leave types retain their configured annual entitlement behavior.

BRetail uses 22 working days/year as the requested default, not a universal NEC rate. Section 14A of the Labour Act describes vacation leave as one-twelfth of qualifying service after the first year and caps paid vacation accrual at 90 days; NEC agreements and contracts can set different working-day rates and caps. Confirm the applicable agreement and adjust each employee's entitlement before relying on balances. BRetail's current leave balance is year-to-date and does not calculate cross-year carryover or cash-in-lieu. See the [Labour Act, section 14A](https://zimlii.org/akn/zw/act/1985/16/eng%402016-12-31).

Employee visibility follows the user's branch access. System administrators, business owners and HR officers can manage records across the company. General managers can view records and history. Payroll officers can view the directory. The history panel requires the separate `hr.history.view` permission.

Employees can report to one active employee in the same branch. Self-management, cross-branch manager links and reporting cycles are rejected. Before moving or offboarding a manager, reassign their direct reports. The Leave section includes a month-by-month calendar of pending and approved requests within the viewer's branch access.

Zimbabwe is the selected first payroll jurisdiction. See the [Payroll guide](payroll-guide.md) for source-backed rule setup, monthly calculation scope and posting limitations.
