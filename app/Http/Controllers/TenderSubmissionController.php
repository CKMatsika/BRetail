<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Services\Tender\TenderSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenderSubmissionController extends Controller
{
    public function __construct(
        protected TenderSubmissionService $submissionService
    ) {}

    /**
     * Show approvals, submission status, and readiness checklist.
     */
    public function show(int $tenderId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        $approvals = $this->submissionService->getOrCreateApprovals($tender);
        $approvals->load('approver:id,name');

        $readiness = $this->submissionService->checkSubmissionReadiness($tender);
        $submission = $tender->submission;
        $opening = $tender->openingRecord;

        return response()->json([
            'tender' => [
                'id' => $tender->id,
                'reference_number' => $tender->reference_number,
                'title' => $tender->title,
                'procuring_entity' => $tender->procuring_entity,
                'currency' => $tender->currency,
                'estimated_value' => $tender->estimated_value,
                'status' => $tender->status,
                'closing_at' => $tender->closing_at?->format('Y-m-d H:i'),
                'submission_method' => $tender->submission_method,
                'submission_address' => $tender->submission_address,
            ],
            'approvals' => $approvals,
            'readiness' => $readiness,
            'submission' => $submission,
            'opening_record' => $opening,
        ]);
    }

    /**
     * Record an approval stage decision (technical, commercial, executive).
     */
    public function recordApproval(Request $request, int $tenderId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized: Only tender managers can sign off approvals.'], 403);
        }

        $validated = $request->validate([
            'stage' => 'required|in:technical,commercial,executive',
            'status' => 'required|in:approved,rejected,conditional,pending',
            'comments' => 'nullable|string|max:1000',
            'conditions' => 'nullable|string|max:1000',
        ]);

        $approval = $this->submissionService->recordApprovalDecision(
            $tender,
            $validated['stage'],
            $validated['status'],
            $validated['comments'] ?? null,
            $validated['conditions'] ?? null,
            auth()->user()
        );

        $approvals = $this->submissionService->getOrCreateApprovals($tender);
        $readiness = $this->submissionService->checkSubmissionReadiness($tender);

        return response()->json([
            'message' => 'Approval decision recorded successfully.',
            'approval' => $approval,
            'approvals' => $approvals,
            'readiness' => $readiness,
        ]);
    }

    /**
     * Record official tender bid submission & proof of delivery.
     */
    public function recordSubmission(Request $request, int $tenderId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to record tender submissions.'], 403);
        }

        $validated = $request->validate([
            'submission_channel' => 'required|string|in:praz_egp,physical_box,email_portal',
            'egp_reference_number' => 'nullable|string|max:120',
            'physical_box_location' => 'nullable|string|max:255',
            'delivered_by_name' => 'nullable|string|max:120',
            'delivered_by_phone' => 'nullable|string|max:60',
            'receipt_number' => 'nullable|string|max:100',
            'submitted_at' => 'nullable|date',
            'technical_envelope_verified' => 'nullable|boolean',
            'financial_envelope_verified' => 'nullable|boolean',
            'bid_security_verified' => 'nullable|boolean',
            'copies_count' => 'nullable|integer|min:1',
            'package_notes' => 'nullable|string|max:1000',
            'proof_attachment_path' => 'nullable|string|max:500',
        ]);

        $submission = $this->submissionService->recordSubmission($tender, $validated, auth()->user());

        return response()->json([
            'message' => 'Tender submission recorded and status updated to submitted.',
            'submission' => $submission,
            'tender_status' => $tender->fresh()->status,
        ]);
    }

    /**
     * Record public opening minutes and competitor bids read out.
     */
    public function recordOpening(Request $request, int $tenderId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to record opening minutes.'], 403);
        }

        $validated = $request->validate([
            'attended_by_name' => 'nullable|string|max:150',
            'opening_date' => 'nullable|date',
            'venue' => 'nullable|string|max:255',
            'minutes_notes' => 'nullable|string',
            'competitor_bids' => 'nullable|array',
            'competitor_bids.*.competitor_name' => 'required|string|max:180',
            'competitor_bids.*.currency' => 'nullable|string|size:3',
            'competitor_bids.*.bid_amount' => 'required|numeric|min:0',
            'competitor_bids.*.bid_security_provided' => 'nullable|boolean',
            'competitor_bids.*.remarks' => 'nullable|string|max:500',
        ]);

        $opening = $this->submissionService->recordOpeningMinutes($tender, $validated);

        return response()->json([
            'message' => 'Public tender opening records saved.',
            'opening_record' => $opening,
        ]);
    }
}
