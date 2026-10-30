<?php

return [
    'training_enabled' => (bool) env('POS_TRAINING_ENABLED', false),
    'cashier_discount_limit' => '10.00',
    'report_timezone' => env('POS_REPORT_TIMEZONE', 'Africa/Harare'),
    'methods' => ['cash', 'card', 'mobile_money', 'bank_transfer'],
    'role_permissions' => [
        'Cashier' => ['pos.view', 'pos.sell', 'pos.shift', 'pos.customer'],
        'Sales Assistant' => ['pos.view', 'pos.sell', 'pos.shift', 'pos.customer'],
        'Branch Manager' => ['pos.view', 'pos.sell', 'pos.shift', 'pos.customer', 'pos.refund', 'pos.manage', 'pos.discount.override'],
        'General Manager' => ['pos.view', 'pos.sell', 'pos.shift', 'pos.customer', 'pos.refund', 'pos.manage', 'pos.discount.override'],
        'Accountant' => ['pos.view', 'pos.manage'],
        'Auditor' => ['pos.view', 'pos.manage'],
    ],
];
