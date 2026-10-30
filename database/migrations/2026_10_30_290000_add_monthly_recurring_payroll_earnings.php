<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_compensations', function (Blueprint $t) {
            $t->decimal('monthly_recurring_cash_earnings', 18, 4)->default(0);
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('employee_compensations')->where('monthly_recurring_cash_earnings', '>', 0)->exists()) {
            throw new RuntimeException('Payroll terms with recurring earnings must be preserved.');
        }
        Schema::table('employee_compensations', function (Blueprint $t) {
            $t->dropColumn('monthly_recurring_cash_earnings');
        });
    }
};
