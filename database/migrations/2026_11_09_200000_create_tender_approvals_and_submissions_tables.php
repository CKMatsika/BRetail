<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Multi-tier Tender Approvals
        Schema::create('tender_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->string('stage', 40); // technical, commercial, executive
            $table->string('status', 40)->default('pending'); // pending, approved, rejected, conditional
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approver_name', 150)->nullable();
            $table->string('approver_role', 100)->nullable();
            $table->text('comments')->nullable();
            $table->text('conditions')->nullable();
            $table->dateTime('actioned_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'tender_id', 'stage']);
            $table->index(['tenant_id', 'status']);
        });

        // 2. Tender Submissions & Proof of Delivery
        Schema::create('tender_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->string('submission_channel', 50)->default('praz_egp'); // praz_egp, physical_box, email_portal
            $table->string('egp_reference_number', 120)->nullable();
            $table->string('physical_box_location', 255)->nullable();
            $table->string('delivered_by_name', 120)->nullable();
            $table->string('delivered_by_phone', 60)->nullable();
            $table->string('receipt_number', 100)->nullable();
            $table->dateTime('submitted_at');
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('technical_envelope_verified')->default(true);
            $table->boolean('financial_envelope_verified')->default(true);
            $table->boolean('bid_security_verified')->default(true);
            $table->integer('copies_count')->default(1);
            $table->text('package_notes')->nullable();
            $table->string('proof_attachment_path', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'tender_id']);
        });

        // 3. Public Tender Opening & Competitor Record
        Schema::create('tender_opening_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->string('attended_by_name', 150)->nullable();
            $table->dateTime('opening_date')->nullable();
            $table->string('venue', 255)->nullable();
            $table->text('minutes_notes')->nullable();
            $table->json('competitor_bids')->nullable(); // [{competitor_name, currency, bid_amount, bid_security_ok, remarks}]
            $table->timestamps();

            $table->index(['tenant_id', 'tender_id']);
        });

        // 4. Tender Outcomes & Win/Loss Tracking
        Schema::create('tender_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->string('outcome', 40)->default('under_evaluation'); // awarded, lost, disqualified, cancelled, under_evaluation
            $table->dateTime('awarded_at')->nullable();
            $table->string('winning_bidder_name', 180)->nullable();
            $table->decimal('winning_price', 18, 2)->nullable();
            $table->char('winning_currency', 3)->default('USD');
            $table->decimal('price_variance_percent', 8, 2)->nullable();
            $table->string('loss_reason', 80)->nullable(); // price_higher, non_responsive_technical, statutory_omission, post_qualification, cancelled
            $table->text('debrief_notes')->nullable();
            $table->string('contract_reference', 120)->nullable();
            $table->dateTime('contract_signed_at')->nullable();
            $table->decimal('final_margin_percent', 6, 2)->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'outcome']);
            $table->index(['tenant_id', 'tender_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tender_outcomes');
        Schema::dropIfExists('tender_opening_records');
        Schema::dropIfExists('tender_submissions');
        Schema::dropIfExists('tender_approvals');
    }
};

