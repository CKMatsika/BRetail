<?php

return [
    'role_permissions' => [
        'General Manager' => ['tenders.view', 'tenders.manage'],
        'Purchasing Officer' => ['tenders.view', 'tenders.manage'],
        'Accountant' => ['tenders.view', 'tenders.manage'],
        'Auditor' => ['tenders.view'],
        'Sales Assistant' => ['tenders.view'],
    ],
    'reminder_intervals_hours' => [
        336, // 14 days
        168, // 7 days
        72,  // 3 days
        48,  // 48 hours
        24,  // 24 hours
        6,   // 6 hours
        1,   // 1 hour
    ],
    'default_currency' => 'USD',
    'supported_currencies' => ['USD', 'ZWG', 'ZAR', 'EUR', 'GBP'],
];

