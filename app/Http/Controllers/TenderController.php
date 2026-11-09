<?php

namespace App\Http\Controllers;

use App\Services\Tender\TenderService;
use Illuminate\Http\Request;

class TenderController
{
    public function data(Request $request, TenderService $service)
    {
        $filters = $request->only(['search', 'category', 'status', 'entity_type', 'opportunity_type']);

        return response()->json([
            'data' => $service->list($request->user(), $filters),
        ]);
    }

    public function show(Request $request, string $id, TenderService $service)
    {
        return response()->json([
            'data' => $service->find($request->user(), (int) $id),
        ]);
    }

    public function store(Request $request, TenderService $service)
    {
        $validated = $request->validate([
            'reference_number' => 'required|string|max:120',
            'title' => 'required|string|max:255',
            'procuring_entity' => 'required|string|max:180',
            'entity_type' => 'nullable|string|in:government,local_authority,parastatal,ngo,private,international',
            'procurement_category' => 'required|string|max:80',
            'procurement_method' => 'nullable|string|max:80',
            'opportunity_type' => 'nullable|string|max:80',
            'praz_categories' => 'nullable|array',
            'praz_categories.*' => 'string|max:30',
            'unspsc_code' => 'nullable|string|max:30',
            'currency' => 'nullable|string|size:3',
            'estimated_value' => 'nullable|numeric|min:0',
            'tender_fee' => 'nullable|numeric|min:0',
            'bid_security_required' => 'nullable|boolean',
            'bid_security_amount' => 'nullable|numeric|min:0',
            'bid_security_details' => 'nullable|string|max:2000',
            'closing_at' => 'required|date',
            'clarification_deadline' => 'nullable|date',
            'site_visit_required' => 'nullable|boolean',
            'site_visit_at' => 'nullable|date',
            'site_visit_location' => 'nullable|string|max:255',
            'internal_deadline' => 'nullable|date',
            'submission_method' => 'nullable|string|in:electronic_portal,physical_delivery,email',
            'submission_address' => 'nullable|string|max:255',
            'source_url' => 'nullable|url|max:500',
            'contact_person' => 'nullable|string|max:120',
            'contact_email' => 'nullable|email|max:180',
            'contact_phone' => 'nullable|string|max:60',
            'assigned_employee_id' => 'nullable|integer',
            'status' => 'nullable|string|in:draft,evaluating_go_no_go,active_bidding,submitted,awarded,unsuccessful,cancelled',
            'notes' => 'nullable|string|max:5000',
            'documents' => 'nullable|array',
            'documents.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,zip,jpg,jpeg,png|max:30720',
        ]);

        $files = $request->file('documents') ?? [];
        $tender = $service->store($request->user(), $validated, is_array($files) ? $files : [$files]);

        return response()->json([
            'message' => 'Tender opportunity created successfully.',
            'tender' => $tender,
            'data' => $service->list($request->user()),
        ]);
    }

    public function update(Request $request, string $id, TenderService $service)
    {
        $validated = $request->validate([
            'reference_number' => 'nullable|string|max:120',
            'title' => 'nullable|string|max:255',
            'procuring_entity' => 'nullable|string|max:180',
            'entity_type' => 'nullable|string|in:government,local_authority,parastatal,ngo,private,international',
            'procurement_category' => 'nullable|string|max:80',
            'procurement_method' => 'nullable|string|max:80',
            'opportunity_type' => 'nullable|string|max:80',
            'praz_categories' => 'nullable|array',
            'praz_categories.*' => 'string|max:30',
            'unspsc_code' => 'nullable|string|max:30',
            'currency' => 'nullable|string|size:3',
            'estimated_value' => 'nullable|numeric|min:0',
            'tender_fee' => 'nullable|numeric|min:0',
            'bid_security_required' => 'nullable|boolean',
            'bid_security_amount' => 'nullable|numeric|min:0',
            'bid_security_details' => 'nullable|string|max:2000',
            'closing_at' => 'nullable|date',
            'clarification_deadline' => 'nullable|date',
            'site_visit_required' => 'nullable|boolean',
            'site_visit_at' => 'nullable|date',
            'site_visit_location' => 'nullable|string|max:255',
            'internal_deadline' => 'nullable|date',
            'submission_method' => 'nullable|string|in:electronic_portal,physical_delivery,email',
            'submission_address' => 'nullable|string|max:255',
            'source_url' => 'nullable|url|max:500',
            'contact_person' => 'nullable|string|max:120',
            'contact_email' => 'nullable|email|max:180',
            'contact_phone' => 'nullable|string|max:60',
            'assigned_employee_id' => 'nullable|integer',
            'status' => 'nullable|string|in:draft,evaluating_go_no_go,active_bidding,submitted,awarded,unsuccessful,cancelled',
            'status_reason' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:5000',
        ]);

        $tender = $service->update($request->user(), (int) $id, $validated);

        return response()->json([
            'message' => 'Tender opportunity updated successfully.',
            'tender' => $tender,
            'data' => $service->list($request->user()),
        ]);
    }

    public function updateStatus(Request $request, string $id, TenderService $service)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:draft,evaluating_go_no_go,active_bidding,submitted,awarded,unsuccessful,cancelled',
            'reason' => 'nullable|string|max:1000',
        ]);

        $tender = $service->updateStatus($request->user(), (int) $id, $validated['status'], $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Tender status updated.',
            'tender' => $tender,
            'data' => $service->list($request->user()),
        ]);
    }

    public function uploadDocument(Request $request, string $id, TenderService $service)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,zip,jpg,jpeg,png|max:30720',
            'document_type' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        $doc = $service->uploadDocument($request->user(), (int) $id, $file, $request->only(['document_type', 'description']));

        return response()->json([
            'message' => 'Tender document uploaded.',
            'document' => $doc,
            'data' => $service->find($request->user(), (int) $id),
        ]);
    }

    public function downloadDocument(Request $request, string $documentId, TenderService $service)
    {
        return $service->downloadDocument($request->user(), (int) $documentId);
    }

    public function deleteDocument(Request $request, string $documentId, TenderService $service)
    {
        $service->deleteDocument($request->user(), (int) $documentId);

        return response()->json([
            'message' => 'Tender document deleted.',
        ]);
    }
}
