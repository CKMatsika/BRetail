<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_compensations', function (Blueprint $table) {
            $table->unsignedBigInteger('medical_aid_provider_id')->nullable();
            $table->string('medical_aid_member_number', 100)->nullable();
            $table->decimal('monthly_medical_aid_employee_contribution', 18, 4)->default(0);
            $table->decimal('monthly_medical_aid_employer_contribution', 18, 4)->default(0);
            $table->foreign(['tenant_id', 'medical_aid_provider_id'])->references(['tenant_id', 'id'])->on('medical_aid_providers');
        });
    }

    public function down(): void
    {
        Schema::table('employee_compensations', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'medical_aid_provider_id']);
            $table->dropColumn(['medical_aid_provider_id', 'medical_aid_member_number', 'monthly_medical_aid_employee_contribution', 'monthly_medical_aid_employer_contribution']);
        });
    }
};
