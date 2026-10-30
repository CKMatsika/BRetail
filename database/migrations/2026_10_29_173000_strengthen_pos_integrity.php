<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', fn (Blueprint $t) => $t->unique(['tenant_id', 'id', 'warehouse_id', 'user_id'], 'pos_session_owner_unique'));
        Schema::table('pos_sales', function (Blueprint $t) {
            $t->unique(['tenant_id', 'hold_id'], 'pos_one_sale_per_hold');
            $t->unique(['tenant_id', 'id', 'warehouse_id'], 'pos_sale_warehouse_unique');
            $t->foreign(['tenant_id', 'session_id', 'warehouse_id', 'user_id'], 'pos_sale_session_owner_fk')->references(['tenant_id', 'id', 'warehouse_id', 'user_id'])->on('pos_sessions');
        });
        Schema::table('pos_returns', function (Blueprint $t) {
            $t->foreign(['tenant_id', 'session_id', 'warehouse_id', 'user_id'], 'pos_return_session_owner_fk')->references(['tenant_id', 'id', 'warehouse_id', 'user_id'])->on('pos_sessions');
            $t->foreign(['tenant_id', 'sale_id', 'warehouse_id'], 'pos_return_sale_warehouse_fk')->references(['tenant_id', 'id', 'warehouse_id'])->on('pos_sales');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION pos_session_immutable() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF TG_OP = ''DELETE'' OR OLD.status <> ''open'' OR NEW.status <> ''closed'' OR NEW.tenant_id <> OLD.tenant_id OR NEW.warehouse_id <> OLD.warehouse_id OR NEW.user_id <> OLD.user_id OR NEW.opening_cash <> OLD.opening_cash OR NEW.currency <> OLD.currency OR NEW.request_id <> OLD.request_id OR NEW.payload_hash <> OLD.payload_hash THEN RAISE EXCEPTION ''A cashier shift can only be closed once; its opening identity is immutable''; END IF; RETURN NEW; END';");
            DB::unprepared('CREATE TRIGGER pos_session_immutable BEFORE UPDATE OR DELETE ON pos_sessions FOR EACH ROW EXECUTE FUNCTION pos_session_immutable()');
            DB::statement("ALTER TABLE pos_sales ADD CONSTRAINT pos_training_sale_only CHECK (mode='training' AND total > 0 AND tax >= 0 AND discount >= 0 AND change >= 0)");
            DB::statement("ALTER TABLE pos_returns ADD CONSTRAINT pos_training_return_only CHECK (mode='training' AND total >= 0 AND net >= 0 AND tax >= 0)");
            DB::statement("ALTER TABLE pos_sessions ADD CONSTRAINT pos_session_valid_status CHECK ((status='open' AND closed_at IS NULL) OR (status='closed' AND closed_at IS NOT NULL AND counted_cash IS NOT NULL AND expected_cash IS NOT NULL AND close_request_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_sessions DROP CONSTRAINT pos_session_valid_status');
            DB::statement('ALTER TABLE pos_returns DROP CONSTRAINT pos_training_return_only');
            DB::statement('ALTER TABLE pos_sales DROP CONSTRAINT pos_training_sale_only');
            DB::unprepared('DROP TRIGGER IF EXISTS pos_session_immutable ON pos_sessions');
            DB::unprepared('DROP FUNCTION IF EXISTS pos_session_immutable()');
        }
        Schema::table('pos_returns', function (Blueprint $t) {
            $t->dropForeign('pos_return_sale_warehouse_fk');
            $t->dropForeign('pos_return_session_owner_fk');
        });
        Schema::table('pos_sales', function (Blueprint $t) {
            $t->dropForeign('pos_sale_session_owner_fk');
            $t->dropUnique('pos_sale_warehouse_unique');
            $t->dropUnique('pos_one_sale_per_hold');
        });
        Schema::table('pos_sessions', fn (Blueprint $t) => $t->dropUnique('pos_session_owner_unique'));
    }
};
