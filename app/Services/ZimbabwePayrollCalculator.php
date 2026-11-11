<?php

namespace App\Services;

use App\Models\PayrollRuleSet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class ZimbabwePayrollCalculator
{
    private function money(string|int|float $value): BigDecimal
    {
        return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HALF_UP);
    }

    public function calculate(PayrollRuleSet $rule, array $term): array
    {
        $salary = $this->money($term['monthly_salary']);
        $cashEarnings = $this->money($term['cash_earnings'] ?? '0');
        $benefits = $this->money($term['monthly_taxable_benefits']);
        $allowable = $this->money($term['monthly_allowable_deductions']);
        $credit = $this->money($term['monthly_tax_credits']);
        $cashDeductions = $this->money($term['monthly_cash_deductions']);
        $payableDays = (int) ($term['payable_days'] ?? 0);
        $periodDays = (int) ($term['period_days'] ?? 0);
        if ($payableDays < 1 || $periodDays < 1 || $payableDays > $periodDays) {
            throw ValidationException::withMessages(['period' => 'Payroll payable days must be between one and the calendar days in the month.']);
        }
        $dailyTaxBasis = $payableDays < $periodDays || (bool) ($term['daily_tax_basis'] ?? false);
        if ($dailyTaxBasis && ($rule->proration_basis !== 'calendar_days' || ! is_array($rule->daily_paye_bands) || ! $rule->daily_paye_bands)) {
            throw ValidationException::withMessages(['rules' => 'This run includes a partial employment period or salary change. Approve a calendar-day policy and sourced daily PAYE bands before calculating it.']);
        }
        $grossCash = $salary->plus($cashEarnings)->toScale(2, RoundingMode::HALF_UP);
        $taxableBeforeFloor = $grossCash->plus($benefits)->minus($allowable);
        $taxable = ($taxableBeforeFloor->isNegative() ? BigDecimal::zero() : $taxableBeforeFloor)->toScale(2, RoundingMode::HALF_UP);
        $basicTax = null;
        $taxBands = $dailyTaxBasis ? $rule->daily_paye_bands : $rule->paye_bands;
        $taxableForBand = $dailyTaxBasis
            ? $taxable->dividedBy((string) $payableDays, 8, RoundingMode::HALF_UP)
            : $taxable;
        foreach ($taxBands as $band) {
            if ($band['up_to'] === null || $taxableForBand->isLessThanOrEqualTo((string) $band['up_to'])) {
                $rawTax = $taxableForBand->multipliedBy((string) $band['rate'])->dividedBy('100', 2, RoundingMode::HALF_UP)->minus((string) $band['deduct']);
                $periodTax = ($rawTax->isNegative() ? BigDecimal::zero() : $rawTax)->toScale(2, RoundingMode::HALF_UP);
                $basicTax = $dailyTaxBasis ? $periodTax->multipliedBy((string) $payableDays)->toScale(2, RoundingMode::HALF_UP) : $periodTax;
                break;
            }
        }
        if ($basicTax === null) throw ValidationException::withMessages(['rules' => 'PAYE bands do not contain an open-ended final band.']);
        $taxAfterCredit = $basicTax->minus($credit);
        $paye = ($taxAfterCredit->isNegative() ? BigDecimal::zero() : $taxAfterCredit)->toScale(2, RoundingMode::HALF_UP);
        $levy = $paye->multipliedBy((string) $rule->aids_levy_rate)->dividedBy('100', 2, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP);
        $nssaEmployee = BigDecimal::zero();
        $nssaEmployer = BigDecimal::zero();
        $apwcsEmployer = BigDecimal::zero();
        if ($term['nssa_eligible']) {
            if ($rule->nssa_employee_rate === null || $rule->nssa_employer_rate === null) throw ValidationException::withMessages(['rules' => 'NSSA is marked applicable for this employee, but the approved rule set has no NSSA rates.']);
            if ($rule->nssa_ceiling !== null && $rule->nssa_ceiling_currency !== $rule->currency) throw ValidationException::withMessages(['rules' => 'The NSSA earnings ceiling currency differs from the payroll currency; no cross-currency conversion is available.']);
            $ceiling = $rule->nssa_ceiling === null ? null : BigDecimal::of((string) $rule->nssa_ceiling);
            $insurable = $ceiling === null || $grossCash->isLessThanOrEqualTo($ceiling) ? $grossCash : $ceiling;
            $nssaEmployee = $insurable->multipliedBy((string) $rule->nssa_employee_rate)->dividedBy('100', 2, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP);
            $nssaEmployer = $insurable->multipliedBy((string) $rule->nssa_employer_rate)->dividedBy('100', 2, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP);
        }
        if ($term['apwcs_eligible'] ?? false) {
            if ($rule->apwcs_employer_rate === null) throw ValidationException::withMessages(['rules' => 'APWCS applies to this employee, but the approved rule set has no employer industry rate. Add the NSSA-assigned rate and source before calculating payroll.']);
            $apwcsEmployer = $salary->multipliedBy((string) $rule->apwcs_employer_rate)->dividedBy('100', 2, RoundingMode::HalfUp)->toScale(2, RoundingMode::HalfUp);
        }
        $net = $grossCash->minus($paye)->minus($levy)->minus($nssaEmployee)->minus($cashDeductions)->toScale(2, RoundingMode::HALF_UP);
        if ($net->isNegative()) throw ValidationException::withMessages(['salary' => 'Payroll deductions exceed cash salary for '.$term['employee_number'].'.']);

        return [
            'gross_salary' => (string) $grossCash,
            'payable_days' => $payableDays ?: $periodDays,
            'period_days' => $periodDays,
            'taxable_income' => (string) $taxable,
            'paye_before_credits' => (string) $basicTax,
            'tax_credit' => (string) ($credit->isGreaterThan($basicTax) ? $basicTax : $credit)->toScale(2),
            'cash_deductions' => (string) $cashDeductions,
            'cash_earnings' => (string) $cashEarnings,
            'paye' => (string) $paye,
            'aids_levy' => (string) $levy,
            'nssa_employee' => (string) $nssaEmployee,
            'nssa_employer' => (string) $nssaEmployer,
            'apwcs_employer' => (string) $apwcsEmployer,
            'net_pay' => (string) $net,
            'employer_cost' => (string) $grossCash->plus($nssaEmployer)->plus($apwcsEmployer)->toScale(2),
        ];
    }
}
