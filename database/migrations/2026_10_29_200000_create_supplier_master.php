<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 30);
            $t->string('name', 160);
            $t->string('contact_name', 160)->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->text('address')->nullable();
            $t->unsignedSmallInteger('terms_days')->default(30);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('suppliers')->exists()) {
            throw new RuntimeException('Supplier records exist. Preserve them before considering rollback.');
        }
        Schema::dropIfExists('suppliers');
    }
};
