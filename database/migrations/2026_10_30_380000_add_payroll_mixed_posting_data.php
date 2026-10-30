<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_postings', function (Blueprint $table) {
            $table->json('fx_snapshot')->nullable();
            $table->unsignedBigInteger('foreign_entry_id')->nullable();
            $table->foreign('foreign_entry_id')->references('id')->on('foreign_entries');
        });
    }

    public function down(): void
    {
        if (DB::table('payroll_run_postings')->whereNotNull('foreign_entry_id')->exists()) {
            throw new RuntimeException('Preserve mixed-currency payroll posting history.');
        }
        Schema::table('payroll_run_postings', function (Blueprint $table) {
            $table->dropForeign(['foreign_entry_id']);
            $table->dropColumn(['fx_snapshot', 'foreign_entry_id']);
        });
    }
};
