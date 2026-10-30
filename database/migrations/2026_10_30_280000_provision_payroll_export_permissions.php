<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $extra = config('payroll.role_permissions.'.$role->name, []);
            if (! $extra) continue;
            $permissions = json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR);
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...$extra])), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void {}
};
