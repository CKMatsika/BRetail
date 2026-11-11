<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confidential payslip</title></head>
<body style="margin:0;background:#f3f5f7;color:#20252b;font:15px/1.5 Arial,sans-serif">
<main style="max-width:680px;margin:24px auto;padding:28px;background:#fff">
    <p style="color:#66717d">{{ $company }}</p>
    <h1 style="font-size:23px;margin-bottom:4px">Confidential pay statement</h1>
    <p>{{ $run->period_start->format('F Y') }} · {{ $run->currency }}</p>
    <p>Hello {{ $snapshot['employee'] }},</p>
    <p>This statement covers {{ $run->period_start->format('d M Y') }} to {{ $run->period_end->format('d M Y') }}.</p>
    @if (isset($snapshot['payable_days'], $snapshot['period_days']) && $snapshot['payable_days'] < $snapshot['period_days'])
        <p>Payable calendar days: {{ $snapshot['payable_days'] }} / {{ $snapshot['period_days'] }}.</p>
    @endif
    <table role="presentation" style="width:100%;border-collapse:collapse">
        <tbody>
        <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">Base cash salary</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ $run->currency }} {{ number_format((float) ($snapshot['prorated_monthly']['monthly_salary'] ?? $snapshot['monthly_salary'] ?? 0), 2) }}</td></tr>
        @if ((float) ($snapshot['prorated_monthly']['monthly_recurring_cash_earnings'] ?? $snapshot['monthly_recurring_cash_earnings'] ?? 0) > 0)
            <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">Recurring cash earnings</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ $run->currency }} {{ number_format((float) ($snapshot['prorated_monthly']['monthly_recurring_cash_earnings'] ?? $snapshot['monthly_recurring_cash_earnings']), 2) }}</td></tr>
        @endif
        @foreach (collect($snapshot['adjustments'] ?? [])->where('kind', '!=', 'tax_credit') as $adjustment)
            <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">{{ $adjustment['description'] }} ({{ str_replace('_', ' ', $adjustment['kind']) }})</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ in_array($adjustment['kind'], ['allowable_deduction', 'cash_deduction'], true) ? '− ' : '' }}{{ $run->currency }} {{ number_format((float) $adjustment['amount'], 2) }}</td></tr>
        @endforeach
        <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">Gross cash wages</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ $run->currency }} {{ number_format((float) $line->gross_salary, 2) }}</td></tr>
        <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">Taxable noncash benefits</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ $run->currency }} {{ number_format((float) ($snapshot['prorated_monthly']['monthly_taxable_benefits'] ?? $snapshot['monthly_taxable_benefits'] ?? 0), 2) }}</td></tr>
        <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">Allowable PAYE deductions</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ $run->currency }} {{ number_format((float) ($snapshot['prorated_monthly']['monthly_allowable_deductions'] ?? $snapshot['monthly_allowable_deductions'] ?? 0), 2) }}</td></tr>
        <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">Taxable income</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ $run->currency }} {{ number_format((float) $line->taxable_income, 2) }}</td></tr>
        <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">PAYE before credits</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ $run->currency }} {{ number_format((float) ($snapshot['paye_before_credits'] ?? 0), 2) }}</td></tr>
        <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">Tax credits</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">− {{ $run->currency }} {{ number_format((float) ($snapshot['tax_credit'] ?? 0), 2) }}</td></tr>
        <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">PAYE</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ $run->currency }} {{ number_format((float) $line->paye, 2) }}</td></tr>
        <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">AIDS levy</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ $run->currency }} {{ number_format((float) $line->aids_levy, 2) }}</td></tr>
        <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">NSSA employee contribution</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ $run->currency }} {{ number_format((float) $line->nssa_employee, 2) }}</td></tr>
        <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">Other cash deductions</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ $run->currency }} {{ number_format((float) ($snapshot['cash_deductions'] ?? 0), 2) }}</td></tr>
        <tr><td style="padding:12px 9px;border-top:2px solid #20252b;font-weight:bold">Net cash pay</td><td style="padding:12px 9px;border-top:2px solid #20252b;text-align:right;font-weight:bold">{{ $run->currency }} {{ number_format((float) $line->net_pay, 2) }}</td></tr>
        </tbody>
    </table>
    @if (count($snapshot['salary_term_segments'] ?? []) > 1)
        <h2 style="font-size:16px;margin-top:24px">Effective-dated salary terms</h2>
        <ul>@foreach ($snapshot['salary_term_segments'] as $segment)<li>{{ $segment['effective_from'] }} – {{ $segment['effective_to'] }} · {{ $segment['payable_days'] }} days · {{ $run->currency }} {{ number_format((float) $segment['prorated_salary'], 2) }} base pay</li>@endforeach</ul>
    @endif
    @if (isset($snapshot['annual_leave_accrual_days']))
        <h2 style="font-size:16px;margin-top:24px">Annual leave balance</h2>
        <table role="presentation" style="width:100%;border-collapse:collapse"><tbody>
            <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">Accrued this payroll</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ number_format((float) $snapshot['annual_leave_accrual_days'], 2) }} working days</td></tr>
            <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">Accrued year to date</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ number_format((float) $snapshot['annual_leave_cumulative_accrued_days'], 2) }} working days</td></tr>
            <tr><td style="padding:9px;border-bottom:1px solid #e5e8eb">Annual entitlement</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e5e8eb">{{ number_format((float) $snapshot['annual_leave_entitlement_days'], 2) }} working days/year</td></tr>
        </tbody></table>
    @endif
    <p style="margin-top:24px;color:#66717d;font-size:12px">This message contains confidential employment and pay information. If you received it in error, contact your employer and delete this message.</p>
</main>
</body>
</html>
