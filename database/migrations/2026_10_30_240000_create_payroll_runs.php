<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_compensations', function (Blueprint $t) {
            $t->decimal('monthly_taxable_benefits', 18, 4)->default(0);
            $t->decimal('monthly_allowable_deductions', 18, 4)->default(0);
            $t->decimal('monthly_tax_credits', 18, 4)->default(0);
            $t->decimal('monthly_cash_deductions', 18, 4)->default(0);
            $t->boolean('nssa_eligible')->default(false);
        });
        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('request_key');
            $t->string('payload_hash', 64);
            $t->unsignedBigInteger('rule_set_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('period_start');
            $t->date('period_end');
            $t->string('currency', 8);
            $t->string('status', 20)->default('draft');
            $t->json('totals');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'request_key']);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'rule_set_id'])->references(['tenant_id', 'id'])->on('payroll_rule_sets');
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
        });
        Schema::create('payroll_run_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('payroll_run_id');
            $t->unsignedBigInteger('employee_id');
            $t->unsignedBigInteger('branch_id');
            $t->json('snapshot');
            $t->decimal('gross_salary', 18, 2);
            $t->decimal('taxable_income', 18, 2);
            $t->decimal('paye', 18, 2);
            $t->decimal('aids_levy', 18, 2);
            $t->decimal('nssa_employee', 18, 2);
            $t->decimal('nssa_employer', 18, 2);
            $t->decimal('net_pay', 18, 2);
            $t->timestamps();
            $t->unique(['tenant_id', 'payroll_run_id', 'employee_id']);
            $t->foreign(['tenant_id', 'payroll_run_id'])->references(['tenant_id', 'id'])->on('payroll_runs');
            $t->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('employees');
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
        });
    }

    public function down(): void
    {
        if (DB::table('payroll_run_lines')->exists() || DB::table('payroll_runs')->exists()) throw new RuntimeException('Payroll run history must be preserved.');
        Schema::dropIfExists('payroll_run_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::table('employee_compensations', function (Blueprint $t) {
            $t->dropColumn(['monthly_taxable_benefits', 'monthly_allowable_deductions', 'monthly_tax_credits', 'monthly_cash_deductions', 'nssa_eligible']);
        });
    }
};
