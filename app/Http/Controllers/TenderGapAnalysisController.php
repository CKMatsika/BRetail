<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Models\TenderGapAnalysis;
use App\Services\Tender\TenderGapAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenderGapAnalysisController extends Controller
{
    public function __construct(
        protected TenderGapAnalysisService $gapService
    ) {}

    /**
     * Show or retrieve current gap analysis.
     */
    public function show(int $tenderId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        $analysis = TenderGapAnalysis::where('tender_id', $tender->id)->first();

        if (! $analysis) {
            $analysis = $this->gapService->evaluate($tender);
        }

        return response()->json([
            'gap_analysis' => $analysis,
            'tender' => [
                'id' => $tender->id,
                'reference_number' => $tender->reference_number,
                'title' => $tender->title,
                'procuring_entity' => $tender->procuring_entity,
                'status' => $tender->status,
                'closing_at' => $tender->closing_at,
                'currency' => $tender->currency,
                'estimated_value' => $tender->estimated_value,
                'bid_security_required' => $tender->bid_security_required,
                'bid_security_amount' => $tender->bid_security_amount,
                'site_visit_required' => $tender->site_visit_required,
                'site_visit_at' => $tender->site_visit_at,
            ],
        ]);
    }

    /**
     * Re-evaluate or run fresh gap analysis.
     */
    public function evaluate(int $tenderId): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to perform gap evaluation.'], 403);
        }

        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        $analysis = $this->gapService->evaluate($tender);

        return response()->json([
            'message' => 'Gap analysis and Bid/No-Bid assessment refreshed successfully.',
            'gap_analysis' => $analysis,
        ]);
    }

    /**
     * Record human manual override.
     */
    public function recordOverride(int $tenderId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to override tender recommendation.'], 403);
        }

        $request->validate([
            'override_recommendation' => 'required|string|in:strong_bid,conditional_bid,high_risk,do_not_bid',
            'override_notes' => 'required|string|max:2000',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        $analysis = TenderGapAnalysis::where('tender_id', $tender->id)->firstOrFail();

        $updated = $this->gapService->recordOverride(
            $analysis,
            $request->override_recommendation,
            $request->override_notes,
            auth()->id()
        );

        return response()->json([
            'message' => 'Recommendation override saved successfully.',
            'gap_analysis' => $updated,
        ]);
    }

    /**
     * Update individual action plan remediation item status.
     */
    public function updateActionItem(int $tenderId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to update action plan items.'], 403);
        }

        $request->validate([
            'item_id' => 'required|string',
            'status' => 'required|string|in:pending,in_progress,resolved',
            'notes' => 'nullable|string|max:1000',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        $analysis = TenderGapAnalysis::where('tender_id', $tender->id)->firstOrFail();

        $updated = $this->gapService->updateActionItem(
            $analysis,
            $request->item_id,
            $request->status,
            $request->notes
        );

        return response()->json([
            'message' => 'Remediation task status updated.',
            'gap_analysis' => $updated,
        ]);
    }

    /**
     * Export remediation action plan as CSV.
     */
    public function exportCsv(int $tenderId): StreamedResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);
        $analysis = TenderGapAnalysis::where('tender_id', $tender->id)->first();

        if (! $analysis) {
            $analysis = $this->gapService->evaluate($tender);
        }

        $actionPlan = $analysis->action_plan ?? [];
        $filename = 'tender_action_plan_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $tender->reference_number) . '.csv';

        return response()->streamDownload(function () use ($tender, $analysis, $actionPlan) {
            $handle = fopen('php://output', 'w');

            // Header summary
            fputcsv($handle, ['Tender Reference', $tender->reference_number]);
            fputcsv($handle, ['Tender Title', $tender->title]);
            fputcsv($handle, ['Procuring Entity', $tender->procuring_entity]);
            fputcsv($handle, ['Overall Compliance Score', $analysis->overall_score . '%']);
            fputcsv($handle, ['Bid/No-Bid Verdict', strtoupper(str_replace('_', ' ', $analysis->recommendation))]);
            fputcsv($handle, ['Fatal Flaws Detected', $analysis->fatal_flaws_count]);
            fputcsv($handle, []);

            // Columns
            fputcsv($handle, [
                'Task ID',
                'Priority',
                'Category',
                'Action Item',
                'Description',
                'Responsible Person',
                'Estimated Days',
                'Estimated Cost (USD)',
                'Status',
                'Target Due Date',
            ]);

            foreach ($actionPlan as $idx => $item) {
                fputcsv($handle, [
                    'ACT-' . ($idx + 1),
                    strtoupper($item['priority'] ?? 'MEDIUM'),
                    ucwords(str_replace('_', ' ', $item['category'] ?? '')),
                    $item['title'] ?? '',
                    $item['description'] ?? '',
                    $item['responsible_person'] ?? '',
                    $item['estimated_days'] ?? 0,
                    $item['estimated_cost'] ?? 0,
                    strtoupper(str_replace('_', ' ', $item['status'] ?? 'PENDING')),
                    $item['due_date'] ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
