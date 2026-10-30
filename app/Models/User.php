<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $attributes = ['active' => true];

    /** @use HasFactory<UserFactory> */
    use HasFactory, \Laravel\Sanctum\HasApiTokens, Notifiable;

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function platformStaff()
    {
        return $this->hasOne(PlatformStaff::class);
    }

    public function isPlatformStaff(): bool
    {
        return $this->platformStaff()->where('active', true)->exists();
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class)->withPivot('tenant_id');
    }

    public function canDo(string $permission): bool
    {
        if (! $this->active || ! $this->tenant_id || ! $this->role || $this->role->tenant_id !== $this->tenant_id) {
            return false;
        }

        return in_array('*', $this->role->permissions, true) || in_array($permission, $this->role->permissions, true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }
}
