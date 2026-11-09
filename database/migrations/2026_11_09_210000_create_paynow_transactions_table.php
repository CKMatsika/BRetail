<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paynow_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('document_id');
            $table->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('trade_documents')->cascadeOnDelete();
            $table->string('reference', 100);
            $table->string('paynow_reference', 100)->nullable();
            $table->decimal('amount', 24, 4);
            $table->string('currency', 3)->default('USD');
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('status', 40)->default('initiated');
            $table->text('poll_url')->nullable();
            $table->text('browser_url')->nullable();
            $table->unsignedBigInteger('settlement_document_id')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'status']);
            $table->index(['reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paynow_transactions');
    }
};

