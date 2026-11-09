<?php

namespace App\Http\Controllers;

use App\Services\Compliance\ComplianceService;
use Illuminate\Http\Request;

class ComplianceController
{
    public function data(Request $request, ComplianceService $service)
    {
        $filters = $request->only(['category', 'status']);

        return response()->json([
            'data' => $service->list($request->user(), $filters),
        ]);
    }

    public function store(Request $request, ComplianceService $service)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:60',
            'type_key' => 'required|string|max:80',
            'title' => 'required|string|max:160',
            'issuing_authority' => 'nullable|string|max:160',
            'reference_number' => 'nullable|string|max:120',
            'praz_categories' => 'nullable|array',
            'praz_categories.*' => 'string|max:30',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'verification_status' => 'nullable|in:valid,expiring,expired,missing,pending_verification,not_applicable',
            'status_reason' => 'nullable|string|max:255',
            'responsible_employee_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:2000',
            'file' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,zip,jpg,jpeg,png|max:20480',
        ]);

        $file = $request->file('file');
        $doc = $service->store($request->user(), $validated, $file);

        return response()->json([
            'message' => 'Compliance document saved successfully.',
            'document' => $doc,
            'data' => $service->list($request->user()),
        ]);
    }

    public function markNotApplicable(Request $request, ComplianceService $service)
    {
        $validated = $request->validate([
            'type_key' => 'required|string|max:80',
            'reason' => 'required|string|min:5|max:255',
        ]);

        $doc = $service->markNotApplicable($request->user(), $validated['type_key'], $validated['reason']);

        return response()->json([
            'message' => 'Document marked as not applicable.',
            'document' => $doc,
            'data' => $service->list($request->user()),
        ]);
    }

    public function download(Request $request, string $id, ComplianceService $service)
    {
        return $service->download($request->user(), (int) $id);
    }

    public function history(Request $request, string $id, ComplianceService $service)
    {
        return response()->json([
            'data' => $service->history($request->user(), (int) $id),
        ]);
    }
}
