<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained();
            $table->string('plan_name', 100)->default('Unassigned');
            $table->string('status', 20)->default('pending');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->date('trial_ends_on')->nullable();
            $table->date('grace_ends_on')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('billing_reference', 160)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['status', 'ends_on']);
        });

        Schema::create('platform_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamp('granted_at');
            $table->timestamps();
        });

        Schema::create('platform_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('action', 80);
            $table->string('reason', 1000)->nullable();
            $table->json('details');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'created_at']);
        });

        // Existing workspaces continue without interruption; new registrations start pending until activated.
        DB::table('tenants')->orderBy('id')->get(['id', 'created_at'])->each(function ($tenant) {
            DB::table('tenant_subscriptions')->insert([
                'tenant_id' => $tenant->id,
                'plan_name' => 'Existing',
                'status' => 'active',
                'starts_on' => $tenant->created_at ? substr($tenant->created_at, 0, 10) : now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER platform_audit_events_immutable BEFORE UPDATE OR DELETE ON platform_audit_events FOR EACH ROW EXECUTE FUNCTION inventory_immutable()');
        }
    }

    public function down(): void
    {
        if (DB::table('platform_audit_events')->exists() || DB::table('platform_staff')->exists() || DB::table('tenant_subscriptions')->exists()) {
            throw new RuntimeException('Subscription and platform audit history cannot be removed.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS platform_audit_events_immutable ON platform_audit_events');
        }
        Schema::dropIfExists('platform_audit_events');
        Schema::dropIfExists('platform_staff');
        Schema::dropIfExists('tenant_subscriptions');
    }
};
