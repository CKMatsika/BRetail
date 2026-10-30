<?php

return [
    'payslip_email_enabled' => env('PAYROLL_PAYSLIP_EMAIL_ENABLED', false),
    'role_permissions' => [
        'System Administrator' => ['payroll.view', 'payroll.rules.manage', 'payroll.rules.approve', 'payroll.compensation.view', 'payroll.compensation.manage', 'payroll.runs.view', 'payroll.runs.create', 'payroll.runs.approve', 'payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.export', 'payroll.runs.settle', 'payroll.payslips.email'],
        'Business Owner' => ['payroll.view', 'payroll.rules.manage', 'payroll.rules.approve', 'payroll.compensation.view', 'payroll.compensation.manage', 'payroll.runs.view', 'payroll.runs.create', 'payroll.runs.approve', 'payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.export', 'payroll.runs.settle', 'payroll.payslips.email'],
        'Accountant' => ['payroll.view', 'payroll.runs.view', 'payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.settle'],
        'Payroll Officer' => ['payroll.view', 'payroll.rules.manage', 'payroll.compensation.view', 'payroll.compensation.manage', 'payroll.runs.view', 'payroll.runs.create', 'payroll.runs.post', 'payroll.runs.export', 'payroll.runs.settle', 'payroll.payslips.email'],
        'Auditor' => ['payroll.view'],
    ],
];
