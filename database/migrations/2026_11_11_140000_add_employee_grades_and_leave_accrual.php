<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employee_group', 24)->nullable();
            $table->string('job_grade', 80)->nullable();
            $table->decimal('annual_leave_entitlement_days', 7, 2)->default(22);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['employee_group', 'job_grade', 'annual_leave_entitlement_days']);
        });
    }
};
