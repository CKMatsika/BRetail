<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Models\TenderPricingEstimate;
use App\Models\TenderPricingItem;
use App\Models\TenderPriceSource;
use App\Services\Tender\TenderPriceSourcing;
use App\Services\Tender\TenderPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenderPricingController extends Controller
{
    public function __construct(
        protected TenderPricingService $pricingService
    ) {}

    /**
     * Show or initialize pricing estimate and financial analysis.
     */
    public function show(int $tenderId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        $estimate = $this->pricingService->getOrCreateEstimate($tender);
        $estimate->load([
            'items' => fn ($q) => $q->orderBy('order_index'),
            'approvedBy:id,name',
        ]);

        $summary = $this->pricingService->calculateFinancialSummary($estimate);

        return response()->json([
            'estimate' => $this->estimateData($estimate),
            'summary' => $this->summaryData($summary),
            'sources' => TenderPriceSource::where('tender_id',$tender->id)->latest('id')->limit(300)->get(),
            'tender' => [
                'id' => $tender->id,
                'reference_number' => $tender->reference_number,
                'title' => $tender->title,
                'procuring_entity' => $tender->procuring_entity,
                'currency' => $tender->currency,
                'estimated_value' => $tender->estimated_value,
                'tender_fee' => $tender->tender_fee,
                'bid_security_amount' => $tender->bid_security_amount,
                'status' => $tender->status,
            ],
        ]);
    }

    /**
     * Add line item to Bill of Quantities.
     */
    public function storeItem(int $tenderId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to modify pricing.'], 403);
        }

        $request->validate([
            'description' => 'required|string|max:1000',
            'category' => 'nullable|string|max:50',
            'item_number' => 'nullable|string|max:30', 'item_code' => 'nullable|string|max:30',
            'unit_of_measure' => 'nullable|string|max:30', 'unit' => 'nullable|string|max:30',
            'quantity' => 'required|numeric|min:0.001',
            'unit_cost' => 'sometimes|required|numeric|min:0', 'unit_direct_cost' => 'sometimes|required|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:1000',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);
        $estimate = $this->pricingService->getOrCreateEstimate($tender);

        $item = $this->pricingService->addItem($estimate, $this->pricingInput($request));

        return response()->json([
            'message' => 'BOQ line item added successfully.',
            'item' => $this->itemData($item),
            ...$this->pricingResponse($estimate->fresh(['items']), $this->pricingService->calculateFinancialSummary($estimate->fresh())),
        ], 201);
    }

    /**
     * Update BOQ line item.
     */
    public function updateItem(int $itemId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to modify pricing.'], 403);
        }

        $request->validate([
            'description' => 'sometimes|required|string|max:1000',
            'category' => 'nullable|string|max:50',
            'item_number' => 'nullable|string|max:30', 'item_code' => 'nullable|string|max:30',
            'unit_of_measure' => 'nullable|string|max:30', 'unit' => 'nullable|string|max:30',
            'quantity' => 'sometimes|required|numeric|min:0.001',
            'unit_cost' => 'sometimes|required|numeric|min:0', 'unit_direct_cost' => 'sometimes|required|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:1000',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $item = TenderPricingItem::where('tenant_id', $tenantId)->findOrFail($itemId);

        $updated = $this->pricingService->updateItem($item, $this->pricingInput($request));
        $estimate = $item->estimate;

        return response()->json([
            'message' => 'Line item updated.',
            'item' => $this->itemData($updated),
            ...$this->pricingResponse($estimate->fresh(['items']), $this->pricingService->calculateFinancialSummary($estimate)),
        ]);
    }

    /**
     * Delete line item.
     */
    public function destroyItem(int $itemId): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to delete pricing items.'], 403);
        }

        $tenantId = auth()->user()->tenant_id;
        $item = TenderPricingItem::where('tenant_id', $tenantId)->findOrFail($itemId);
        $estimate = $item->estimate;

        $this->pricingService->deleteItem($item);

        return response()->json([
            'message' => 'Line item removed.',
            ...$this->pricingResponse($estimate->fresh(['items']), $this->pricingService->calculateFinancialSummary($estimate)),
        ]);
    }

    /**
     * Update pricing settings, currencies, margins, and statutory levy assumptions.
     */
    public function updateSettings(int $tenderId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to update pricing settings.'], 403);
        }

        $request->validate([
            'primary_currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0.0001',
            'exchange_risk_buffer_percent' => 'nullable|numeric|min:0|max:100',
            'is_split_currency' => 'nullable|boolean',
            'foreign_currency' => 'nullable|string|size:3',
            'local_currency' => 'nullable|string|size:3',
            'foreign_split_ratio' => 'nullable|numeric|min:0|max:100',
            'local_split_ratio' => 'nullable|numeric|min:0|max:100',
            'target_margin_percent' => 'nullable|numeric|min:0|max:100',
            'overheads_percent' => 'nullable|numeric|min:0|max:100',
            'vat_rate_percent' => 'nullable|numeric|min:0|max:100',
            'customs_duty_percent' => 'nullable|numeric|min:0|max:100',
            'praz_levy_percent' => 'nullable|numeric|min:0|max:100',
            'praz_bid_fee' => 'nullable|numeric|min:0',
            'bid_security_cost' => 'nullable|numeric|min:0',
            'other_statutory_costs' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);
        $estimate = $this->pricingService->getOrCreateEstimate($tender);

        $estimate->update($request->all());

        return response()->json([
            'message' => 'Pricing assumptions updated successfully.',
            ...$this->pricingResponse($estimate->fresh(['items']), $this->pricingService->calculateFinancialSummary($estimate->fresh())),
        ]);
    }

    /**
     * Sign off / approve commercial pricing estimate.
     */
    public function approve(int $tenderId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to sign off pricing.'], 403);
        }

        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);
        $estimate = $this->pricingService->getOrCreateEstimate($tender);

        $estimate->update([
            'status' => 'approved',
            'approved_by_user_id' => auth()->id(),
            'approved_at' => now(),
            'notes' => $request->input('notes', $estimate->notes),
        ]);

        return response()->json([
            'message' => 'Commercial bid pricing signed off successfully.',
            'estimate' => $this->estimateData($estimate->fresh(['items','approvedBy:id,name'])),
        ]);
    }

    public function inventorySources(int $tenderId, int $itemId, Request $request, TenderPriceSourcing $sourcing): JsonResponse
    {
        abort_unless($request->user()->canDo('tenders.manage'),403);
        $tender=Tender::where('tenant_id',$request->user()->tenant_id)->findOrFail($tenderId);
        $item=$this->pricingItem($tender,$itemId);
        return response()->json(['sources'=>$sourcing->inventory($request->user(),$tender,$item)]);
    }

    public function webSources(int $tenderId, int $itemId, Request $request, TenderPriceSourcing $sourcing): JsonResponse
    {
        abort_unless($request->user()->canDo('tenders.manage'),403);
        $tender=Tender::where('tenant_id',$request->user()->tenant_id)->findOrFail($tenderId);
        $item=$this->pricingItem($tender,$itemId);
        return response()->json($sourcing->searchWeb($request->user(),$tender,$item));
    }

    public function uploadSupplierQuote(int $tenderId, Request $request, TenderPriceSourcing $sourcing): JsonResponse
    {
        abort_unless($request->user()->canDo('tenders.manage'),403);
        $data=$request->validate(['pricing_item_id'=>'required|integer','file'=>'required|file|mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png|max:30720']);
        $tender=Tender::where('tenant_id',$request->user()->tenant_id)->findOrFail($tenderId);
        $item=$this->pricingItem($tender,(int)$data['pricing_item_id']);
        return response()->json($sourcing->uploadQuote($request->user(),$tender,$item,$request->file('file')),201);
    }

    public function reviewSource(int $tenderId, int $sourceId, Request $request, TenderPriceSourcing $sourcing): JsonResponse
    {
        abort_unless($request->user()->canDo('tenders.manage'),403);
        $data=$request->validate(['decision'=>'required|in:approve,reject']);
        $tender=Tender::where('tenant_id',$request->user()->tenant_id)->findOrFail($tenderId);
        $source=TenderPriceSource::where('tender_id',$tender->id)->findOrFail($sourceId);
        $source=$sourcing->decide($request->user(),$source,$data['decision']);
        $estimate=TenderPricingEstimate::where('tender_id',$tender->id)->firstOrFail()->fresh(['items']);
        return response()->json(['source'=>$source,...$this->pricingResponse($estimate,$this->pricingService->calculateFinancialSummary($estimate))]);
    }

    private function pricingItem(Tender $tender,int $itemId): TenderPricingItem
    {
        $estimate=TenderPricingEstimate::where('tender_id',$tender->id)->firstOrFail();
        return TenderPricingItem::where('tender_pricing_estimate_id',$estimate->id)->findOrFail($itemId);
    }

    private function pricingInput(Request $request): array
    {
        $data=$request->all();
        $data['item_number']=$data['item_number']??$data['item_code']??null;
        $data['unit_of_measure']=$data['unit_of_measure']??$data['unit']??null;
        $data['unit_cost']=$data['unit_cost']??$data['unit_direct_cost']??0;
        return $data;
    }

    private function itemData(TenderPricingItem $item): array
    {
        $category=match($item->category){'materials'=>'material','preliminaries'=>'overheads',default=>$item->category};
        return [...$item->toArray(),
            'item_code'=>$item->item_number,'unit'=>$item->unit_of_measure,'category'=>$category,
            'unit_direct_cost'=>(float)$item->unit_cost,'total_direct_cost'=>(float)$item->subtotal_cost,
            'unit_selling_price'=>(float)$item->unit_price,'total_selling_price'=>(float)$item->total_price,
            'source_verified_at'=>$item->source_verified_at?->toIso8601String(),
        ];
    }

    private function estimateData(TenderPricingEstimate $estimate): array
    {
        return [...$estimate->toArray(),'items'=>$estimate->items->map(fn($item)=>$this->itemData($item))->values()];
    }

    private function summaryData(array $summary): array
    {
        $budget=$summary['competitiveness'];
        return [...$summary,
            'budget_comparison'=>[
                'estimated_budget'=>$budget['benchmark_budget'],'total_bid_price'=>$summary['total_bid_price'],
                'difference'=>$budget['benchmark_budget']===null?null:$summary['total_bid_price']-$budget['benchmark_budget'],
                'difference_percent'=>$budget['ratio_percent']===null?null:$budget['ratio_percent']-100,
                'competitiveness_status'=>$budget['rating'],'label'=>$budget['status_label'],
            ],
            'scenarios'=>$summary['sensitivity'],
        ];
    }

    private function pricingResponse(TenderPricingEstimate $estimate,array $summary): array
    {
        return ['estimate'=>$this->estimateData($estimate),'summary'=>$this->summaryData($summary)];
    }

    /**
     * Export complete Price Schedule / Bill of Quantities as CSV.
     */
    public function exportCsv(int $tenderId): StreamedResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);
        $estimate = $this->pricingService->getOrCreateEstimate($tender);
        $summary = $this->pricingService->calculateFinancialSummary($estimate);
        $items = TenderPricingItem::where('tender_pricing_estimate_id', $estimate->id)
            ->orderBy('order_index')
            ->get();

        $filename = 'BOQ_Price_Schedule_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $tender->reference_number) . '.csv';

        return response()->streamDownload(function () use ($tender, $estimate, $summary, $items) {
            $handle = fopen('php://output', 'w');

            // Header summary
            fputcsv($handle, ['Tender Reference', $tender->reference_number]);
            fputcsv($handle, ['Tender Title', $tender->title]);
            fputcsv($handle, ['Procuring Entity', $tender->procuring_entity]);
            fputcsv($handle, ['Primary Currency', $estimate->primary_currency]);
            fputcsv($handle, ['Target Gross Margin', $summary['gross_margin_percent'] . '%']);
            fputcsv($handle, ['Total Bid Sum (Excl VAT)', number_format($summary['bid_sum_before_vat'], 2)]);
            fputcsv($handle, ['VAT Amount (15%)', number_format($summary['vat_amount'], 2)]);
            fputcsv($handle, ['Total Bid Price (Incl VAT)', number_format($summary['total_bid_price'], 2)]);
            fputcsv($handle, []);

            // Column Headers
            fputcsv($handle, [
                'Item No',
                'Classification',
                'Item Description / Specification',
                'Unit of Measure',
                'Quantity',
                'Direct Unit Cost',
                'Subtotal Cost',
                'Markup %',
                'Bid Unit Price',
                'Total Bid Price',
                'Cost Source Type',
                'Cost Source',
                'Source Link',
            ]);

            foreach ($items as $item) {
                fputcsv($handle, [
                    $item->item_number,
                    ucwords(str_replace('_', ' ', $item->category)),
                    $item->description,
                    $item->unit_of_measure,
                    $item->quantity,
                    number_format((float) $item->unit_cost, 4),
                    number_format((float) $item->subtotal_cost, 2),
                    $item->markup_percent . '%',
                    number_format((float) $item->unit_price, 4),
                    number_format((float) $item->total_price, 2),
                    $item->source_type ?? 'manual',
                    $item->source_label ?? '',
                    $item->source_url ?? '',
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['', '', '', '', '', '', 'Direct Cost Subtotal', '', '', number_format($summary['direct_cost_total'], 2)]);
            fputcsv($handle, ['', '', '', '', '', '', 'Statutory Levies & Fees', '', '', number_format($summary['fixed_statutory_costs'] + $summary['praz_levy_amount'], 2)]);
            fputcsv($handle, ['', '', '', '', '', '', 'VAT (' . $estimate->vat_rate_percent . '%)', '', '', number_format($summary['vat_amount'], 2)]);
            fputcsv($handle, ['', '', '', '', '', '', 'GRAND TOTAL BID PRICE', '', '', number_format($summary['total_bid_price'], 2)]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}

