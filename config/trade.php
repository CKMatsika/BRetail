<?php

return ['role_permissions' => [
    'Accountant' => ['purchasing.view', 'purchasing.configure', 'purchasing.create', 'purchasing.approve', 'purchasing.receive', 'purchasing.bill', 'purchasing.pay', 'sales.view', 'sales.configure', 'sales.quote', 'sales.invoice', 'sales.receive', 'sales.return'],
    'Purchasing Officer' => ['purchasing.view', 'purchasing.configure', 'purchasing.create', 'purchasing.receive'],
    'Inventory Manager' => ['purchasing.view', 'purchasing.receive'],
    'General Manager' => ['purchasing.view', 'purchasing.approve', 'sales.view', 'sales.quote'],
    'Branch Manager' => ['purchasing.view', 'purchasing.approve', 'sales.view', 'sales.quote'],
    'Sales Assistant' => ['sales.view', 'sales.quote'],
    'Auditor' => ['purchasing.view', 'sales.view'],
]];
