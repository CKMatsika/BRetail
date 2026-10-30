<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $t) {
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->foreign(['tenant_id', 'closed_by'], 'pos_session_closer_fk')->references(['tenant_id', 'id'])->on('users');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $t) {
            $t->dropForeign('pos_session_closer_fk');
            $t->dropColumn('closed_by');
        });
    }
};
