<?php

return [
    'role_permissions' => [
        'System Administrator' => ['hr.view', 'hr.create', 'hr.edit', 'hr.personal.view', 'hr.personal.manage', 'hr.history.view', 'hr.leave.manage', 'hr.leave.request', 'hr.leave.approve'],
        'Business Owner' => ['hr.view', 'hr.create', 'hr.edit', 'hr.personal.view', 'hr.personal.manage', 'hr.history.view', 'hr.leave.manage', 'hr.leave.request', 'hr.leave.approve'],
        'General Manager' => ['hr.view', 'hr.history.view', 'hr.leave.request', 'hr.leave.approve'],
        'HR Officer' => ['hr.view', 'hr.create', 'hr.edit', 'hr.personal.view', 'hr.personal.manage', 'hr.history.view', 'hr.leave.manage', 'hr.leave.request', 'hr.leave.approve'],
        'Payroll Officer' => ['hr.view'],
        'Auditor' => ['hr.view', 'hr.history.view'],
    ],
];
