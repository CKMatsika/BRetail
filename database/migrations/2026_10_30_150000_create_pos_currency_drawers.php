<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_currency_drawers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('session_id');
            $t->unsignedBigInteger('foreign_account_id');
            $t->unsignedBigInteger('safe_account_id');
            $t->decimal('opening_amount', 24, 4);
            $t->decimal('counted_amount', 24, 4)->nullable();
            $t->decimal('expected_amount', 24, 4)->nullable();
            $t->string('status', 16)->default('open');
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->timestamps();
            $t->unique(['tenant_id', 'request_id']);
            $t->unique(['tenant_id', 'foreign_account_id']);
            foreach (['session_id' => 'pos_sessions', 'foreign_account_id' => 'foreign_accounts', 'safe_account_id' => 'foreign_accounts'] as $c => $table) {
                $t->foreign(['tenant_id', $c])->references(['tenant_id', 'id'])->on($table);
            }
        });
        Schema::create('pos_currency_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->string('kind', 16);
            $t->unsignedBigInteger('session_id');
            $t->unsignedBigInteger('sale_id')->nullable();
            $t->unsignedBigInteger('return_id')->nullable();
            $t->json('tenders');
            $t->decimal('foreign_base', 24, 4);
            $t->timestamp('created_at');
            $t->unique(['tenant_id', 'request_id']);
            foreach (['session_id' => 'pos_sessions', 'sale_id' => 'pos_sales', 'return_id' => 'pos_returns'] as $c => $table) {
                $t->foreign(['tenant_id', $c])->references(['tenant_id', 'id'])->on($table);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve currency drawer histories.');
    }
};
