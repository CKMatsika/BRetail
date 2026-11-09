<?php

namespace App\Http\Controllers;

use App\Models\BidProposal;
use App\Models\BidProposalSection;
use App\Models\BidStandardDocument;
use App\Models\Tender;
use App\Services\Tender\BidStudioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BidStudioController extends Controller
{
    public function __construct(
        protected BidStudioService $bidService
    ) {}

    /**
     * Get or initialize bid proposal with sections and returnable schedules.
     */
    public function show(int $tenderId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        $proposal = $this->bidService->getOrCreateProposal($tender, auth()->id());
        $proposal->load([
            'sections' => fn ($q) => $q->orderBy('order_index'),
            'standardDocuments' => fn ($q) => $q->orderBy('id'),
            'leadAuthor:id,name',
            'approvedBy:id,name',
        ]);

        return response()->json([
            'proposal' => $proposal,
            'tender' => [
                'id' => $tender->id,
                'reference_number' => $tender->reference_number,
                'title' => $tender->title,
                'procuring_entity' => $tender->procuring_entity,
                'closing_at' => $tender->closing_at,
                'currency' => $tender->currency,
                'estimated_value' => $tender->estimated_value,
                'status' => $tender->status,
            ],
        ]);
    }

    /**
     * Regenerate all sections for the bid proposal.
     */
    public function generateAll(int $tenderId): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to generate bid proposals.'], 403);
        }

        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);
        $proposal = $this->bidService->getOrCreateProposal($tender, auth()->id());

        $this->bidService->generateAllSections($proposal, $tender);
        $this->bidService->generateStandardDocuments($proposal, $tender);

        return response()->json([
            'message' => 'Complete bid proposal package generated successfully.',
            'proposal' => $proposal->fresh(['sections', 'standardDocuments']),
        ]);
    }

    /**
     * Regenerate a single specific proposal section.
     */
    public function regenerateSection(int $sectionId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to regenerate section.'], 403);
        }

        $tenantId = auth()->user()->tenant_id;
        $section = BidProposalSection::where('tenant_id', $tenantId)->findOrFail($sectionId);

        $instructions = $request->input('instructions');
        $updated = $this->bidService->regenerateSection($section, $instructions);

        return response()->json([
            'message' => 'Section regenerated successfully.',
            'section' => $updated,
        ]);
    }

    /**
     * Update proposal section content, title, or status.
     */
    public function updateSection(int $sectionId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to modify proposal sections.'], 403);
        }

        $request->validate([
            'title' => 'sometimes|required|string|max:180',
            'content' => 'sometimes|required|string',
            'is_included' => 'sometimes|required|boolean',
            'status' => 'sometimes|required|string|in:draft,in_review,approved,finalized',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $section = BidProposalSection::where('tenant_id', $tenantId)->findOrFail($sectionId);

        $attributes = $request->only(['title', 'content', 'is_included', 'status']);
        $attributes['last_edited_by_user_id'] = auth()->id();

        $section->update($attributes);

        return response()->json([
            'message' => 'Section saved successfully.',
            'section' => $section->fresh(),
        ]);
    }

    /**
     * Update standard returnable document content or status.
     */
    public function updateDocument(int $docId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to modify standard documents.'], 403);
        }

        $request->validate([
            'title' => 'sometimes|required|string|max:180',
            'content' => 'sometimes|required|string',
            'status' => 'sometimes|required|string|in:draft,ready_to_sign,signed_attached',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $doc = BidStandardDocument::where('tenant_id', $tenantId)->findOrFail($docId);

        $doc->update($request->only(['title', 'content', 'status']));

        return response()->json([
            'message' => 'Standard returnable schedule updated.',
            'document' => $doc->fresh(),
        ]);
    }

    /**
     * Update master proposal approval status.
     */
    public function updateStatus(int $tenderId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to update proposal status.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:draft,in_review,approved,finalized',
            'overall_notes' => 'nullable|string|max:1000',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);
        $proposal = BidProposal::where('tender_id', $tender->id)->firstOrFail();

        $attributes = [
            'status' => $request->status,
            'overall_notes' => $request->overall_notes,
        ];

        if ($request->status === 'approved' && $proposal->status !== 'approved') {
            $attributes['approved_by_user_id'] = auth()->id();
            $attributes['approved_at'] = now();
        } elseif ($request->status !== 'approved') {
            $attributes['approved_by_user_id'] = null;
            $attributes['approved_at'] = null;
        }

        $proposal->update($attributes);

        return response()->json([
            'message' => 'Proposal workflow status updated.',
            'proposal' => $proposal->fresh(['leadAuthor:id,name', 'approvedBy:id,name']),
        ]);
    }

    /**
     * Export complete proposal as printable publication-grade HTML.
     */
    public function exportHtml(int $tenderId): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);
        $proposal = $this->bidService->getOrCreateProposal($tender, auth()->id());

        $html = $this->bidService->exportPrintableHtml($proposal);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * Export complete proposal as Microsoft Word formatted document (.doc).
     */
    public function exportDoc(int $tenderId): StreamedResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);
        $proposal = $this->bidService->getOrCreateProposal($tender, auth()->id());

        $docContent = $this->bidService->exportWordDocument($proposal);
        $filename = 'Proposal_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $tender->reference_number) . '.doc';

        return response()->streamDownload(function () use ($docContent) {
            echo $docContent;
        }, $filename, [
            'Content-Type' => 'application/msword; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}

