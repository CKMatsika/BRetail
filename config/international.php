<?php

return ['role_permissions' => [
    'Accountant' => ['currencies.view', 'currencies.configure', 'currencies.rates', 'currencies.approve', 'currencies.post', 'currencies.revalue', 'transfers.view', 'transfers.create', 'transfers.approve', 'transfers.dispatch', 'transfers.receive', 'transfers.resolve'],
    'Inventory Manager' => ['transfers.view', 'transfers.create', 'transfers.dispatch', 'transfers.receive'],
    'General Manager' => ['currencies.view', 'transfers.view', 'transfers.approve'],
    'Branch Manager' => ['transfers.view', 'transfers.approve'],
    'Auditor' => ['currencies.view', 'transfers.view'],
]];
