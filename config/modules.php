<?php

return [
    'dashboard' => ['name' => 'Overview', 'phase' => 1, 'required' => true, 'depends' => []],
    'branches' => ['name' => 'Branches & warehouses', 'phase' => 1, 'required' => true, 'depends' => []],
    'access' => ['name' => 'Team & access', 'phase' => 1, 'required' => true, 'depends' => []],
    'administration' => ['name' => 'Administration', 'phase' => 1, 'required' => true, 'depends' => []],
    'inventory' => ['name' => 'Inventory', 'phase' => 2, 'available' => true, 'depends' => ['branches']],
    'pos' => ['name' => 'Point of sale', 'phase' => 3, 'available' => true, 'depends' => ['inventory'], 'live_depends' => ['accounting']],
    'sales' => ['name' => 'Sales & customers', 'phase' => 5, 'available' => true, 'depends' => ['inventory', 'accounting']],
    'accounting' => ['name' => 'Accounting & finance', 'phase' => 4, 'available' => true, 'depends' => ['inventory']],
    'purchasing' => ['name' => 'Purchasing', 'phase' => 5, 'available' => true, 'depends' => ['inventory', 'accounting']],
    'currencies' => ['name' => 'Multi-currency', 'phase' => 6, 'available' => true, 'depends' => ['accounting','inventory']],
    'hr' => ['name' => 'Human resources', 'phase' => 7, 'available' => true, 'depends' => ['branches']],
    'payroll' => ['name' => 'Payroll', 'phase' => 7, 'available' => true, 'depends' => ['hr', 'accounting']],
    'loyalty' => ['name' => 'Loyalty & promotions', 'phase' => 8, 'depends' => ['sales']],
    'reports' => ['name' => 'Reports & analytics', 'phase' => 8, 'available' => true, 'depends' => ['accounting']],
    'mobile' => ['name' => 'Mobile & offline services', 'phase' => 8, 'depends' => ['pos']],
    'backoffice' => ['name' => 'Back office', 'phase' => 5, 'available' => true, 'depends' => ['sales', 'purchasing']],
];
