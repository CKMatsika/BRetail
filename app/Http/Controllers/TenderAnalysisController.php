<?php

namespace App\Http\Controllers;

use App\Services\Tender\TenderAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenderAnalysisController
{
    public function __construct(
        private readonly TenderAnalysisService $analysisService
    ) {}

    public function getAnalysis(Request $r, int $tenderId): JsonResponse
    {
        $data = $this->analysisService->getAnalysis($r->user(), $tenderId);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function runAnalysis(Request $r, int $tenderId): JsonResponse
    {
        $data = $this->analysisService->analyzeTender($r->user(), $tenderId);

        return response()->json([
            'success' => true,
            'message' => 'AI Tender document analysis completed.',
            'data' => $data,
        ]);
    }

    public function updateRequirement(Request $r, int $requirementId): JsonResponse
    {
        $validated = $r->validate([
            'verification_status' => 'nullable|string|in:pending,compliant,non_compliant,clarification_needed',
            'human_notes' => 'nullable|string|max:1000',
            'human_verified' => 'nullable|boolean',
        ]);

        $data = $this->analysisService->updateRequirement($r->user(), $requirementId, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Requirement updated.',
            'data' => $data,
        ]);
    }

    public function addRequirement(Request $r, int $tenderId): JsonResponse
    {
        $validated = $r->validate([
            'category' => 'required|string|in:statutory_compliance,administrative,technical_specification,financial,bid_security,experience,personnel,equipment,insurance,submission_instruction',
            'title' => 'required|string|max:255',
            'original_wording' => 'nullable|string',
            'simplified_explanation' => 'nullable|string',
            'is_mandatory' => 'required|boolean',
            'evidence_required' => 'nullable|string|max:500',
            'source_document_name' => 'nullable|string|max:255',
            'page_number' => 'nullable|integer|min:1',
            'verification_status' => 'nullable|string|in:pending,compliant,non_compliant,clarification_needed',
            'human_notes' => 'nullable|string|max:1000',
        ]);

        $data = $this->analysisService->addRequirement($r->user(), $tenderId, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Requirement added to matrix.',
            'data' => $data,
        ], 201);
    }

    public function deleteRequirement(Request $r, int $requirementId): JsonResponse
    {
        $data = $this->analysisService->deleteRequirement($r->user(), $requirementId);

        return response()->json([
            'success' => true,
            'message' => 'Requirement removed from matrix.',
            'data' => $data,
        ]);
    }
}

