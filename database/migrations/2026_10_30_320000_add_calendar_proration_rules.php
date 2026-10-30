<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_rule_sets', function (Blueprint $t) {
            $t->json('daily_paye_bands')->nullable();
            $t->string('proration_basis', 24)->default('none');
            $t->string('proration_source_name', 200)->nullable();
            $t->string('proration_source_url', 1000)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('payroll_runs')->exists()) {
            throw new RuntimeException('Payroll run history may reference calendar proration rules.');
        }
        Schema::table('payroll_rule_sets', function (Blueprint $t) {
            $t->dropColumn(['daily_paye_bands', 'proration_basis', 'proration_source_name', 'proration_source_url']);
        });
    }
};
