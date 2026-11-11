<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_pricing_items', function (Blueprint $table) {
            $table->text('notes')->nullable();
            $table->string('source_type', 24)->nullable();
            $table->string('source_label', 255)->nullable();
            $table->text('source_url')->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->unsignedBigInteger('inventory_variant_id')->nullable();
            $table->timestamp('source_verified_at')->nullable();
            $table->foreign('source_document_id')->references('id')->on('tender_documents')->nullOnDelete();
            $table->foreign('inventory_variant_id')->references('id')->on('product_variants')->nullOnDelete();
        });
        Schema::create('tender_price_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->foreignId('pricing_item_id')->constrained('tender_pricing_items')->cascadeOnDelete();
            $table->string('source_type', 24); // inventory, supplier_quote, web
            $table->string('source_label', 255);
            $table->decimal('unit_cost', 14, 4);
            $table->char('currency', 3);
            $table->text('source_url')->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->unsignedBigInteger('inventory_variant_id')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->text('match_reason')->nullable();
            $table->char('candidate_key', 64);
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'candidate_key']);
            $table->index(['tenant_id', 'tender_id', 'pricing_item_id', 'status'], 'tender_price_source_review_idx');
            $table->foreign('source_document_id')->references('id')->on('tender_documents')->nullOnDelete();
            $table->foreign('inventory_variant_id')->references('id')->on('product_variants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_price_sources');
        Schema::table('tender_pricing_items', function (Blueprint $table) {
            $table->dropForeign(['source_document_id']);
            $table->dropForeign(['inventory_variant_id']);
            $table->dropColumn(['notes','source_type','source_label','source_url','source_document_id','inventory_variant_id','source_verified_at']);
        });
    }
};
