<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->date('date_of_birth')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('employees')->whereNotNull('date_of_birth')->exists()) {
            throw new RuntimeException('Preserve employee date-of-birth records before rollback.');
        }
        Schema::table('employees', function (Blueprint $t) {
            $t->dropColumn('date_of_birth');
        });
    }
};
