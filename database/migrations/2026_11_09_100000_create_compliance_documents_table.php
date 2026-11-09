<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('category', 60)->index();
            $table->string('type_key', 80)->index();
            $table->string('title', 160);
            $table->string('issuing_authority', 160)->nullable();
            $table->string('reference_number', 120)->nullable();
            $table->json('praz_categories')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->string('verification_status', 40)->default('missing')->index();
            $table->string('status_reason', 255)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('parent_document_id')->nullable()->constrained('compliance_documents')->nullOnDelete();
            $table->foreignId('responsible_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'category', 'is_active']);
            $table->index(['tenant_id', 'type_key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_documents');
    }
};
