<?php

return [
    'roles' => [
        'System Administrator' => ['*'], 'Business Owner' => ['*'],
        'General Manager' => ['dashboard.view', 'branches.view', 'branches.create', 'branches.edit', 'company.view', 'users.view'],
        'Branch Manager' => ['dashboard.view', 'branches.view', 'branches.edit'],
        'Accountant' => ['dashboard.view', 'branches.view', 'company.view'],
        'Inventory Manager' => ['dashboard.view', 'branches.view'], 'Purchasing Officer' => ['dashboard.view', 'branches.view'],
        'Cashier' => ['dashboard.view'], 'Sales Assistant' => ['dashboard.view'],
        'HR Officer' => ['dashboard.view', 'users.view'], 'Payroll Officer' => ['dashboard.view'],
        'Auditor' => ['dashboard.view', 'branches.view', 'company.view', 'users.view', 'modules.view', 'audit.view'],
    ],
    'company_wide_roles' => ['System Administrator', 'Business Owner', 'General Manager', 'Accountant', 'Auditor', 'HR Officer'],
    'templates' => [
        'general' => ['label' => 'General retail', 'attributes' => ['Brand', 'Unit']],
        'apparel' => ['label' => 'Baby clothing & fashion', 'attributes' => ['Size', 'Colour', 'Brand', 'Age group', 'Season']],
        'electronics' => ['label' => 'Computers & accessories', 'attributes' => ['Brand', 'Model', 'Processor', 'RAM', 'Storage', 'Warranty months', 'Serial number']],
    ],
];
