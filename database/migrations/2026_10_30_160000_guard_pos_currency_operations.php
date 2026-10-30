<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER pos_currency_operations_immutable BEFORE UPDATE OR DELETE ON pos_currency_operations FOR EACH ROW EXECUTE FUNCTION inventory_immutable()');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS pos_currency_operations_immutable ON pos_currency_operations');
        }
    }
};
