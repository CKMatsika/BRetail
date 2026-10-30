<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 3);
            $t->string('name', 80);
            $t->unsignedSmallInteger('minor_units');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('currency_id');
            $t->date('effective_on');
            $t->decimal('rate', 28, 12);
            $t->string('source', 255);
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->foreign(['tenant_id', 'currency_id'])->references(['tenant_id', 'id'])->on('currencies');
            $t->unique(['tenant_id', 'currency_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        if (DB::table('exchange_rates')->exists()) {
            throw new RuntimeException('Preserve historical exchange rates before rollback.');
        }
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
