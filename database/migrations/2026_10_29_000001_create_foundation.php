<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name');
            $t->json('permissions');
            $t->boolean('company_wide')->default(false);
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('tenant_id')->nullable()->constrained();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on('roles');
        });
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->unique()->constrained();
            $t->string('name');
            $t->string('industry');
            $t->string('base_currency', 3);
            $t->json('accepted_currencies');
            $t->json('settings');
            $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name');
            $t->string('code', 20);
            $t->string('address')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('branch_id');
            $t->string('name');
            $t->timestamps();
            $t->unique(['tenant_id', 'branch_id', 'name']);
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
        });
        Schema::create('branch_user', function (Blueprint $t) {
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id');
            $t->primary(['branch_id', 'user_id']);
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
            $t->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
        });
        Schema::create('tenant_modules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('key');
            $t->boolean('enabled')->default(false);
            $t->json('settings');
            $t->timestamps();
            $t->unique(['tenant_id', 'key']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action');
            $t->string('subject');
            $t->json('details');
            $t->timestamp('created_at');
            $t->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'tenant_modules', 'branch_user', 'warehouses', 'branches', 'companies'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'role_id']);
            $t->dropForeign(['tenant_id']);
            $t->dropUnique(['tenant_id', 'id']);
            $t->dropColumn(['tenant_id', 'role_id', 'active']);
        });
        Schema::dropIfExists('roles');
        Schema::dropIfExists('tenants');
    }
};
