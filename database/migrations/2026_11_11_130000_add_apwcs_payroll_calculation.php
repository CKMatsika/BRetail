<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_rule_sets', function (Blueprint $table) {
            $table->decimal('apwcs_employer_rate', 7, 4)->nullable();
            $table->string('apwcs_industry_code', 40)->nullable();
            $table->string('apwcs_source_name', 200)->nullable();
            $table->text('apwcs_source_url')->nullable();
        });
        Schema::table('payroll_run_lines', function (Blueprint $table) {
            $table->decimal('apwcs_employer', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        if (DB::table('payroll_run_lines')->where('apwcs_employer', '>', 0)->exists()) {
            throw new RuntimeException('APWCS payroll history must be preserved.');
        }
        Schema::table('payroll_run_lines', fn (Blueprint $table) => $table->dropColumn('apwcs_employer'));
        Schema::table('payroll_rule_sets', fn (Blueprint $table) => $table->dropColumn(['apwcs_employer_rate', 'apwcs_industry_code', 'apwcs_source_name', 'apwcs_source_url']));
    }
};
