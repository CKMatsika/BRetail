<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_customers', function (Blueprint $table) {
            $table->string('loyalty_number', 32)->nullable();
            $table->timestamp('loyalty_consent_at')->nullable();
            $table->string('loyalty_consent_version', 32)->nullable();
            $table->timestamp('loyalty_opted_out_at')->nullable();
            $table->unique(['tenant_id', 'loyalty_number']);
        });

        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained();
            $table->boolean('active')->default(true);
            $table->decimal('earn_points_per_currency', 12, 3)->default('1.000');
            $table->decimal('value_per_point', 12, 4)->default('0.0100');
            $table->decimal('max_redemption_percent', 5, 2)->default('50.00');
            $table->decimal('minimum_redemption_points', 12, 3)->default('100.000');
            $table->unsignedInteger('expiry_days')->default(365);
            $table->string('consent_version', 32)->default('2026-10-v1');
            $table->timestamps();
        });

        Schema::create('loyalty_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unique(['tenant_id', 'id']);
            $table->string('name', 120);
            $table->decimal('multiplier', 5, 2);
            $table->decimal('minimum_spend', 20, 2)->default('0.00');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'id'])->on('users');
            $table->timestamps();
            $table->index(['tenant_id', 'active', 'starts_on', 'ends_on']);
        });

        Schema::create('loyalty_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unique(['tenant_id', 'id']);
            foreach (['customer_id' => 'pos_customers', 'branch_id' => 'branches'] as $column => $target) {
                $table->unsignedBigInteger($column);
                $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($target);
            }
            foreach (['sale_id' => 'pos_sales', 'return_id' => 'pos_returns'] as $column => $target) {
                $table->unsignedBigInteger($column)->nullable();
                $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($target);
            }
            $table->unsignedBigInteger('source_entry_id')->nullable();
            $table->foreign(['tenant_id', 'source_entry_id'])->references(['tenant_id', 'id'])->on('loyalty_entries');
            $table->unsignedBigInteger('reverses_entry_id')->nullable();
            $table->foreign(['tenant_id', 'reverses_entry_id'])->references(['tenant_id', 'id'])->on('loyalty_entries');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->foreign(['tenant_id', 'actor_id'])->references(['tenant_id', 'id'])->on('users');
            $table->uuid('request_id');
            $table->string('event_key', 160);
            $table->string('kind', 24);
            $table->decimal('points', 22, 3);
            $table->string('currency', 3);
            $table->decimal('amount_base', 24, 4)->default('0');
            $table->date('expires_on')->nullable();
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'request_id']);
            $table->unique(['tenant_id', 'event_key']);
            $table->index(['tenant_id', 'customer_id', 'occurred_at']);
            $table->index(['tenant_id', 'sale_id', 'kind']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE TRIGGER loyalty_entries_immutable BEFORE UPDATE OR DELETE ON loyalty_entries FOR EACH ROW EXECUTE FUNCTION inventory_immutable()");
        }
    }

    public function down(): void
    {
        if (DB::table('loyalty_entries')->exists()) {
            throw new RuntimeException('Loyalty history is append-only and cannot be removed.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS loyalty_entries_immutable ON loyalty_entries;');
        }
        Schema::dropIfExists('loyalty_entries');
        Schema::dropIfExists('loyalty_promotions');
        Schema::dropIfExists('loyalty_programs');
        Schema::table('pos_customers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'loyalty_number']);
            $table->dropColumn(['loyalty_number', 'loyalty_consent_at', 'loyalty_consent_version', 'loyalty_opted_out_at']);
        });
    }
};
