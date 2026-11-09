<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Services\Tender\TenderAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenderAnalyticsController extends Controller
{
    public function __construct(
        protected TenderAnalyticsService $analyticsService
    ) {}

    /**
     * Executive analytics dashboard KPIs.
     */
    public function dashboard(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $metrics = $this->analyticsService->getExecutiveDashboardMetrics($tenantId);

        return response()->json($metrics);
    }

    /**
     * Get outcome data for a specific tender.
     */
    public function showOutcome(int $tenderId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        return response()->json([
            'tender' => [
                'id' => $tender->id,
                'reference_number' => $tender->reference_number,
                'title' => $tender->title,
                'procuring_entity' => $tender->procuring_entity,
                'currency' => $tender->currency,
                'estimated_value' => $tender->estimated_value,
                'status' => $tender->status,
            ],
            'outcome' => $tender->outcome,
        ]);
    }

    /**
     * Record win/loss outcome for a tender.
     */
    public function recordOutcome(Request $request, int $tenderId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized: Only managers can record tender outcomes.'], 403);
        }

        $validated = $request->validate([
            'outcome' => 'required|in:awarded,lost,disqualified,cancelled,under_evaluation',
            'awarded_at' => 'nullable|date',
            'winning_bidder_name' => 'nullable|string|max:180',
            'winning_price' => 'nullable|numeric|min:0',
            'winning_currency' => 'nullable|string|size:3',
            'price_variance_percent' => 'nullable|numeric',
            'loss_reason' => 'nullable|string|max:80',
            'debrief_notes' => 'nullable|string',
            'contract_reference' => 'nullable|string|max:120',
            'contract_signed_at' => 'nullable|date',
            'final_margin_percent' => 'nullable|numeric',
        ]);

        $outcome = $this->analyticsService->recordOutcome($tender, $validated, auth()->user());

        return response()->json([
            'message' => 'Tender outcome recorded successfully.',
            'outcome' => $outcome,
            'tender_status' => $tender->fresh()->status,
        ]);
    }

    /**
     * Export complete audit report for a tender in CSV format.
     */
    public function exportAuditCsv(int $tenderId): StreamedResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)
            ->with(['approvals', 'submission', 'openingRecord', 'outcome', 'milestones', 'pricingEstimate'])
            ->findOrFail($tenderId);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"Tender_Audit_{$tender->reference_number}.csv\"",
        ];

        return response()->stream(function () use ($tender) {
            $handle = fopen('php://output', 'w');

            // Header info
            fputcsv($handle, ['TENDER MASTER AUDIT TRAIL']);
            fputcsv($handle, ['Reference Number', $tender->reference_number]);
            fputcsv($handle, ['Title', $tender->title]);
            fputcsv($handle, ['Procuring Entity', $tender->procuring_entity]);
            fputcsv($handle, ['Entity Type', $tender->entity_type]);
            fputcsv($handle, ['Category', $tender->procurement_category]);
            fputcsv($handle, ['Currency', $tender->currency]);
            fputcsv($handle, ['Estimated Value', $tender->estimated_value]);
            fputcsv($handle, ['Current Status', $tender->status]);
            fputcsv($handle, []);

            // Approvals
            fputcsv($handle, ['--- INTERNAL APPROVAL SIGN-OFFS ---']);
            fputcsv($handle, ['Stage', 'Status', 'Approver', 'Role', 'Comments', 'Conditions', 'Actioned Date']);
            foreach ($tender->approvals as $app) {
                fputcsv($handle, [
                    $app->stage,
                    $app->status,
                    $app->approver_name ?: 'N/A',
                    $app->approver_role,
                    $app->comments ?: 'None',
                    $app->conditions ?: 'None',
                    $app->actioned_at?->format('Y-m-d H:i') ?: 'Pending',
                ]);
            }
            fputcsv($handle, []);

            // Submission Details
            fputcsv($handle, ['--- SUBMISSION & DELIVERY PROOF ---']);
            if ($sub = $tender->submission) {
                fputcsv($handle, ['Channel', $sub->submission_channel]);
                fputcsv($handle, ['eGP Ref Number', $sub->egp_reference_number ?: 'N/A']);
                fputcsv($handle, ['Physical Box Location', $sub->physical_box_location ?: 'N/A']);
                fputcsv($handle, ['Delivered By', $sub->delivered_by_name ?: 'N/A']);
                fputcsv($handle, ['Receipt Number', $sub->receipt_number ?: 'N/A']);
                fputcsv($handle, ['Submission Timestamp', $sub->submitted_at?->format('Y-m-d H:i')]);
                fputcsv($handle, ['Technical Envelope Verified', $sub->technical_envelope_verified ? 'YES' : 'NO']);
                fputcsv($handle, ['Financial Envelope Verified', $sub->financial_envelope_verified ? 'YES' : 'NO']);
                fputcsv($handle, ['Bid Security Verified', $sub->bid_security_verified ? 'YES' : 'NO']);
            } else {
                fputcsv($handle, ['Status', 'Not yet officially submitted']);
            }
            fputcsv($handle, []);

            // Outcome
            fputcsv($handle, ['--- TENDER OUTCOME & WIN/LOSS ---']);
            if ($out = $tender->outcome) {
                fputcsv($handle, ['Outcome', $out->outcome]);
                fputcsv($handle, ['Winning Bidder', $out->winning_bidder_name ?: 'N/A']);
                fputcsv($handle, ['Winning Price', $out->winning_price ?: 'N/A']);
                fputcsv($handle, ['Loss Reason', $out->loss_reason ?: 'N/A']);
                fputcsv($handle, ['Debrief Notes', $out->debrief_notes ?: 'N/A']);
                fputcsv($handle, ['Contract Reference', $out->contract_reference ?: 'N/A']);
            } else {
                fputcsv($handle, ['Status', 'Pending procurement evaluation']);
            }

            fclose($handle);
        }, 200, $headers);
    }
}

