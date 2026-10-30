<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_rule_sets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('jurisdiction', 8);
            $t->string('currency', 8);
            $t->string('frequency', 20)->default('monthly');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->json('paye_bands');
            $t->decimal('aids_levy_rate', 7, 4);
            $t->decimal('nssa_employee_rate', 7, 4)->nullable();
            $t->decimal('nssa_employer_rate', 7, 4)->nullable();
            $t->decimal('nssa_ceiling', 18, 4)->nullable();
            $t->string('nssa_ceiling_currency', 8)->nullable();
            $t->string('source_name', 200);
            $t->text('source_url');
            $t->string('nssa_source_name', 200)->nullable();
            $t->text('nssa_source_url')->nullable();
            $t->string('status', 20)->default('draft');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('employee_compensations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('employee_id');
            $t->string('currency', 8);
            $t->decimal('monthly_salary', 18, 4);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('employees');
            $t->unique(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('employee_compensations')->exists() || DB::table('payroll_rule_sets')->exists()) {
            throw new RuntimeException('Payroll history must be preserved.');
        }
        Schema::dropIfExists('employee_compensations');
        Schema::dropIfExists('payroll_rule_sets');
    }
};
