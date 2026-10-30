<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name', 80);
            $t->decimal('annual_entitlement', 7, 2);
            $t->boolean('paid')->default(true);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('employee_id');
            $t->unsignedBigInteger('leave_type_id');
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->uuid('request_key');
            $t->string('payload_hash', 64);
            $t->date('start_date');
            $t->date('end_date');
            $t->decimal('days', 7, 2);
            $t->text('reason');
            $t->string('status', 20)->default('pending');
            $t->text('decision_note')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'request_key']);
            $t->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('employees');
            $t->foreign(['tenant_id', 'leave_type_id'])->references(['tenant_id', 'id'])->on('leave_types');
        });
    }

    public function down(): void
    {
        if (DB::table('leave_requests')->exists()) throw new RuntimeException('Leave decision history must be preserved.');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
    }
};
