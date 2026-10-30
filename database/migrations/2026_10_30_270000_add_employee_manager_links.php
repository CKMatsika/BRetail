<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->unsignedBigInteger('manager_id')->nullable();
            $t->foreign(['tenant_id', 'manager_id'])->references(['tenant_id', 'id'])->on('employees');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'manager_id']);
            $t->dropColumn('manager_id');
        });
    }
};
