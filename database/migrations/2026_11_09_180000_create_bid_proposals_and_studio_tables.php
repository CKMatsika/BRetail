<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Master Bid Proposal
        Schema::create('bid_proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();

            $table->string('title', 255);
            $table->string('bidder_name', 180);
            $table->string('bidder_address', 255)->nullable();
            $table->unsignedBigInteger('lead_author_id')->nullable();
            $table->string('status', 30)->default('draft')->index(); // draft, in_review, approved, finalized
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('overall_notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('lead_author_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'tender_id']);
        });

        // 2. Proposal Sections
        Schema::create('bid_proposal_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('bid_proposal_id')->constrained('bid_proposals')->cascadeOnDelete();

            $table->string('section_key', 50)->index(); // executive_summary, company_overview, technical_methodology, work_plan, team_composition, risk_quality_assurance, hse_plan, mobilisation_plan, local_content_plan, statutory_declarations, custom
            $table->string('title', 180);
            $table->longText('content');
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->boolean('is_included')->default(true);
            $table->string('status', 30)->default('draft')->index(); // draft, in_review, approved, finalized
            $table->boolean('ai_generated')->default(true);
            $table->unsignedBigInteger('last_edited_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('last_edited_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['bid_proposal_id', 'order_index']);
        });

        // 3. Standard Returnable Schedules & Letters
        Schema::create('bid_standard_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->foreignId('bid_proposal_id')->nullable()->constrained('bid_proposals')->cascadeOnDelete();

            $table->string('doc_type', 60)->index(); // bid_submission_letter, manufacturer_authorisation, joint_venture_agreement, subcontractor_commitment, site_visit_certificate, non_collusion_declaration, litigation_declaration, praz_category_declaration, power_of_attorney
            $table->string('title', 180);
            $table->longText('content');
            $table->string('status', 30)->default('draft')->index(); // draft, ready_to_sign, signed_attached
            $table->string('attachment_path', 255)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_standard_documents');
        Schema::dropIfExists('bid_proposal_sections');
        Schema::dropIfExists('bid_proposals');
    }
};
