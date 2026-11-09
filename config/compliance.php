<?php

return [
    'role_permissions' => [
        'General Manager' => ['compliance.view', 'compliance.manage'],
        'Accountant' => ['compliance.view', 'compliance.manage'],
        'Purchasing Officer' => ['compliance.view', 'compliance.manage'],
        'Auditor' => ['compliance.view'],
        'HR Officer' => ['compliance.view'],
    ],
    'expiry_warning_days' => 30,
];
