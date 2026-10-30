<?php

return [
    'role_permissions' => [
        'Accountant' => ['accounting.view', 'accounting.post', 'accounting.configure', 'accounting.periods', 'accounting.reverse', 'accounting.bank', 'accounting.purchase', 'accounting.export'],
        'General Manager' => ['accounting.view', 'accounting.export'],
        'Branch Manager' => ['accounting.view'],
        'Auditor' => ['accounting.view', 'accounting.export'],
    ],
    'accounts' => [
        ['1000', 'Cash safe', 'asset', true, 'cash_safe'], ['1005', 'Cash drawers', 'asset', true, 'cash'], ['1010', 'Bank', 'asset', true, 'bank'], ['1020', 'Card settlement clearing', 'asset', false, 'card'], ['1030', 'Mobile money', 'asset', true, 'mobile_money'],
        ['1100', 'Accounts receivable', 'asset', false, 'receivable'], ['1200', 'Inventory', 'asset', false, 'inventory'], ['1300', 'Input tax', 'asset', false, 'tax_receivable'], ['1500', 'Equipment', 'asset', false, null],
        ['2000', 'Accounts payable', 'liability', false, 'payable'], ['2100', 'Output tax', 'liability', false, 'tax_payable'], ['2500', 'Loans', 'liability', false, null],
        ['3000', 'Owner capital', 'equity', false, null], ['3100', 'Retained earnings', 'equity', false, null], ['3200', 'Opening balance equity', 'equity', false, 'opening_equity'],
        ['4000', 'Retail sales', 'income', false, 'sales'], ['4100', 'Sales returns', 'income', false, 'returns'], ['4200', 'Inventory gains', 'income', false, 'inventory_gain'], ['4300', 'Cash overages', 'income', false, 'cash_over'],
        ['5000', 'Cost of goods sold', 'expense', false, 'cogs'], ['5100', 'Inventory losses', 'expense', false, 'inventory_loss'], ['5200', 'Operating expenses', 'expense', false, 'expense'], ['5300', 'Bank charges', 'expense', false, 'bank_fees'], ['5400', 'Cash shortages', 'expense', false, 'cash_short'], ['5500', 'Rounding differences', 'expense', false, 'rounding'],
        ['5600', 'Payroll expense', 'expense', false, 'payroll_expense'], ['5610', 'Employer social security expense', 'expense', false, 'payroll_nssa_expense'],
        ['2200', 'Net wages payable', 'liability', false, 'payroll_net_payable'], ['2210', 'PAYE and AIDS levy payable', 'liability', false, 'payroll_paye_payable'], ['2220', 'NSSA payable', 'liability', false, 'payroll_nssa_payable'], ['2230', 'Other payroll deductions payable', 'liability', false, 'payroll_other_deductions'],
    ],
];
