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
            $table->boolean('zimbabwean_citizen')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('employees')->whereNotNull('zimbabwean_citizen')->exists()) {
            throw new RuntimeException('Preserve employee citizenship records.');
        }
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('zimbabwean_citizen'));
    }
};
