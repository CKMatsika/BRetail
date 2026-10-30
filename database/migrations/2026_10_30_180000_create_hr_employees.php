<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('branch_id');
            $t->string('employee_number', 40);
            $t->string('legal_name', 160);
            $t->string('preferred_name', 120)->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 60)->nullable();
            $t->string('job_title', 120);
            $t->string('status', 20)->default('active');
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'employee_number']);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
        });
        Schema::create('employment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('employee_id');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('kind', 30);
            $t->date('effective_date');
            $t->json('snapshot');
            $t->timestamp('created_at');
            $t->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('employees');
        });
    }

    public function down(): void
    {
        if (DB::table('employment_events')->exists()) {
            throw new RuntimeException('Employment history must be preserved.');
        }
        Schema::dropIfExists('employment_events');
        Schema::dropIfExists('employees');
    }
};
