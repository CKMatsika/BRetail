<?php

return ['role_permissions' => [
    'General Manager' => ['manufacturing.view','manufacturing.manage','manufacturing.produce'],
    'Branch Manager' => ['manufacturing.view','manufacturing.manage','manufacturing.produce'],
    'Inventory Manager' => ['manufacturing.view','manufacturing.manage','manufacturing.produce'],
    'Accountant' => ['manufacturing.view'], 'Auditor' => ['manufacturing.view'],
]];
