<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foreign_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('currency_id');
            $t->unsignedBigInteger('account_id');
            $t->string('name', 120);
            $t->string('kind', 24);
            $t->timestamps();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'account_id']);
            foreach (['branch_id' => 'branches', 'currency_id' => 'currencies', 'account_id' => 'chart_accounts'] as $col => $table) {
                $t->foreign(['tenant_id', $col])->references(['tenant_id', 'id'])->on($table);
            }
        });
        Schema::create('foreign_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('foreign_account_id');
            $t->string('book', 16);
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->string('kind', 24);
            $t->date('date');
            $t->string('reference', 160);
            $t->decimal('original_amount', 24, 4);
            $t->decimal('base_amount', 24, 4);
            $t->json('snapshot');
            $t->unsignedBigInteger('reverses_id')->nullable();
            $t->timestamp('created_at');
            $t->unique(['tenant_id', 'request_id']);
            $t->unique(['tenant_id', 'reverses_id']);
            $t->foreign(['tenant_id', 'foreign_account_id'])->references(['tenant_id', 'id'])->on('foreign_accounts');
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach (['foreign_entries', 'transfer_events'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION inventory_immutable()");
            }
            DB::unprepared("CREATE OR REPLACE FUNCTION approved_rate_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF OLD.approved_at IS NOT NULL THEN RAISE EXCEPTION 'Approved rates are immutable'; END IF; IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END $$; CREATE TRIGGER exchange_rates_immutable BEFORE UPDATE OR DELETE ON exchange_rates FOR EACH ROW EXECUTE FUNCTION approved_rate_immutable()");
        }
    }

    public function down(): void
    {
        if (DB::table('foreign_entries')->exists()) {
            throw new RuntimeException('Preserve foreign currency history.');
        }Schema::dropIfExists('foreign_entries');
        Schema::dropIfExists('foreign_accounts');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS transfer_events_immutable ON transfer_events; DROP TRIGGER IF EXISTS exchange_rates_immutable ON exchange_rates; DROP FUNCTION IF EXISTS approved_rate_immutable()');
        }
    }
};
