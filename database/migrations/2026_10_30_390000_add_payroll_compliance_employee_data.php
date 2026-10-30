<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->text('national_id_number')->nullable();
            $table->text('nssa_number')->nullable();
            $table->text('zimra_tin')->nullable();
            $table->string('nationality', 80)->nullable();
            $table->boolean('ordinarily_resident_zimbabwe')->nullable();
            $table->string('employment_category', 32)->nullable();
            $table->string('marital_status', 24)->nullable();
            $table->boolean('blind')->nullable();
            $table->boolean('physically_disabled')->nullable();
            $table->unsignedSmallInteger('disabled_dependents_count')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('employees')->where(fn ($query) => $query
            ->whereNotNull('national_id_number')->orWhereNotNull('nssa_number')->orWhereNotNull('zimra_tin')
            ->orWhereNotNull('nationality')->orWhereNotNull('employment_category')->orWhereNotNull('marital_status')
            ->orWhereNotNull('blind')->orWhereNotNull('physically_disabled')->orWhereNotNull('disabled_dependents_count')
        )->exists()) {
            throw new RuntimeException('Preserve employee tax and NSSA compliance records.');
        }
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['national_id_number', 'nssa_number', 'zimra_tin', 'nationality', 'ordinarily_resident_zimbabwe', 'employment_category', 'marital_status', 'blind', 'physically_disabled', 'disabled_dependents_count']);
        });
    }
};
