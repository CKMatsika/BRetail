<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_gap_analyses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();

            $table->unsignedSmallInteger('overall_score')->default(0); // 0-100
            $table->string('recommendation', 30)->default('conditional_bid'); // strong_bid, conditional_bid, high_risk, do_not_bid
            $table->text('recommendation_reason')->nullable();
            $table->boolean('is_mandatory_passed')->default(false);
            $table->unsignedSmallInteger('fatal_flaws_count')->default(0);

            // Categorized breakdowns stored as structured JSON
            $table->json('category_scores')->nullable(); // statutory, eligibility, technical, financial, experience, bid_security
            $table->json('fatal_flaws')->nullable(); // list of strings/objects
            $table->json('action_plan')->nullable(); // array of actionable remediation tasks

            // Human manual override
            $table->boolean('manual_override')->default(false);
            $table->string('override_recommendation', 30)->nullable();
            $table->text('override_notes')->nullable();
            $table->unsignedBigInteger('override_by_user_id')->nullable();
            $table->timestamp('override_at')->nullable();

            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('override_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_gap_analyses');
    }
};
