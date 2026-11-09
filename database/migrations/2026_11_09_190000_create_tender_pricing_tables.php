<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Master Tender Pricing Estimate & Financial Assumptions
        Schema::create('tender_pricing_estimates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();

            $table->char('primary_currency', 3)->default('USD');
            $table->decimal('exchange_rate', 12, 4)->default(1.0000);
            $table->decimal('exchange_risk_buffer_percent', 5, 2)->default(0.00); // 0-100% inflation/volatility buffer

            // Split-Currency Bidding
            $table->boolean('is_split_currency')->default(false);
            $table->char('foreign_currency', 3)->nullable(); // e.g. USD offshore component
            $table->char('local_currency', 3)->nullable(); // e.g. ZWG onshore component
            $table->decimal('foreign_split_ratio', 5, 2)->default(70.00); // %
            $table->decimal('local_split_ratio', 5, 2)->default(30.00); // %

            // Margin & Financial Assumptions
            $table->decimal('target_margin_percent', 5, 2)->default(15.00);
            $table->decimal('overheads_percent', 5, 2)->default(5.00);
            $table->decimal('vat_rate_percent', 5, 2)->default(15.00);
            $table->decimal('customs_duty_percent', 5, 2)->default(0.00);
            $table->decimal('withholding_tax_percent', 5, 2)->default(0.00);

            // Zimbabwean Statutory Procurement Costs
            $table->decimal('praz_levy_percent', 5, 2)->default(1.00); // SPB / PRAZ 1% statutory levy
            $table->decimal('praz_bid_fee', 14, 2)->default(0.00); // tender document purchase fee
            $table->decimal('bid_security_cost', 14, 2)->default(0.00); // bank guarantee establishment cost
            $table->decimal('other_statutory_costs', 14, 2)->default(0.00);

            $table->string('status', 30)->default('draft')->index(); // draft, in_review, approved
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('approved_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'tender_id']);
        });

        // 2. Bill of Quantities (BOQ) / Price Schedule Line Items
        Schema::create('tender_pricing_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('tender_pricing_estimate_id')->constrained('tender_pricing_estimates')->cascadeOnDelete();

            $table->string('item_number', 30); // 1.01, 1.02, etc.
            $table->string('category', 50)->default('materials')->index(); // materials, equipment, labour, subcontractor, logistics, preliminaries
            $table->text('description');
            $table->string('unit_of_measure', 30)->default('unit'); // unit, lot, kg, meter, hour, month, day
            $table->decimal('quantity', 14, 3)->default(1.000);
            $table->decimal('unit_cost', 14, 4)->default(0.0000); // Direct base cost
            $table->decimal('subtotal_cost', 14, 2)->default(0.00); // quantity * unit_cost
            $table->decimal('markup_percent', 5, 2)->default(15.00);
            $table->decimal('unit_price', 14, 4)->default(0.0000); // Selling price per unit
            $table->decimal('total_price', 14, 2)->default(0.00); // quantity * unit_price
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tender_pricing_estimate_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_pricing_items');
        Schema::dropIfExists('tender_pricing_estimates');
    }
};
