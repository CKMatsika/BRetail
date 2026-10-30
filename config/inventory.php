<?php

return ['role_permissions' => [
    'General Manager' => ['inventory.view', 'inventory.export'],
    'Branch Manager' => ['inventory.view', 'inventory.count', 'inventory.export'],
    'Inventory Manager' => ['inventory.view', 'inventory.configure', 'inventory.adjust', 'inventory.count', 'inventory.import', 'inventory.export'],
    'Accountant' => ['inventory.view', 'inventory.export'],
    'Purchasing Officer' => ['inventory.view'],
    'Auditor' => ['inventory.view', 'inventory.export'],
]];
