<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_rule_sets', function (Blueprint $table) {
            $table->decimal('annual_bonus_exempt_limit', 18, 4)->nullable();
            $table->string('annual_bonus_exempt_currency', 8)->nullable();
            $table->string('bonus_exemption_source_name', 200)->nullable();
            $table->text('bonus_exemption_source_url')->nullable();
            $table->decimal('medical_aid_credit_rate', 7, 4)->nullable();
            $table->string('medical_aid_credit_source_name', 200)->nullable();
            $table->text('medical_aid_credit_source_url')->nullable();
        });
        Schema::create('payroll_heads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('kind', 32);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });
        Schema::create('medical_aid_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name', 160);
            $table->string('member_number_label', 80)->nullable();
            $table->string('source_name', 200)->nullable();
            $table->text('source_url')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_aid_providers');
        Schema::dropIfExists('payroll_heads');
        Schema::table('payroll_rule_sets', function (Blueprint $table) {
            $table->dropColumn(['annual_bonus_exempt_limit', 'annual_bonus_exempt_currency', 'bonus_exemption_source_name', 'bonus_exemption_source_url', 'medical_aid_credit_rate', 'medical_aid_credit_source_name', 'medical_aid_credit_source_url']);
        });
    }
};
