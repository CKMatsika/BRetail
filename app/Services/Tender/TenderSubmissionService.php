<?php

namespace App\Services\Tender;

use App\Models\ComplianceDocument;
use App\Models\Tender;
use App\Models\TenderApproval;
use App\Models\TenderMilestone;
use App\Models\TenderOpeningRecord;
use App\Models\TenderPricingEstimate;
use App\Models\TenderSubmission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class TenderSubmissionService
{
    public const STAGES = [
        'technical' => [
            'name' => 'Technical & Compliance Verification',
            'role' => 'Lead Engineer / Technical Specialist',
            'description' => 'Verification that technical specifications, schedule of requirements, and methodology strictly comply with tender specifications.',
        ],
        'commercial' => [
            'name' => 'Commercial & Financial Verification',
            'role' => 'Finance Director / Commercial Manager',
            'description' => 'Review of itemized BOQ, profit margins, PRAZ 1% statutory levy, 15% VAT, split currency, and bid security bond guarantee.',
        ],
        'executive' => [
            'name' => 'Executive Final Sign-Off & Power of Attorney',
            'role' => 'Managing Director / CEO',
            'description' => 'Final corporate authorization, Power of Attorney delegation, and authorization to seal and submit tender.',
        ],
    ];

    /**
     * Get or initialize the 3-tier internal approval stages for the tender.
     */
    public function getOrCreateApprovals(Tender $tender): Collection
    {
        $tenantId = $tender->tenant_id;
        $existing = TenderApproval::where('tender_id', $tender->id)->get();

        if ($existing->count() < 3) {
            foreach (self::STAGES as $stageKey => $meta) {
                if (! $existing->firstWhere('stage', $stageKey)) {
                    TenderApproval::create([
                        'tenant_id' => $tenantId,
                        'tender_id' => $tender->id,
                        'stage' => $stageKey,
                        'status' => 'pending',
                        'approver_role' => $meta['role'],
                    ]);
                }
            }
            $existing = TenderApproval::where('tender_id', $tender->id)->get();
        }

        return $existing;
    }

    /**
     * Record an approval or rejection decision on a specific stage.
     */
    public function recordApprovalDecision(
        Tender $tender,
        string $stage,
        string $status,
        ?string $comments,
        ?string $conditions,
        User $user
    ): TenderApproval {
        if (! array_key_exists($stage, self::STAGES)) {
            throw new \InvalidArgumentException("Invalid approval stage: {$stage}");
        }

        if (! in_array($status, ['approved', 'rejected', 'conditional', 'pending'])) {
            throw new \InvalidArgumentException("Invalid approval status: {$status}");
        }

        $approval = TenderApproval::firstOrCreate(
            ['tenant_id' => $tender->tenant_id, 'tender_id' => $tender->id, 'stage' => $stage],
            ['approver_role' => self::STAGES[$stage]['role']]
        );

        $approval->update([
            'status' => $status,
            'approver_id' => $user->id,
            'approver_name' => $user->name,
            'comments' => $comments,
            'conditions' => $conditions,
            'actioned_at' => now(),
        ]);

        return $approval->fresh(['approver']);
    }

    /**
     * Check submission readiness checklist.
     */
    public function checkSubmissionReadiness(Tender $tender): array
    {
        $approvals = $this->getOrCreateApprovals($tender);
        $pricing = TenderPricingEstimate::where('tender_id', $tender->id)->first();
        $proposal = $tender->bidProposal;

        // Check statutory compliance documents
        $tenantId = $tender->tenant_id;
        $statutoryTypes = ['zimra_itf263', 'praz_certificate', 'nssa_clearance'];
        $docs = ComplianceDocument::where('tenant_id', $tenantId)
            ->whereIn('type_key', $statutoryTypes)
            ->where('is_active_version', true)
            ->get();

        $validStatutoryDocs = $docs->filter(fn ($d) => ! $d->isExpired());
        $statutoryPassed = $validStatutoryDocs->count() >= 2;

        $checklist = [
            [
                'key' => 'approvals_technical',
                'label' => 'Technical Review Approval',
                'passed' => $approvals->firstWhere('stage', 'technical')?->status === 'approved',
                'details' => $approvals->firstWhere('stage', 'technical')?->status ?? 'pending',
            ],
            [
                'key' => 'approvals_commercial',
                'label' => 'Commercial & Pricing Approval',
                'passed' => $approvals->firstWhere('stage', 'commercial')?->status === 'approved',
                'details' => $approvals->firstWhere('stage', 'commercial')?->status ?? 'pending',
            ],
            [
                'key' => 'approvals_executive',
                'label' => 'Executive Sign-Off & Power of Attorney',
                'passed' => $approvals->firstWhere('stage', 'executive')?->status === 'approved',
                'details' => $approvals->firstWhere('stage', 'executive')?->status ?? 'pending',
            ],
            [
                'key' => 'commercial_pricing',
                'label' => 'Commercial Pricing & BOQ Sign-Off',
                'passed' => $pricing && $pricing->status === 'approved',
                'details' => $pricing ? "Status: {$pricing->status}" : 'No pricing model initialized',
            ],
            [
                'key' => 'statutory_compliance',
                'label' => 'Statutory Compliance Documents (ITF263, PRAZ, NSSA)',
                'passed' => $statutoryPassed,
                'details' => "{$validStatutoryDocs->count()} verified valid statutory certificates in vault",
            ],
            [
                'key' => 'bid_proposal',
                'label' => 'Bid Proposal Documents Assembled',
                'passed' => $proposal !== null,
                'details' => $proposal ? "Proposal status: {$proposal->status}" : 'Proposal not drafted in studio',
            ],
        ];

        $passedCount = count(array_filter($checklist, fn ($item) => $item['passed']));
        $allPassed = $passedCount === count($checklist);

        return [
            'is_ready' => $allPassed,
            'passed_count' => $passedCount,
            'total_count' => count($checklist),
            'checklist' => $checklist,
        ];
    }

    /**
     * Record final submission proof of delivery and update tender status.
     */
    public function recordSubmission(Tender $tender, array $data, User $user): TenderSubmission
    {
        $submission = TenderSubmission::updateOrCreate(
            [
                'tenant_id' => $tender->tenant_id,
                'tender_id' => $tender->id,
            ],
            [
                'submission_channel' => $data['submission_channel'] ?? 'praz_egp',
                'egp_reference_number' => $data['egp_reference_number'] ?? null,
                'physical_box_location' => $data['physical_box_location'] ?? null,
                'delivered_by_name' => $data['delivered_by_name'] ?? null,
                'delivered_by_phone' => $data['delivered_by_phone'] ?? null,
                'receipt_number' => $data['receipt_number'] ?? null,
                'submitted_at' => isset($data['submitted_at']) ? Carbon::parse($data['submitted_at']) : now(),
                'submitted_by_id' => $user->id,
                'technical_envelope_verified' => (bool) ($data['technical_envelope_verified'] ?? true),
                'financial_envelope_verified' => (bool) ($data['financial_envelope_verified'] ?? true),
                'bid_security_verified' => (bool) ($data['bid_security_verified'] ?? true),
                'copies_count' => (int) ($data['copies_count'] ?? 1),
                'package_notes' => $data['package_notes'] ?? null,
                'proof_attachment_path' => $data['proof_attachment_path'] ?? null,
            ]
        );

        // Transition tender status to submitted
        $tender->update([
            'status' => 'submitted',
            'status_reason' => 'Tender bid officially submitted via ' . ($data['submission_channel'] ?? 'electronic portal'),
        ]);

        // Complete closing milestone if exists
        TenderMilestone::where('tender_id', $tender->id)
            ->where('milestone_type', 'submission_closing')
            ->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

        return $submission;
    }

    /**
     * Record public tender opening minutes and competitor read-out prices.
     */
    public function recordOpeningMinutes(Tender $tender, array $data): TenderOpeningRecord
    {
        $opening = TenderOpeningRecord::updateOrCreate(
            [
                'tenant_id' => $tender->tenant_id,
                'tender_id' => $tender->id,
            ],
            [
                'attended_by_name' => $data['attended_by_name'] ?? null,
                'opening_date' => isset($data['opening_date']) ? Carbon::parse($data['opening_date']) : now(),
                'venue' => $data['venue'] ?? null,
                'minutes_notes' => $data['minutes_notes'] ?? null,
                'competitor_bids' => $data['competitor_bids'] ?? [],
            ]
        );

        return $opening;
    }
}

