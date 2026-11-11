<?php

namespace App\Models;

class MedicalAidProvider extends TenantRecord
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
