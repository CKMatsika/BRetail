<?php

return [
    'consent_version' => '2026-10-v1',
    'role_permissions' => [
        'General Manager' => ['loyalty.view', 'loyalty.manage'],
        'Branch Manager' => ['loyalty.view'],
        'Accountant' => ['loyalty.view'],
        'Auditor' => ['loyalty.view'],
    ],
];
