<?php

namespace App\Http\Controllers;

use App\Services\Tender\TechnicalSpecService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenderTechnicalSpecController
{
    public function __construct(
        private readonly TechnicalSpecService $specService
    ) {}

    public function index(Request $r, int $tenderId): JsonResponse
    {
        $data = $this->specService->list($r->user(), $tenderId);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function generate(Request $r, int $tenderId): JsonResponse
    {
        $data = $this->specService->generateFromRequirements($r->user(), $tenderId);

        return response()->json([
            'success' => true,
            'message' => 'Technical specifications matrix generated.',
            'data' => $data,
        ]);
    }

    public function store(Request $r, int $tenderId): JsonResponse
    {
        $validated = $r->validate([
            'item_number' => 'nullable|string|max:50',
            'title' => 'required|string|max:255',
            'original_specification' => 'required|string',
            'plain_language_explanation' => 'nullable|string',
            'purpose' => 'nullable|string',
            'is_mandatory' => 'required|boolean',
            'required_evidence' => 'nullable|string|max:1000',
            'suggested_solution' => 'nullable|string',
            'alternative_solutions' => 'nullable|string',
            'compliance_status' => 'nullable|string|in:comply,comply_with_deviation,alternative_offered,not_comply,needs_clarification',
            'bidder_offered_specification' => 'nullable|string',
            'deviation_statement' => 'nullable|string',
            'evidence_reference' => 'nullable|string|max:255',
        ]);

        $data = $this->specService->store($r->user(), $tenderId, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Technical specification clause added.',
            'data' => $data,
        ], 201);
    }

    public function update(Request $r, int $specId): JsonResponse
    {
        $validated = $r->validate([
            'compliance_status' => 'nullable|string|in:comply,comply_with_deviation,alternative_offered,not_comply,needs_clarification',
            'bidder_offered_specification' => 'nullable|string',
            'deviation_statement' => 'nullable|string',
            'evidence_reference' => 'nullable|string|max:255',
            'suggested_solution' => 'nullable|string',
            'alternative_solutions' => 'nullable|string',
            'is_mandatory' => 'nullable|boolean',
            'human_verified' => 'nullable|boolean',
        ]);

        $data = $this->specService->update($r->user(), $specId, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Technical specification updated.',
            'data' => $data,
        ]);
    }

    public function destroy(Request $r, int $specId): JsonResponse
    {
        $data = $this->specService->delete($r->user(), $specId);

        return response()->json([
            'success' => true,
            'message' => 'Technical specification removed.',
            'data' => $data,
        ]);
    }

    public function export(Request $r, int $tenderId): StreamedResponse
    {
        return $this->specService->exportCsv($r->user(), $tenderId);
    }

    public function bulkImport(Request $r, int $tenderId): JsonResponse
    {
        $validated = $r->validate([
            'raw_text' => 'required|string|min:3|max:50000',
        ]);

        $data = $this->specService->bulkImport($r->user(), $tenderId, $validated['raw_text']);

        return response()->json([
            'success' => true,
            'message' => 'Technical specifications imported successfully.',
            'data' => $data,
        ]);
    }
}

