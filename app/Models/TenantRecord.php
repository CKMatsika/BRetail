<?php

namespace App\Models;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class TenantRecord extends Model
{
    protected $guarded = ['id', 'tenant_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            $q->where($q->getModel()->qualifyColumn('tenant_id'), app(TenantContext::class)->requireId());
        });
        static::creating(function (Model $m) {
            $m->tenant_id = app(TenantContext::class)->requireId();
        });
        static::updating(function (Model $m) {
            if ($m->isDirty('tenant_id')) {
                throw new \LogicException('Tenant ownership is immutable.');
            }
        });
    }
}
