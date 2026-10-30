<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->json('currency_snapshot')->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve purchase order currency snapshots.');
    }
};
