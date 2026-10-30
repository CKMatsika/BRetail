<?php

return [
    'role_permissions' => [
        'General Manager' => ['reports.view', 'reports.export'],
        'Branch Manager' => ['reports.view', 'reports.export'],
        'Accountant' => ['reports.view', 'reports.export'],
        'Auditor' => ['reports.view', 'reports.export'],
    ],
];
