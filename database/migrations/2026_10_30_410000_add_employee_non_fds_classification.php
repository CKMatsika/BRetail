<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('paye_non_fds_reason', 40)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('employees')->whereNotNull('paye_non_fds_reason')->exists()) {
            throw new RuntimeException('Preserve employee PAYE method records.');
        }
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('paye_non_fds_reason'));
    }
};
