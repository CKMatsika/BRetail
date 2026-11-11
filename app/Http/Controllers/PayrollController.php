<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\ForeignAccount;
use App\Models\PayrollRuleSet;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\PayrollRunPosting;
use App\Models\PostingLink;
use App\Models\PayrollPayslipDelivery;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\Audit;
use App\Services\AccountingAccess;
use App\Services\AccountingLedger;
use App\Services\TenantContext;
use App\Services\ZimbabwePayrollCalculator;
use App\Services\PosMath;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class PayrollController
{
    private function permit(Request $request, string $permission): void
    {
        abort_unless($request->user()->canDo($permission), 403);
    }

    private function branchIds(Request $request)
    {
        return $request->user()->role->company_wide ? Branch::pluck('id') : $request->user()->branches()->pluck('branches.id');
    }

    private function nssaPobsStatus(Employee $employee, bool $confirmed, CarbonImmutable $asOf): array
    {
        if (! $confirmed) return ['eligible' => false, 'basis' => 'coverage_not_confirmed'];
        if (! $employee->date_of_birth) throw ValidationException::withMessages(['employees' => 'Record a confidential date of birth before confirming NSSA coverage for '.$employee->employee_number.'.']);
        $age = CarbonImmutable::parse($employee->date_of_birth)->diffInYears($asOf);
        if ($age >= 65) return ['eligible' => false, 'basis' => 'age_65_exemption'];
        if ($age <= 16) throw ValidationException::withMessages(['employees' => 'NSSA POBS coverage starts after age 16. Review the confirmed eligibility for '.$employee->employee_number.'.']);
        if ($employee->employment_category === 'domestic_worker') throw ValidationException::withMessages(['employees' => 'NSSA POBS exempts domestic workers. Review the coverage confirmation for '.$employee->employee_number.'.']);
        if ($employee->employment_category === 'diplomatic_staff' && $employee->zimbabwean_citizen === null) throw ValidationException::withMessages(['employees' => 'Record citizenship before confirming NSSA coverage for diplomatic staff member '.$employee->employee_number.'.']);
        if ($employee->employment_category === 'diplomatic_staff' && $employee->zimbabwean_citizen === false) throw ValidationException::withMessages(['employees' => 'Non-Zimbabwean diplomatic staff are exempt from NSSA POBS. Review the coverage confirmation for '.$employee->employee_number.'.']);
        if ($employee->zimbabwean_citizen !== true && $employee->ordinarily_resident_zimbabwe !== true && ($employee->zimbabwean_citizen === null || $employee->ordinarily_resident_zimbabwe === null)) {
            throw ValidationException::withMessages(['employees' => 'Record both citizenship and ordinary residence status before confirming NSSA POBS coverage for '.$employee->employee_number.'.']);
        }
        if ($employee->zimbabwean_citizen !== true && $employee->ordinarily_resident_zimbabwe !== true) {
            throw ValidationException::withMessages(['employees' => 'NSSA POBS exempts non-Zimbabwean employees who are not ordinarily resident in Zimbabwe. Review the coverage confirmation for '.$employee->employee_number.'.']);
        }
        return ['eligible' => true, 'basis' => 'coverage_confirmed_under_65_and_in_scope'];
    }

    private function apwcsStatus(Employee $employee, CarbonImmutable $asOf): array
    {
        if ($employee->employment_category === 'civil_servant') return ['eligible' => false, 'basis' => 'civil_servant_exemption'];
        if (! $employee->date_of_birth) throw ValidationException::withMessages(['employees' => 'Record a confidential date of birth before payroll can check NSSA APWCS age coverage for '.$employee->employee_number.'.']);
        $age = CarbonImmutable::parse($employee->date_of_birth)->diffInYears($asOf);
        if ($age <= 16) return ['eligible' => false, 'basis' => 'under_17'];
        return ['eligible' => true, 'basis' => 'apwcs_over_16_non_civil_servant'];
    }

    private function annualLeaveAccrual(Employee $employee, int $payableDays, int $periodDays, CarbonImmutable $periodStart): array
    {
        $annualDays = BigDecimal::of((string) ($employee->annual_leave_entitlement_days ?? '22'));
        $monthlyRate = $annualDays->dividedBy('12', 8, \Brick\Math\RoundingMode::HalfUp);
        $calculatedThisMonth = $monthlyRate->multipliedBy((string) $payableDays)->dividedBy((string) max(1, $periodDays), 8, \Brick\Math\RoundingMode::HalfUp)->toScale(4, \Brick\Math\RoundingMode::HalfUp);
        $runs = PayrollRun::with(['lines' => fn ($query) => $query->where('employee_id', $employee->id), 'postings'])
            ->where('status', 'approved')->whereYear('period_start', $periodStart->year)->whereDate('period_start', '<=', $periodStart->endOfMonth()->toDateString())
            ->whereHas('lines', fn ($query) => $query->where('employee_id', $employee->id))->orderBy('period_start')->get();
        $priorByMonth = [];
        foreach ($runs as $run) {
            if ($run->postings->contains('kind', 'reversal')) continue;
            $line = $run->lines->first();
            if (! $line) continue;
            $days = $line->snapshot['annual_leave_accrual_days'] ?? null;
            if ($days === null) {
                $days = BigDecimal::of((string) ($employee->annual_leave_entitlement_days ?? '22'))->dividedBy('12', 8, \Brick\Math\RoundingMode::HalfUp)
                    ->multipliedBy((string) ($line->snapshot['payable_days'] ?? $run->period_start->daysInMonth))
                    ->dividedBy((string) max(1, $line->snapshot['period_days'] ?? $run->period_start->daysInMonth), 8, \Brick\Math\RoundingMode::HalfUp)->toScale(4, \Brick\Math\RoundingMode::HalfUp);
            }
            $priorByMonth[$run->period_start->format('Y-m')] ??= (string) $days;
        }
        $currentMonthKey = $periodStart->format('Y-m');
        $thisMonth = isset($priorByMonth[$currentMonthKey]) ? BigDecimal::zero()->toScale(4) : $calculatedThisMonth;
        $cumulative = BigDecimal::of(PosMath::sum([...array_values($priorByMonth), (string) $thisMonth], 4));
        return ['annual_leave_entitlement_days' => (string) $annualDays->toScale(2), 'annual_leave_accrual_days' => (string) $thisMonth, 'annual_leave_cumulative_accrued_days' => (string) $cumulative];
    }

    private function taxYearContext(Employee $employee, CarbonImmutable $periodStart, string $currency): array
    {
        $yearStart = $periodStart->startOfYear();
        $method = (($employee->start_date && $employee->start_date->gt($yearStart))
            || ($employee->end_date && $employee->end_date->year === $periodStart->year)
            || filled($employee->paye_non_fds_reason)) ? 'NON_FDS' : 'FDS_AVERAGING';
        $priorRuns = PayrollRun::with(['lines', 'postings'])
            ->whereYear('period_start', $periodStart->year)->whereDate('period_start', '<', $periodStart->toDateString())
            ->whereHas('lines', fn ($query) => $query->where('employee_id', $employee->id))
            ->orderBy('period_start')->get()->filter(fn ($run) => ($run->status === 'approved' || $run->postings->contains('kind', 'posted')) && ! $run->postings->contains('kind', 'reversal'));
        $priorLines = collect();
        $priorMonthKeys = [];
        foreach ($priorRuns as $run) {
            if (! in_array($run->currency, [$currency, 'USD', 'MIXED'], true) || ($currency === 'ZWG' && $run->currency !== 'ZWG')) {
                throw ValidationException::withMessages(['currency' => 'FDS year-to-date earnings must use one consistent tax currency. Redraft prior periods consistently before calculating '.$employee->employee_number.'.']);
            }
            $line = $run->lines->firstWhere('employee_id', $employee->id);
            if (! $line) continue;
            if (! isset($line->snapshot['tax_method'])) {
                throw ValidationException::withMessages(['period' => 'Prior payroll history for '.$employee->employee_number.' predates the FDS/Non-FDS method snapshot. Reconcile and correct that history before calculating this tax year.']);
            }
            if ($line->snapshot['tax_method'] !== $method) {
                throw ValidationException::withMessages(['employees' => 'This employee changed between FDS and Non-FDS during the tax year. Correct prior period calculations before continuing.']);
            }
            $monthKey = $run->period_start->format('Y-m');
            if (in_array($monthKey, $priorMonthKeys, true)) {
                throw ValidationException::withMessages(['period' => 'More than one approved payroll run includes '.$employee->employee_number.' in '.$monthKey.'. Reconcile the duplicate period before calculating year-to-date PAYE.']);
            }
            $priorMonthKeys[] = $monthKey;
            $priorLines->push($line);
        }
        $expectedPriorMonths = $periodStart->month > 1
            ? collect(range(1, $periodStart->month - 1))->map(fn ($month) => $periodStart->format('Y').'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT))->all()
            : [];
        if ($method === 'FDS_AVERAGING' && array_values(array_unique($priorMonthKeys)) !== $expectedPriorMonths) {
            throw ValidationException::withMessages(['period' => 'FDS averaging needs an approved, non-reversed payroll run for every prior month of the tax year. Import or correct the missing payroll history for '.$employee->employee_number.'.']);
        }
        $priorTaxable = PosMath::sum($priorLines->map(fn ($line) => $line->taxable_income)->all() ?: ['0'], 2);
        $priorCredits = PosMath::sum($priorLines->map(fn ($line) => $line->snapshot['tax_credit_claimed'] ?? $line->snapshot['tax_credit'] ?? '0')->all() ?: ['0'], 2);
        $priorPaye = PosMath::sum($priorLines->map(fn ($line) => $line->paye)->all() ?: ['0'], 2);
        $priorLevy = PosMath::sum($priorLines->map(fn ($line) => $line->aids_levy)->all() ?: ['0'], 2);
        return ['method' => $method, 'prior_taxable' => $priorTaxable, 'prior_credits' => $priorCredits, 'prior_paye' => $priorPaye, 'prior_levy' => $priorLevy, 'prior_periods' => $priorLines->count()];
    }

    private function fdsAverageTax(ZimbabwePayrollCalculator $calculator, PayrollRuleSet $rule, string $employeeNumber, CarbonImmutable $periodStart, string $currentTaxable, string $currentCredits, array $history): array
    {
        $period = $periodStart->month;
        $periodDays = $periodStart->daysInMonth;
        $calcAtAverage = function (string $totalTaxable, int $periodCount) use ($calculator, $rule, $employeeNumber, $periodDays) {
            if ($periodCount < 1) return BigDecimal::zero();
            $average = BigDecimal::of($totalTaxable)->dividedBy((string) $periodCount, 8, \Brick\Math\RoundingMode::HalfUp)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
            $result = $calculator->calculate($rule, ['monthly_salary' => (string) $average, 'cash_earnings' => '0', 'monthly_taxable_benefits' => '0', 'monthly_allowable_deductions' => '0', 'monthly_tax_credits' => '0', 'monthly_cash_deductions' => '0', 'payable_days' => $periodDays, 'period_days' => $periodDays, 'daily_tax_basis' => false, 'nssa_eligible' => false, 'employee_number' => $employeeNumber]);
            return BigDecimal::of($result['paye_before_credits']);
        };
        $priorTaxable = BigDecimal::of($history['prior_taxable']);
        $priorCredits = BigDecimal::of($history['prior_credits']);
        $currentTaxableValue = BigDecimal::of($currentTaxable);
        $currentCreditsValue = BigDecimal::of($currentCredits);
        $cumulativeBasicTax = $calcAtAverage((string) $priorTaxable->plus($currentTaxableValue), $period)->multipliedBy((string) $period)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        $previousBasicTax = $period === 1 ? BigDecimal::zero() : $calcAtAverage((string) $priorTaxable, $period - 1)->multipliedBy((string) ($period - 1))->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        $cumulativeCreditApplied = $priorCredits->plus($currentCreditsValue);
        if ($cumulativeCreditApplied->isGreaterThan($cumulativeBasicTax)) $cumulativeCreditApplied = $cumulativeBasicTax;
        $cumulativeCreditApplied = $cumulativeCreditApplied->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        $previousCreditApplied = $priorCredits;
        if ($previousCreditApplied->isGreaterThan($previousBasicTax)) $previousCreditApplied = $previousBasicTax;
        $previousCreditApplied = $previousCreditApplied->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        $cumulativePaye = $cumulativeBasicTax->minus($cumulativeCreditApplied)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        $cumulativeLevy = $cumulativePaye->multipliedBy((string) $rule->aids_levy_rate)->dividedBy('100', 2, \Brick\Math\RoundingMode::HalfUp)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        $paye = $cumulativePaye->minus((string) $history['prior_paye'])->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        $levy = $cumulativeLevy->minus((string) $history['prior_levy'])->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        return ['paye_before_credits' => (string) $cumulativeBasicTax->minus($previousBasicTax), 'tax_credit' => (string) $cumulativeCreditApplied->minus($previousCreditApplied), 'paye' => (string) $paye, 'aids_levy' => (string) $levy, 'cumulative_paye_before_credits' => (string) $cumulativeBasicTax, 'cumulative_tax_credit_applied' => (string) $cumulativeCreditApplied];
    }

    private function employeeQuery(Request $request)
    {
        return Employee::whereIn('branch_id', $this->branchIds($request));
    }

    private function payslipEmailReady(): bool
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        if (! config('payroll.payslip_email_enabled') || in_array($mailer, ['log', 'array', 'failover'], true)) return false;
        if (! filter_var($from, FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($from), '@example.com')) return false;
        if ($mailer === 'smtp' && ! filled(config('mail.mailers.smtp.host'))) return false;
        return true;
    }

    private function templates(): array
    {
        $source = 'ZIMRA PAYE tax tables for 1 January to 31 December 2026';
        return [
            'USD' => ['effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'paye_bands' => [
                ['up_to' => 100, 'rate' => 0, 'deduct' => 0], ['up_to' => 300, 'rate' => 20, 'deduct' => 20], ['up_to' => 1000, 'rate' => 25, 'deduct' => 35],
                ['up_to' => 2000, 'rate' => 30, 'deduct' => 85], ['up_to' => 3000, 'rate' => 35, 'deduct' => 185], ['up_to' => null, 'rate' => 40, 'deduct' => 335],
            ], 'daily_paye_bands' => null, 'proration_basis' => 'none', 'proration_source_name' => null, 'proration_source_url' => null, 'aids_levy_rate' => 3, 'nssa_employee_rate' => null, 'nssa_employer_rate' => null, 'nssa_ceiling' => null, 'nssa_ceiling_currency' => null, 'apwcs_employer_rate' => null, 'apwcs_industry_code' => null, 'apwcs_source_name' => null, 'apwcs_source_url' => null, 'source_name' => $source, 'source_url' => 'https://www.zimra.co.zw/?download=4523:usd-paye-tax-tables-2026', 'nssa_source_name' => null, 'nssa_source_url' => null],
            'ZWG' => ['effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'paye_bands' => [
                ['up_to' => 2800, 'rate' => 0, 'deduct' => 0], ['up_to' => 8400, 'rate' => 20, 'deduct' => 560], ['up_to' => 28000, 'rate' => 25, 'deduct' => 980],
                ['up_to' => 56000, 'rate' => 30, 'deduct' => 2380], ['up_to' => 84000, 'rate' => 35, 'deduct' => 5180], ['up_to' => null, 'rate' => 40, 'deduct' => 9380],
            ], 'daily_paye_bands' => [
                ['up_to' => 92.05, 'rate' => 0, 'deduct' => 0], ['up_to' => 276.16, 'rate' => 20, 'deduct' => 18.41], ['up_to' => 920.55, 'rate' => 25, 'deduct' => 32.22],
                ['up_to' => 1841.10, 'rate' => 30, 'deduct' => 78.25], ['up_to' => 2761.64, 'rate' => 35, 'deduct' => 170.30], ['up_to' => null, 'rate' => 40, 'deduct' => 308.38],
            ], 'proration_basis' => 'calendar_days', 'proration_source_name' => $source.' · ZWG daily table', 'proration_source_url' => 'https://www.zimra.co.zw/domestic-taxes/tax-tables?download=4522:zwg-paye-tax-tables-2026', 'aids_levy_rate' => 3, 'nssa_employee_rate' => null, 'nssa_employer_rate' => null, 'nssa_ceiling' => null, 'nssa_ceiling_currency' => null, 'apwcs_employer_rate' => null, 'apwcs_industry_code' => null, 'apwcs_source_name' => null, 'apwcs_source_url' => null, 'source_name' => $source, 'source_url' => 'https://www.zimra.co.zw/domestic-taxes/tax-tables?download=4522:zwg-paye-tax-tables-2026', 'nssa_source_name' => null, 'nssa_source_url' => null],
        ];
    }

    private function data(Request $request): array
    {
        $company = Company::firstOrFail();
        $visibleEmployees = $this->employeeQuery($request)->orderBy('employee_number')->get();
        $employees = $visibleEmployees->where('status', 'active')->values();
        $compensations = $request->user()->canDo('payroll.compensation.view')
            ? EmployeeCompensation::whereIn('employee_id', $visibleEmployees->pluck('id'))->with('employee')->latest('effective_from')->get()->map(fn ($c) => [...$c->only('id', 'currency', 'monthly_salary', 'monthly_recurring_cash_earnings', 'monthly_taxable_benefits', 'monthly_allowable_deductions', 'monthly_tax_credits', 'monthly_cash_deductions', 'nssa_eligible', 'effective_from', 'effective_to'), 'employee' => $c->employee?->legal_name, 'employee_number' => $c->employee?->employee_number])
            : collect();
        $foreignBankAccounts = collect();
        if ($request->user()->canDo('payroll.runs.settle') && TenantModule::where('key', 'currencies')->where('enabled', true)->exists()) {
            $foreignAccounts = ForeignAccount::where('kind', 'bank')->whereIn('branch_id', $this->branchIds($request))->get();
            $currencyCodes = Currency::whereIn('id', $foreignAccounts->pluck('currency_id'))->pluck('code', 'id');
            $activeAccountIds = \App\Models\ChartAccount::where('active', true)->whereIn('id', $foreignAccounts->pluck('account_id'))->pluck('id');
            $foreignBankAccounts = $foreignAccounts->filter(fn ($account) => $activeAccountIds->contains($account->account_id) && ($currencyCodes[$account->currency_id] ?? null) !== $company->base_currency)->map(fn ($account) => ['id' => $account->id, 'branch_id' => $account->branch_id, 'name' => $account->name, 'currency' => $currencyCodes[$account->currency_id] ?? '']);
        }

        $payslipEmailReady = $this->payslipEmailReady();
        $runs = $request->user()->canDo('payroll.runs.view')
            ? PayrollRun::with(['lines.employee', 'lines.payslipDeliveries', 'postings', 'ruleSet'])->whereIn('branch_id', $this->branchIds($request))->latest('period_start')->limit(24)->get()->map(function ($run) use ($request, $payslipEmailReady) {
                $hasPosted = $run->postings->contains('kind', 'posted');
                $hasReversal = $run->postings->contains('kind', 'reversal');
                $hasSettlement = $run->postings->contains(fn ($posting) => in_array($posting->kind, ['settlement', 'settle_usd', 'settle_zwg'], true));
                $hasNetPay = BigDecimal::of((string) ($run->totals['net_pay'] ?? '0'))->isPositive();
                return [...$run->only('id', 'period_start', 'period_end', 'payment_date', 'branch_id', 'currency', 'totals'), 'status' => $hasReversal ? 'reversed' : ($hasPosted ? 'posted' : $run->status), 'branch' => Branch::find($run->branch_id)?->name, 'rule_source' => $run->ruleSet?->source_name, 'rule_effective_from' => $run->ruleSet?->effective_from?->toDateString(),
                    'can_approve' => $request->user()->canDo('payroll.runs.approve') && $run->status === 'draft' && $run->created_by !== $request->user()->id,
                    'can_post' => $request->user()->canDo('payroll.runs.post') && $run->status === 'approved' && ! $hasPosted,
                    'can_reverse' => $request->user()->canDo('payroll.runs.reverse') && $hasPosted && ! $hasReversal && ! $hasSettlement,
                    'can_settle' => $request->user()->canDo('payroll.runs.settle') && $hasPosted && ! $hasReversal && ! $hasSettlement && $hasNetPay,
                    'postings' => $run->postings->map(fn ($p) => $p->only('id', 'journal_id', 'book', 'kind', 'date', 'reason')),
                    'lines' => $request->user()->canDo('payroll.compensation.view') ? $run->lines->map(function ($line) use ($request, $run, $hasReversal, $payslipEmailReady) {
                        $lastDelivery = $line->payslipDeliveries->sortByDesc('id')->first();
                        return [...$line->only('id', 'gross_salary', 'taxable_income', 'paye', 'aids_levy', 'nssa_employee', 'nssa_employer', 'apwcs_employer', 'net_pay'), ...$line->snapshot,
                            'can_email_payslip' => $payslipEmailReady && $request->user()->canDo('payroll.payslips.email') && $run->status === 'approved' && ! $hasReversal && filled($line->employee?->email),
                            'payslip_email_status' => $lastDelivery?->status,
                            'payslip_email_sent_at' => $lastDelivery?->sent_at?->toIso8601String(),
                        ];
                    }) : collect()];
            })
            : collect();
        return [
            'rules' => PayrollRuleSet::orderByDesc('effective_from')->orderByDesc('id')->get()->map(fn ($r) => [...$r->only('id', 'jurisdiction', 'currency', 'frequency', 'effective_from', 'effective_to', 'paye_bands', 'daily_paye_bands', 'proration_basis', 'proration_source_name', 'proration_source_url', 'aids_levy_rate', 'nssa_employee_rate', 'nssa_employer_rate', 'nssa_ceiling', 'nssa_ceiling_currency', 'apwcs_employer_rate', 'apwcs_industry_code', 'apwcs_source_name', 'apwcs_source_url', 'source_name', 'source_url', 'nssa_source_name', 'nssa_source_url', 'status', 'approved_at'), 'can_approve' => $request->user()->canDo('payroll.rules.approve') && $r->created_by !== $request->user()->id]),
            'employees' => $employees->map(fn ($e) => [...$e->only('id', 'employee_number', 'legal_name', 'job_title'), 'branch_id' => $e->branch_id]),
            'compensations' => $compensations,
            'runs' => $runs,
            'bank_accounts' => $request->user()->canDo('payroll.runs.settle') ? BankAccount::where('active', true)->where('currency', $company->base_currency)->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $this->branchIds($request)))->orderBy('name')->get(['id', 'branch_id', 'name', 'currency']) : collect(),
            'foreign_bank_accounts' => $foreignBankAccounts,
            'base_currency' => $company->base_currency,
            'templates' => $this->templates(),
            'payslip_email_ready' => $payslipEmailReady,
            'branches' => Branch::whereIn('id', $this->branchIds($request))->where('active', true)->orderBy('name')->get(['id', 'name']),
            'currencies' => ['USD', 'ZWG'],
            'can' => ['rules_manage' => $request->user()->canDo('payroll.rules.manage'), 'rules_approve' => $request->user()->canDo('payroll.rules.approve'), 'compensation_view' => $request->user()->canDo('payroll.compensation.view'), 'compensation_manage' => $request->user()->canDo('payroll.compensation.manage'), 'runs_create' => $request->user()->canDo('payroll.runs.create'), 'runs_view' => $request->user()->canDo('payroll.runs.view'), 'runs_approve' => $request->user()->canDo('payroll.runs.approve'), 'runs_post' => $request->user()->canDo('payroll.runs.post'), 'runs_reverse' => $request->user()->canDo('payroll.runs.reverse'), 'runs_export' => $request->user()->canDo('payroll.runs.export'), 'runs_settle' => $request->user()->canDo('payroll.runs.settle'), 'payslips_email' => $request->user()->canDo('payroll.payslips.email')],
        ];
    }

    public function page(Request $request)
    {
        $this->permit($request, 'payroll.view');
        return Inertia::render('Workspace', [
            'section' => 'payroll', 'user' => $request->user()->only('id', 'name', 'email'), 'role' => $request->user()->role->name,
            'permissions' => $request->user()->role->permissions,
            'company' => ['logo_url' => Company::firstOrFail()->logo_url, 'name' => Company::value('name'), 'industry' => '', 'base_currency' => Company::value('base_currency'), 'accepted_currencies' => [], 'settings' => []],
            'branches' => Branch::whereIn('id', $this->branchIds($request))->with('warehouses')->get(),
            'modules' => TenantModule::get()->map(fn ($m) => [...$m->toArray(), ...config("modules.$m->key")]),
            'payroll' => $this->data($request),
        ]);
    }

    public function index(Request $request)
    {
        $this->permit($request, 'payroll.view');
        return ['data' => $this->data($request)];
    }

    public function storeRule(Request $request)
    {
        $this->permit($request, 'payroll.rules.manage');
        $data = $request->validate([
            'currency' => ['required', 'string', Rule::in(['USD', 'ZWG'])], 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'paye_bands' => 'required|array|min:1|max:20', 'paye_bands.*.up_to' => 'nullable|numeric|min:0', 'paye_bands.*.rate' => 'required|numeric|min:0|max:100', 'paye_bands.*.deduct' => 'required|numeric|min:0',
            'proration_basis' => ['required', Rule::in(['none', 'calendar_days'])], 'daily_paye_bands' => 'nullable|array|min:1|max:20', 'daily_paye_bands.*.up_to' => 'nullable|numeric|min:0', 'daily_paye_bands.*.rate' => 'required_with:daily_paye_bands|numeric|min:0|max:100', 'daily_paye_bands.*.deduct' => 'required_with:daily_paye_bands|numeric|min:0', 'proration_source_name' => 'nullable|string|max:200', 'proration_source_url' => 'nullable|url|max:1000',
            'aids_levy_rate' => 'required|numeric|min:0|max:100', 'nssa_employee_rate' => 'nullable|numeric|min:0|max:100', 'nssa_employer_rate' => 'nullable|numeric|min:0|max:100',
            'nssa_ceiling' => 'nullable|numeric|min:0', 'nssa_ceiling_currency' => ['nullable', 'string', Rule::in(['USD', 'ZWG'])], 'source_name' => 'required|string|max:200', 'source_url' => 'required|url|max:1000',
            'nssa_source_name' => 'nullable|string|max:200', 'nssa_source_url' => 'nullable|url|max:1000',
            'apwcs_employer_rate' => 'nullable|numeric|min:0|max:100', 'apwcs_industry_code' => 'nullable|string|max:40', 'apwcs_source_name' => 'nullable|string|max:200', 'apwcs_source_url' => 'nullable|url|max:1000',
        ]);
        if (is_null($data['nssa_employee_rate'] ?? null) !== is_null($data['nssa_employer_rate'] ?? null)) {
            throw ValidationException::withMessages(['nssa_employee_rate' => 'Enter both NSSA employee and employer rates, or leave both blank.']);
        }
        if (! is_null($data['nssa_employee_rate'] ?? null) && (empty($data['nssa_source_name']) || empty($data['nssa_source_url']))) {
            throw ValidationException::withMessages(['nssa_source_url' => 'Cite the official source for configured NSSA parameters.']);
        }
        if (! is_null($data['apwcs_employer_rate'] ?? null) && (empty($data['apwcs_industry_code']) || empty($data['apwcs_source_name']) || empty($data['apwcs_source_url']))) {
            throw ValidationException::withMessages(['apwcs_source_url' => 'Enter the employer’s NSSA industry code, assigned APWCS rate and the source confirming them.']);
        }
        if (is_null($data['nssa_ceiling'] ?? null) !== is_null($data['nssa_ceiling_currency'] ?? null)) {
            throw ValidationException::withMessages(['nssa_ceiling' => 'Provide both an NSSA ceiling and its currency, or leave both blank.']);
        }
        $bands = $data['paye_bands'];
        if (array_filter($bands, fn ($band) => ! array_key_exists('up_to', $band)) || count(array_filter($bands, fn ($band) => $band['up_to'] === null)) !== 1 || end($bands)['up_to'] !== null) {
            throw ValidationException::withMessages(['paye_bands' => 'Tax bands must be ordered with exactly one open-ended final band.']);
        }
        foreach (array_slice($bands, 1) as $i => $band) {
            if ((float) $band['up_to'] <= (float) $bands[$i]['up_to']) throw ValidationException::withMessages(['paye_bands' => 'Tax band thresholds must increase.']);
        }
        if ($data['proration_basis'] === 'calendar_days') {
            $daily = $data['daily_paye_bands'] ?? [];
            if ($daily && (array_filter($daily, fn ($band) => ! array_key_exists('up_to', $band)) || count(array_filter($daily, fn ($band) => $band['up_to'] === null)) !== 1 || end($daily)['up_to'] !== null)) {
                throw ValidationException::withMessages(['daily_paye_bands' => 'Optional daily PAYE bands must be ordered with exactly one open-ended final band.']);
            }
            foreach (array_slice($daily, 1) as $i => $band) if ((float) $band['up_to'] <= (float) $daily[$i]['up_to']) throw ValidationException::withMessages(['daily_paye_bands' => 'Daily PAYE band thresholds must increase.']);
            if (empty($data['proration_source_name']) || empty($data['proration_source_url'])) throw ValidationException::withMessages(['proration_source_url' => 'Cite the source for the calendar-day earnings-proration policy.']);
        } else {
            $data['daily_paye_bands'] = null;
            $data['proration_source_name'] = null;
            $data['proration_source_url'] = null;
        }
        $rule = PayrollRuleSet::create([...$data, 'jurisdiction' => 'ZW', 'frequency' => 'monthly', 'status' => 'draft', 'created_by' => $request->user()->id]);
        Audit::record('payroll.rules.created', (string) $rule->id, ['currency' => $rule->currency, 'effective_from' => $rule->effective_from->toDateString(), 'source_name' => $rule->source_name]);
        return $request->expectsJson() ? response()->json(['data' => $rule], 201) : back()->with('success', 'Draft payroll rules saved. A different authorized user must approve them.');
    }

    public function approveRule(Request $request, int $id)
    {
        $this->permit($request, 'payroll.rules.approve');
        $rule = DB::transaction(function () use ($request, $id) {
            Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $rule = PayrollRuleSet::lockForUpdate()->findOrFail($id);
            if ($rule->status !== 'draft') throw ValidationException::withMessages(['rule' => 'Only draft rule sets can be approved.']);
            if ($rule->created_by === $request->user()->id) throw ValidationException::withMessages(['rule' => 'The person who prepared a rule set cannot approve it.']);
            $overlap = PayrollRuleSet::where('jurisdiction', $rule->jurisdiction)->where('currency', $rule->currency)->where('frequency', $rule->frequency)->where('status', 'approved')->whereDate('effective_from', '<=', $rule->effective_to ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $rule->effective_from))->exists();
            if ($overlap) throw ValidationException::withMessages(['rule' => 'An approved rule set already covers part of these dates for this currency.']);
            $rule->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            Audit::record('payroll.rules.approved', (string) $rule->id, ['currency' => $rule->currency, 'effective_from' => $rule->effective_from->toDateString()]);
            return $rule;
        });
        return $request->expectsJson() ? response()->json(['data' => $rule]) : back()->with('success', 'Payroll rule set approved.');
    }

    public function storeCompensation(Request $request, int $employeeId)
    {
        $this->permit($request, 'payroll.compensation.manage');
        $employee = $this->employeeQuery($request)->findOrFail($employeeId);
        $data = $request->validate(['currency' => ['required', 'string', Rule::in(['USD', 'ZWG'])], 'monthly_salary' => 'required|numeric|min:0|max:999999999999', 'monthly_recurring_cash_earnings' => 'required|numeric|min:0|max:999999999999', 'monthly_taxable_benefits' => 'required|numeric|min:0|max:999999999999', 'monthly_allowable_deductions' => 'required|numeric|min:0|max:999999999999', 'monthly_tax_credits' => 'required|numeric|min:0|max:999999999999', 'monthly_cash_deductions' => 'required|numeric|min:0|max:999999999999', 'nssa_eligible' => 'required|boolean', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from']);
        if ($employee->status === 'terminated' || $employee->start_date->gt($data['effective_from']) || ($employee->end_date && $employee->end_date->lt($data['effective_from']))) {
            throw ValidationException::withMessages(['effective_from' => 'The salary term must begin within an active employment period.']);
        }
        DB::transaction(function () use ($employee, $data, $request) {
            Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $openTerm = EmployeeCompensation::where('employee_id', $employee->id)->where('currency', $data['currency'])->whereNull('effective_to')->lockForUpdate()->first();
            if ($openTerm) {
                $newStart = \Illuminate\Support\Carbon::parse($data['effective_from']);
                if ($newStart->lessThanOrEqualTo($openTerm->effective_from)) {
                    throw ValidationException::withMessages(['effective_from' => 'A new salary term must start after the current open term begins.']);
                }
                $priorEnd = $newStart->copy()->subDay()->toDateString();
                $openTerm->update(['effective_to' => $priorEnd]);
                Audit::record('payroll.compensation.closed', (string) $employee->id, ['term_id' => $openTerm->id, 'effective_to' => $priorEnd]);
            }
            $overlap = EmployeeCompensation::where('employee_id', $employee->id)->where('currency', $data['currency'])->whereDate('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))->exists();
            if ($overlap) throw ValidationException::withMessages(['effective_from' => 'An existing compensation term overlaps these dates. End the previous term before adding another.']);
            EmployeeCompensation::create([...$data, 'employee_id' => $employee->id, 'created_by' => $request->user()->id]);
            Audit::record('payroll.compensation.created', (string) $employee->id, ['currency' => $data['currency'], 'effective_from' => $data['effective_from']]);
        });
        return $request->expectsJson() ? response()->json(['data' => ['employee_id' => $employee->id, 'currency' => $data['currency'], 'effective_from' => $data['effective_from']]], 201) : back()->with('success', 'Confidential salary term saved.');
    }

    public function storeRun(Request $request, ZimbabwePayrollCalculator $calculator)
    {
        $this->permit($request, 'payroll.runs.create');
        $data = $request->validate([
            'request_key' => 'required|uuid', 'period' => 'required|date_format:Y-m',
            'branch_id' => ['required', 'integer', Rule::in($this->branchIds($request)->all())],
            'currency' => ['required', 'string', Rule::in(['USD', 'ZWG', 'MIXED'])],
            'payment_date' => ['required_if:currency,MIXED', 'nullable', 'date_format:Y-m-d'],
            'adjustments' => 'sometimes|array|max:500', 'adjustments.*.employee_id' => 'required|integer',
            'adjustments.*.kind' => ['required', Rule::in(['cash_earning', 'taxable_benefit', 'allowable_deduction', 'tax_credit', 'cash_deduction'])],
            'adjustments.*.currency' => ['required_if:currency,MIXED', 'nullable', 'string', Rule::in(['USD', 'ZWG'])],
            'adjustments.*.description' => 'required|string|max:120', 'adjustments.*.amount' => 'required|numeric|gt:0|max:999999999999',
        ]);
        $periodStart = CarbonImmutable::createFromFormat('!Y-m', $data['period'])->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();
        $payloadHash = PosMath::hash(collect($data)->except('request_key')->all());

        $run = DB::transaction(function () use ($request, $data, $periodStart, $periodEnd, $payloadHash, $calculator) {
            Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $existing = PayrollRun::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) throw ValidationException::withMessages(['request_key' => 'This payroll request key was used for different run details.']);
                return $existing->load('lines');
            }
            if ($data['currency'] === 'MIXED') return $this->storeMixedRunLocked($request, $data, $periodStart, $periodEnd, $payloadHash, $calculator);
            $duplicate = PayrollRun::where('branch_id', $data['branch_id'])->where('currency', $data['currency'])->whereDate('period_start', $periodStart->toDateString())->whereDoesntHave('postings', fn ($q) => $q->where('kind', 'reversal'))->exists();
            if ($duplicate) throw ValidationException::withMessages(['period' => 'A payroll run already exists for this branch, currency and month.']);
            $rule = PayrollRuleSet::where('jurisdiction', 'ZW')->where('currency', $data['currency'])->where('frequency', 'monthly')->where('status', 'approved')->whereDate('effective_from', '<=', $periodStart->toDateString())->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodEnd->toDateString()))->orderByDesc('effective_from')->first();
            if (! $rule) throw ValidationException::withMessages(['rules' => 'No independently approved Zimbabwe monthly rule set covers the entire pay period.']);

            $employees = Employee::where('branch_id', $data['branch_id'])->whereIn('status', ['active', 'on_leave', 'terminated'])->whereDate('start_date', '<=', $periodEnd->toDateString())->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $periodStart->toDateString()))->orderBy('employee_number')->get();
            if ($employees->isEmpty()) throw ValidationException::withMessages(['employees' => 'No employees were employed during this pay month.']);
            $employmentWindows = [];
            foreach ($employees as $employee) {
                $from = CarbonImmutable::parse($employee->start_date)->max($periodStart);
                $to = $employee->end_date ? CarbonImmutable::parse($employee->end_date)->min($periodEnd) : $periodEnd;
                $employmentWindows[$employee->id] = ['from' => $from, 'to' => $to, 'payable_days' => $from->diffInDays($to) + 1];
            }
            $overlappingTerms = EmployeeCompensation::whereIn('employee_id', $employees->pluck('id'))->whereDate('effective_from', '<=', $periodEnd->toDateString())->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodStart->toDateString()))->get();
            $segmentsByEmployee = collect();
            $termProblems = [];
            $currencyProblems = [];
            $eligibilityProblems = [];
            foreach ($employees as $employee) {
                $window = $employmentWindows[$employee->id];
                $employeeTerms = $overlappingTerms->where('employee_id', $employee->id)->filter(fn ($term) => $term->effective_from->lte($window['to']) && (! $term->effective_to || $term->effective_to->gte($window['from'])))->values();
                $employeeTerms = $employeeTerms->sortBy('effective_from')->values();
                $cursor = $window['from'];
                $segments = [];
                $eligibilities = [];
                foreach ($employeeTerms as $term) {
                    $segmentFrom = $term->effective_from->max($window['from']);
                    $segmentTo = ($term->effective_to ?? $periodEnd)->min($window['to']);
                    if ($segmentFrom->gt($cursor) || $segmentFrom->lt($cursor)) {
                        $termProblems[] = $employee->employee_number;
                        break;
                    }
                    if ($term->currency !== $data['currency']) $currencyProblems[] = $employee->employee_number;
                    $days = $segmentFrom->diffInDays($segmentTo) + 1;
                    $segments[] = ['term' => $term, 'from' => $segmentFrom, 'to' => $segmentTo, 'days' => $days];
                    $eligibilities[] = (bool) $term->nssa_eligible;
                    $cursor = $segmentTo->copy()->addDay();
                }
                if ($cursor->lte($window['to'])) $termProblems[] = $employee->employee_number;
                if ($segments && count(array_unique($eligibilities)) > 1) $eligibilityProblems[] = $employee->employee_number;
                if (! $segments || in_array($employee->employee_number, $termProblems, true)) {
                    $termProblems[] = $employee->employee_number;
                    continue;
                }
                $segmentsByEmployee->put($employee->id, $segments);
            }
            if ($termProblems) throw ValidationException::withMessages(['employees' => 'Salary terms must continuously cover every employed day without gaps or overlaps. Resolve missing or overlapping terms for: '.implode(', ', array_unique($termProblems)).'.']);
            if ($currencyProblems) throw ValidationException::withMessages(['currency' => 'Each employee must be paid in one currency for the whole pay month. Mixed-currency salary components are not supported. Review: '.implode(', ', array_unique($currencyProblems)).'.']);
            if ($eligibilityProblems) throw ValidationException::withMessages(['employees' => 'NSSA eligibility cannot change partway through a monthly run. Resolve the effective-dated terms for: '.implode(', ', array_unique($eligibilityProblems)).'.']);
            $missingNssaBirthDates = $employees->filter(function ($employee) use ($segmentsByEmployee) {
                return (bool) $segmentsByEmployee[$employee->id][0]['term']->nssa_eligible && ! $employee->date_of_birth;
            });
            if ($missingNssaBirthDates->isNotEmpty()) throw ValidationException::withMessages(['employees' => 'HR must record a confidential date of birth before payroll can check NSSA age eligibility for: '.$missingNssaBirthDates->pluck('employee_number')->join(', ').'.']);
            $partialCount = collect($employmentWindows)->filter(fn ($window) => $window['payable_days'] < $periodStart->daysInMonth)->count();
            $splitTermCount = $segmentsByEmployee->filter(fn ($segments) => count($segments) > 1)->count();
            $usesDailyTax = $partialCount > 0 || $splitTermCount > 0;
            if ($usesDailyTax && $rule->proration_basis !== 'calendar_days') {
                throw ValidationException::withMessages(['rules' => 'This run includes partial employment or a salary-term change. Configure and independently approve a calendar-day earnings-proration policy first.']);
            }
            $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach ($data['adjustments'] ?? [] as $index => $adjustment) {
                if (! in_array((int) $adjustment['employee_id'], $employeeIds, true)) {
                    throw ValidationException::withMessages(["adjustments.$index.employee_id" => 'One-off adjustments must belong to an employee included in this branch, currency and month.']);
                }
            }
            $adjustmentsByEmployee = collect($data['adjustments'] ?? [])->groupBy('employee_id');
            $sumAdjustments = fn ($items, array $kinds) => PosMath::sum(collect($items)->whereIn('kind', $kinds)->pluck('amount')->all() ?: ['0'], 2);

            $calculations = [];
            $prorate = fn ($amount, $days) => BigDecimal::of((string) $amount)->multipliedBy((string) $days)->dividedBy((string) $periodStart->daysInMonth, 2, \Brick\Math\RoundingMode::HALF_UP)->toScale(2, \Brick\Math\RoundingMode::HALF_UP)->__toString();
            foreach ($employees as $employee) {
                $segments = $segmentsByEmployee[$employee->id];
                $term = $segments[0]['term'];
                $window = $employmentWindows[$employee->id];
                $payableDays = $window['payable_days'];
                $nssaStatus = $this->nssaPobsStatus($employee, (bool) $term->nssa_eligible, $periodEnd);
                $nssaAgeEligible = $nssaStatus['eligible'];
                $nssaEligibilityBasis = $nssaStatus['basis'];
                $apwcsStatus = $this->apwcsStatus($employee, $periodEnd);
                $sumSegmentAmount = function (string $field) use ($segments, $payableDays, $periodStart, $prorate) {
                    if (count($segments) === 1 && $payableDays === $periodStart->daysInMonth) return PosMath::money((string) $segments[0]['term']->{$field});
                    return PosMath::sum(array_map(fn ($segment) => $prorate($segment['term']->{$field}, $segment['days']), $segments), 2);
                };
                $adjustments = $adjustmentsByEmployee->get($employee->id, collect())->values()->map(fn ($item) => ['kind' => $item['kind'], 'description' => $item['description'], 'amount' => PosMath::money((string) $item['amount'])])->all();
                $baseSalary = $sumSegmentAmount('monthly_salary');
                $recurringCash = $sumSegmentAmount('monthly_recurring_cash_earnings');
                $taxableBenefits = $sumSegmentAmount('monthly_taxable_benefits');
                $allowableDeductions = $sumSegmentAmount('monthly_allowable_deductions');
                $taxCredits = $sumSegmentAmount('monthly_tax_credits');
                $baseCashDeductions = $sumSegmentAmount('monthly_cash_deductions');
                $currentCredits = PosMath::sum([$taxCredits, $sumAdjustments($adjustments, ['tax_credit'])], 2);
                $taxHistory = $this->taxYearContext($employee, $periodStart, $data['currency']);
                $calc = $calculator->calculate($rule, [
                    'monthly_salary' => $baseSalary, 'cash_earnings' => PosMath::sum([$recurringCash, $sumAdjustments($adjustments, ['cash_earning'])], 2),
                    'monthly_taxable_benefits' => PosMath::sum([$taxableBenefits, $sumAdjustments($adjustments, ['taxable_benefit'])], 2),
                    'monthly_allowable_deductions' => PosMath::sum([$allowableDeductions, $sumAdjustments($adjustments, ['allowable_deduction'])], 2),
                    'monthly_tax_credits' => '0',
                    'monthly_cash_deductions' => PosMath::sum([$baseCashDeductions, $sumAdjustments($adjustments, ['cash_deduction', 'allowable_deduction'])], 2),
                    'payable_days' => $periodStart->daysInMonth, 'period_days' => $periodStart->daysInMonth, 'daily_tax_basis' => false,
                    'nssa_eligible' => $nssaAgeEligible, 'apwcs_eligible' => $apwcsStatus['eligible'], 'employee_number' => $employee->employee_number,
                ]);
                if ($taxHistory['method'] === 'FDS_AVERAGING') {
                    $calc = [...$calc, ...$this->fdsAverageTax($calculator, $rule, $employee->employee_number, $periodStart, $calc['taxable_income'], $currentCredits, $taxHistory)];
                }
                $calc['tax_method'] = $taxHistory['method'];
                $calc['tax_credit_claimed'] = $taxHistory['method'] === 'NON_FDS' ? '0.00' : $currentCredits;
                $calc['net_pay'] = PosMath::money(PosMath::sum([$calc['gross_salary'], '-'.(string) $calc['paye'], '-'.(string) $calc['aids_levy'], '-'.(string) $calc['nssa_employee'], '-'.(string) $calc['cash_deductions']], 2));
                if (BigDecimal::of($calc['net_pay'])->isNegative()) throw ValidationException::withMessages(['salary' => 'Payroll deductions exceed cash salary for '.$employee->employee_number.'.']);
                $segmentSnapshots = array_map(fn ($segment) => ['effective_from' => $segment['from']->toDateString(), 'effective_to' => $segment['to']->toDateString(), 'payable_days' => $segment['days'], 'salary_term_id' => $segment['term']->id, 'currency' => $segment['term']->currency, 'monthly_salary' => $segment['term']->monthly_salary, 'prorated_salary' => $prorate($segment['term']->monthly_salary, $segment['days']), 'monthly_recurring_cash_earnings' => $segment['term']->monthly_recurring_cash_earnings, 'monthly_taxable_benefits' => $segment['term']->monthly_taxable_benefits, 'monthly_allowable_deductions' => $segment['term']->monthly_allowable_deductions, 'monthly_tax_credits' => $segment['term']->monthly_tax_credits, 'monthly_cash_deductions' => $segment['term']->monthly_cash_deductions], $segments);
                $calculations[] = ['employee' => $employee, 'term' => $term, 'calculation' => $calc, 'adjustments' => $adjustments, 'window' => $window, 'segments' => $segmentSnapshots, 'nssa_eligibility_basis' => $nssaEligibilityBasis, 'apwcs_eligibility_basis' => $apwcsStatus['basis'], 'tax_method' => $taxHistory['method'], 'tax_credit_claimed' => $calc['tax_credit_claimed'], 'cumulative_paye_before_credits' => $calc['cumulative_paye_before_credits'] ?? null, 'cumulative_tax_credit_applied' => $calc['cumulative_tax_credit_applied'] ?? null, 'prorated' => ['monthly_salary' => $baseSalary, 'monthly_recurring_cash_earnings' => $recurringCash, 'monthly_taxable_benefits' => $taxableBenefits, 'monthly_allowable_deductions' => $allowableDeductions, 'monthly_tax_credits' => $taxCredits, 'monthly_cash_deductions' => $baseCashDeductions]];
            }
            $sum = fn ($key) => PosMath::sum(array_map(fn ($row) => $row['calculation'][$key], $calculations), 2);
            $totals = ['employees' => count($calculations), 'gross_salary' => $sum('gross_salary'), 'taxable_income' => $sum('taxable_income'), 'paye' => $sum('paye'), 'aids_levy' => $sum('aids_levy'), 'nssa_employee' => $sum('nssa_employee'), 'nssa_employer' => $sum('nssa_employer'), 'apwcs_employer' => $sum('apwcs_employer'), 'cash_deductions' => $sum('cash_deductions'), 'net_pay' => $sum('net_pay'), 'employer_cost' => $sum('employer_cost')];
            $run = PayrollRun::create(['request_key' => $data['request_key'], 'payload_hash' => $payloadHash, 'rule_set_id' => $rule->id, 'branch_id' => $data['branch_id'], 'period_start' => $periodStart->toDateString(), 'period_end' => $periodEnd->toDateString(), 'currency' => $data['currency'], 'status' => 'draft', 'totals' => $totals, 'created_by' => $request->user()->id]);
            foreach ($calculations as $row) {
                $employee = $row['employee']; $term = $row['term']; $calc = $row['calculation'];
                $leaveAccrual = $this->annualLeaveAccrual($employee, (int) $calc['payable_days'], (int) $calc['period_days'], $periodStart);
                PayrollRunLine::create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'snapshot' => ['employee' => $employee->preferred_name ?: $employee->legal_name, 'employee_number' => $employee->employee_number, 'job_title' => $employee->job_title, 'employee_group' => $employee->employee_group, 'job_grade' => $employee->job_grade, ...$leaveAccrual, 'employment_from' => $row['window']['from']->toDateString(), 'employment_to' => $row['window']['to']->toDateString(), 'payable_days' => $calc['payable_days'], 'period_days' => $calc['period_days'], 'salary_term_segments' => $row['segments'], 'nssa_eligibility_basis' => $row['nssa_eligibility_basis'], 'apwcs_eligibility_basis' => $row['apwcs_eligibility_basis'], 'apwcs_industry_code' => $rule->apwcs_industry_code, 'apwcs_employer_rate' => $rule->apwcs_employer_rate, 'tax_method' => $row['tax_method'], 'tax_credit_claimed' => $row['tax_credit_claimed'], 'cumulative_paye_before_credits' => $row['cumulative_paye_before_credits'], 'cumulative_tax_credit_applied' => $row['cumulative_tax_credit_applied'], 'monthly_salary' => $term->monthly_salary, 'prorated_monthly' => $row['prorated'], 'monthly_recurring_cash_earnings' => $term->monthly_recurring_cash_earnings, 'monthly_taxable_benefits' => $term->monthly_taxable_benefits, 'monthly_allowable_deductions' => $term->monthly_allowable_deductions, 'monthly_tax_credits' => $term->monthly_tax_credits, 'monthly_cash_deductions' => $term->monthly_cash_deductions, 'cash_deductions' => $calc['cash_deductions'], 'cash_earnings' => $calc['cash_earnings'], 'adjustments' => $row['adjustments'], 'paye_before_credits' => $calc['paye_before_credits'], 'tax_credit' => $calc['tax_credit'], 'employer_cost' => $calc['employer_cost']], ...collect($calc)->only('gross_salary', 'taxable_income', 'paye', 'aids_levy', 'nssa_employee', 'nssa_employer', 'apwcs_employer', 'net_pay')->all()]);
            }
            Audit::record('payroll.run.drafted', (string) $run->id, ['period' => $data['period'], 'currency' => $data['currency'], 'branch_id' => $data['branch_id'], 'employees' => count($calculations), 'rule_set_id' => $rule->id]);
            return $run->load('lines');
        }, 3);

        return $request->expectsJson() ? response()->json(['data' => $run->only('id', 'period_start', 'period_end', 'payment_date', 'branch_id', 'currency', 'status', 'totals')], 201) : back()->with('success', 'Payroll calculation saved as a draft for independent review.');
    }

    private function storeMixedRunLocked(Request $request, array $data, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, string $payloadHash, ZimbabwePayrollCalculator $calculator): PayrollRun
    {
        $paymentDate = $data['payment_date'] ?? null;
        if (! $paymentDate || CarbonImmutable::parse($paymentDate)->lt($periodEnd)) {
            throw ValidationException::withMessages(['payment_date' => 'Enter the actual payment date on or after the final day of this pay period.']);
        }
        $ruleFor = fn (string $currency) => PayrollRuleSet::where('jurisdiction', 'ZW')->where('currency', $currency)->where('frequency', 'monthly')->where('status', 'approved')->whereDate('effective_from', '<=', $periodStart->toDateString())->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodEnd->toDateString()))->orderByDesc('effective_from')->first();
        $usdRule = $ruleFor('USD');
        if (! $usdRule) throw ValidationException::withMessages(['rules' => 'Mixed payroll requires an independently approved USD rule version covering the entire pay period.']);
        $rates = app(\App\Services\CurrencyRates::class);
        $usdFx = $rates->usdUnitRate('USD', $paymentDate);
        $zwgFx = $rates->usdUnitRate('ZWG', $paymentDate);
        $employees = Employee::where('branch_id', $data['branch_id'])->whereIn('status', ['active', 'on_leave', 'terminated'])->whereDate('start_date', '<=', $periodEnd->toDateString())->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $periodStart->toDateString()))->orderBy('employee_number')->get();
        if ($employees->isEmpty()) throw ValidationException::withMessages(['employees' => 'No employees were employed during this pay month.']);
        $ids = $employees->pluck('id');
        $terms = EmployeeCompensation::whereIn('employee_id', $ids)->whereDate('effective_from', '<=', $periodEnd->toDateString())->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodStart->toDateString()))->orderBy('effective_from')->get()->groupBy('employee_id');
        $employeeIds = $employees->pluck('id')->map(fn ($value) => (int) $value)->all();
        $adjustmentsByEmployee = collect($data['adjustments'] ?? [])->groupBy('employee_id');
        foreach ($data['adjustments'] ?? [] as $index => $adjustment) {
            if (! in_array((int) $adjustment['employee_id'], $employeeIds, true)) throw ValidationException::withMessages(["adjustments.$index.employee_id" => 'One-off adjustments must belong to an employee included in this branch and month.']);
            if (! in_array($adjustment['currency'] ?? '', ['USD', 'ZWG'], true)) throw ValidationException::withMessages(["adjustments.$index.currency" => 'Choose USD or ZWG for each mixed-pay adjustment.']);
        }

        $money = fn ($value) => BigDecimal::of((string) $value)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        $usdValue = function (string $currency, $amount) use ($money, $zwgFx) {
            $amount = $money($amount);
            return $currency === 'USD' ? $amount : $amount->multipliedBy($zwgFx['usd_per_unit'])->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        };
        $calculations = [];
        $partial = [];
        foreach ($employees as $employee) {
            $employeePartial = false;
            $windowFrom = CarbonImmutable::parse($employee->start_date)->max($periodStart);
            $windowTo = $employee->end_date ? CarbonImmutable::parse($employee->end_date)->min($periodEnd) : $periodEnd;
            $fields = ['monthly_salary', 'monthly_recurring_cash_earnings', 'monthly_taxable_benefits', 'monthly_allowable_deductions', 'monthly_tax_credits', 'monthly_cash_deductions'];
            $native = ['USD' => [], 'ZWG' => []];
            foreach (['USD', 'ZWG'] as $code) foreach ($fields as $field) $native[$code][$field] = BigDecimal::zero();
            $salarySegments = ['USD' => [], 'ZWG' => []];
            $eligibilityFlags = [];
            $usesDailyTax = ! $windowFrom->equalTo($periodStart) || ! $windowTo->equalTo($periodEnd);
            $payableDays = $windowFrom->diffInDays($windowTo) + 1;
            foreach (['USD', 'ZWG'] as $code) {
                $currencyTerms = $terms->get($employee->id, collect())->where('currency', $code)->filter(fn ($term) => $term->effective_from->lte($windowTo) && (! $term->effective_to || $term->effective_to->gte($windowFrom)))->sortBy('effective_from')->values();
                $cursor = $windowFrom;
                foreach ($currencyTerms as $term) {
                    $segmentFrom = $term->effective_from->max($windowFrom);
                    $segmentTo = ($term->effective_to ?? $periodEnd)->min($windowTo);
                    if (! $segmentFrom->equalTo($cursor) || $segmentTo->lt($segmentFrom)) {
                        $partial[] = $employee->employee_number.' ('.$code.')';
                        $employeePartial = true;
                        break;
                    }
                    $days = $segmentFrom->diffInDays($segmentTo) + 1;
                    $segment = ['currency' => $code, 'salary_term_id' => $term->id, 'effective_from' => $segmentFrom->toDateString(), 'effective_to' => $segmentTo->toDateString(), 'payable_days' => $days];
                    foreach ($fields as $field) {
                        $prorated = BigDecimal::of((string) $term->{$field})->multipliedBy((string) $days)->dividedBy((string) $periodStart->daysInMonth, 2, \Brick\Math\RoundingMode::HalfUp)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
                        $native[$code][$field] = $native[$code][$field]->plus($prorated)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
                        $segment[$field] = $term->{$field};
                        $segment['prorated_'.$field] = (string) $prorated;
                    }
                    $salarySegments[$code][] = $segment;
                    $eligibilityFlags[] = (bool) $term->nssa_eligible;
                    $cursor = $segmentTo->addDay();
                    if (count($salarySegments[$code]) > 1) $usesDailyTax = true;
                }
                if (! $currencyTerms->count() || $cursor->lte($windowTo)) {
                    $partial[] = $employee->employee_number.' ('.$code.')';
                    $employeePartial = true;
                }
            }
            if ($employeePartial) continue;
            if ($usesDailyTax && $usdRule->proration_basis !== 'calendar_days') {
                throw ValidationException::withMessages(['rules' => 'Mixed hires, departures and salary-term changes require an independently approved calendar-day earnings-proration policy.']);
            }
            if (count(array_unique($eligibilityFlags)) > 1) throw ValidationException::withMessages(['employees' => 'NSSA eligibility cannot change partway through the mixed payroll period for '.$employee->employee_number.'.']);
            $nssaEligible = (bool) ($eligibilityFlags[0] ?? false);
            $nssaStatus = $this->nssaPobsStatus($employee, $nssaEligible, $periodEnd);
            $nssaAgeEligible = $nssaStatus['eligible'];
            $apwcsStatus = $this->apwcsStatus($employee, $periodEnd);
            if ($apwcsStatus['eligible'] && $usdRule->apwcs_employer_rate === null) throw ValidationException::withMessages(['rules' => 'Mixed payroll requires the employer’s NSSA APWCS industry code, assigned premium rate and source in the approved USD rule.']);

            $adjustments = $adjustmentsByEmployee->get($employee->id, collect())->values()->map(fn ($item) => ['kind' => $item['kind'], 'currency' => $item['currency'], 'description' => $item['description'], 'amount' => PosMath::money((string) $item['amount'])])->all();
            $adjustmentSums = ['USD' => [], 'ZWG' => []];
            foreach ($adjustments as $adjustment) $adjustmentSums[$adjustment['currency']][$adjustment['kind']][] = $adjustment['amount'];
            foreach (['USD', 'ZWG'] as $code) foreach ([
                'monthly_recurring_cash_earnings' => 'cash_earning', 'monthly_taxable_benefits' => 'taxable_benefit',
                'monthly_allowable_deductions' => 'allowable_deduction', 'monthly_tax_credits' => 'tax_credit', 'monthly_cash_deductions' => 'cash_deduction',
            ] as $field => $kind) {
                if (! empty($adjustmentSums[$code][$kind])) $native[$code][$field] = $native[$code][$field]->plus(PosMath::sum($adjustmentSums[$code][$kind], 2))->toScale(2, \Brick\Math\RoundingMode::HalfUp);
            }
            $grossUsdByCurrency = [
                'USD' => $native['USD']['monthly_salary']->plus($native['USD']['monthly_recurring_cash_earnings'])->toScale(2, \Brick\Math\RoundingMode::HalfUp),
                'ZWG' => $usdValue('ZWG', $native['ZWG']['monthly_salary']->plus($native['ZWG']['monthly_recurring_cash_earnings'])),
            ];
            $cashUsd = $grossUsdByCurrency['USD']->plus($grossUsdByCurrency['ZWG'])->toScale(2, \Brick\Math\RoundingMode::HalfUp);
            $taxableUsdByCurrency = [];
            foreach (['USD', 'ZWG'] as $code) {
                $taxableNative = $native[$code]['monthly_salary']->plus($native[$code]['monthly_recurring_cash_earnings'])->plus($native[$code]['monthly_taxable_benefits'])->minus($native[$code]['monthly_allowable_deductions']);
                if ($taxableNative->isNegative()) $taxableNative = BigDecimal::zero();
                $taxableUsdByCurrency[$code] = $usdValue($code, $taxableNative);
            }
            $taxableUsd = $taxableUsdByCurrency['USD']->plus($taxableUsdByCurrency['ZWG'])->toScale(2, \Brick\Math\RoundingMode::HalfUp);
            $creditsUsd = $usdValue('USD', $native['USD']['monthly_tax_credits'])->plus($usdValue('ZWG', $native['ZWG']['monthly_tax_credits']))->toScale(2, \Brick\Math\RoundingMode::HalfUp);
            $taxHistory = $this->taxYearContext($employee, $periodStart, 'MIXED');
            $taxCalc = $calculator->calculate($usdRule, [
                'monthly_salary' => (string) $taxableUsd, 'cash_earnings' => '0', 'monthly_taxable_benefits' => '0',
                'monthly_allowable_deductions' => '0', 'monthly_tax_credits' => '0',
                'monthly_cash_deductions' => '0', 'payable_days' => $payableDays, 'period_days' => $periodStart->daysInMonth,
                'daily_tax_basis' => false, 'nssa_eligible' => false, 'employee_number' => $employee->employee_number,
            ]);
            if ($taxHistory['method'] === 'FDS_AVERAGING') $taxCalc = [...$taxCalc, ...$this->fdsAverageTax($calculator, $usdRule, $employee->employee_number, $periodStart, (string) $taxableUsd, (string) $creditsUsd, $taxHistory)];
            $taxCalc['tax_method'] = $taxHistory['method'];
            $taxCalc['tax_credit_claimed'] = $taxHistory['method'] === 'NON_FDS' ? '0.00' : (string) $creditsUsd;
            $totalTaxBase = $taxableUsd->isZero() ? BigDecimal::zero() : BigDecimal::of($taxCalc['paye'])->multipliedBy('1');
            $allocations = [];
            foreach (['USD', 'ZWG'] as $code) {
                $share = $totalTaxBase->isZero() ? BigDecimal::zero() : BigDecimal::of($taxCalc['paye'])->multipliedBy($taxableUsdByCurrency[$code])->dividedBy($taxableUsd, 8, \Brick\Math\RoundingMode::HalfUp)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
                $levyShare = $totalTaxBase->isZero() ? BigDecimal::zero() : BigDecimal::of($taxCalc['aids_levy'])->multipliedBy($taxableUsdByCurrency[$code])->dividedBy($taxableUsd, 8, \Brick\Math\RoundingMode::HalfUp)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
                $unitRate = $code === 'USD' ? '1' : $zwgFx['usd_per_unit'];
                $allocations[$code] = [
                    'paye' => $code === 'USD' ? $share : $share->dividedBy($unitRate, 2, \Brick\Math\RoundingMode::HalfUp),
                    'aids_levy' => $code === 'USD' ? $levyShare : $levyShare->dividedBy($unitRate, 2, \Brick\Math\RoundingMode::HalfUp),
                ];
            }
            // Assign currency rounding remainders to ZWG so native allocations reconcile to the USD tax result.
            $allocations['ZWG']['paye'] = $allocations['ZWG']['paye']->plus(BigDecimal::of($taxCalc['paye'])->minus($allocations['USD']['paye'])->minus($allocations['ZWG']['paye']->multipliedBy($zwgFx['usd_per_unit']))->dividedBy($zwgFx['usd_per_unit'], 2, \Brick\Math\RoundingMode::HalfUp));
            $allocations['ZWG']['aids_levy'] = $allocations['ZWG']['aids_levy']->plus(BigDecimal::of($taxCalc['aids_levy'])->minus($allocations['USD']['aids_levy'])->minus($allocations['ZWG']['aids_levy']->multipliedBy($zwgFx['usd_per_unit']))->dividedBy($zwgFx['usd_per_unit'], 2, \Brick\Math\RoundingMode::HalfUp));

            if ($nssaAgeEligible && ($usdRule->nssa_ceiling === null || $usdRule->nssa_ceiling_currency !== 'USD')) {
                throw ValidationException::withMessages(['rules' => 'Mixed payroll requires the applicable NSSA ceiling to be verified and configured in USD on the approved USD rule.']);
            }
            $nssaCalc = $calculator->calculate($usdRule, [
                'monthly_salary' => (string) $cashUsd, 'cash_earnings' => '0', 'monthly_taxable_benefits' => '0',
                'monthly_allowable_deductions' => '0', 'monthly_tax_credits' => '0', 'monthly_cash_deductions' => '0',
                'payable_days' => $periodStart->daysInMonth, 'period_days' => $periodStart->daysInMonth, 'daily_tax_basis' => false,
                'nssa_eligible' => $nssaAgeEligible, 'employee_number' => $employee->employee_number,
            ]);
            $nssa = [];
            foreach (['employee' => 'nssa_employee', 'employer' => 'nssa_employer'] as $party => $field) {
                $nssaTotalUsd = BigDecimal::of($nssaCalc[$field]);
                $usdShare = $cashUsd->isZero() ? BigDecimal::zero() : $nssaTotalUsd->multipliedBy($grossUsdByCurrency['USD'])->dividedBy($cashUsd, 8, \Brick\Math\RoundingMode::HalfUp)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
                $zwgShareUsd = $nssaTotalUsd->minus($usdShare);
                $nssa['USD'][$party] = $usdShare;
                $nssa['ZWG'][$party] = $zwgShareUsd->dividedBy($zwgFx['usd_per_unit'], 2, \Brick\Math\RoundingMode::HalfUp);
            }
            $apwcs = [];
            foreach (['USD', 'ZWG'] as $code) {
                $basicEarnings = $native[$code]['monthly_salary']->toScale(2, \Brick\Math\RoundingMode::HalfUp);
                $rate = (string) ($usdRule->apwcs_employer_rate ?? '0');
                $apwcs[$code] = $apwcsStatus['eligible'] ? $basicEarnings->multipliedBy($rate)->dividedBy('100', 2, \Brick\Math\RoundingMode::HalfUp)->toScale(2, \Brick\Math\RoundingMode::HalfUp) : BigDecimal::zero();
            }
            $net = [];
            foreach (['USD', 'ZWG'] as $code) {
                $gross = $native[$code]['monthly_salary']->plus($native[$code]['monthly_recurring_cash_earnings'])->toScale(2, \Brick\Math\RoundingMode::HalfUp);
                $cashWithheld = $native[$code]['monthly_cash_deductions']->plus($native[$code]['monthly_allowable_deductions'])->toScale(2, \Brick\Math\RoundingMode::HalfUp);
                $net[$code] = $gross->minus($allocations[$code]['paye'])->minus($allocations[$code]['aids_levy'])->minus($nssa[$code]['employee'])->minus($cashWithheld)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
                if ($net[$code]->isNegative()) throw ValidationException::withMessages(['salary' => 'Deductions exceed '.$code.' cash pay for '.$employee->employee_number.'.']);
            }
            $calculations[] = ['employee' => $employee, 'native' => $native, 'adjustments' => $adjustments, 'salary_segments' => $salarySegments, 'payable_days' => $payableDays, 'period_days' => $periodStart->daysInMonth, 'taxable_usd_by_currency' => $taxableUsdByCurrency, 'taxable_usd' => $taxableUsd, 'cash_usd' => $cashUsd, 'tax' => $taxCalc, 'allocations' => $allocations, 'nssa' => $nssa, 'apwcs' => $apwcs, 'net' => $net, 'nssa_eligibility_basis' => $nssaStatus['basis'], 'apwcs_eligibility_basis' => $apwcsStatus['basis']];
        }
        if ($partial) throw ValidationException::withMessages(['employees' => 'Mixed-currency payroll requires continuous USD and ZWG salary-term coverage for every employed day. Review the listed employees and currencies: '.implode(', ', array_unique($partial)).'.']);

        $sum = function (callable $value) use ($calculations) {
            return PosMath::sum(array_map(fn ($row) => (string) $value($row), $calculations), 2);
        };
        $sumCode = fn (string $code, callable $value) => PosMath::sum(array_map(fn ($row) => (string) $value($row, $code), $calculations), 2);
        $totals = [
            'employees' => count($calculations), 'gross_salary' => $sum(fn ($r) => $r['cash_usd']), 'taxable_income' => $sum(fn ($r) => $r['taxable_usd']),
            'paye' => $sum(fn ($r) => $r['tax']['paye']), 'aids_levy' => $sum(fn ($r) => $r['tax']['aids_levy']),
            'nssa_employee' => $sum(fn ($r) => $r['nssa']['USD']['employee']->plus($usdValue('ZWG', $r['nssa']['ZWG']['employee']))),
            'nssa_employer' => $sum(fn ($r) => $r['nssa']['USD']['employer']->plus($usdValue('ZWG', $r['nssa']['ZWG']['employer']))),
            'apwcs_employer' => $sum(fn ($r) => $r['apwcs']['USD']->plus($usdValue('ZWG', $r['apwcs']['ZWG']))),
            'cash_deductions' => $sum(fn ($r) => $r['native']['USD']['monthly_cash_deductions']->plus($r['native']['USD']['monthly_allowable_deductions'])->plus($usdValue('ZWG', $r['native']['ZWG']['monthly_cash_deductions']->plus($r['native']['ZWG']['monthly_allowable_deductions'])))),
            'net_pay' => $sum(fn ($r) => $r['net']['USD']->plus($usdValue('ZWG', $r['net']['ZWG']))),
            'employer_cost' => $sum(fn ($r) => $r['cash_usd']->plus($r['nssa']['USD']['employer'])->plus($usdValue('ZWG', $r['nssa']['ZWG']['employer']))->plus($r['apwcs']['USD'])->plus($usdValue('ZWG', $r['apwcs']['ZWG']))),
            'currency_basis' => 'USD_equivalent',
            'net_pay_by_currency' => ['USD' => $sumCode('USD', fn ($r, $c) => $r['net'][$c]), 'ZWG' => $sumCode('ZWG', fn ($r, $c) => $r['net'][$c])],
            'paye_by_currency' => ['USD' => $sumCode('USD', fn ($r, $c) => $r['allocations'][$c]['paye']), 'ZWG' => $sumCode('ZWG', fn ($r, $c) => $r['allocations'][$c]['paye'])],
            'aids_levy_by_currency' => ['USD' => $sumCode('USD', fn ($r, $c) => $r['allocations'][$c]['aids_levy']), 'ZWG' => $sumCode('ZWG', fn ($r, $c) => $r['allocations'][$c]['aids_levy'])],
        ];
        $duplicate = PayrollRun::where('branch_id', $data['branch_id'])->where('currency', 'MIXED')->whereDate('period_start', $periodStart->toDateString())->whereDoesntHave('postings', fn ($q) => $q->where('kind', 'reversal'))->exists();
        if ($duplicate) throw ValidationException::withMessages(['period' => 'A mixed-currency payroll run already exists for this branch and month.']);
        $run = PayrollRun::create(['request_key' => $data['request_key'], 'payload_hash' => $payloadHash, 'rule_set_id' => $usdRule->id, 'branch_id' => $data['branch_id'], 'period_start' => $periodStart->toDateString(), 'period_end' => $periodEnd->toDateString(), 'payment_date' => $paymentDate, 'currency' => 'MIXED', 'status' => 'draft', 'totals' => $totals, 'created_by' => $request->user()->id]);
        foreach ($calculations as $row) {
            $nssaUsd = $row['nssa']['USD']['employee']->plus($usdValue('ZWG', $row['nssa']['ZWG']['employee']));
            $nssaEmployerUsd = $row['nssa']['USD']['employer']->plus($usdValue('ZWG', $row['nssa']['ZWG']['employer']));
            $deductionsUsd = $row['native']['USD']['monthly_cash_deductions']->plus($row['native']['USD']['monthly_allowable_deductions'])->plus($usdValue('ZWG', $row['native']['ZWG']['monthly_cash_deductions']->plus($row['native']['ZWG']['monthly_allowable_deductions'])));
            $breakdown = [];
            foreach (['USD', 'ZWG'] as $code) $breakdown[$code] = [
                'base_salary' => (string) $row['native'][$code]['monthly_salary'],
                'recurring_cash_earnings' => (string) $row['native'][$code]['monthly_recurring_cash_earnings'],
                'gross_cash' => (string) $row['native'][$code]['monthly_salary']->plus($row['native'][$code]['monthly_recurring_cash_earnings']),
                'taxable_benefits' => (string) $row['native'][$code]['monthly_taxable_benefits'],
                'allowable_deductions' => (string) $row['native'][$code]['monthly_allowable_deductions'],
                'tax_credits' => (string) $row['native'][$code]['monthly_tax_credits'],
                'taxable_income' => (string) $row['taxable_usd_by_currency'][$code], 'paye' => (string) $row['allocations'][$code]['paye'],
                'aids_levy' => (string) $row['allocations'][$code]['aids_levy'], 'nssa_employee' => (string) $row['nssa'][$code]['employee'],
                'nssa_employer' => (string) $row['nssa'][$code]['employer'], 'other_cash_deductions' => (string) $row['native'][$code]['monthly_cash_deductions'],
                'apwcs_insurable_earnings' => (string) $row['native'][$code]['monthly_salary'], 'apwcs_employer' => (string) $row['apwcs'][$code],
                'cash_deductions' => (string) $row['native'][$code]['monthly_cash_deductions']->plus($row['native'][$code]['monthly_allowable_deductions']),
                'net_pay' => (string) $row['net'][$code],
            ];
            $apwcsUsd = $row['apwcs']['USD']->plus($usdValue('ZWG', $row['apwcs']['ZWG']));
            $leaveAccrual = $this->annualLeaveAccrual($row['employee'], $row['payable_days'], $row['period_days'], $periodStart);
            $snapshot = ['employee' => $row['employee']->preferred_name ?: $row['employee']->legal_name, 'employee_number' => $row['employee']->employee_number, 'job_title' => $row['employee']->job_title, 'employee_group' => $row['employee']->employee_group, 'job_grade' => $row['employee']->job_grade, ...$leaveAccrual, 'payable_days' => $row['payable_days'], 'period_days' => $row['period_days'], 'nssa_eligibility_basis' => $row['nssa_eligibility_basis'], 'apwcs_eligibility_basis' => $row['apwcs_eligibility_basis'], 'apwcs_industry_code' => $usdRule->apwcs_industry_code, 'apwcs_employer_rate' => $usdRule->apwcs_employer_rate, 'tax_method' => $row['tax']['tax_method'], 'tax_credit_claimed' => $row['tax']['tax_credit_claimed'], 'cumulative_paye_before_credits' => $row['tax']['cumulative_paye_before_credits'] ?? null, 'cumulative_tax_credit_applied' => $row['tax']['cumulative_tax_credit_applied'] ?? null, 'currency_basis' => 'USD_equivalent', 'currency_breakdown' => $breakdown, 'mixed_currency_fx' => ['payment_date' => $paymentDate, 'USD' => $usdFx, 'ZWG' => $zwgFx], 'paye_before_credits' => $row['tax']['paye_before_credits'], 'tax_credit' => $row['tax']['tax_credit'], 'cash_deductions' => (string) $deductionsUsd, 'adjustments' => $row['adjustments'], 'salary_term_segments' => array_merge($row['salary_segments']['USD'], $row['salary_segments']['ZWG'])];
            PayrollRunLine::create(['payroll_run_id' => $run->id, 'employee_id' => $row['employee']->id, 'branch_id' => $row['employee']->branch_id, 'snapshot' => $snapshot, 'gross_salary' => (string) $row['cash_usd'], 'taxable_income' => (string) $row['taxable_usd'], 'paye' => $row['tax']['paye'], 'aids_levy' => $row['tax']['aids_levy'], 'nssa_employee' => (string) $nssaUsd, 'nssa_employer' => (string) $nssaEmployerUsd, 'apwcs_employer' => (string) $apwcsUsd, 'net_pay' => (string) $row['net']['USD']->plus($usdValue('ZWG', $row['net']['ZWG']))]);
        }
        Audit::record('payroll.run.drafted', (string) $run->id, ['period' => $periodStart->format('Y-m'), 'currency' => 'MIXED', 'branch_id' => $data['branch_id'], 'employees' => count($calculations), 'rule_set_id' => $usdRule->id, 'payment_date' => $paymentDate, 'exchange_rate_ids' => [$zwgFx['exchange_rate_id']]]);
        return $run->load('lines');
    }

    public function approveRun(Request $request, int $id)
    {
        $this->permit($request, 'payroll.runs.approve');
        $run = DB::transaction(function () use ($request, $id) {
            Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $run = PayrollRun::lockForUpdate()->whereIn('branch_id', $this->branchIds($request))->findOrFail($id);
            if ($run->status !== 'draft') throw ValidationException::withMessages(['run' => 'Only draft payroll calculations can be approved.']);
            if ($run->created_by === $request->user()->id) throw ValidationException::withMessages(['run' => 'The payroll run preparer cannot approve their own calculation.']);
            $run->load('lines');
            $accrualEmployeeIds = $run->lines->filter(fn ($line) => (float) ($line->snapshot['annual_leave_accrual_days'] ?? 0) > 0)->pluck('employee_id');
            if ($accrualEmployeeIds->isNotEmpty()) {
                $samePeriodRuns = PayrollRun::with(['lines', 'postings'])->where('id', '!=', $run->id)
                    ->whereDate('period_start', $run->period_start->toDateString())->where('status', 'approved')->get();
                foreach ($samePeriodRuns as $samePeriodRun) {
                    if ($samePeriodRun->postings->contains('kind', 'reversal')) continue;
                    if ($samePeriodRun->lines->contains(fn ($line) => $accrualEmployeeIds->contains($line->employee_id) && (float) ($line->snapshot['annual_leave_accrual_days'] ?? 0) > 0)) {
                        throw ValidationException::withMessages(['run' => 'An approved payroll run already accrued annual leave for one or more employees in this month. Correct the duplicate draft before approval.']);
                    }
                }
            }
            $run->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            Audit::record('payroll.run.approved', (string) $run->id, ['period_start' => $run->period_start->toDateString(), 'currency' => $run->currency, 'branch_id' => $run->branch_id]);
            return $run;
        });
        return $request->expectsJson() ? response()->json(['data' => $run]) : back()->with('success', 'Payroll calculation approved and locked for posting.');
    }

    public function postRun(Request $request, int $id)
    {
        $this->permit($request, 'payroll.runs.post');
        $posting = DB::transaction(function () use ($request, $id) {
            Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $run = PayrollRun::with('lines')->whereIn('branch_id', $this->branchIds($request))->findOrFail($id);
            if ($run->status !== 'approved') throw ValidationException::withMessages(['run' => 'Approve the payroll calculation before posting.']);
            if ($existing = PayrollRunPosting::where('payroll_run_id', $run->id)->where('kind', 'posted')->first()) return $existing;
            $setting = AccountingAccess::setting();
            $baseCurrency = Company::firstOrFail()->base_currency;
            $mixed = $run->currency === 'MIXED';
            if ($setting->currency !== $baseCurrency || (! $mixed && ($setting->currency !== $run->currency || $baseCurrency !== $run->currency))) {
                throw ValidationException::withMessages(['currency' => 'Payroll journal posting requires the payroll currency to match the company base and ledger currency.']);
            }
            $ledger = app(AccountingLedger::class);
            $cashDeductions = PosMath::sum(array_map(fn ($line) => $line->snapshot['cash_deductions'] ?? $line->snapshot['monthly_cash_deductions'] ?? '0', $run->lines->all()), 2);
            $fxSnapshot = null;
            $toBase = fn ($amount) => BigDecimal::of((string) $amount)->toScale(4, \Brick\Math\RoundingMode::HalfUp);
            $accountingDate = $run->period_end->toDateString();
            if ($mixed) {
                $paymentFx = $run->lines->first()?->snapshot['mixed_currency_fx'] ?? null;
                if (! is_array($paymentFx) || empty($paymentFx['ZWG']['usd_per_unit'])) throw ValidationException::withMessages(['currency' => 'The mixed payroll has no approved pay-date FX snapshot. Redraft it with an applicable approved rate.']);
                $fxSnapshot = ['source' => 'approved tenant exchange rate', 'valuation_date' => $run->payment_date->toDateString(), 'base_currency' => $baseCurrency, 'payroll_amount_basis' => 'USD equivalent', 'ZWG' => $paymentFx['ZWG']];
                $accountingDate = $run->payment_date->toDateString();
                if ($baseCurrency === 'ZWG') {
                    $toBase = fn ($amount) => BigDecimal::of((string) $amount)->dividedBy($fxSnapshot['ZWG']['usd_per_unit'], 4, \Brick\Math\RoundingMode::HalfUp)->toScale(4, \Brick\Math\RoundingMode::HalfUp);
                } elseif ($baseCurrency !== 'USD') {
                    throw ValidationException::withMessages(['currency' => 'Mixed Zimbabwe payroll can post only to USD or ZWG base ledgers.']);
                }
            }
            $grossBase = $toBase($run->totals['gross_salary']);
            $employerNssaBase = $toBase($run->totals['nssa_employer']);
            $employerApwcsBase = $toBase($run->totals['apwcs_employer'] ?? '0');
            $payeBase = $toBase($run->totals['paye']);
            $levyBase = $toBase($run->totals['aids_levy']);
            $taxLiabilityBase = $payeBase->plus($levyBase)->toScale(4, \Brick\Math\RoundingMode::HalfUp);
            $employeeNssaBase = $toBase($run->totals['nssa_employee']);
            $otherDeductionsBase = $toBase($cashDeductions);
            $netPayBase = $grossBase->minus($payeBase)->minus($levyBase)->minus($employeeNssaBase)->minus($otherDeductionsBase)->toScale(4, \Brick\Math\RoundingMode::HalfUp);
            if ($netPayBase->isNegative()) throw ValidationException::withMessages(['run' => 'Payroll conversion produced an unbalanced net payable. Review the approved exchange rate and payroll totals.']);
            $book = AccountingAccess::book();
            $journal = $ledger->system($request->user(), [
                'book' => $book, 'source_type' => 'payroll_run', 'source_id' => (string) $run->id,
                'branch_id' => $run->branch_id, 'date' => $accountingDate,
                'reference' => 'PAY-'.$run->currency.'-'.$run->period_start->format('Ym'),
                'description' => 'Monthly payroll '.$run->period_start->format('F Y'), 'cashflow' => 'none',
                'lines' => [
                    $ledger->line('payroll_expense', (string) $grossBase, '0', 'Gross wages'.($mixed ? ' (base currency equivalent)' : '')),
                    $ledger->line('payroll_nssa_expense', (string) $employerNssaBase->plus($employerApwcsBase), '0', 'Employer NSSA POBS and APWCS'.($mixed ? ' (base currency equivalent)' : '')),
                    $ledger->line('payroll_net_payable', '0', (string) $netPayBase, 'Net wages payable'.($mixed ? ' (base currency equivalent)' : '')),
                    $taxLiabilityBase->isNegative()
                        ? $ledger->line('payroll_paye_payable', (string) $taxLiabilityBase->abs(), '0', 'FDS PAYE/AIDS levy refund due'.($mixed ? ' (base currency equivalent)' : ''))
                        : $ledger->line('payroll_paye_payable', '0', (string) $taxLiabilityBase, 'PAYE and AIDS levy payable'.($mixed ? ' (base currency equivalent)' : '')),
                    $ledger->line('payroll_nssa_payable', '0', (string) $employeeNssaBase->plus($employerNssaBase)->plus($employerApwcsBase), 'NSSA POBS and APWCS contributions payable'.($mixed ? ' (base currency equivalent)' : '')),
                    $ledger->line('payroll_other_deductions', '0', (string) $otherDeductionsBase, 'Other payroll deductions payable'.($mixed ? ' (base currency equivalent)' : '')),
                ],
            ]);
            if (! $journal) throw ValidationException::withMessages(['run' => 'Payroll journal was empty and could not be posted.']);
            $posting = PayrollRunPosting::create(['payroll_run_id' => $run->id, 'journal_id' => $journal->id, 'book' => $book, 'kind' => 'posted', 'actor_id' => $request->user()->id, 'date' => $run->period_end->toDateString(), 'fx_snapshot' => $fxSnapshot, 'created_at' => now()]);
            Audit::record('payroll.run.posted', (string) $run->id, ['journal_id' => $journal->id, 'book' => $book, 'currency' => $run->currency]);
            return $posting;
        }, 3);
        return $request->expectsJson() ? response()->json(['data' => $posting], 201) : back()->with('success', 'Payroll journal posted to the accounting ledger.');
    }

    public function reverseRun(Request $request, int $id)
    {
        $this->permit($request, 'payroll.runs.reverse');
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:2000']);
        $posting = DB::transaction(function () use ($request, $id, $data) {
            Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $run = PayrollRun::whereIn('branch_id', $this->branchIds($request))->findOrFail($id);
            $original = PayrollRunPosting::where('payroll_run_id', $run->id)->where('kind', 'posted')->firstOrFail();
            if (PayrollRunPosting::where('payroll_run_id', $run->id)->where('kind', 'reversal')->exists()) throw ValidationException::withMessages(['run' => 'This payroll posting has already been reversed.']);
            if (PayrollRunPosting::where('payroll_run_id', $run->id)->whereIn('kind', ['settlement', 'settle_usd', 'settle_zwg'])->exists()) throw ValidationException::withMessages(['run' => 'A payroll posting cannot be reversed after net wages have been paid.']);
            $batch = \App\Models\JournalBatch::with('lines')->findOrFail($original->journal_id);
            $lines = $batch->lines->map(fn ($line) => ['account_id' => $line->account_id, 'debit' => $line->credit, 'credit' => $line->debit, 'description' => 'Reversal of '.$batch->number])->all();
            $reversal = app(AccountingLedger::class)->system($request->user(), ['book' => $original->book, 'source_type' => 'payroll_run_reversal', 'source_id' => (string) $original->id, 'branch_id' => $run->branch_id, 'date' => $data['date'], 'reference' => 'REV-PAY-'.$run->currency.'-'.$run->period_start->format('Ym'), 'description' => $data['reason'], 'cashflow' => $batch->cashflow, 'lines' => $lines]);
            if (! $reversal) throw ValidationException::withMessages(['run' => 'Payroll reversal journal could not be posted.']);
            $event = PayrollRunPosting::create(['payroll_run_id' => $run->id, 'journal_id' => $reversal->id, 'reverses_posting_id' => $original->id, 'book' => $original->book, 'kind' => 'reversal', 'actor_id' => $request->user()->id, 'date' => $data['date'], 'reason' => $data['reason'], 'created_at' => now()]);
            Audit::record('payroll.run.reversed', (string) $run->id, ['original_journal_id' => $batch->id, 'reversal_journal_id' => $reversal->id, 'reason' => $data['reason']]);
            return $event;
        }, 3);
        return $request->expectsJson() ? response()->json(['data' => $posting], 201) : back()->with('success', 'Payroll journal reversed. Create a corrected payroll run if required.');
    }

    public function settleRun(Request $request, int $id)
    {
        $this->permit($request, 'payroll.runs.settle');
        $data = $request->validate(['request_key' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'bank_account_id' => 'nullable|integer', 'foreign_bank_accounts' => 'sometimes|array', 'foreign_bank_accounts.USD' => 'nullable|integer', 'foreign_bank_accounts.ZWG' => 'nullable|integer', 'reference' => 'required|string|max:160']);
        $posting = DB::transaction(function () use ($request, $id, $data) {
            Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $run = PayrollRun::whereIn('branch_id', $this->branchIds($request))->findOrFail($id);
            $original = PayrollRunPosting::where('payroll_run_id', $run->id)->where('kind', 'posted')->firstOrFail();
            if (PayrollRunPosting::where('payroll_run_id', $run->id)->where('kind', 'reversal')->exists()) throw ValidationException::withMessages(['run' => 'A reversed payroll journal cannot be settled.']);
            if ($run->currency === 'MIXED') return $this->settleMixedRun($request, $run, $original, $data);
            $amount = PosMath::money((string) $run->totals['net_pay']);
            if (BigDecimal::of($amount)->isZero()) throw ValidationException::withMessages(['run' => 'This payroll run has no net wages to settle.']);
            if (empty($data['bank_account_id'])) throw ValidationException::withMessages(['bank_account_id' => 'Choose a bank account for this payroll payment.']);
            if ($run->currency !== Company::firstOrFail()->base_currency || $run->currency !== AccountingAccess::setting()->currency) {
                throw ValidationException::withMessages(['currency' => 'Payroll settlement requires payroll, company and ledger currencies to match.']);
            }
            $bank = BankAccount::where('active', true)->where('currency', $run->currency)->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $run->branch_id))->findOrFail($data['bank_account_id']);
            $account = \App\Models\ChartAccount::whereKey($bank->account_id)->where('active', true)->where('is_cash', true)->firstOrFail();
            $payloadHash = PosMath::hash(['run_id' => $run->id, 'book' => $original->book, 'date' => $data['date'], 'bank_account_id' => $bank->id, 'reference' => $data['reference']]);
            $retry = PayrollRunPosting::where('request_key', $data['request_key'])->first();
            if ($retry) {
                if ((int) $retry->payroll_run_id !== (int) $run->id || ! hash_equals((string) $retry->payload_hash, $payloadHash)) throw ValidationException::withMessages(['request_key' => 'This settlement request key was already used for different details.']);
                return $retry;
            }
            if (PayrollRunPosting::where('payroll_run_id', $run->id)->where('book', $original->book)->where('kind', 'settlement')->exists()) {
                throw ValidationException::withMessages(['run' => 'Net wages for this payroll run have already been settled in this accounting book.']);
            }
            $balance = app(AccountingLedger::class)->balance($account->id, $original->book, null, $data['date']);
            if (BigDecimal::of($balance)->isLessThan($amount)) throw ValidationException::withMessages(['bank_account_id' => 'The selected bank account does not have enough recorded funds for this payroll payment.']);
            $ledger = app(AccountingLedger::class);
            $journal = $ledger->system($request->user(), [
                'book' => $original->book, 'source_type' => 'payroll_settlement', 'source_id' => (string) $run->id,
                'branch_id' => $run->branch_id, 'date' => $data['date'], 'reference' => $data['reference'],
                'description' => 'Net payroll paid for '.$run->period_start->format('F Y'), 'cashflow' => 'operating',
                'lines' => [$ledger->line('payroll_net_payable', $amount, '0', 'Settle net payroll'), $ledger->line($account->id, '0', $amount, 'Employee payroll payment')],
            ]);
            if (! $journal) throw ValidationException::withMessages(['run' => 'The payroll settlement journal could not be posted.']);
            $event = PayrollRunPosting::create(['payroll_run_id' => $run->id, 'journal_id' => $journal->id, 'request_key' => $data['request_key'], 'payload_hash' => $payloadHash, 'book' => $original->book, 'kind' => 'settlement', 'actor_id' => $request->user()->id, 'date' => $data['date'], 'reason' => $data['reference'], 'created_at' => now()]);
            Audit::record('payroll.run.settled', (string) $run->id, ['journal_id' => $journal->id, 'book' => $original->book, 'amount' => $amount, 'currency' => $run->currency, 'bank_account_id' => $bank->id]);
            return $event;
        }, 3);
        return $request->expectsJson() ? response()->json(['data' => $posting], 201) : back()->with('success', 'Payroll payment recorded and net wages payable cleared.');
    }

    private function settleMixedRun(Request $request, PayrollRun $run, PayrollRunPosting $original, array $data): PayrollRunPosting
    {
        $payloadHash = PosMath::hash(['run_id' => $run->id, 'book' => $original->book, ...$data]);
        $retry = PayrollRunPosting::where('request_key', $data['request_key'])->first();
        if ($retry) {
            if ((int) $retry->payroll_run_id !== (int) $run->id || ! hash_equals((string) $retry->payload_hash, $payloadHash)) throw ValidationException::withMessages(['request_key' => 'This settlement request key was already used for different details.']);
            return $retry;
        }
        if (PayrollRunPosting::where('payroll_run_id', $run->id)->where('book', $original->book)->whereIn('kind', ['settlement', 'settle_usd', 'settle_zwg'])->exists()) {
            throw ValidationException::withMessages(['run' => 'This mixed payroll run has already been settled in this accounting book.']);
        }
        $base = Company::firstOrFail()->base_currency;
        if (AccountingAccess::setting()->currency !== $base || ! in_array($base, ['USD', 'ZWG'], true)) throw ValidationException::withMessages(['currency' => 'Mixed payroll settlement requires matching USD or ZWG company and ledger currencies.']);
        $fx = $original->fx_snapshot;
        if (! is_array($fx) || empty($fx['ZWG']['usd_per_unit'])) throw ValidationException::withMessages(['currency' => 'The mixed payroll journal has no approved actual-pay-date FX snapshot. Reverse and redraft it after an applicable approved rate is available.']);
        $net = $run->totals['net_pay_by_currency'] ?? [];
        $positiveCurrencies = array_values(array_filter(['USD', 'ZWG'], fn ($code) => BigDecimal::of((string) ($net[$code] ?? '0'))->isPositive()));
        if (! $positiveCurrencies) throw ValidationException::withMessages(['run' => 'This mixed payroll run has no net wages to settle.']);
        $ledger = app(AccountingLedger::class);
        $liabilityAccount = $ledger->account('payroll_net_payable');
        $liabilityBalance = BigDecimal::of($ledger->balance($liabilityAccount->id, $original->book, $run->branch_id, $data['date']))->negated();
        $remainingLiabilityBase = BigDecimal::zero();
        foreach ($positiveCurrencies as $code) {
            $amount = BigDecimal::of((string) $net[$code])->toScale(2, \Brick\Math\RoundingMode::HalfUp);
            if ($code === $base) $baseAmount = $amount;
            elseif ($base === 'USD') $baseAmount = $amount->multipliedBy((string) $fx['ZWG']['usd_per_unit'])->toScale(4, \Brick\Math\RoundingMode::HalfUp);
            else $baseAmount = $amount->dividedBy((string) $fx['ZWG']['usd_per_unit'], 4, \Brick\Math\RoundingMode::HalfUp)->toScale(4, \Brick\Math\RoundingMode::HalfUp);
            $remainingLiabilityBase = $remainingLiabilityBase->plus($baseAmount);
        }
        if ($liabilityBalance->isLessThan($remainingLiabilityBase)) throw ValidationException::withMessages(['run' => 'The recorded net wages payable is insufficient to settle the mixed-currency amounts.']);

        $events = collect();
        $requestKeyAssigned = false;
        foreach ($positiveCurrencies as $code) {
            $amount = BigDecimal::of((string) $net[$code])->toScale(2, \Brick\Math\RoundingMode::HalfUp);
            $reference = substr($data['reference'], 0, 150).'-'.$code;
            $kind = 'settle_'.strtolower($code);
            if ($code === $base) {
                if (empty($data['bank_account_id'])) throw ValidationException::withMessages(['bank_account_id' => 'Choose the company-currency bank account for '.$code.' net pay.']);
                $bank = BankAccount::where('active', true)->where('currency', $base)->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $run->branch_id))->findOrFail($data['bank_account_id']);
                $account = \App\Models\ChartAccount::whereKey($bank->account_id)->where('active', true)->where('is_cash', true)->firstOrFail();
                $balance = BigDecimal::of($ledger->balance($account->id, $original->book, null, $data['date']));
                if ($balance->isLessThan($amount)) throw ValidationException::withMessages(['bank_account_id' => 'The selected bank account does not have enough recorded funds for the '.$code.' payroll payment.']);
                $journal = $ledger->system($request->user(), ['book' => $original->book, 'source_type' => 'payroll_mixed_settlement', 'source_id' => $run->id.':'.$code, 'branch_id' => $run->branch_id, 'date' => $data['date'], 'reference' => $reference, 'description' => 'Mixed payroll '.$code.' wages paid for '.$run->period_start->format('F Y'), 'cashflow' => 'operating', 'lines' => [$ledger->line($liabilityAccount->id, (string) $amount, '0', 'Settle '.$code.' net payroll'), $ledger->line($account->id, '0', (string) $amount, $code.' employee payroll bank payment')]]);
                if (! $journal) throw ValidationException::withMessages(['run' => 'The '.$code.' payroll settlement journal could not be posted.']);
                $entryId = null;
            } else {
                $foreignId = $data['foreign_bank_accounts'][$code] ?? null;
                if (! $foreignId) throw ValidationException::withMessages(['foreign_bank_accounts.'.$code => 'Choose a '.$code.' foreign bank account for the '.$code.' net pay.']);
                $foreign = ForeignAccount::where('kind', 'bank')->where('branch_id', $run->branch_id)->findOrFail($foreignId);
                $currencyCode = Currency::whereKey($foreign->currency_id)->value('code');
                if ($currencyCode !== $code) throw ValidationException::withMessages(['foreign_bank_accounts.'.$code => 'The selected foreign bank account does not match the '.$code.' payroll amount.']);
                $offsetBase = $base === 'USD'
                    ? $amount->multipliedBy((string) $fx['ZWG']['usd_per_unit'])->toScale(4, \Brick\Math\RoundingMode::HalfUp)
                    : $amount->dividedBy((string) $fx['ZWG']['usd_per_unit'], 4, \Brick\Math\RoundingMode::HalfUp)->toScale(4, \Brick\Math\RoundingMode::HalfUp);
                $entry = app(\App\Services\ForeignLedger::class)->payrollBankDecrease($request->user(), $foreign->id, ['request_id' => (string) Str::uuid(), 'date' => $data['date'], 'reference' => $reference, 'amount' => (string) $amount], (string) $offsetBase);
                $journalId = PostingLink::where('book', $original->book)->where('source_type', 'fx_ledger')->where('source_id', (string) $entry->id)->value('journal_id');
                if (! $journalId) throw ValidationException::withMessages(['run' => 'The '.$code.' foreign bank settlement journal could not be found.']);
                $journal = \App\Models\JournalBatch::findOrFail($journalId);
                $entryId = $entry->id;
            }
            $events->push(PayrollRunPosting::create(['payroll_run_id' => $run->id, 'journal_id' => $journal->id, 'request_key' => $requestKeyAssigned ? null : $data['request_key'], 'payload_hash' => $requestKeyAssigned ? null : $payloadHash, 'foreign_entry_id' => $entryId, 'book' => $original->book, 'kind' => $kind, 'actor_id' => $request->user()->id, 'date' => $data['date'], 'reason' => $reference, 'created_at' => now()]));
            $requestKeyAssigned = true;
        }
        Audit::record('payroll.run.settled', (string) $run->id, ['book' => $original->book, 'currency' => 'MIXED', 'amounts' => $net, 'settlement_journals' => $events->pluck('journal_id')->all(), 'foreign_entries' => $events->pluck('foreign_entry_id')->filter()->values()->all()]);
        return $events->first();
    }

    private function csvText(?string $value): string
    {
        $value = $value ?? '';
        return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'".$value : $value;
    }

    public function exportRegister(Request $request, int $id)
    {
        $this->permit($request, 'payroll.runs.export');
        $this->permit($request, 'payroll.compensation.view');
        $run = PayrollRun::with('lines')->whereIn('branch_id', $this->branchIds($request))->findOrFail($id);
        if ($run->status === 'draft') abort(403);
        Audit::record('payroll.run.register_exported', (string) $run->id, ['period_start' => $run->period_start->toDateString(), 'currency' => $run->currency, 'row_count' => $run->lines->count()]);
        return response()->streamDownload(function () use ($run) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee number', 'Employee', 'Role', 'Employee group', 'Grade', 'Currency', 'Amount basis', 'Payable days', 'Period days', 'Gross wages', 'Recurring cash earnings', 'Taxable benefits', 'Allowable deductions', 'Taxable income', 'PAYE before credits', 'Tax credit', 'PAYE', 'AIDS levy', 'NSSA POBS employee', 'NSSA POBS employer', 'APWCS employer', 'APWCS industry code', 'NSSA eligibility basis', 'Annual leave accrued this payroll', 'Annual leave accrued YTD', 'Other cash deductions', 'Net pay', 'Employer cost', 'Salary term segments', 'One-off adjustments', 'Native currency breakdown']);
            foreach ($run->lines as $line) {
                $s = $line->snapshot;
                $prorated = $s['prorated_monthly'] ?? [];
                fputcsv($out, [$this->csvText($s['employee_number'] ?? ''), $this->csvText($s['employee'] ?? ''), $this->csvText($s['job_title'] ?? ''), $this->csvText($s['employee_group'] ?? ''), $this->csvText($s['job_grade'] ?? ''), $run->currency, $run->currency === 'MIXED' ? 'USD equivalent' : $run->currency, $s['payable_days'] ?? '', $s['period_days'] ?? '', $line->gross_salary, $prorated['monthly_recurring_cash_earnings'] ?? $s['monthly_recurring_cash_earnings'] ?? '0', $prorated['monthly_taxable_benefits'] ?? $s['monthly_taxable_benefits'] ?? '0', $prorated['monthly_allowable_deductions'] ?? $s['monthly_allowable_deductions'] ?? '0', $line->taxable_income, $s['paye_before_credits'] ?? '0', $s['tax_credit'] ?? '0', $line->paye, $line->aids_levy, $line->nssa_employee, $line->nssa_employer, $line->apwcs_employer, $this->csvText($s['apwcs_industry_code'] ?? ''), $this->csvText($s['nssa_eligibility_basis'] ?? ''), $s['annual_leave_accrual_days'] ?? '0', $s['annual_leave_cumulative_accrued_days'] ?? '0', $s['cash_deductions'] ?? $s['monthly_cash_deductions'] ?? '0', $line->net_pay, $s['employer_cost'] ?? '0', $this->csvText(json_encode($s['salary_term_segments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), $this->csvText(json_encode($s['adjustments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), $this->csvText(json_encode($s['currency_breakdown'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
            }
            fclose($out);
        }, 'payroll-register-'.$run->period_start->format('Y-m').'-'.$run->currency.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportYearToDate(Request $request)
    {
        $this->permit($request, 'payroll.runs.export');
        $this->permit($request, 'payroll.compensation.view');
        $data = $request->validate(['year' => 'required|integer|min:2000|max:2100']);
        $runs = PayrollRun::with(['lines', 'postings'])->whereIn('branch_id', $this->branchIds($request))->whereYear('period_start', $data['year'])->where(function ($query) {
            $query->where('status', 'approved')->orWhereHas('postings', fn ($q) => $q->where('kind', 'posted'));
        })->get()->filter(fn ($run) => ! $run->postings->contains('kind', 'reversal'));
        $totals = [];
        foreach ($runs as $run) foreach ($run->lines as $line) {
            $key = $line->employee_id.':'.$run->currency;
            $totals[$key] ??= ['employee_number' => $line->snapshot['employee_number'] ?? '', 'employee' => $line->snapshot['employee'] ?? '', 'currency' => $run->currency, 'periods' => 0, 'gross_salary' => '0', 'taxable_income' => '0', 'tax_credits' => '0', 'paye' => '0', 'aids_levy' => '0', 'nssa_employee' => '0', 'nssa_employer' => '0', 'apwcs_employer' => '0', 'cash_deductions' => '0', 'net_pay' => '0'];
            $totals[$key]['periods']++;
            foreach (['gross_salary', 'taxable_income', 'paye', 'aids_levy', 'nssa_employee', 'nssa_employer', 'apwcs_employer', 'net_pay'] as $field) $totals[$key][$field] = PosMath::sum([$totals[$key][$field], $line->$field], 2);
            $totals[$key]['tax_credits'] = PosMath::sum([$totals[$key]['tax_credits'], $line->snapshot['tax_credit'] ?? '0'], 2);
            $totals[$key]['cash_deductions'] = PosMath::sum([$totals[$key]['cash_deductions'], $line->snapshot['cash_deductions'] ?? $line->snapshot['monthly_cash_deductions'] ?? '0'], 2);
        }
        Audit::record('payroll.ytd_exported', (string) $data['year'], ['row_count' => count($totals)]);
        return response()->streamDownload(function () use ($totals) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee number', 'Employee', 'Currency', 'Amount basis', 'Payroll periods', 'Gross wages YTD', 'Taxable income YTD', 'Tax credits YTD', 'PAYE YTD', 'AIDS levy YTD', 'NSSA POBS employee YTD', 'NSSA POBS employer YTD', 'APWCS employer YTD', 'Cash deductions YTD', 'Net pay YTD']);
            foreach ($totals as $row) fputcsv($out, [$this->csvText($row['employee_number']), $this->csvText($row['employee']), $row['currency'], $row['currency'] === 'MIXED' ? 'USD equivalent' : $row['currency'], $row['periods'], $row['gross_salary'], $row['taxable_income'], $row['tax_credits'], $row['paye'], $row['aids_levy'], $row['nssa_employee'], $row['nssa_employer'], $row['apwcs_employer'], $row['cash_deductions'], $row['net_pay']]);
            fclose($out);
        }, 'payroll-ytd-'.$data['year'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportMonthlyReturn(Request $request)
    {
        $this->permit($request, 'payroll.runs.export');
        $this->permit($request, 'payroll.compensation.view');
        $data = $request->validate(['period' => ['required', 'date_format:Y-m'], 'branch_id' => 'nullable|integer']);
        $branchIds = $this->branchIds($request);
        if (! empty($data['branch_id']) && ! $branchIds->contains((int) $data['branch_id'])) abort(403);
        $runs = PayrollRun::with(['lines', 'postings'])
            ->whereIn('branch_id', $branchIds)
            ->when(! empty($data['branch_id']), fn ($q) => $q->where('branch_id', $data['branch_id']))
            ->whereDate('period_start', CarbonImmutable::createFromFormat('!Y-m', $data['period'])->toDateString())
            ->where(function ($query) { $query->where('status', 'approved')->orWhereHas('postings', fn ($q) => $q->where('kind', 'posted')); })
            ->get()
            ->filter(fn ($run) => ! $run->postings->contains('kind', 'reversal'));
        $branchNames = Branch::whereIn('id', $runs->pluck('branch_id')->unique())->pluck('name', 'id');
        $details = [];
        $summary = [];
        foreach ($runs as $run) foreach ($run->lines as $line) {
            $breakdown = $line->snapshot['currency_breakdown'] ?? null;
            foreach ($run->currency === 'MIXED' && is_array($breakdown) ? ['USD', 'ZWG'] : [$run->currency] as $currency) {
                $part = $run->currency === 'MIXED' ? ($breakdown[$currency] ?? []) : [];
                $gross = $run->currency === 'MIXED' ? ($part['gross_cash'] ?? '0') : (string) $line->gross_salary;
                $benefits = $run->currency === 'MIXED' ? ($part['taxable_benefits'] ?? '0') : (string) ($line->snapshot['prorated_monthly']['monthly_taxable_benefits'] ?? $line->snapshot['monthly_taxable_benefits'] ?? '0');
                $taxable = $run->currency === 'MIXED' ? ($part['taxable_income'] ?? '0') : (string) $line->taxable_income;
                $paye = $run->currency === 'MIXED' ? ($part['paye'] ?? '0') : (string) $line->paye;
                $levy = $run->currency === 'MIXED' ? ($part['aids_levy'] ?? '0') : (string) $line->aids_levy;
                $key = $run->branch_id.':'.$currency;
                $branchName = $branchNames[$run->branch_id] ?? '';
                $summary[$key] ??= ['branch_id' => $run->branch_id, 'branch' => $branchName, 'currency' => $currency, 'employees' => [], 'gross_remuneration' => '0', 'taxable_income' => '0', 'paye' => '0', 'aids_levy' => '0', 'total_due' => '0'];
                $summary[$key]['employees'][$line->employee_id] = true;
                foreach (['gross_remuneration' => PosMath::sum([$gross, $benefits], 2), 'taxable_income' => $taxable, 'paye' => $paye, 'aids_levy' => $levy, 'total_due' => PosMath::sum([$paye, $levy], 2)] as $field => $amount) $summary[$key][$field] = PosMath::sum([$summary[$key][$field], $amount], 2);
                $details[] = ['branch' => $branchName, 'run_id' => $run->id, 'employee_number' => $line->snapshot['employee_number'] ?? '', 'employee' => $line->snapshot['employee'] ?? '', 'currency' => $currency, 'gross_remuneration' => PosMath::sum([$gross, $benefits], 2), 'taxable_income' => $taxable, 'paye' => $paye, 'aids_levy' => $levy, 'total_due' => PosMath::sum([$paye, $levy], 2)];
            }
        }
        foreach ($summary as &$row) $row['employee_count'] = count($row['employees']);
        unset($row);
        Audit::record('payroll.monthly_return_workpaper_exported', $data['period'], ['summary_rows' => count($summary), 'detail_rows' => count($details), 'branch_id' => $data['branch_id'] ?? null]);
        return response()->streamDownload(function () use ($summary, $details, $data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Zimbabwe TaRMS monthly PAYE reconciliation workpaper', $data['period'], 'Manual review and portal entry required; this file is not an electronic filing']);
            fputcsv($out, []);
            fputcsv($out, ['Summary by branch and currency']);
            fputcsv($out, ['Branch', 'Currency', 'Employee count', 'Gross remuneration (cash + taxable benefits)', 'Taxable income', 'PAYE', 'AIDS levy', 'Total PAYE and levy due']);
            foreach ($summary as $row) fputcsv($out, [$this->csvText($row['branch']), $row['currency'], $row['employee_count'], $row['gross_remuneration'], $row['taxable_income'], $row['paye'], $row['aids_levy'], $row['total_due']]);
            fputcsv($out, []);
            fputcsv($out, ['Employee detail for TaRMS reconciliation']);
            fputcsv($out, ['Branch', 'Payroll run', 'Employee number', 'Employee', 'Currency', 'Gross remuneration', 'Taxable income', 'PAYE', 'AIDS levy', 'Total due']);
            foreach ($details as $row) fputcsv($out, [$this->csvText($row['branch']), $row['run_id'], $this->csvText($row['employee_number']), $this->csvText($row['employee']), $row['currency'], $row['gross_remuneration'], $row['taxable_income'], $row['paye'], $row['aids_levy'], $row['total_due']]);
            fclose($out);
        }, 'zimra-paye-workpaper-'.$data['period'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function payslip(Request $request, int $runId, int $lineId)
    {
        $this->permit($request, 'payroll.compensation.view');
        $run = PayrollRun::whereIn('branch_id', $this->branchIds($request))->findOrFail($runId);
        abort_unless($run->status === 'approved' && ! $run->postings()->where('kind', 'reversal')->exists(), 404);
        $line = PayrollRunLine::where('payroll_run_id', $run->id)->findOrFail($lineId);
        Audit::record('payroll.payslip_viewed', (string) $run->id, ['employee_id' => $line->employee_id, 'period_start' => $run->period_start->toDateString()]);
        return view('payroll.payslip', ['company' => Company::value('name'), 'run' => $run, 'line' => $line, 'snapshot' => $line->snapshot]);
    }

    public function emailPayslip(Request $request, int $runId, int $lineId)
    {
        $this->permit($request, 'payroll.payslips.email');
        $this->permit($request, 'payroll.compensation.view');
        abort_unless($this->payslipEmailReady(), 503, 'Payslip email delivery is disabled or the mail transport is not safely configured.');
        $data = $request->validate(['request_key' => 'required|uuid']);
        $tenantId = app(TenantContext::class)->requireId();

        $context = DB::transaction(function () use ($request, $runId, $lineId, $data, $tenantId) {
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $run = PayrollRun::whereIn('branch_id', $this->branchIds($request))->findOrFail($runId);
            if ($run->status !== 'approved' || $run->postings()->where('kind', 'reversal')->exists()) {
                throw ValidationException::withMessages(['payslip' => 'Only approved, non-reversed payslips can be emailed.']);
            }
            $line = PayrollRunLine::where('payroll_run_id', $run->id)->findOrFail($lineId);
            $employee = Employee::whereIn('branch_id', $this->branchIds($request))->findOrFail($line->employee_id);
            if (! filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['payslip' => 'Add a valid employee email address in HR before sending this payslip.']);
            }
            $recipientHash = hash_hmac('sha256', mb_strtolower(trim($employee->email)), (string) config('app.key'));
            $payloadHash = PosMath::hash(['run_id' => $run->id, 'line_id' => $line->id, 'recipient_hash' => $recipientHash]);
            $existing = PayrollPayslipDelivery::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) throw ValidationException::withMessages(['request_key' => 'This payslip email request key was used for different details.']);
                if ($existing->status === 'sent') return ['delivery' => $existing, 'run' => $run, 'line' => $line, 'employee' => $employee];
                throw ValidationException::withMessages(['request_key' => 'This request is already recorded. Use a new request key for a deliberate retry.']);
            }
            $delivery = PayrollPayslipDelivery::create([
                'payroll_run_id' => $run->id, 'payroll_run_line_id' => $line->id, 'actor_id' => $request->user()->id,
                'request_key' => $data['request_key'], 'payload_hash' => $payloadHash, 'recipient_hash' => $recipientHash, 'status' => 'sending',
            ]);
            return compact('delivery', 'run', 'line', 'employee');
        }, 3);

        if ($context['delivery']->status === 'sent') {
            return back()->with('success', 'This payslip email request was already completed; it was not sent a second time.');
        }

        try {
            Mail::to($context['employee']->email)->send(new \App\Mail\PayrollPayslipMail(
                (string) Company::value('name'), $context['run'], $context['line'], $context['line']->snapshot,
            ));
        } catch (Throwable $error) {
            $context['delivery']->update(['status' => 'failed', 'failure_code' => class_basename($error)]);
            try {
                Audit::record('payroll.payslip_email_failed', (string) $context['run']->id, [
                    'employee_id' => $context['line']->employee_id, 'delivery_id' => $context['delivery']->id,
                    'failure_code' => class_basename($error),
                ]);
            } catch (Throwable) {
                // The delivery row remains the durable record if the secondary audit stream is unavailable.
            }
            throw ValidationException::withMessages(['payslip' => 'The mail transport rejected the payslip. Check its configuration before retrying.']);
        }
        $context['delivery']->update(['status' => 'sent', 'sent_at' => now()]);
        try {
            Audit::record('payroll.payslip_emailed', (string) $context['run']->id, [
                'employee_id' => $context['line']->employee_id, 'delivery_id' => $context['delivery']->id,
                'currency' => $context['run']->currency, 'period_start' => $context['run']->period_start->toDateString(),
            ]);
        } catch (Throwable) {
            // The delivery row remains the durable record if the secondary audit stream is unavailable.
        }

        return back()->with('success', 'The mail transport accepted the confidential payslip for the employee’s HR email address.');
    }
}
