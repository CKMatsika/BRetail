<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payslip {{ $run->period_start->format('F Y') }} · {{ $snapshot['employee_number'] }}</title>
    <style>
        body{font:14px/1.45 Arial,sans-serif;color:#20252b;margin:0;background:#f3f5f7}
        main{max-width:760px;margin:32px auto;padding:36px;background:white;box-shadow:0 4px 24px #0001}
        header{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #20252b;padding-bottom:20px;margin-bottom:22px}
        h1{font-size:24px;margin:0 0 6px}h2{font-size:16px;margin:24px 0 8px}
        .muted{color:#66717d}.identity{display:grid;grid-template-columns:1fr 1fr;gap:8px 24px}
        .identity div{padding:8px 0;border-bottom:1px solid #e5e8eb}
        table{width:100%;border-collapse:collapse}td,th{padding:10px 8px;border-bottom:1px solid #e5e8eb;text-align:left}td:last-child,th:last-child{text-align:right}
        .net{font-size:18px;font-weight:bold;border-top:2px solid #20252b}
        button{padding:10px 16px;background:#1c6b4a;border:0;border-radius:6px;color:white;font-weight:bold;cursor:pointer}
        @media print{body{background:white}main{margin:0 auto;padding:0;box-shadow:none}button{display:none}}
        @media(max-width:600px){main{margin:0;padding:20px}header{display:block}.identity{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main>
    <header>
        <div><p class="muted">{{ $company }}</p><h1>Pay statement</h1><strong>{{ $run->period_start->format('F Y') }}</strong></div>
        <div><button onclick="window.print()">Print / Save PDF</button><p class="muted">Approved payroll · {{ $run->currency === 'MIXED' ? 'USD + ZWG' : $run->currency }}</p></div>
    </header>
    <section class="identity">
        <div><span class="muted">Employee</span><br><strong>{{ $snapshot['employee'] }}</strong></div>
        <div><span class="muted">Employee number</span><br><strong>{{ $snapshot['employee_number'] }}</strong></div>
        <div><span class="muted">Position</span><br>{{ $snapshot['job_title'] ?: '—' }}</div>
        <div><span class="muted">Pay period</span><br>{{ $run->period_start->format('d M Y') }} – {{ $run->period_end->format('d M Y') }}</div>
        @if (isset($snapshot['payable_days'], $snapshot['period_days']) && $snapshot['payable_days'] < $snapshot['period_days'])
            <div><span class="muted">Employed days this month</span><br>{{ $snapshot['payable_days'] }} / {{ $snapshot['period_days'] }} calendar days</div>
        @endif
    </section>
    @if (count($snapshot['salary_term_segments'] ?? []) > 1)
        <h2>Effective-dated salary terms</h2>
        <table><thead><tr><th>Effective dates</th><th>Days</th><th>Monthly base rate</th><th>Prorated base pay</th></tr></thead><tbody>
            @foreach ($snapshot['salary_term_segments'] as $segment)
                <tr><td>{{ $segment['effective_from'] }} – {{ $segment['effective_to'] }}</td><td>{{ $segment['payable_days'] }}</td><td>{{ $run->currency }} {{ number_format((float) $segment['monthly_salary'], 2) }}</td><td>{{ $run->currency }} {{ number_format((float) $segment['prorated_salary'], 2) }}</td></tr>
            @endforeach
        </tbody></table>
    @endif
    <h2>Earnings and deductions</h2>
    @if (isset($snapshot['currency_breakdown']))
        <p class="muted">PAYE is calculated using the approved USD table. Figures below show amounts withheld and paid in each native currency. Taxable income is converted using the approved exchange rate effective {{ $snapshot['mixed_currency_fx']['ZWG']['effective_on'] }} for the payment date {{ $snapshot['mixed_currency_fx']['payment_date'] }}.</p>
        <table><thead><tr><th>Item</th><th>USD</th><th>ZWG</th></tr></thead><tbody>
            @foreach ([['Gross cash wages', 'gross_cash'], ['Taxable benefits', 'taxable_benefits'], ['Allowable PAYE deductions', 'allowable_deductions'], ['Tax credits', 'tax_credits'], ['PAYE withheld', 'paye'], ['AIDS levy', 'aids_levy'], ['NSSA employee contribution', 'nssa_employee'], ['Other cash deductions', 'other_cash_deductions'], ['Net cash pay', 'net_pay']] as [$label, $key])
                <tr @if ($key === 'net_pay') class="net" @endif><td>{{ $label }}</td><td>USD {{ number_format((float) $snapshot['currency_breakdown']['USD'][$key], 2) }}</td><td>ZWG {{ number_format((float) $snapshot['currency_breakdown']['ZWG'][$key], 2) }}</td></tr>
            @endforeach
        </tbody></table>
        <h2>Tax calculation (USD equivalent)</h2>
        <table><tbody>
            <tr><td>Taxable income</td><td>USD {{ number_format((float) $line->taxable_income, 2) }}</td></tr>
            <tr><td>PAYE before credits</td><td>USD {{ number_format((float) ($snapshot['paye_before_credits'] ?? 0), 2) }}</td></tr>
            <tr><td>Tax credits</td><td>− USD {{ number_format((float) ($snapshot['tax_credit'] ?? 0), 2) }}</td></tr>
            <tr><td>Total PAYE and AIDS levy</td><td>USD {{ number_format((float) $line->paye + (float) $line->aids_levy, 2) }} equivalent</td></tr>
        </tbody></table>
        <p class="muted">This mixed-currency calculation is not yet posted to the accounting ledger or settled through bank accounts.</p>
    @else
    <table><tbody>
        <tr><td>Base cash salary</td><td>{{ $run->currency }} {{ number_format((float) ($snapshot['prorated_monthly']['monthly_salary'] ?? $snapshot['monthly_salary'] ?? $line->gross_salary), 2) }}</td></tr>
        @if ((float) ($snapshot['prorated_monthly']['monthly_recurring_cash_earnings'] ?? $snapshot['monthly_recurring_cash_earnings'] ?? 0) > 0)
            <tr><td>Recurring cash earnings</td><td>{{ $run->currency }} {{ number_format((float) ($snapshot['prorated_monthly']['monthly_recurring_cash_earnings'] ?? $snapshot['monthly_recurring_cash_earnings']), 2) }}</td></tr>
        @endif
        @foreach (collect($snapshot['adjustments'] ?? [])->where('kind', '!=', 'tax_credit') as $adjustment)
            <tr><td>{{ $adjustment['description'] }} ({{ str_replace('_', ' ', $adjustment['kind']) }})</td><td>{{ in_array($adjustment['kind'], ['allowable_deduction', 'tax_credit', 'cash_deduction'], true) ? '− ' : '' }}{{ $run->currency }} {{ number_format((float) $adjustment['amount'], 2) }}</td></tr>
        @endforeach
        <tr><td>Total gross cash wages</td><td>{{ $run->currency }} {{ number_format((float) $line->gross_salary, 2) }}</td></tr>
        <tr><td>Taxable noncash benefits</td><td>{{ $run->currency }} {{ number_format((float) ($snapshot['prorated_monthly']['monthly_taxable_benefits'] ?? $snapshot['monthly_taxable_benefits'] ?? 0), 2) }}</td></tr>
        <tr><td>Allowable PAYE deductions</td><td>{{ $run->currency }} {{ number_format((float) ($snapshot['prorated_monthly']['monthly_allowable_deductions'] ?? $snapshot['monthly_allowable_deductions'] ?? 0), 2) }}</td></tr>
        <tr><td>Taxable income</td><td>{{ $run->currency }} {{ number_format((float) $line->taxable_income, 2) }}</td></tr>
        <tr><td>PAYE before credits</td><td>{{ $run->currency }} {{ number_format((float) ($snapshot['paye_before_credits'] ?? 0), 2) }}</td></tr>
        <tr><td>Tax credits</td><td>− {{ $run->currency }} {{ number_format((float) ($snapshot['tax_credit'] ?? 0), 2) }}</td></tr>
        <tr><td>PAYE withheld</td><td>{{ $run->currency }} {{ number_format((float) $line->paye, 2) }}</td></tr>
        <tr><td>AIDS levy</td><td>{{ $run->currency }} {{ number_format((float) $line->aids_levy, 2) }}</td></tr>
        <tr><td>NSSA employee contribution</td><td>{{ $run->currency }} {{ number_format((float) $line->nssa_employee, 2) }}</td></tr>
        <tr><td>Other cash deductions</td><td>{{ $run->currency }} {{ number_format((float) ($snapshot['cash_deductions'] ?? $snapshot['monthly_cash_deductions'] ?? 0), 2) }}</td></tr>
        <tr class="net"><td>Net cash pay</td><td>{{ $run->currency }} {{ number_format((float) $line->net_pay, 2) }}</td></tr>
    </tbody></table>
    @endif
    <p class="muted">Prepared from approved payroll run {{ $run->id }}. Noncash benefits affect taxable income but are not paid as cash. This statement is confidential.</p>
</main>
</body>
</html>
