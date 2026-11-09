<?php

namespace App\Services\Tender;

use App\Models\Tender;
use App\Models\TenderRequirement;
use App\Models\TenderTechnicalSpec;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TechnicalSpecService
{
    private function permit(User $u, string $permission): void
    {
        abort_unless($u->canDo($permission), 403, 'Unauthorized technical specification action.');
    }

    public function list(User $u, int $tenderId): array
    {
        $this->permit($u, 'tenders.view');

        $tender = Tender::with(['technicalSpecs'])->findOrFail($tenderId);
        $specs = $tender->technicalSpecs;

        $totalSpecs = $specs->count();
        $complyCount = $specs->where('compliance_status', 'comply')->count();
        $deviationCount = $specs->where('compliance_status', 'comply_with_deviation')->count();
        $alternativeCount = $specs->where('compliance_status', 'alternative_offered')->count();
        $nonComplyCount = $specs->where('compliance_status', 'not_comply')->count();
        $clarificationCount = $specs->where('compliance_status', 'needs_clarification')->count();

        $compliancePercent = $totalSpecs > 0
            ? round((($complyCount + ($alternativeCount * 0.9) + ($deviationCount * 0.5)) / $totalSpecs) * 100, 1)
            : 0.0;

        // Mandatory items with deviations or non-compliance
        $mandatoryDeviations = $specs->filter(function ($s) {
            return $s->is_mandatory && in_array($s->compliance_status, ['comply_with_deviation', 'not_comply'], true);
        })->map(fn ($s) => [
            'item_number' => $s->item_number,
            'title' => $s->title,
            'compliance_status' => $s->compliance_status,
            'deviation_statement' => $s->deviation_statement ?: 'No deviation justification recorded.',
        ])->values()->all();

        return [
            'tender' => [
                'id' => $tender->id,
                'reference_number' => $tender->reference_number,
                'title' => $tender->title,
                'procuring_entity' => $tender->procuring_entity,
                'status' => $tender->status,
            ],
            'specs' => $specs->map(fn (TenderTechnicalSpec $s) => [
                'id' => $s->id,
                'tender_id' => $s->tender_id,
                'item_number' => $s->item_number,
                'title' => $s->title,
                'original_specification' => $s->original_specification,
                'plain_language_explanation' => $s->plain_language_explanation,
                'purpose' => $s->purpose,
                'is_mandatory' => $s->is_mandatory,
                'required_evidence' => $s->required_evidence,
                'suggested_solution' => $s->suggested_solution,
                'alternative_solutions' => $s->alternative_solutions,
                'compliance_status' => $s->compliance_status,
                'bidder_offered_specification' => $s->bidder_offered_specification,
                'deviation_statement' => $s->deviation_statement,
                'evidence_reference' => $s->evidence_reference,
                'human_verified' => $s->human_verified,
                'order_index' => $s->order_index,
            ])->all(),
            'summary' => [
                'total_specs' => $totalSpecs,
                'comply_count' => $complyCount,
                'deviation_count' => $deviationCount,
                'alternative_count' => $alternativeCount,
                'non_comply_count' => $nonComplyCount,
                'clarification_count' => $clarificationCount,
                'compliance_percentage' => $compliancePercent,
                'mandatory_deviations' => $mandatoryDeviations,
                'has_disqualifying_non_compliance' => $specs->where('is_mandatory', true)->where('compliance_status', 'not_comply')->isNotEmpty(),
            ],
        ];
    }

    public function generateFromRequirements(User $u, int $tenderId): array
    {
        $this->permit($u, 'tenders.manage');

        $tender = Tender::with(['requirements'])->findOrFail($tenderId);

        // Fetch technical requirements
        $techReqs = $tender->requirements->where('category', 'technical_specification')->values();

        // If no technical requirements exist yet, seed baseline technical clauses from tender scope
        if ($techReqs->isEmpty()) {
            $defaultClauses = [
                [
                    'title' => 'Technical Scope Compliance',
                    'original' => "The goods/services supplied shall strictly adhere to the technical schedule and scope detailed in {$tender->title}.",
                    'explanation' => 'Full adherence to procuring entity technical schedule.',
                    'purpose' => 'Ensure standard operational compatibility and functionality.',
                    'evidence' => 'Manufacturer technical datasheets, brochures, and clause compliance schedule.',
                    'solution' => 'Offered solution fully compliant with advertised schedule.',
                    'mandatory' => true,
                ],
                [
                    'title' => 'Applicable Standards & Quality Certifications',
                    'original' => 'Goods/equipment must conform to Standards Association of Zimbabwe (SAZ), ISO, or recognized international equivalents.',
                    'explanation' => 'Certified conformity with SAZ or ISO manufacturing standards.',
                    'purpose' => 'Guarantee product safety, reliability, and regulatory compliance.',
                    'evidence' => 'Certified copies of ISO/SAZ certificates and laboratory test reports.',
                    'solution' => 'Conforming to SAZ / ISO 9001 certified manufacturing.',
                    'mandatory' => true,
                ],
                [
                    'title' => 'Warranty & Spares Availability',
                    'original' => 'Minimum twelve (12) months warranty from date of commissioning, with local after-sales service and spare parts availability in Zimbabwe.',
                    'explanation' => '1-year comprehensive warranty and accessible local repair support.',
                    'purpose' => 'Mitigate downtime and guarantee long-term asset operation.',
                    'evidence' => 'Manufacturer warranty letter and local service centre details.',
                    'solution' => '12 months warranty with dedicated local support centre in Zimbabwe.',
                    'mandatory' => true,
                ],
            ];
        } else {
            $defaultClauses = $techReqs->map(fn ($r) => [
                'title' => $r->title,
                'original' => $r->original_wording ?: $r->title,
                'explanation' => $r->simplified_explanation ?: $r->title,
                'purpose' => 'Technical quality assurance and bid compliance verification.',
                'evidence' => $r->evidence_required ?: 'Manufacturer technical datasheet and test reports.',
                'solution' => 'Fully complies with technical parameters as detailed in specification.',
                'mandatory' => (bool) $r->is_mandatory,
                'requirement_id' => $r->id,
            ])->all();
        }

        return DB::transaction(function () use ($u, $tender, $defaultClauses) {
            // Delete existing generated specs to allow fresh sync
            TenderTechnicalSpec::where('tender_id', $tender->id)->delete();

            $index = 1;
            foreach ($defaultClauses as $clause) {
                TenderTechnicalSpec::create([
                    'tenant_id' => $tender->tenant_id,
                    'tender_id' => $tender->id,
                    'tender_requirement_id' => $clause['requirement_id'] ?? null,
                    'item_number' => sprintf('TS-%02d', $index),
                    'title' => Str::limit($clause['title'], 255),
                    'original_specification' => $clause['original'],
                    'plain_language_explanation' => $clause['explanation'],
                    'purpose' => $clause['purpose'],
                    'is_mandatory' => $clause['mandatory'],
                    'required_evidence' => $clause['evidence'],
                    'suggested_solution' => $clause['solution'],
                    'alternative_solutions' => 'Permitted equivalent solutions subject to written entity approval.',
                    'compliance_status' => 'comply',
                    'bidder_offered_specification' => $clause['solution'],
                    'deviation_statement' => null,
                    'evidence_reference' => 'Technical Brochure & Annexure A',
                    'human_verified' => false,
                    'order_index' => $index,
                ]);
                $index++;
            }

            Audit::record('tender_technical_specs.generated', (string) $tender->id, [
                'specs_count' => count($defaultClauses),
            ]);

            return $this->list($u, $tender->id);
        });
    }

    public function store(User $u, int $tenderId, array $data): array
    {
        $this->permit($u, 'tenders.manage');

        $tender = Tender::findOrFail($tenderId);
        $nextOrder = TenderTechnicalSpec::where('tender_id', $tender->id)->max('order_index') + 1;

        TenderTechnicalSpec::create([
            'tenant_id' => $tender->tenant_id,
            'tender_id' => $tender->id,
            'item_number' => $data['item_number'] ?? sprintf('TS-%02d', $nextOrder),
            'title' => Str::limit($data['title'], 255),
            'original_specification' => $data['original_specification'],
            'plain_language_explanation' => $data['plain_language_explanation'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'is_mandatory' => (bool) ($data['is_mandatory'] ?? true),
            'required_evidence' => $data['required_evidence'] ?? null,
            'suggested_solution' => $data['suggested_solution'] ?? null,
            'alternative_solutions' => $data['alternative_solutions'] ?? null,
            'compliance_status' => $data['compliance_status'] ?? 'comply',
            'bidder_offered_specification' => $data['bidder_offered_specification'] ?? $data['suggested_solution'] ?? null,
            'deviation_statement' => $data['deviation_statement'] ?? null,
            'evidence_reference' => $data['evidence_reference'] ?? null,
            'human_verified' => true,
            'order_index' => $nextOrder,
        ]);

        Audit::record('tender_technical_spec.created', (string) $tender->id, [
            'item_number' => $data['item_number'] ?? "TS-{$nextOrder}",
            'title' => $data['title'],
        ]);

        return $this->list($u, $tender->id);
    }

    public function update(User $u, int $specId, array $data): array
    {
        $this->permit($u, 'tenders.manage');

        $spec = TenderTechnicalSpec::findOrFail($specId);

        if (array_key_exists('compliance_status', $data)) {
            $spec->compliance_status = $data['compliance_status'];
        }
        if (array_key_exists('bidder_offered_specification', $data)) {
            $spec->bidder_offered_specification = $data['bidder_offered_specification'];
        }
        if (array_key_exists('deviation_statement', $data)) {
            $spec->deviation_statement = $data['deviation_statement'];
        }
        if (array_key_exists('evidence_reference', $data)) {
            $spec->evidence_reference = $data['evidence_reference'];
        }
        if (array_key_exists('suggested_solution', $data)) {
            $spec->suggested_solution = $data['suggested_solution'];
        }
        if (array_key_exists('alternative_solutions', $data)) {
            $spec->alternative_solutions = $data['alternative_solutions'];
        }
        if (array_key_exists('is_mandatory', $data)) {
            $spec->is_mandatory = (bool) $data['is_mandatory'];
        }
        if (array_key_exists('human_verified', $data)) {
            $spec->human_verified = (bool) $data['human_verified'];
        }

        $spec->save();

        Audit::record('tender_technical_spec.updated', (string) $spec->id, [
            'compliance_status' => $spec->compliance_status,
            'tender_id' => $spec->tender_id,
        ]);

        return $this->list($u, $spec->tender_id);
    }

    public function delete(User $u, int $specId): array
    {
        $this->permit($u, 'tenders.manage');

        $spec = TenderTechnicalSpec::findOrFail($specId);
        $tenderId = $spec->tender_id;
        $spec->delete();

        Audit::record('tender_technical_spec.deleted', (string) $specId, ['tender_id' => $tenderId]);

        return $this->list($u, $tenderId);
    }

    public function exportCsv(User $u, int $tenderId): StreamedResponse
    {
        $this->permit($u, 'tenders.view');

        $tender = Tender::with(['technicalSpecs'])->findOrFail($tenderId);
        $specs = $tender->technicalSpecs;

        $filename = 'Technical_Compliance_Matrix_'.Str::slug($tender->reference_number).'_'.date('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($tender, $specs) {
            $handle = fopen('php://output', 'w');

            // Header info
            fputcsv($handle, ['SCHEDULE OF TECHNICAL COMPLIANCE & SPECIFICATION MATRIX']);
            fputcsv($handle, ['Tender Reference:', $tender->reference_number]);
            fputcsv($handle, ['Tender Title:', $tender->title]);
            fputcsv($handle, ['Procuring Entity:', $tender->procuring_entity]);
            fputcsv($handle, ['Date Generated:', date('Y-m-d H:i:s')]);
            fputcsv($handle, []);

            // Columns
            fputcsv($handle, [
                'Item No',
                'Classification',
                'Procuring Entity Specification',
                'Purpose / Function',
                'Bidder Offered Specification',
                'Compliance Status',
                'Deviations / Remarks',
                'Evidence / Reference',
            ]);

            foreach ($specs as $s) {
                fputcsv($handle, [
                    $s->item_number,
                    $s->is_mandatory ? 'MANDATORY' : 'OPTIONAL',
                    $s->original_specification,
                    $s->purpose ?: ($s->plain_language_explanation ?: 'Technical Conformity'),
                    $s->bidder_offered_specification ?: $s->suggested_solution,
                    strtoupper(str_replace('_', ' ', $s->compliance_status)),
                    $s->deviation_statement ?: 'Fully Compliant without deviation',
                    $s->evidence_reference ?: 'Datasheet / Test Certificate',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
