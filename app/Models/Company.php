<?php

namespace App\Models;

class Company extends TenantRecord
{
    protected $hidden = ['logo_path'];

    protected $appends = ['logo_url'];

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? '/company/logo?v='.substr(hash('sha256', $this->logo_path), 0, 12) : null;
    }

    protected function casts(): array
    {
        return ['settings' => 'array', 'accepted_currencies' => 'array'];
    }
}
