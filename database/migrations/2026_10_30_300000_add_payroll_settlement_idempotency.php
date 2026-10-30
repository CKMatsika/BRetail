<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_postings', function (Blueprint $t) {
            $t->uuid('request_key')->nullable();
            $t->string('payload_hash', 64)->nullable();
            $t->unique(['tenant_id', 'request_key']);
        });
    }

    public function down(): void
    {
        if (DB::table('payroll_run_postings')->whereNotNull('request_key')->exists()) {
            throw new RuntimeException('Payroll settlement idempotency history must be preserved.');
        }
        Schema::table('payroll_run_postings', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'request_key']);
            $t->dropColumn(['request_key', 'payload_hash']);
        });
    }
};
