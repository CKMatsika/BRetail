<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_documents', fn (Blueprint $t) => $t->json('currency_snapshot')->nullable());
        Schema::table('trade_allocations', fn (Blueprint $t) => $t->decimal('original_amount', 24, 4)->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve original currency snapshots and allocations.');
    }
};
