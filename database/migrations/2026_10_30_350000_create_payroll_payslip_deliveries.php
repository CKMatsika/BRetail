<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_lines', function (Blueprint $t) {
            $t->unique(['tenant_id', 'payroll_run_id', 'id'], 'payroll_lines_tenant_run_id_unique');
        });
        Schema::create('payroll_payslip_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('payroll_run_id');
            $t->unsignedBigInteger('payroll_run_line_id');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->uuid('request_key');
            $t->string('payload_hash', 64);
            $t->string('recipient_hash', 64);
            $t->string('status', 16)->default('sending');
            $t->string('failure_code', 100)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'request_key']);
            $t->foreign(['tenant_id', 'payroll_run_id', 'payroll_run_line_id'])
                ->references(['tenant_id', 'payroll_run_id', 'id'])->on('payroll_run_lines');
        });
    }

    public function down(): void
    {
        if (DB::table('payroll_payslip_deliveries')->exists()) {
            throw new RuntimeException('Preserve payslip delivery audit records before rollback.');
        }
        Schema::dropIfExists('payroll_payslip_deliveries');
        Schema::table('payroll_run_lines', function (Blueprint $t) {
            $t->dropUnique('payroll_lines_tenant_run_id_unique');
        });
    }
};
