<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->get(['id', 'settings']) as $company) {
            $settings = is_array($company->settings) ? $company->settings : (json_decode($company->settings ?? '{}', true) ?: []);
            $rates = $settings['tax_rates'] ?? [];
            foreach ($rates as &$tax) {
                $tax['id'] = $tax['id'] ?? (string) Str::uuid();
            }
            unset($tax);
            if ($rates) {
                $settings['tax_rates'] = $rates;
                DB::table('companies')->where('id', $company->id)->update(['settings' => json_encode($settings)]);
            }
        }
    }

    public function down(): void
    {
        // Profile IDs may already be referenced by inventory products; keep them on rollback.
    }
};
