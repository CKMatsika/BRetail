<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach (['tenders', 'compliance'] as $key) {
                DB::table('tenant_modules')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'key' => $key],
                    ['enabled' => false, 'settings' => json_encode(['preferred' => false]), 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('tenant_modules')->whereIn('key', ['tenders', 'compliance'])->delete();
    }
};
