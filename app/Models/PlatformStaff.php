<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PlatformStaff extends Model
{
    protected $fillable = ['user_id', 'active', 'granted_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'granted_at' => 'datetime'];
    }
}
