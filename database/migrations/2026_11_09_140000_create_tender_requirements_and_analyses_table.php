<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->string('status', 30)->default('pending'); // pending, processing, completed, failed
            $table->integer('extracted_count')->default(0);
            $table->integer('mandatory_count')->default(0);
            $table->integer('statutory_count')->default(0);
            $table->integer('technical_count')->default(0);
            $table->decimal('compliance_score', 5, 2)->default(0.00);
            $table->string('eligibility_verdict', 30)->default('needs_clarification'); // eligible, conditionally_eligible, not_eligible, needs_clarification
            $table->text('executive_summary')->nullable();
            $table->json('key_risks')->nullable();
            $table->json('missing_evidence')->nullable();
            $table->string('engine_used', 40)->default('heuristic'); // gemini, heuristic
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'tender_id']);
        });

        Schema::create('tender_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->foreignId('tender_document_id')->nullable()->constrained('tender_documents')->nullOnDelete();
            $table->string('category', 40); // administrative, statutory_compliance, technical_specification, financial, experience, personnel, equipment, insurance, bid_security, submission_instruction
            $table->string('title', 255);
            $table->text('original_wording');
            $table->text('simplified_explanation');
            $table->boolean('is_mandatory')->default(true);
            $table->text('evidence_required')->nullable();
            $table->string('source_document_name', 255)->nullable();
            $table->integer('page_number')->nullable();
            $table->string('verification_status', 30)->default('pending'); // pending, compliant, non_compliant, clarification_needed
            $table->decimal('confidence_score', 5, 2)->default(90.00);
            $table->boolean('human_verified')->default(false);
            $table->text('human_notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'tender_id']);
            $table->index(['tenant_id', 'tender_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_requirements');
        Schema::dropIfExists('tender_analyses');
    }
};
