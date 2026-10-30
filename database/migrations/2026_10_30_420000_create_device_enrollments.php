<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->uuid('device_uuid');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 120);
            $table->string('platform', 30);
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'device_uuid']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('device_enrollments')->exists()) {
            throw new RuntimeException('Preserve device enrollment and revocation history.');
        }
        Schema::dropIfExists('device_enrollments');
    }
};
