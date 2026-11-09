<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_technical_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->foreignId('tender_requirement_id')->nullable()->constrained('tender_requirements')->nullOnDelete();
            $table->string('item_number', 50)->default('TS-01');
            $table->string('title', 255);
            $table->text('original_specification');
            $table->text('plain_language_explanation')->nullable();
            $table->text('purpose')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->text('required_evidence')->nullable();
            $table->text('suggested_solution')->nullable();
            $table->text('alternative_solutions')->nullable();
            $table->string('compliance_status', 30)->default('comply'); // comply, comply_with_deviation, alternative_offered, not_comply, needs_clarification
            $table->text('bidder_offered_specification')->nullable();
            $table->text('deviation_statement')->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->boolean('human_verified')->default(false);
            $table->unsignedInteger('order_index')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'tender_id']);
            $table->index(['tenant_id', 'tender_id', 'compliance_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_technical_specs');
    }
};
