<?php

namespace App\Services\Tender;

use App\Models\ComplianceDocument;
use App\Models\Employee;
use App\Models\Tender;
use App\Models\TenderGapAnalysis;
use App\Models\TenderRequirement;
use App\Models\TenderTechnicalSpec;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TenderGapAnalysisService
{
    /**
     * Perform or refresh gap analysis for a tender.
     */
    public function evaluate(Tender $tender): TenderGapAnalysis
    {
        $tenantId = $tender->tenant_id;

        // 1. Fetch active compliance documents
        $documents = ComplianceDocument::where('tenant_id', $tenantId)->where('is_active', true)->get();

        // 2. Fetch technical specifications
        $techSpecs = TenderTechnicalSpec::where('tender_id', $tender->id)->get();

        // 3. Fetch tender requirements
        $requirements = TenderRequirement::where('tender_id', $tender->id)->get();

        // Find assigned officer / fallback employee for action plan
        $defaultOfficer = $this->getDefaultComplianceOfficer($tenantId);

        $fatalFlaws = [];
        $actionPlan = [];

        // --- Category 1: Statutory & Regulatory Compliance (25%) ---
        $statutoryEval = $this->evaluateStatutory($documents, $tender, $fatalFlaws, $actionPlan, $defaultOfficer);

        // --- Category 2: Eligibility & Governance (15%) ---
        $eligibilityEval = $this->evaluateEligibility($documents, $fatalFlaws, $actionPlan, $defaultOfficer);

        // --- Category 3: Technical Capability & Staff (25%) ---
        $technicalEval = $this->evaluateTechnical($documents, $techSpecs, $requirements, $fatalFlaws, $actionPlan, $defaultOfficer);

        // --- Category 4: Financial Standing & Capacity (15%) ---
        $financialEval = $this->evaluateFinancial($documents, $tender, $fatalFlaws, $actionPlan, $defaultOfficer);

        // --- Category 5: Past Experience & References (10%) ---
        $experienceEval = $this->evaluateExperience($documents, $fatalFlaws, $actionPlan, $defaultOfficer);

        // --- Category 6: Bid Security & Mandatory Preliminaries (10%) ---
        $bidSecurityEval = $this->evaluateBidSecurityAndPreliminaries($tender, $fatalFlaws, $actionPlan, $defaultOfficer);

        // Compute Weighted Overall Score
        $rawScore = round(
            ($statutoryEval['score'] * 0.25) +
            ($eligibilityEval['score'] * 0.15) +
            ($technicalEval['score'] * 0.25) +
            ($financialEval['score'] * 0.15) +
            ($experienceEval['score'] * 0.10) +
            ($bidSecurityEval['score'] * 0.10)
        );

        $fatalFlawsCount = count($fatalFlaws);
        $isMandatoryPassed = ($fatalFlawsCount === 0);

        // Score penalty if fatal flaws exist
        $overallScore = $fatalFlawsCount > 0 ? min($rawScore, 42) : $rawScore;

        // Recommendation determination
        if ($fatalFlawsCount > 0) {
            $recommendation = 'do_not_bid';
            $recommendationReason = 'Disqualification risk: ' . $fatalFlaws[0] . ' (Total ' . $fatalFlawsCount . ' fatal flaw' . ($fatalFlawsCount > 1 ? 's' : '') . ' detected). Remediation or formal justification required before proceeding.';
        } elseif ($overallScore >= 82) {
            $recommendation = 'strong_bid';
            $recommendationReason = 'High compliance match (' . $overallScore . '%). All mandatory statutory, technical, and commercial criteria are satisfied. Proceed with competitive proposal preparation.';
        } elseif ($overallScore >= 60) {
            $recommendation = 'conditional_bid';
            $recommendationReason = 'Viable opportunity (' . $overallScore . '%). Mandatory criteria are met, but ' . count($actionPlan) . ' remediation task(s) must be completed before submission deadline.';
        } else {
            $recommendation = 'high_risk';
            $recommendationReason = 'Low readiness score (' . $overallScore . '%). Significant gaps identified in supporting documentation or technical qualifications.';
        }

        $categoryScores = [
            'statutory' => [
                'name' => 'Statutory & Regulatory Compliance',
                'weight' => 25,
                'score' => $statutoryEval['score'],
                'status' => $statutoryEval['status'],
                'details' => $statutoryEval['details'],
            ],
            'eligibility' => [
                'name' => 'Eligibility & Governance',
                'weight' => 15,
                'score' => $eligibilityEval['score'],
                'status' => $eligibilityEval['status'],
                'details' => $eligibilityEval['details'],
            ],
            'technical' => [
                'name' => 'Technical Capability & Human Resources',
                'weight' => 25,
                'score' => $technicalEval['score'],
                'status' => $technicalEval['status'],
                'details' => $technicalEval['details'],
            ],
            'financial' => [
                'name' => 'Financial Standing & Capacity',
                'weight' => 15,
                'score' => $financialEval['score'],
                'status' => $financialEval['status'],
                'details' => $financialEval['details'],
            ],
            'experience' => [
                'name' => 'Past Experience & References',
                'weight' => 10,
                'score' => $experienceEval['score'],
                'status' => $experienceEval['status'],
                'details' => $experienceEval['details'],
            ],
            'bid_security' => [
                'name' => 'Bid Security & Commercial Preliminaries',
                'weight' => 10,
                'score' => $bidSecurityEval['score'],
                'status' => $bidSecurityEval['status'],
                'details' => $bidSecurityEval['details'],
            ],
        ];

        // Retrieve existing gap analysis to preserve manual override if present
        $existing = TenderGapAnalysis::where('tender_id', $tender->id)->first();

        if ($existing && $existing->manual_override) {
            $recommendation = $existing->override_recommendation ?: $recommendation;
        }

        $gapAnalysis = TenderGapAnalysis::updateOrCreate(
            ['tender_id' => $tender->id],
            [
                'tenant_id' => $tenantId,
                'overall_score' => $overallScore,
                'recommendation' => $recommendation,
                'recommendation_reason' => $recommendationReason,
                'is_mandatory_passed' => $isMandatoryPassed,
                'fatal_flaws_count' => $fatalFlawsCount,
                'category_scores' => $categoryScores,
                'fatal_flaws' => $fatalFlaws,
                'action_plan' => $actionPlan,
                'analyzed_at' => now(),
            ]
        );

        return $gapAnalysis;
    }

    /**
     * Record a manual override on the gap analysis.
     */
    public function recordOverride(TenderGapAnalysis $analysis, string $overrideRecommendation, string $notes, int $userId): TenderGapAnalysis
    {
        $analysis->update([
            'manual_override' => true,
            'override_recommendation' => $overrideRecommendation,
            'override_notes' => $notes,
            'override_by_user_id' => $userId,
            'override_at' => now(),
        ]);

        return $analysis;
    }

    /**
     * Update the status of a remediation item in the action plan.
     */
    public function updateActionItem(TenderGapAnalysis $analysis, string $itemId, string $status, ?string $notes = null): TenderGapAnalysis
    {
        $plan = $analysis->action_plan ?? [];
        $updated = false;

        foreach ($plan as &$item) {
            if (($item['id'] ?? '') === $itemId) {
                $item['status'] = $status;
                if ($notes !== null) {
                    $item['notes'] = $notes;
                }
                $item['updated_at'] = now()->toIso8601String();
                $updated = true;
                break;
            }
        }

        if ($updated) {
            $analysis->action_plan = $plan;
            $analysis->save();
        }

        return $analysis;
    }

    // --- Private Evaluation Methods ---

    protected function evaluateStatutory($documents, Tender $tender, array &$fatalFlaws, array &$actionPlan, string $officer): array
    {
        $statutoryScore = 100;
        $details = [];

        // 1. ZIMRA Tax Clearance (ITF263)
        $itf263 = $documents->firstWhere('type_key', 'zimra_itf263');
        if (! $itf263) {
            $statutoryScore -= 40;
            $fatalFlaws[] = 'Missing valid ZIMRA ITF263 Tax Clearance Certificate.';
            $actionPlan[] = [
                'id' => (string) Str::uuid(),
                'title' => 'Obtain / Upload ZIMRA ITF263 Tax Clearance Certificate',
                'category' => 'statutory',
                'description' => 'Zimbabwe Revenue Authority Tax Clearance is a mandatory statutory requirement for all public and private tenders.',
                'priority' => 'critical',
                'estimated_days' => 3,
                'estimated_cost' => 0,
                'responsible_person' => $officer,
                'status' => 'pending',
                'due_date' => $this->suggestDueDate($tender, 5),
            ];
            $details[] = 'ZIMRA ITF263 Tax Clearance: Missing (Disqualification fatal flaw)';
        } elseif ($itf263->isExpired()) {
            $statutoryScore -= 40;
            $fatalFlaws[] = 'ZIMRA ITF263 Tax Clearance is expired (Expired on ' . $itf263->expiry_date->format('d M Y') . ').';
            $actionPlan[] = [
                'id' => (string) Str::uuid(),
                'title' => 'Renew Expired ZIMRA ITF263 Tax Clearance',
                'category' => 'statutory',
                'description' => 'Current ITF263 has expired. Obtain updated clearance from ZIMRA self-service portal.',
                'priority' => 'critical',
                'estimated_days' => 2,
                'estimated_cost' => 0,
                'responsible_person' => $officer,
                'status' => 'pending',
                'due_date' => $this->suggestDueDate($tender, 3),
            ];
            $details[] = 'ZIMRA ITF263 Tax Clearance: Expired on ' . $itf263->expiry_date->format('d M Y');
        } else {
            $details[] = 'ZIMRA ITF263 Tax Clearance: Valid until ' . ($itf263->expiry_date ? $itf263->expiry_date->format('d M Y') : 'Indefinite');
        }

        // 2. PRAZ Registration
        $praz = $documents->firstWhere('type_key', 'praz_certificate');
        if (! $praz) {
            $statutoryScore -= 35;
            $fatalFlaws[] = 'Missing PRAZ Supplier Registration Certificate.';
            $actionPlan[] = [
                'id' => (string) Str::uuid(),
                'title' => 'Register with PRAZ and Upload Annual Supplier Certificate',
                'category' => 'statutory',
                'description' => 'Mandatory for all Zimbabwean government, parastatal, and local authority bids under the PPDPA Act.',
                'priority' => 'critical',
                'estimated_days' => 2,
                'estimated_cost' => 120,
                'responsible_person' => $officer,
                'status' => 'pending',
                'due_date' => $this->suggestDueDate($tender, 4),
            ];
            $details[] = 'PRAZ Registration: Missing (Disqualification fatal flaw)';
        } elseif ($praz->isExpired()) {
            $statutoryScore -= 35;
            $fatalFlaws[] = 'PRAZ Certificate is expired (Expired on ' . $praz->expiry_date->format('d M Y') . ').';
            $actionPlan[] = [
                'id' => (string) Str::uuid(),
                'title' => 'Renew PRAZ Supplier Registration Certificate',
                'category' => 'statutory',
                'description' => 'Renew registration on PRAZ portal and upload valid certificate.',
                'priority' => 'critical',
                'estimated_days' => 1,
                'estimated_cost' => 120,
                'responsible_person' => $officer,
                'status' => 'pending',
                'due_date' => $this->suggestDueDate($tender, 3),
            ];
            $details[] = 'PRAZ Registration: Expired on ' . $praz->expiry_date->format('d M Y');
        } else {
            $details[] = 'PRAZ Registration: Valid until ' . ($praz->expiry_date ? $praz->expiry_date->format('d M Y') : 'Active');
        }

        // 3. NSSA Clearance
        $nssa = $documents->firstWhere('type_key', 'nssa_clearance');
        if (! $nssa) {
            $statutoryScore -= 15;
            $actionPlan[] = [
                'id' => (string) Str::uuid(),
                'title' => 'Upload NSSA Clearance Certificate',
                'category' => 'statutory',
                'description' => 'National Social Security Authority compliance certificate.',
                'priority' => 'high',
                'estimated_days' => 3,
                'estimated_cost' => 0,
                'responsible_person' => $officer,
                'status' => 'pending',
                'due_date' => $this->suggestDueDate($tender, 5),
            ];
            $details[] = 'NSSA Clearance: Missing';
        } elseif ($nssa->isExpired()) {
            $statutoryScore -= 15;
            $details[] = 'NSSA Clearance: Expired';
        } else {
            $details[] = 'NSSA Clearance: Valid';
        }

        // 4. Certificate of Incorporation / CR14
        $incorp = $documents->firstWhere('type_key', 'cert_incorporation');
        $cr14 = $documents->firstWhere('type_key', 'cr14_directors');
        if (! $incorp || ! $cr14) {
            $statutoryScore -= 10;
            $details[] = 'Company Registry (CR14 / Incorp): Partially incomplete';
        } else {
            $details[] = 'Company Registry: Complete (CR14 & Certificate of Incorporation present)';
        }

        $finalScore = max(0, min(100, $statutoryScore));

        return [
            'score' => $finalScore,
            'status' => $finalScore >= 80 ? 'compliant' : ($finalScore >= 50 ? 'warning' : 'failed'),
            'details' => $details,
        ];
    }

    protected function evaluateEligibility($documents, array &$fatalFlaws, array &$actionPlan, string $officer): array
    {
        $score = 80;
        $details = [];

        $profile = $documents->firstWhere('type_key', 'company_profile');
        if ($profile) {
            $score += 10;
            $details[] = 'Company Profile: Attached';
        } else {
            $details[] = 'Company Profile: Not uploaded in Compliance Vault';
        }

        $bankConfirmation = $documents->firstWhere('type_key', 'bank_confirmation');
        if ($bankConfirmation) {
            $score += 10;
            $details[] = 'Bank Account Confirmation Letter: Available';
        } else {
            $details[] = 'Bank Account Confirmation: Missing';
        }

        $finalScore = min(100, $score);

        return [
            'score' => $finalScore,
            'status' => $finalScore >= 75 ? 'compliant' : 'warning',
            'details' => $details,
        ];
    }

    protected function evaluateTechnical($documents, $techSpecs, $requirements, array &$fatalFlaws, array &$actionPlan, string $officer): array
    {
        $score = 100;
        $details = [];

        // Check key staff CVs
        $staffCv = $documents->firstWhere('type_key', 'staff_cvs');
        if (! $staffCv) {
            $score -= 15;
            $actionPlan[] = [
                'id' => (string) Str::uuid(),
                'title' => 'Compile and Attach Key Personnel CVs & Certified Certificates',
                'category' => 'technical',
                'description' => 'Prepare curriculum vitae and certified educational/professional certificates for lead technical personnel.',
                'priority' => 'high',
                'estimated_days' => 2,
                'estimated_cost' => 0,
                'responsible_person' => $officer,
                'status' => 'pending',
                'due_date' => now()->addDays(4)->toDateString(),
            ];
            $details[] = 'Key Personnel CVs: Missing in document vault';
        } else {
            $details[] = 'Key Personnel CVs: Available';
        }

        // Check technical specifications compliance matrix
        if ($techSpecs->isNotEmpty()) {
            $totalSpecs = $techSpecs->count();
            $nonCompliantMandatory = $techSpecs->where('is_mandatory', true)->where('compliance_status', 'non_compliant');
            $deviatingMandatoryWithoutStatement = $techSpecs->where('is_mandatory', true)
                ->where('compliance_status', 'deviates')
                ->filter(fn ($s) => empty(trim($s->deviation_statement ?? '')));

            if ($nonCompliantMandatory->isNotEmpty()) {
                $count = $nonCompliantMandatory->count();
                $score -= 40;
                $fatalFlaws[] = 'Mandatory technical specification marked as non-compliant (' . $count . ' clause' . ($count > 1 ? 's' : '') . ').';
                $details[] = 'Technical Specs: ' . $count . ' mandatory clause(s) marked non-compliant';
            }

            if ($deviatingMandatoryWithoutStatement->isNotEmpty()) {
                $count = $deviatingMandatoryWithoutStatement->count();
                $score -= 20;
                $details[] = 'Technical Specs: ' . $count . ' mandatory deviation(s) lack formal justification';
            }

            $compliantCount = $techSpecs->whereIn('compliance_status', ['compliant', 'exceeds'])->count();
            $details[] = 'Technical Compliance: ' . $compliantCount . ' of ' . $totalSpecs . ' clauses compliant or exceeding';
        } else {
            $details[] = 'Technical Specification Matrix: Not yet extracted / reviewed';
        }

        $finalScore = max(0, min(100, $score));

        return [
            'score' => $finalScore,
            'status' => $finalScore >= 75 ? 'compliant' : ($finalScore >= 50 ? 'warning' : 'failed'),
            'details' => $details,
        ];
    }

    protected function evaluateFinancial($documents, Tender $tender, array &$fatalFlaws, array &$actionPlan, string $officer): array
    {
        $score = 70;
        $details = [];

        $audited = $documents->firstWhere('type_key', 'audited_financials');
        $mgmtAccounts = $documents->firstWhere('type_key', 'management_accounts');

        if ($audited && ! $audited->isExpired()) {
            $score += 25;
            $details[] = 'Audited Financial Statements: Present & valid';
        } elseif ($mgmtAccounts) {
            $score += 15;
            $details[] = 'Management Accounts: Available (Audited financials recommended)';
        } else {
            $score -= 20;
            $actionPlan[] = [
                'id' => (string) Str::uuid(),
                'title' => 'Upload Audited Financial Statements or Management Accounts',
                'category' => 'financial',
                'description' => 'Provide recent 2-3 years audited financial statements to satisfy financial turnover criteria.',
                'priority' => 'high',
                'estimated_days' => 3,
                'estimated_cost' => 0,
                'responsible_person' => $officer,
                'status' => 'pending',
                'due_date' => $this->suggestDueDate($tender, 6),
            ];
            $details[] = 'Financial Statements: Missing';
        }

        $credit = $documents->firstWhere('type_key', 'bank_reference_letter');
        if ($credit) {
            $score += 5;
            $details[] = 'Bank Reference / Credit Facilities: Available';
        }

        $finalScore = max(0, min(100, $score));

        return [
            'score' => $finalScore,
            'status' => $finalScore >= 75 ? 'compliant' : 'warning',
            'details' => $details,
        ];
    }

    protected function evaluateExperience($documents, array &$fatalFlaws, array &$actionPlan, string $officer): array
    {
        $score = 75;
        $details = [];

        $references = $documents->firstWhere('type_key', 'project_references');
        $completion = $documents->firstWhere('type_key', 'completion_certificates');

        if ($references && $completion) {
            $score += 25;
            $details[] = 'Past Experience & Completion Certificates: Complete';
        } elseif ($references || $completion) {
            $score += 10;
            $details[] = 'Past Experience: Partially documented (Add more completion certificates)';
        } else {
            $score -= 20;
            $actionPlan[] = [
                'id' => (string) Str::uuid(),
                'title' => 'Compile at least 3 Client Reference Letters and Completion Certificates',
                'category' => 'experience',
                'description' => 'Document previous successful assignments of similar scope, value, and complexity.',
                'priority' => 'medium',
                'estimated_days' => 4,
                'estimated_cost' => 0,
                'responsible_person' => $officer,
                'status' => 'pending',
                'due_date' => now()->addDays(5)->toDateString(),
            ];
            $details[] = 'Past Experience: Reference letters and completion certificates missing';
        }

        $finalScore = max(0, min(100, $score));

        return [
            'score' => $finalScore,
            'status' => $finalScore >= 70 ? 'compliant' : 'warning',
            'details' => $details,
        ];
    }

    protected function evaluateBidSecurityAndPreliminaries(Tender $tender, array &$fatalFlaws, array &$actionPlan, string $officer): array
    {
        $score = 100;
        $details = [];

        // Check Site Visit
        if ($tender->site_visit_required) {
            if ($tender->site_visit_at && Carbon::parse($tender->site_visit_at)->isPast()) {
                $score -= 30;
                $details[] = 'Site Visit: Mandatory site visit date (' . Carbon::parse($tender->site_visit_at)->format('d M Y') . ') has passed. Confirm attendance certificate.';
            } else {
                $details[] = 'Site Visit: Mandatory site visit scheduled for ' . ($tender->site_visit_at ? Carbon::parse($tender->site_visit_at)->format('d M Y') : 'TBD');
                $actionPlan[] = [
                    'id' => (string) Str::uuid(),
                    'title' => 'Attend Mandatory Pre-Bid Site Visit',
                    'category' => 'bid_security',
                    'description' => 'Ensure designated engineer attends site visit at ' . ($tender->site_visit_location ?: 'procuring entity site') . ' and obtains signed certificate.',
                    'priority' => 'critical',
                    'estimated_days' => 1,
                    'estimated_cost' => 50,
                    'responsible_person' => $officer,
                    'status' => 'pending',
                    'due_date' => $tender->site_visit_at ? Carbon::parse($tender->site_visit_at)->toDateString() : now()->addDays(3)->toDateString(),
                ];
            }
        }

        // Check Bid Security
        if ($tender->bid_security_required) {
            $amountText = $tender->bid_security_amount ? $tender->currency . ' ' . number_format($tender->bid_security_amount, 2) : 'Specified in RFP';
            $details[] = 'Bid Security: Required (' . $amountText . '). Secure bank guarantee / PRAZ bid bond.';
            $actionPlan[] = [
                'id' => (string) Str::uuid(),
                'title' => 'Establish Bid Security Bond / Bank Guarantee (' . $amountText . ')',
                'category' => 'bid_security',
                'description' => 'Engage commercial bank or insurance underwriter to issue required bid security bond matching procuring entity format.',
                'priority' => 'critical',
                'estimated_days' => 4,
                'estimated_cost' => $tender->bid_security_amount ? round($tender->bid_security_amount * 0.02) : 250,
                'responsible_person' => $officer,
                'status' => 'pending',
                'due_date' => $this->suggestDueDate($tender, 4),
            ];
        } else {
            $details[] = 'Bid Security: Not required by procuring entity';
        }

        $finalScore = max(0, min(100, $score));

        return [
            'score' => $finalScore,
            'status' => $finalScore >= 75 ? 'compliant' : 'warning',
            'details' => $details,
        ];
    }

    protected function suggestDueDate(Tender $tender, int $daysBefore): string
    {
        if ($tender->closing_at) {
            $closing = Carbon::parse($tender->closing_at);
            if ($closing->isFuture()) {
                $target = $closing->copy()->subDays($daysBefore);
                if ($target->isPast()) {
                    return now()->addDay()->toDateString();
                }
                return $target->toDateString();
            }
        }

        return now()->addDays(max(1, $daysBefore))->toDateString();
    }

    protected function getDefaultComplianceOfficer(int $tenantId): string
    {
        $employee = Employee::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        if ($employee) {
            return $employee->preferred_name ?: $employee->legal_name;
        }

        return 'Compliance Lead';
    }
}
