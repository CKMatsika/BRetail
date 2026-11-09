<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Critical Milestones
        Schema::create('tender_milestones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();

            $table->string('type', 50)->index(); // clarification_deadline, site_visit, addendum_review, internal_draft, technical_review, pricing_signoff, submission_closing, tender_validity_expiry, bid_security_expiry, custom
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('venue_or_location', 255)->nullable();
            $table->dateTime('due_at')->index();
            $table->string('status', 30)->default('pending')->index(); // pending, completed, at_risk, missed
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->unsignedBigInteger('responsible_employee_id')->nullable();

            $table->json('reminder_lead_times')->nullable(); // ['7d', '3d', '24h', '2h']
            $table->json('reminders_sent')->nullable(); // recorded triggers
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('completed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('responsible_employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'due_at']);
        });

        // 2. Clarifications & Addenda Tracker
        Schema::create('tender_clarifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();

            $table->string('type', 40)->default('clarification_request')->index(); // clarification_request, addendum_received
            $table->string('reference_or_number', 80); // e.g. "Addendum No. 1", "Clarification Req #01"
            $table->string('subject', 255);
            $table->text('question_or_content');
            $table->text('response_or_amendment')->nullable();
            $table->dateTime('submitted_or_received_at')->index();
            $table->dateTime('response_due_at')->nullable();
            $table->dateTime('response_received_at')->nullable();
            $table->string('status', 30)->default('submitted')->index(); // draft, submitted, answered, acknowledged

            $table->boolean('affects_closing_date')->default(false);
            $table->dateTime('new_closing_date')->nullable();
            $table->boolean('affects_pricing')->default(false);
            $table->boolean('affects_technical_specs')->default(false);
            $table->string('attachment_path', 255)->nullable();
            $table->string('attachment_name', 255)->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // 3. Tender In-App & Email Alerts
        Schema::create('tender_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->foreignId('tender_milestone_id')->nullable()->constrained('tender_milestones')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable(); // null means all tender managers in tenant

            $table->string('level', 20)->default('warning'); // info, warning, critical
            $table->string('title', 180);
            $table->text('message');
            $table->string('lead_time', 20)->nullable(); // 7d, 3d, 24h, 2h, overdue
            $table->string('channel', 20)->default('in_app'); // in_app, email

            $table->boolean('is_read')->default(false)->index();
            $table->dateTime('read_at')->nullable();
            $table->dateTime('sent_at')->index();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'is_read', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_alerts');
        Schema::dropIfExists('tender_clarifications');
        Schema::dropIfExists('tender_milestones');
    }
};

