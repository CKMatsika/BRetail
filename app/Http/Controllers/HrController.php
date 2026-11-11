<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\Audit;
use App\Services\PosMath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class HrController
{
    private function csvText(?string $value): string
    {
        $value = $value ?? '';
        return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'".$value : $value;
    }

    private function permit(Request $request, string $permission): void
    {
        abort_unless($request->user()->canDo($permission), 403);
    }

    private function branchIds(Request $request)
    {
        return $request->user()->role->company_wide
            ? Branch::pluck('id')
            : $request->user()->branches()->pluck('branches.id');
    }

    private function employees(Request $request)
    {
        return Employee::with('branch')->whereIn('branch_id', $this->branchIds($request));
    }

    private function activeBranchIds(Request $request)
    {
        return Branch::whereIn('id', $this->branchIds($request))->where('active', true)->pluck('id')->all();
    }

    private function annualLeaveAccruedForYear(Employee $employee, int $year): float
    {
        $runs = PayrollRun::with(['lines' => fn ($query) => $query->where('employee_id', $employee->id), 'postings'])
            ->where('status', 'approved')->whereYear('period_start', $year)
            ->whereHas('lines', fn ($query) => $query->where('employee_id', $employee->id))->orderBy('period_start')->get();
        $byMonth = [];
        foreach ($runs as $run) {
            if ($run->postings->contains('kind', 'reversal')) continue;
            $line = $run->lines->first();
            if (! $line) continue;
            $days = $line->snapshot['annual_leave_accrual_days'] ?? null;
            if ($days === null) $days = (float) $employee->annual_leave_entitlement_days / 12 * ((int) ($line->snapshot['payable_days'] ?? $run->period_start->daysInMonth) / max(1, (int) ($line->snapshot['period_days'] ?? $run->period_start->daysInMonth)));
            $byMonth[$run->period_start->format('Y-m')] ??= (float) $days;
        }
        return array_sum($byMonth);
    }

    private function payload(Request $request): array
    {
        $employees = $this->employees($request)->with('manager')->orderBy('employee_number')->get();
        $employeeIds = $employees->pluck('id');
        $leaveRequests = LeaveRequest::with(['employee', 'leaveType'])->whereIn('employee_id', $employeeIds)->latest('id')->limit(100)->get();
        $years = collect([now()->year, ...$leaveRequests->map(fn ($leave) => $leave->start_date->year)])->unique()->sort()->values();
        $types = LeaveType::where('active', true)->orderBy('name')->get();
        $approvedDays = LeaveRequest::whereIn('employee_id', $employeeIds)->where('status', 'approved')->whereBetween('start_date', [$years->first().'-01-01', $years->last().'-12-31'])->get()->groupBy(fn ($row) => $row->employee_id.':'.$row->leave_type_id.':'.$row->start_date->year)->map(fn ($rows) => (float) $rows->sum('days'));
        $annualAccrualByPeriod = [];
        $approvedPayrollRuns = PayrollRun::with(['lines' => fn ($query) => $query->whereIn('employee_id', $employeeIds), 'postings'])
            ->where('status', 'approved')->whereBetween('period_start', [$years->first().'-01-01', $years->last().'-12-31'])
            ->whereHas('lines', fn ($query) => $query->whereIn('employee_id', $employeeIds))->get();
        foreach ($approvedPayrollRuns as $run) {
            if ($run->postings->contains('kind', 'reversal')) continue;
            foreach ($run->lines as $line) {
                $days = $line->snapshot['annual_leave_accrual_days'] ?? null;
                if ($days === null) {
                    $employee = $employees->firstWhere('id', $line->employee_id);
                    if (! $employee) continue;
                    $days = (float) $employee->annual_leave_entitlement_days / 12 * ((int) ($line->snapshot['payable_days'] ?? $run->period_start->daysInMonth) / max(1, (int) ($line->snapshot['period_days'] ?? $run->period_start->daysInMonth)));
                }
                $key = $line->employee_id.':'.$run->period_start->format('Y-m');
                $annualAccrualByPeriod[$key] ??= ['year' => $run->period_start->year, 'days' => (string) $days];
            }
        }
        return [
            'employees' => $employees->map(fn (Employee $e) => [
                ...$e->only('id', 'employee_number', 'legal_name', 'preferred_name', 'email', 'phone', 'job_title', 'status', 'employee_group', 'job_grade', 'annual_leave_entitlement_days'),
                'branch_id' => $e->branch_id, 'branch' => $e->branch?->name, 'manager_id' => $e->manager_id, 'manager' => $e->manager?->preferred_name ?: $e->manager?->legal_name,
                'start_date' => $e->start_date?->toDateString(), 'end_date' => $e->end_date?->toDateString(),
                ...($request->user()->canDo('hr.personal.view') ? [
                    'date_of_birth' => $e->date_of_birth?->toDateString(), 'national_id_number' => $e->national_id_number,
                    'nssa_number' => $e->nssa_number, 'zimra_tin' => $e->zimra_tin, 'nationality' => $e->nationality,
                    'zimbabwean_citizen' => $e->zimbabwean_citizen, 'ordinarily_resident_zimbabwe' => $e->ordinarily_resident_zimbabwe, 'employment_category' => $e->employment_category,
                    'paye_non_fds_reason' => $e->paye_non_fds_reason,
                    'marital_status' => $e->marital_status, 'blind' => $e->blind, 'physically_disabled' => $e->physically_disabled,
                    'disabled_dependents_count' => $e->disabled_dependents_count,
                ] : []),
            ]),
            'branches' => Branch::whereIn('id', $this->branchIds($request))->where('active', true)->orderBy('name')->get(['id', 'name']),
            'leave_types' => $types,
            'leave_requests' => $leaveRequests->map(fn ($l) => [...$l->only('id', 'days', 'reason', 'status', 'decision_note', 'created_at'), 'start_date' => $l->start_date->toDateString(), 'end_date' => $l->end_date->toDateString(), 'employee' => $l->employee?->legal_name, 'employee_number' => $l->employee?->employee_number, 'leave_type' => $l->leaveType?->name, 'requested_by' => $l->requested_by]),
            'leave_balances' => $employees->flatMap(fn (Employee $employee) => $types->flatMap(fn ($type) => $years->map(function ($year) use ($employee, $type, $approvedDays, $annualAccrualByPeriod) {
                $used = $approvedDays[$employee->id.':'.$type->id.':'.$year] ?? 0;
                $isAnnualLeave = mb_strtolower(trim($type->name)) === 'annual leave';
                $accrued = $isAnnualLeave ? collect($annualAccrualByPeriod)->filter(fn ($row, $key) => str_starts_with($key, $employee->id.':') && $row['year'] === $year)->sum(fn ($row) => (float) $row['days']) : (float) $type->annual_entitlement;
                return ['id' => $employee->id.'-'.$type->id.'-'.$year, 'employee' => $employee->preferred_name ?: $employee->legal_name, 'employee_number' => $employee->employee_number, 'name' => $type->name, 'year' => $year, 'entitlement' => $isAnnualLeave ? (float) $employee->annual_leave_entitlement_days : (float) $type->annual_entitlement, 'accrued' => $isAnnualLeave ? $accrued : (float) $type->annual_entitlement, 'used' => $used, 'remaining' => max(0, $accrued - $used), 'paid' => $type->paid];
            }))),
            'can' => ['create' => $request->user()->canDo('hr.create'), 'edit' => $request->user()->canDo('hr.edit'), 'personal_manage' => $request->user()->canDo('hr.personal.manage'), 'payroll_view' => $request->user()->canDo('payroll.view') && TenantModule::where('key', 'payroll')->where('enabled', true)->exists(), 'payroll_enabled' => TenantModule::where('key', 'payroll')->where('enabled', true)->exists(), 'modules_configure' => $request->user()->canDo('modules.configure'), 'modules_view' => $request->user()->canDo('modules.view'), 'nssa_export' => $request->user()->canDo('hr.personal.view') && $request->user()->canDo('payroll.compensation.view') && $request->user()->canDo('payroll.runs.export') && TenantModule::where('key', 'payroll')->where('enabled', true)->exists(), 'history' => $request->user()->canDo('hr.history.view'), 'leave_manage' => $request->user()->canDo('hr.leave.manage'), 'leave_request' => $request->user()->canDo('hr.leave.request'), 'leave_approve' => $request->user()->canDo('hr.leave.approve')],
            'viewer_id' => $request->user()->id,
        ];
    }

    public function page(Request $request)
    {
        $this->permit($request, 'hr.view');
        return Inertia::render('Workspace', [
            'section' => 'hr', 'user' => $request->user()->only('id', 'name', 'email'), 'role' => $request->user()->role->name,
            'permissions' => $request->user()->role->permissions,
            'company' => ['logo_url' => Company::firstOrFail()->logo_url, 'name' => Company::value('name'), 'industry' => '', 'base_currency' => Company::value('base_currency'), 'accepted_currencies' => [], 'settings' => []],
            'branches' => Branch::whereIn('id', $this->branchIds($request))->with('warehouses')->get(),
            'modules' => TenantModule::get()->map(fn ($m) => [...$m->toArray(), ...config("modules.$m->key")]),
            'hr' => $this->payload($request),
        ]);
    }

    public function index(Request $request)
    {
        $this->permit($request, 'hr.view');
        return ['data' => $this->payload($request)];
    }

    public function exportNssaP4(Request $request)
    {
        $this->permit($request, 'hr.personal.view');
        $this->permit($request, 'payroll.compensation.view');
        $this->permit($request, 'payroll.runs.export');
        abort_unless(TenantModule::where('key', 'payroll')->where('enabled', true)->exists(), 403);
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        $runs = PayrollRun::with(['lines.employee', 'postings'])->whereIn('branch_id', $this->branchIds($request))
            ->whereDate('period_start', CarbonImmutable::createFromFormat('!Y-m', $data['period'])->toDateString())
            ->where(fn ($query) => $query->where('status', 'approved')->orWhereHas('postings', fn ($q) => $q->where('kind', 'posted')))
            ->get()->filter(fn ($run) => ! $run->postings->contains('kind', 'reversal'));
        $branchNames = Branch::whereIn('id', $runs->pluck('branch_id')->unique())->pluck('name', 'id');
        $rows = [];
        foreach ($runs as $run) foreach ($run->lines as $line) {
            $employee = $line->employee;
            if (! $employee) continue;
            $breakdown = $line->snapshot['currency_breakdown'] ?? [];
            $currencies = $run->currency === 'MIXED' ? ['USD', 'ZWG'] : [$run->currency];
            foreach ($currencies as $currency) {
                $part = $run->currency === 'MIXED' ? ($breakdown[$currency] ?? []) : [];
                $employeeNssa = $run->currency === 'MIXED' ? ($part['nssa_employee'] ?? '0') : (string) $line->nssa_employee;
                $employerNssa = $run->currency === 'MIXED' ? ($part['nssa_employer'] ?? '0') : (string) $line->nssa_employer;
                $apwcsInsurable = $run->currency === 'MIXED' ? ($part['apwcs_insurable_earnings'] ?? '0') : ($line->snapshot['prorated_monthly']['monthly_salary'] ?? $line->snapshot['monthly_salary'] ?? '0');
                $apwcsPremium = $run->currency === 'MIXED' ? ($part['apwcs_employer'] ?? '0') : (string) $line->apwcs_employer;
                if (BigDecimal::of($employeeNssa)->isZero() && BigDecimal::of($employerNssa)->isZero() && BigDecimal::of($apwcsPremium)->isZero()) continue;
                $pobsInsurable = $run->currency === 'MIXED' ? ($part['gross_cash'] ?? '0') : (string) $line->gross_salary;
                $rows[] = [$this->csvText($branchNames[$run->branch_id] ?? ''), $data['period'], $this->csvText($employee->nssa_number), $this->csvText($employee->national_id_number), $this->csvText($line->snapshot['employee'] ?? $employee->legal_name), $employee->date_of_birth?->toDateString() ?? '', $employee->start_date?->toDateString() ?? '', $employee->end_date?->toDateString() ?? '', $currency, $pobsInsurable, $employeeNssa, $employerNssa, $apwcsInsurable, $line->snapshot['apwcs_industry_code'] ?? '', $line->snapshot['apwcs_employer_rate'] ?? '', $apwcsPremium, PosMath::sum([$employeeNssa, $employerNssa, $apwcsPremium], 2), $employee->employment_category ?? ''];
            }
        }
        Audit::record('hr.nssa_p4_workpaper_exported', $data['period'], ['row_count' => count($rows)]);
        return response()->streamDownload(function () use ($rows, $data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['NSSA monthly contribution workpaper', $data['period'], 'Reconcile the POBS and APWCS calculations with the NSSA portal inspection/P4/P4A; this file is not an electronic filing. Arrears, payments, penalties and surcharges are excluded.']);
            fputcsv($out, ['Branch', 'Contribution month', 'NSSA number', 'National ID', 'Employee', 'Date of birth', 'Employment start', 'Employment end', 'Currency', 'POBS insurable earnings', 'POBS employee contribution', 'POBS employer contribution', 'APWCS insurable earnings', 'NSSA industry code', 'APWCS employer rate (%)', 'APWCS employer premium', 'Current-period NSSA total due', 'Employment category']);
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
        }, 'nssa-p4-workpaper-'.$data['period'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function leaveCalendar(Request $request)
    {
        $this->permit($request, 'hr.view');
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $start = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $data['month'])->startOfMonth();
        $end = $start->endOfMonth();
        return ['data' => LeaveRequest::with(['employee', 'leaveType'])
            ->whereIn('employee_id', $this->employees($request)->pluck('employees.id'))
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())
            ->orderBy('start_date')->get()
            ->map(fn ($leave) => ['id' => $leave->id, 'start_date' => $leave->start_date->toDateString(), 'end_date' => $leave->end_date->toDateString(), 'employee' => $leave->employee?->preferred_name ?: $leave->employee?->legal_name, 'employee_number' => $leave->employee?->employee_number, 'leave_type' => $leave->leaveType?->name, 'status' => $leave->status])];
    }

    private function validateManager(Request $request, ?int $managerId, int $branchId, ?Employee $employee = null): void
    {
        if ($managerId === null) return;
        $manager = $this->employees($request)->where('branch_id', $branchId)->where('status', 'active')->find($managerId);
        if (! $manager || ($employee && $manager->id === $employee->id)) {
            throw ValidationException::withMessages(['manager_id' => 'Choose another active employee in the same branch as manager.']);
        }
        $ancestor = $manager;
        $seen = [];
        while ($ancestor && $ancestor->manager_id && ! isset($seen[$ancestor->id])) {
            if ($employee && (int) $ancestor->manager_id === (int) $employee->id) {
                throw ValidationException::withMessages(['manager_id' => 'This manager assignment would create a reporting cycle.']);
            }
            $seen[$ancestor->id] = true;
            $ancestor = Employee::find($ancestor->manager_id);
        }
    }

    public function store(Request $request)
    {
        $this->permit($request, 'hr.create');
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:40', Rule::unique('employees')->where('tenant_id', $request->user()->tenant_id)],
            'branch_id' => ['required', 'integer', Rule::in($this->activeBranchIds($request))],
            'manager_id' => ['nullable', 'integer'],
            'legal_name' => 'required|string|max:160', 'preferred_name' => 'nullable|string|max:120', 'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:60', 'job_title' => 'required|string|max:120', 'start_date' => 'required|date',
            'employee_group' => ['nullable', Rule::in(['nec', 'general', 'management'])], 'job_grade' => 'nullable|string|max:80', 'annual_leave_entitlement_days' => 'required|numeric|min:0|max:366',
            'effective_date' => 'required|date|after_or_equal:start_date',
        ]);
        if ($request->user()->canDo('hr.personal.manage')) {
            $data = [...$data, ...$request->validate($this->personalDataRules())];
        }
        if (($data['employee_group'] ?? null) === 'management') $data['job_grade'] = null;
        $this->validateManager($request, isset($data['manager_id']) ? (int) $data['manager_id'] : null, (int) $data['branch_id']);
        $employee = DB::transaction(function () use ($data) {
            $employee = Employee::create(collect($data)->except('effective_date')->all());
            EmploymentEvent::create(['employee_id' => $employee->id, 'actor_id' => auth()->id(), 'kind' => 'created', 'effective_date' => $data['effective_date'], 'snapshot' => $employee->only('employee_number', 'branch_id', 'manager_id', 'legal_name', 'preferred_name', 'email', 'phone', 'job_title', 'status', 'start_date', 'end_date', 'employee_group', 'job_grade', 'annual_leave_entitlement_days'), 'created_at' => now()]);
            Audit::record('hr.employee.created', (string) $employee->id, ['employee_number' => $employee->employee_number, 'branch_id' => $employee->branch_id]);
            if (collect(['date_of_birth', 'national_id_number', 'nssa_number', 'zimra_tin', 'nationality', 'zimbabwean_citizen', 'ordinarily_resident_zimbabwe', 'employment_category', 'paye_non_fds_reason', 'marital_status', 'blind', 'physically_disabled', 'disabled_dependents_count'])->contains(fn ($key) => filled($employee->{$key}))) {
                Audit::record('hr.employee.personal_data_updated', (string) $employee->id, ['fields' => ['initial_compliance_profile']]);
            }
            return $employee;
        });
        if ($request->expectsJson()) return response()->json(['data' => $employee], 201);
        return back()->with('success', "Employee {$employee->employee_number} added.");
    }

    public function update(Request $request, int $id)
    {
        $this->permit($request, 'hr.edit');
        $employee = $this->employees($request)->findOrFail($id);
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($this->activeBranchIds($request))], 'manager_id' => ['nullable', 'integer'], 'legal_name' => 'required|string|max:160',
            'preferred_name' => 'nullable|string|max:120', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:60',
            'job_title' => 'required|string|max:120', 'status' => ['required', Rule::in(['active', 'on_leave', 'terminated'])],
            'employee_group' => ['sometimes', 'nullable', Rule::in(['nec', 'general', 'management'])], 'job_grade' => 'sometimes|nullable|string|max:80', 'annual_leave_entitlement_days' => 'sometimes|required|numeric|min:0|max:366',
            'start_date' => 'required|date', 'end_date' => 'nullable|date|after_or_equal:start_date', 'effective_date' => 'required|date|after_or_equal:start_date',
        ]);
        if ($request->user()->canDo('hr.personal.manage')) {
            $data = [...$data, ...$request->validate($this->personalDataRules())];
        }
        if (($data['employee_group'] ?? $employee->employee_group) === 'management') $data['job_grade'] = null;
        if ($data['status'] === 'terminated' && empty($data['end_date'])) {
            throw ValidationException::withMessages(['end_date' => 'Enter the final employment date before offboarding.']);
        }
        if ($data['status'] !== 'terminated') $data['end_date'] = null;
        if ($data['status'] === 'terminated') $data['manager_id'] = null;
        $this->validateManager($request, isset($data['manager_id']) ? (int) $data['manager_id'] : null, (int) $data['branch_id'], $employee);
        if (($employee->branch_id !== (int) $data['branch_id'] || $data['status'] === 'terminated') && $employee->directReports()->exists()) {
            throw ValidationException::withMessages(['manager_id' => 'Reassign this employee’s direct reports before changing their branch or offboarding them.']);
        }
        if ($data['status'] === 'terminated' && LeaveRequest::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->whereDate('end_date', '>', $data['end_date'])->exists()) {
            throw ValidationException::withMessages(['end_date' => 'Resolve pending or approved leave after the final employment date before offboarding.']);
        }
        DB::transaction(function () use ($employee, $data) {
            $effectiveDate = $data['effective_date'];
            unset($data['effective_date']);
            $birthDateChanged = array_key_exists('date_of_birth', $data) && $employee->date_of_birth?->toDateString() !== $data['date_of_birth'];
            $personalFields = ['date_of_birth', 'national_id_number', 'nssa_number', 'zimra_tin', 'nationality', 'zimbabwean_citizen', 'ordinarily_resident_zimbabwe', 'employment_category', 'paye_non_fds_reason', 'marital_status', 'blind', 'physically_disabled', 'disabled_dependents_count'];
                $personalChanged = collect($personalFields)->filter(fn ($field) => array_key_exists($field, $data) && $employee->{$field} != $data[$field])->values()->all();
            if ($employee->status === 'terminated' && $data['status'] !== 'terminated') {
                throw ValidationException::withMessages(['status' => 'A terminated employee cannot be reactivated. Create an authorized correction instead.']);
            }
            $employee->update($data);
            EmploymentEvent::create(['employee_id' => $employee->id, 'actor_id' => auth()->id(), 'kind' => $employee->status === 'terminated' ? 'offboarded' : 'changed', 'effective_date' => $effectiveDate, 'snapshot' => $employee->only('employee_number', 'branch_id', 'manager_id', 'legal_name', 'preferred_name', 'email', 'phone', 'job_title', 'status', 'start_date', 'end_date', 'employee_group', 'job_grade', 'annual_leave_entitlement_days'), 'created_at' => now()]);
            Audit::record('hr.employee.updated', (string) $employee->id, ['effective_date' => $effectiveDate, 'status' => $employee->status]);
            if ($personalChanged) Audit::record('hr.employee.personal_data_updated', (string) $employee->id, ['fields' => $personalChanged, 'date_of_birth_changed' => $birthDateChanged]);
        });
        if ($request->expectsJson()) {
            $fresh = $employee->fresh();
            if (! $request->user()->canDo('hr.personal.view')) $fresh->makeHidden(['date_of_birth', 'national_id_number', 'nssa_number', 'zimra_tin', 'nationality', 'zimbabwean_citizen', 'ordinarily_resident_zimbabwe', 'employment_category', 'paye_non_fds_reason', 'marital_status', 'blind', 'physically_disabled', 'disabled_dependents_count']);
            return response()->json(['data' => $fresh]);
        }
        return back()->with('success', 'Employee details saved with an effective-dated history record.');
    }

    private function personalDataRules(): array
    {
        return [
            'date_of_birth' => 'nullable|date|before:today', 'national_id_number' => 'nullable|string|max:80',
            'nssa_number' => 'nullable|string|max:80', 'zimra_tin' => 'nullable|string|max:80', 'nationality' => 'nullable|string|max:80',
            'zimbabwean_citizen' => 'nullable|boolean', 'ordinarily_resident_zimbabwe' => 'nullable|boolean',
            'employment_category' => ['nullable', Rule::in(['standard', 'domestic_worker', 'diplomatic_staff', 'casual_worker', 'civil_servant'])],
            'paye_non_fds_reason' => ['nullable', Rule::in(['concurrent_employment', 'part_time_with_full_time_job', 'pension_income', 'other_non_fds_case'])],
            'marital_status' => ['nullable', Rule::in(['single', 'married', 'widowed', 'divorced'])],
            'blind' => 'nullable|boolean', 'physically_disabled' => 'nullable|boolean', 'disabled_dependents_count' => 'nullable|integer|min:0|max:30',
        ];
    }

    public function history(Request $request, int $id)
    {
        $this->permit($request, 'hr.history.view');
        $employee = $this->employees($request)->findOrFail($id);
        return ['data' => $employee->events()->get()->map(fn ($event) => $event->only('id', 'kind', 'effective_date', 'snapshot', 'created_at'))];
    }

    public function storeLeaveType(Request $request)
    {
        $this->permit($request, 'hr.leave.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('leave_types')->where('tenant_id', $request->user()->tenant_id)], 'annual_entitlement' => 'required|numeric|min:0|max:366', 'paid' => 'required|boolean']);
        $type = LeaveType::create($data);
        Audit::record('hr.leave_type.created', (string) $type->id, $data);
        return $request->expectsJson() ? response()->json(['data' => $type], 201) : back()->with('success', 'Leave type added.');
    }

    public function requestLeave(Request $request)
    {
        $this->permit($request, 'hr.leave.request');
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::in($this->employees($request)->pluck('employees.id')->all())],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('tenant_id', $request->user()->tenant_id)->where('active', true)],
            'request_key' => 'required|uuid', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date',
            'days' => 'required|numeric|min:0.01|max:366', 'reason' => 'required|string|max:2000',
        ]);
        if (substr($data['start_date'], 0, 4) !== substr($data['end_date'], 0, 4)) {
            throw ValidationException::withMessages(['end_date' => 'A leave request must fall within one calendar year.']);
        }
        $employee = $this->employees($request)->findOrFail($data['employee_id']);
        if ($employee->status !== 'active' || $employee->start_date->gt($data['start_date'])) {
            throw ValidationException::withMessages(['employee_id' => 'Choose an active employee whose start date is on or before the leave.']);
        }
        $hashData = collect($data)->except('request_key')->sortKeys()->all();
        $hash = hash('sha256', json_encode($hashData, JSON_THROW_ON_ERROR));
        $leave = DB::transaction(function () use ($data, $hash) {
            Tenant::whereKey(app(\App\Services\TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $existing = LeaveRequest::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) throw ValidationException::withMessages(['request_key' => 'This request key was already used for different leave details.']);
                return $existing;
            }
            $overlap = LeaveRequest::where('employee_id', $data['employee_id'])->whereIn('status', ['pending', 'approved'])->whereDate('start_date', '<=', $data['end_date'])->whereDate('end_date', '>=', $data['start_date'])->exists();
            if ($overlap) throw ValidationException::withMessages(['start_date' => 'This employee already has pending or approved leave overlapping those dates.']);
            $leave = LeaveRequest::create([...collect($data)->except('request_key')->all(), 'request_key' => $data['request_key'], 'payload_hash' => $hash, 'requested_by' => auth()->id(), 'status' => 'pending']);
            Audit::record('hr.leave.requested', (string) $leave->id, ['employee_id' => $leave->employee_id, 'leave_type_id' => $leave->leave_type_id, 'days' => $leave->days]);
            return $leave;
        });
        return $request->expectsJson() ? response()->json(['data' => $leave], 201) : back()->with('success', 'Leave request submitted for approval.');
    }

    public function decideLeave(Request $request, int $id)
    {
        $this->permit($request, 'hr.leave.approve');
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'decision_note' => 'nullable|string|max:2000']);
        $leave = DB::transaction(function () use ($request, $id, $data) {
            Tenant::whereKey(app(\App\Services\TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $leave = LeaveRequest::lockForUpdate()->findOrFail($id);
            abort_unless($this->employees($request)->whereKey($leave->employee_id)->exists(), 404);
            if ($leave->status !== 'pending') throw ValidationException::withMessages(['decision' => 'This request has already been decided.']);
            if ($leave->requested_by === $request->user()->id) throw ValidationException::withMessages(['decision' => 'The requester cannot approve or reject their own leave request.']);
            if ($data['decision'] === 'approved') {
                $employee = Employee::whereKey($leave->employee_id)->lockForUpdate()->firstOrFail();
                $type = LeaveType::findOrFail($leave->leave_type_id);
                $used = (float) LeaveRequest::where('employee_id', $leave->employee_id)->where('leave_type_id', $type->id)->where('status', 'approved')->whereYear('start_date', $leave->start_date->year)->sum('days');
                $limit = mb_strtolower(trim($type->name)) === 'annual leave' ? $this->annualLeaveAccruedForYear($employee, $leave->start_date->year) : (float) $type->annual_entitlement;
                if ($used + (float) $leave->days > $limit) throw ValidationException::withMessages(['decision' => 'Approving this request would exceed the employee’s accrued leave balance.']);
            }
            $leave->update(['status' => $data['decision'], 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['decision_note'] ?? null]);
            Audit::record('hr.leave.'.$data['decision'], (string) $leave->id, ['employee_id' => $leave->employee_id, 'days' => $leave->days]);
            return $leave;
        });
        return $request->expectsJson() ? response()->json(['data' => $leave]) : back()->with('success', 'Leave request '.$leave->status.'.');
    }
}
