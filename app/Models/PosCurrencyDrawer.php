<?php

namespace App\Models;

class PosCurrencyDrawer extends TenantRecord
{
    public function account()
    {
        return $this->belongsTo(ForeignAccount::class, 'foreign_account_id');
    }
}
