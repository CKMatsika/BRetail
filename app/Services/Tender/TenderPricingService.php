<?php

namespace App\Services\Tender;

use App\Models\Tender;
use App\Models\TenderPricingEstimate;
use App\Models\TenderPricingItem;
use App\Models\TenderTechnicalSpec;
use Illuminate\Support\Collection;

class TenderPricingService
{
    /**
     * Get or initialize pricing estimate for a tender.
     */
    public function getOrCreateEstimate(Tender $tender): TenderPricingEstimate
    {
        $tenantId = $tender->tenant_id;
        $estimate = TenderPricingEstimate::where('tender_id', $tender->id)->first();

        if ($estimate) {
            return $estimate;
        }

        // Estimate bid security costs (typically 2% underwriting charge if required)
        $bidSecurityCost = $tender->bid_security_amount
            ? round((float) $tender->bid_security_amount * 0.02, 2)
            : 0.00;

        $estimate = TenderPricingEstimate::create([
            'tenant_id' => $tenantId,
            'tender_id' => $tender->id,
            'primary_currency' => $tender->currency ?: 'USD',
            'exchange_rate' => 1.0000,
            'exchange_risk_buffer_percent' => 3.00,
            'is_split_currency' => false,
            'foreign_currency' => 'USD',
            'local_currency' => 'ZWG',
            'foreign_split_ratio' => 70.00,
            'local_split_ratio' => 30.00,
            'target_margin_percent' => 15.00,
            'overheads_percent' => 5.00,
            'vat_rate_percent' => 15.00,
            'customs_duty_percent' => 0.00,
            'withholding_tax_percent' => 0.00,
            'praz_levy_percent' => 1.00,
            'praz_bid_fee' => (float) ($tender->tender_fee ?: 0.00),
            'bid_security_cost' => $bidSecurityCost,
            'other_statutory_costs' => 0.00,
            'status' => 'draft',
            'notes' => 'Pricing model calibrated to PPDPA statutory procurement schedule.',
        ]);

        // Auto-seed default BOQ items
        $this->seedInitialBoqItems($estimate, $tender);

        return $estimate;
    }

    /**
     * Compute full comprehensive financial model, sensitivity analysis, and competitiveness check.
     */
    public function calculateFinancialSummary(TenderPricingEstimate $estimate): array
    {
        $items = TenderPricingItem::where('tender_pricing_estimate_id', $estimate->id)
            ->orderBy('order_index')
            ->get();

        $directCostTotal = (float) $items->sum('subtotal_cost');
        $baseSellingTotal = (float) $items->sum('total_price');

        // Exchange risk / inflation buffer
        $riskBufferCost = round($directCostTotal * ((float) $estimate->exchange_risk_buffer_percent / 100), 2);

        // Overheads
        $overheadsCost = round(($directCostTotal + $riskBufferCost) * ((float) $estimate->overheads_percent / 100), 2);

        // Customs Duty (if applicable)
        $customsDutyCost = round($directCostTotal * ((float) $estimate->customs_duty_percent / 100), 2);

        // Subtotal Direct & Operational Cost
        $totalCostBase = $directCostTotal + $riskBufferCost + $overheadsCost + $customsDutyCost;

        // Statutory Procurement Costs (Fixed + Percentage PRAZ levy)
        $prazBidFee = (float) $estimate->praz_bid_fee;
        $bidSecurityCost = (float) $estimate->bid_security_cost;
        $otherStatutory = (float) $estimate->other_statutory_costs;
        $fixedStatutoryCosts = $prazBidFee + $bidSecurityCost + $otherStatutory;

        // Commercial price before statutory percentage and VAT
        $commercialSubtotal = max($baseSellingTotal, $totalCostBase * (1 + ((float) $estimate->target_margin_percent / 100)));

        // PRAZ 1% statutory administration levy on contract sum
        $prazLevyAmount = round($commercialSubtotal * ((float) $estimate->praz_levy_percent / 100), 2);

        // Total Bid Sum Before VAT
        $bidSumBeforeVat = $commercialSubtotal + $prazLevyAmount + $fixedStatutoryCosts;

        // VAT (15%)
        $vatRate = (float) $estimate->vat_rate_percent;
        $vatAmount = round($bidSumBeforeVat * ($vatRate / 100), 2);

        // Total Bid Sum Inclusive of VAT
        $totalBidPrice = $bidSumBeforeVat + $vatAmount;

        // Profit Margins
        $grossMargin = $commercialSubtotal - $totalCostBase;
        $grossMarginPercent = $commercialSubtotal > 0 ? round(($grossMargin / $commercialSubtotal) * 100, 2) : 0.00;
        $netProfit = $grossMargin - $fixedStatutoryCosts - $prazLevyAmount;
        $netMarginPercent = $commercialSubtotal > 0 ? round(($netProfit / $commercialSubtotal) * 100, 2) : 0.00;

        // Break-Even Minimum Floor Price (zero net profit)
        $breakEvenFloorPrice = round(($totalCostBase + $fixedStatutoryCosts) * (1 + ($vatRate / 100)), 2);

        // Split-Currency Calculation
        $splitCurrencyData = null;
        if ($estimate->is_split_currency) {
            $fRatio = (float) $estimate->foreign_split_ratio / 100;
            $lRatio = (float) $estimate->local_split_ratio / 100;
            $rate = (float) $estimate->exchange_rate ?: 1.0;

            $foreignAmount = round($totalBidPrice * $fRatio, 2);
            $localBaseAmount = round($totalBidPrice * $lRatio, 2);
            $localConvertedAmount = round($localBaseAmount * $rate, 2);

            $splitCurrencyData = [
                'foreign_currency' => $estimate->foreign_currency ?: 'USD',
                'foreign_amount' => $foreignAmount,
                'foreign_ratio_percent' => (float) $estimate->foreign_split_ratio,
                'local_currency' => $estimate->local_currency ?: 'ZWG',
                'local_base_amount' => $localBaseAmount,
                'local_converted_amount' => $localConvertedAmount,
                'local_ratio_percent' => (float) $estimate->local_split_ratio,
                'exchange_rate' => $rate,
            ];
        }

        // Competitiveness Check vs Procuring Entity Budget
        $tender = $estimate->tender;
        $budgetComparison = $this->evaluateCompetitiveness($totalBidPrice, $tender->estimated_value);

        // Sensitivity Analysis Scenarios
        $sensitivity = [
            'realistic' => [
                'name' => 'Target (Realistic) Case',
                'margin_percent' => (float) $estimate->target_margin_percent,
                'total_bid_price' => $totalBidPrice,
                'net_profit' => $netProfit,
                'description' => 'Target margin with normal procurement lead times and operational buffers.',
            ],
            'optimistic' => [
                'name' => 'Optimistic (Best) Case',
                'margin_percent' => (float) $estimate->target_margin_percent + 4.0,
                'total_bid_price' => round($totalBidPrice * 1.035, 2),
                'net_profit' => round($netProfit * 1.30, 2),
                'description' => 'Zero supply chain delays, volume procurement discounts, and stable exchange rates.',
            ],
            'conservative' => [
                'name' => 'Conservative (Aggressive Bid) Case',
                'margin_percent' => max(5.0, (float) $estimate->target_margin_percent - 4.0),
                'total_bid_price' => round($totalBidPrice * 0.94, 2),
                'net_profit' => round($netProfit * 0.65, 2),
                'description' => 'Lean competitive margin to maximize win probability against low-ball competitors.',
            ],
        ];

        return [
            'primary_currency' => $estimate->primary_currency,
            'item_count' => $items->count(),
            'direct_cost_total' => $directCostTotal,
            'risk_buffer_cost' => $riskBufferCost,
            'overheads_cost' => $overheadsCost,
            'customs_duty_cost' => $customsDutyCost,
            'total_cost_base' => $totalCostBase,
            'fixed_statutory_costs' => $fixedStatutoryCosts,
            'commercial_subtotal' => $commercialSubtotal,
            'praz_levy_amount' => $prazLevyAmount,
            'bid_sum_before_vat' => $bidSumBeforeVat,
            'vat_amount' => $vatAmount,
            'total_bid_price' => $totalBidPrice,
            'gross_margin' => $grossMargin,
            'gross_margin_percent' => $grossMarginPercent,
            'net_profit' => $netProfit,
            'net_margin_percent' => $netMarginPercent,
            'break_even_floor_price' => $breakEvenFloorPrice,
            'is_split_currency' => $estimate->is_split_currency,
            'split_currency' => $splitCurrencyData,
            'competitiveness' => $budgetComparison,
            'sensitivity' => $sensitivity,
        ];
    }

    /**
     * Add line item to BOQ.
     */
    public function addItem(TenderPricingEstimate $estimate, array $data): TenderPricingItem
    {
        $qty = (float) ($data['quantity'] ?? 1);
        $cost = (float) ($data['unit_cost'] ?? 0);
        $markup = (float) ($data['markup_percent'] ?? $estimate->target_margin_percent);

        $subtotalCost = round($qty * $cost, 2);
        $unitPrice = round($cost * (1 + ($markup / 100)), 4);
        $totalPrice = round($qty * $unitPrice, 2);

        $orderIndex = (int) ($data['order_index'] ?? (TenderPricingItem::where('tender_pricing_estimate_id', $estimate->id)->max('order_index') + 1));

        return TenderPricingItem::create([
            'tenant_id' => $estimate->tenant_id,
            'tender_pricing_estimate_id' => $estimate->id,
            'item_number' => $data['item_number'] ?? '1.0' . ($orderIndex + 1),
            'category' => $data['category'] ?? 'materials',
            'description' => $data['description'],
            'unit_of_measure' => $data['unit_of_measure'] ?? 'unit',
            'quantity' => $qty,
            'unit_cost' => $cost,
            'subtotal_cost' => $subtotalCost,
            'markup_percent' => $markup,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'order_index' => $orderIndex,
        ]);
    }

    /**
     * Update BOQ line item.
     */
    public function updateItem(TenderPricingItem $item, array $data): TenderPricingItem
    {
        $qty = isset($data['quantity']) ? (float) $data['quantity'] : (float) $item->quantity;
        $cost = isset($data['unit_cost']) ? (float) $data['unit_cost'] : (float) $item->unit_cost;
        $markup = isset($data['markup_percent']) ? (float) $data['markup_percent'] : (float) $item->markup_percent;

        $subtotalCost = round($qty * $cost, 2);
        $unitPrice = round($cost * (1 + ($markup / 100)), 4);
        $totalPrice = round($qty * $unitPrice, 2);

        $item->update([
            'item_number' => $data['item_number'] ?? $item->item_number,
            'category' => $data['category'] ?? $item->category,
            'description' => $data['description'] ?? $item->description,
            'unit_of_measure' => $data['unit_of_measure'] ?? $item->unit_of_measure,
            'quantity' => $qty,
            'unit_cost' => $cost,
            'subtotal_cost' => $subtotalCost,
            'markup_percent' => $markup,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'order_index' => $data['order_index'] ?? $item->order_index,
        ]);

        return $item;
    }

    /**
     * Delete line item.
     */
    public function deleteItem(TenderPricingItem $item): void
    {
        $item->delete();
    }

    /**
     * Seed initial sample BOQ items based on tender specifications or scope.
     */
    protected function seedInitialBoqItems(TenderPricingEstimate $estimate, Tender $tender): void
    {
        $techSpecs = TenderTechnicalSpec::where('tender_id', $tender->id)->get();

        if ($techSpecs->isNotEmpty()) {
            foreach ($techSpecs->take(6) as $idx => $spec) {
                $qty = 1;
                $cost = 5000.00;
                $markup = (float) $estimate->target_margin_percent;
                $unitPrice = round($cost * (1 + ($markup / 100)), 4);

                TenderPricingItem::create([
                    'tenant_id' => $estimate->tenant_id,
                    'tender_pricing_estimate_id' => $estimate->id,
                    'item_number' => (string) ($idx + 1) . '.01',
                    'category' => 'equipment',
                    'description' => $spec->title . ' (' . ($spec->bidder_offered_specification ?: 'Standard specification conforming to RFP') . ')',
                    'unit_of_measure' => 'unit',
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                    'subtotal_cost' => $cost,
                    'markup_percent' => $markup,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice,
                    'order_index' => $idx + 1,
                ]);
            }
        } else {
            // General goods / services default items
            $defaults = [
                ['cat' => 'materials', 'desc' => 'Primary equipment & hardware supply per tender specification', 'qty' => 1, 'cost' => 45000.00],
                ['cat' => 'logistics', 'desc' => 'Multimodal transit freight, handling, and insurance to site', 'qty' => 1, 'cost' => 3500.00],
                ['cat' => 'labour', 'desc' => 'Engineering testing, installation, and site acceptance commissioning', 'qty' => 1, 'cost' => 4000.00],
                ['cat' => 'preliminaries', 'desc' => 'Warranty buffer, documentation, and staff operational training', 'qty' => 1, 'cost' => 1500.00],
            ];

            foreach ($defaults as $idx => $def) {
                $cost = $def['cost'];
                $markup = (float) $estimate->target_margin_percent;
                $unitPrice = round($cost * (1 + ($markup / 100)), 4);

                TenderPricingItem::create([
                    'tenant_id' => $estimate->tenant_id,
                    'tender_pricing_estimate_id' => $estimate->id,
                    'item_number' => '1.0' . ($idx + 1),
                    'category' => $def['cat'],
                    'description' => $def['desc'],
                    'unit_of_measure' => 'lot',
                    'quantity' => $def['qty'],
                    'unit_cost' => $cost,
                    'subtotal_cost' => $cost,
                    'markup_percent' => $markup,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice,
                    'order_index' => $idx + 1,
                ]);
            }
        }
    }

    /**
     * Evaluate competitiveness against estimated budget.
     */
    protected function evaluateCompetitiveness(float $totalBidPrice, ?float $estimatedBudget): array
    {
        if (! $estimatedBudget || $estimatedBudget <= 0) {
            return [
                'has_budget' => false,
                'benchmark_budget' => null,
                'ratio_percent' => null,
                'rating' => 'unbenchmarked',
                'status_label' => 'No Budget Specified',
                'explanation' => 'Procuring entity has not published an estimated budget ceiling.',
            ];
        }

        $ratio = round(($totalBidPrice / $estimatedBudget) * 100, 1);

        if ($ratio > 118) {
            $rating = 'abnormally_high';
            $label = 'Pricing Abnormally High';
            $explanation = "Your bid price ({$ratio}% of budget) exceeds procuring entity benchmark. Risk of losing on commercial score.";
        } elseif ($ratio >= 85) {
            $rating = 'highly_competitive';
            $label = 'Highly Competitive';
            $explanation = "Your bid price ({$ratio}% of budget) is optimally positioned in the realistic procurement zone.";
        } elseif ($ratio >= 70) {
            $rating = 'aggressive';
            $label = 'Aggressive / Lean Pricing';
            $explanation = "Your bid price ({$ratio}% of budget) is lean. Ensure gross margin protects against cost escalations.";
        } else {
            $rating = 'abnormally_low';
            $label = 'Abnormally Low Tender (Risk)';
            $explanation = "Your bid price ({$ratio}% of budget) is exceptionally low. Risk of disqualification under PPDPA Section 52 without cost justification.";
        }

        return [
            'has_budget' => true,
            'benchmark_budget' => $estimatedBudget,
            'ratio_percent' => $ratio,
            'rating' => $rating,
            'status_label' => $label,
            'explanation' => $explanation,
        ];
    }
}

