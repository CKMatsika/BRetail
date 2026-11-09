<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reference_number', 120)->index();
            $table->string('title', 255);
            $table->string('procuring_entity', 180)->index();
            $table->string('entity_type', 60)->default('government')->index(); // government, local_authority, parastatal, ngo, private, international
            $table->string('procurement_category', 80)->index(); // goods, works, consultancy, non_consultancy, ict, energy, etc.
            $table->string('procurement_method', 80)->default('open_competitive_bidding')->index(); // open_competitive_bidding, restricted_bidding, rfq, rfp, direct_procurement, two_stage, framework
            $table->string('opportunity_type', 80)->default('tender')->index(); // tender, eoi, prequalification, supplier_registration, subcontracting
            $table->json('praz_categories')->nullable();
            $table->string('unspsc_code', 30)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->decimal('estimated_value', 18, 2)->nullable();
            $table->decimal('tender_fee', 18, 2)->nullable();
            $table->boolean('bid_security_required')->default(false);
            $table->decimal('bid_security_amount', 18, 2)->nullable();
            $table->text('bid_security_details')->nullable();
            $table->dateTime('closing_at')->index();
            $table->dateTime('clarification_deadline')->nullable();
            $table->boolean('site_visit_required')->default(false);
            $table->dateTime('site_visit_at')->nullable();
            $table->string('site_visit_location', 255)->nullable();
            $table->dateTime('internal_deadline')->nullable();
            $table->string('submission_method', 60)->default('electronic_portal'); // electronic_portal, physical_delivery, email
            $table->string('submission_address', 255)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('contact_email', 180)->nullable();
            $table->string('contact_phone', 60)->nullable();
            $table->foreignId('assigned_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 40)->default('draft')->index(); // draft, evaluating_go_no_go, active_bidding, submitted, awarded, unsuccessful, cancelled
            $table->text('status_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'closing_at']);
            $table->index(['tenant_id', 'procurement_category']);
        });

        Schema::create('tender_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->string('file_path', 255);
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 120);
            $table->string('file_hash', 64);
            $table->string('document_type', 80)->default('solicitation_rfp_bidding_doc'); // solicitation_rfp_bidding_doc, specifications_boq, addendum, drawings, clarification, other
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'tender_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_documents');
        Schema::dropIfExists('tenders');
    }
};

