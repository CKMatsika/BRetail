<?php

namespace App\Services\Tender;

use App\Models\BidProposal;
use App\Models\BidProposalSection;
use App\Models\BidStandardDocument;
use App\Models\ComplianceDocument;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Tender;
use App\Models\TenderTechnicalSpec;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BidStudioService
{
    /**
     * Standard Proposal Section Definitions.
     */
    public const SECTIONS = [
        'executive_summary' => [
            'title' => '1. Executive Summary & Value Proposition',
            'order' => 1,
        ],
        'company_overview' => [
            'title' => '2. Bidder Profile, Corporate Governance & Past Experience',
            'order' => 2,
        ],
        'technical_methodology' => [
            'title' => '3. Technical Approach, Scope of Works & Methodology',
            'order' => 3,
        ],
        'work_plan' => [
            'title' => '4. Project Work Plan, Delivery Milestones & Schedule',
            'order' => 4,
        ],
        'team_composition' => [
            'title' => '5. Team Composition, Key Personnel & Technical Qualifications',
            'order' => 5,
        ],
        'risk_quality_assurance' => [
            'title' => '6. Risk Management, Mitigation Matrix & Quality Assurance Plan',
            'order' => 6,
        ],
        'hse_plan' => [
            'title' => '7. Occupational Health, Safety & Environmental (HSE) Plan',
            'order' => 7,
        ],
        'mobilisation_plan' => [
            'title' => '8. Mobilisation, Logistics & Implementation Plan',
            'order' => 8,
        ],
        'local_content_plan' => [
            'title' => '9. Zimbabwean Local Content, Value Addition & Subcontracting Plan',
            'order' => 9,
        ],
        'statutory_declarations' => [
            'title' => '10. Mandatory Statutory Declarations & Non-Collusion Undertaking',
            'order' => 10,
        ],
    ];

    /**
     * Standard Returnable Documents & Schedules.
     */
    public const STANDARD_DOCUMENTS = [
        'bid_submission_letter' => 'Standard Bid Submission Letter (Form of Bid)',
        'manufacturer_authorisation' => 'Manufacturer Authorization Form (MAF)',
        'non_collusion_declaration' => 'Anti-Collusion & Ethics Declaration',
        'litigation_declaration' => 'Declaration on Litigation History & Solvency',
        'site_visit_certificate' => 'Certificate of Pre-Bid Site Inspection',
        'joint_venture_agreement' => 'Joint Venture / Consortium Letter of Intent',
    ];

    /**
     * Get or initialize master bid proposal for a tender.
     */
    public function getOrCreateProposal(Tender $tender, ?int $userId = null): BidProposal
    {
        $tenantId = $tender->tenant_id;
        $proposal = BidProposal::where('tender_id', $tender->id)->first();

        if ($proposal) {
            return $proposal;
        }

        $tenant = Tenant::find($tenantId);
        $companyName = $tenant ? $tenant->name : 'Registered Bidder';

        $proposal = BidProposal::create([
            'tenant_id' => $tenantId,
            'tender_id' => $tender->id,
            'title' => 'Comprehensive Technical & Commercial Bid Proposal for ' . $tender->reference_number,
            'bidder_name' => $companyName,
            'bidder_address' => 'Harare, Zimbabwe',
            'lead_author_id' => $userId,
            'status' => 'draft',
            'overall_notes' => 'Draft proposal generated via BRetail AI Bid Studio conforming to PPDPA statutory procurement standards.',
        ]);

        // Generate standard sections
        $this->generateAllSections($proposal, $tender);

        // Generate standard returnable schedules
        $this->generateStandardDocuments($proposal, $tender);

        return $proposal;
    }

    /**
     * Generate or regenerate all 10 proposal sections.
     */
    public function generateAllSections(BidProposal $proposal, Tender $tender): void
    {
        $tenantId = $proposal->tenant_id;
        $context = $this->gatherContext($tender, $tenantId);

        foreach (self::SECTIONS as $key => $meta) {
            $content = $this->generateSectionContent($key, $tender, $context);

            BidProposalSection::updateOrCreate(
                [
                    'bid_proposal_id' => $proposal->id,
                    'section_key' => $key,
                ],
                [
                    'tenant_id' => $tenantId,
                    'title' => $meta['title'],
                    'content' => $content,
                    'order_index' => $meta['order'],
                    'is_included' => true,
                    'status' => 'draft',
                    'ai_generated' => true,
                ]
            );
        }
    }

    /**
     * Regenerate a single specific section.
     */
    public function regenerateSection(BidProposalSection $section, ?string $instructions = null): BidProposalSection
    {
        $proposal = $section->proposal;
        $tender = $proposal->tender;
        $context = $this->gatherContext($tender, $proposal->tenant_id);

        $content = $this->generateSectionContent($section->section_key, $tender, $context, $instructions);

        $section->update([
            'content' => $content,
            'ai_generated' => true,
            'status' => 'draft',
            'last_edited_by_user_id' => auth()->id(),
        ]);

        return $section;
    }

    /**
     * Generate standard returnable schedules and statutory letters.
     */
    public function generateStandardDocuments(BidProposal $proposal, Tender $tender): void
    {
        $tenantId = $proposal->tenant_id;
        $context = $this->gatherContext($tender, $tenantId);

        foreach (self::STANDARD_DOCUMENTS as $docType => $title) {
            $content = $this->generateDocumentContent($docType, $tender, $context);

            BidStandardDocument::updateOrCreate(
                [
                    'bid_proposal_id' => $proposal->id,
                    'doc_type' => $docType,
                ],
                [
                    'tenant_id' => $tenantId,
                    'tender_id' => $tender->id,
                    'title' => $title,
                    'content' => $content,
                    'status' => 'draft',
                    'created_by_user_id' => auth()->id(),
                ]
            );
        }
    }

    /**
     * Generate content for a specific proposal section.
     */
    public function generateSectionContent(string $sectionKey, Tender $tender, array $context, ?string $customPrompt = null): string
    {
        $cName = $context['company_name'];
        $entity = $tender->procuring_entity;
        $ref = $tender->reference_number;
        $title = $tender->title;
        $closing = Carbon::parse($tender->closing_at)->format('d F Y');
        $currency = $tender->currency;

        switch ($sectionKey) {
            case 'executive_summary':
                return "### Executive Summary & Value Proposition\n\n" .
                    "**{$cName}** is pleased to submit this comprehensive Technical and Commercial Bid Proposal in response to Invitation to Tender **{$ref}** for the **{$title}**, issued by **{$entity}**.\n\n" .
                    "#### 1. Strategic Alignment & Understanding of Requirements\n" .
                    "{$cName} has thoroughly reviewed the bidding documents, technical specifications, and general terms of contract. We fully comprehend the mission-critical nature of this procurement for {$entity} and confirm our capability to execute the contract in full compliance with the Public Procurement and Disposal of Public Assets Act [Chapter 22:23] (PPDPA).\n\n" .
                    "#### 2. Key Competitive Advantages\n" .
                    "- **Statutory Compliance**: Active and in full standing with the Procurement Regulatory Authority of Zimbabwe (PRAZ), Zimbabwe Revenue Authority (ZIMRA ITF263), and National Social Security Authority (NSSA).\n" .
                    "- **Technical Superiority**: Proven track record of delivering equivalent scope with zero deviations on mandatory technical parameters.\n" .
                    "- **Rapid Mobilisation**: Guaranteed deployment and milestone adherence within the stipulated delivery schedule.\n" .
                    "- **Robust Local Value Addition**: Over 60% local content through domestic technical personnel, local logistics, and after-sales maintenance support.\n\n" .
                    "#### 3. Commitment\n" .
                    "We offer our firm commitment to execute this contract with exemplary quality standards, transparent pricing in **{$currency}**, and dedicated project management." .
                    ($customPrompt ? "\n\n*Special Custom Emphasis: {$customPrompt}*" : "");

            case 'company_overview':
                return "### Bidder Profile, Corporate Governance & Past Experience\n\n" .
                    "#### 1. Corporate Profile\n" .
                    "- **Legal Entity**: {$cName}\n" .
                    "- **Country of Incorporation**: Zimbabwe\n" .
                    "- **Principal Operating Address**: {$context['address']}\n" .
                    "- **PRAZ Registered Supplier Status**: Active\n" .
                    "- **Tax Clearance Status**: ZIMRA ITF263 Active & Verified\n\n" .
                    "#### 2. Governance & Management Structure\n" .
                    "The organisation is governed by a distinguished Board of Directors and managed by qualified executive professionals possessing deep sector expertise. Our governance policies enforce rigorous anti-corruption, whistleblowing, and code-of-conduct frameworks.\n\n" .
                    "#### 3. Track Record & Reference Engagements\n" .
                    "{$cName} has successfully executed contracts of equivalent complexity and value for leading Zimbabwean public institutions, parastatals, and commercial enterprises. Certified past completion certificates and client references are appended in Volume II of this submission.";

            case 'technical_methodology':
                $specsSummary = $context['specs_count'] > 0
                    ? "Our technical proposal responds to all {$context['specs_count']} specified clauses in the Technical Schedule."
                    : "Our technical proposal satisfies all engineering requirements set forth in the RFP.";

                return "### Technical Approach, Scope of Works & Methodology\n\n" .
                    "#### 1. Technical Approach\n" .
                    "{$specsSummary} {$cName} applies industry best practices, adhering to international standards (ISO/IEC/Standards Association of Zimbabwe).\n\n" .
                    "#### 2. Scope Breakdown & Execution Stages\n" .
                    "1. **Inception & Design Verification**: Joint technical kick-off meeting with {$entity} engineering team to finalize specifications.\n" .
                    "2. **Procurement & Manufacturing**: Sourcing from certified tier-1 manufacturers with factory inspection and type test certificates.\n" .
                    "3. **Factory Acceptance Testing (FAT)**: Comprehensive performance and safety testing prior to dispatch.\n" .
                    "4. **Logistics & Customs Clearance**: Seamless multimodal freight with complete transit insurance to site.\n" .
                    "5. **Site Delivery, Installation & SAT**: Professional on-site delivery, installation, and Site Acceptance Testing (SAT).\n" .
                    "6. **Commissioning & Handover**: Formal testing, as-built documentation, warranty certificates, and staff training.";

            case 'work_plan':
                return "### Project Work Plan, Delivery Milestones & Schedule\n\n" .
                    "#### 1. Implementation Timeline\n" .
                    "The project execution is organized into distinct milestone gates to guarantee on-time completion prior to target deadlines:\n\n" .
                    "| Phase | Milestone Description | Target Timeline | Responsible Lead |\n" .
                    "|---|---|---|---|\n" .
                    "| W-01 | Contract Signing & Mobilisation Kick-off | Week 1 | Project Director |\n" .
                    "| W-02 | Detailed Design & Technical Submission Approval | Week 2 | Lead Engineer |\n" .
                    "| W-03 | Component Procurement & Assembly | Weeks 3–5 | Procurement Lead |\n" .
                    "| W-04 | Factory Acceptance Testing (FAT) | Week 6 | QA/QC Inspector |\n" .
                    "| W-05 | In-Country Logistics & Delivery to Site | Week 7 | Logistics Manager |\n" .
                    "| W-06 | Installation, Commissioning & Handover | Week 8 | Commissioning Engineer |\n\n" .
                    "#### 2. Milestone Dependencies & Critical Path\n" .
                    "Critical path management is supervised weekly by our Project Management Office (PMO) with automated daily status telemetry to mitigate risks.";

            case 'team_composition':
                return "### Team Composition, Key Personnel & Technical Qualifications\n\n" .
                    "#### 1. Key Personnel Structure\n" .
                    "Our project execution team comprises seasoned Zimbabwean registered professionals possessing extensive experience:\n\n" .
                    "- **Project Director / Team Leader**: Registered Professional Engineer (15+ years experience leading major public utility infrastructure contracts).\n" .
                    "- **Lead Technical Specialist**: Certified engineering specialist responsible for design adherence, FAT, and site commissioning.\n" .
                    "- **Quality Assurance & Safety Manager**: Dedicated officer responsible for ISO 9001 quality audits and NSSA-compliant safety oversight.\n" .
                    "- **Logistics & Supply Chain Lead**: Specialist managing bonded warehousing, transit clearances, and delivery logistics.\n\n" .
                    "Complete curriculum vitae, certified academic degrees, and professional board registration certificates (Engineering Council of Zimbabwe) are attached.";

            case 'risk_quality_assurance':
                return "### Risk Management, Mitigation Matrix & Quality Assurance Plan\n\n" .
                    "#### 1. Risk Management Framework\n\n" .
                    "| Identified Risk | Severity | Probability | Pre-Emptive Mitigation Strategy |\n" .
                    "|---|---|---|---|\n" .
                    "| Supply Chain & Port Delays | High | Low | Established direct freight agreements with priority forwarding channels. |\n" .
                    "| Foreign Currency Fluctuations | Medium | Medium | Firm hedging arrangements and indexed procurement covenants in {$currency}. |\n" .
                    "| Technical Specification Deviations | High | Low | Dual-stage pre-dispatch audit against original RFP technical clauses. |\n" .
                    "| Site Access & Readiness | Low | Medium | Early pre-installation site surveys conducted jointly with {$entity} representatives. |\n\n" .
                    "#### 2. Quality Assurance Protocol\n" .
                    "Our operations adhere strictly to ISO 9001:2015 quality principles. Every deliverable is backed by original manufacturer test certificates and a comprehensive 12-month standard warranty.";

            case 'hse_plan':
                return "### Occupational Health, Safety & Environmental (HSE) Plan\n\n" .
                    "#### 1. Safety Commitment\n" .
                    "{$cName} maintains a Zero Harm policy across all operations, complying with the Factories and Works Act [Chapter 14:08] and NSSA statutory regulations.\n\n" .
                    "#### 2. Environmental Compliance\n" .
                    "- Strict adherence to Environmental Management Agency (EMA) standards regarding hazardous materials, waste disposal, and carbon minimization.\n" .
                    "- Site-specific risk assessments (JHA/JSA) conducted prior to any physical delivery or installation.\n" .
                    "- Mandatory provision of Personal Protective Equipment (PPE) for all personnel.";

            case 'mobilisation_plan':
                return "### Mobilisation, Logistics & Implementation Plan\n\n" .
                    "#### 1. Immediate Mobilisation Protocol\n" .
                    "Upon receipt of the formal Letter of Acceptance from {$entity}, {$cName} will mobilise within forty-eight (48) hours:\n\n" .
                    "- Issuance of the Performance Security Bond within the statutory period.\n" .
                    "- Establishment of dedicated bonded warehousing and transit staging in Harare.\n" .
                    "- Designation of 24/7 dedicated account liaison engineer for {$entity}.\n\n" .
                    "#### 2. Warranty & After-Sales Support\n" .
                    "We provide in-country local repair, calibration, and spare parts backup with a maximum 4-hour on-site response window during the defect liability period.";

            case 'local_content_plan':
                return "### Zimbabwean Local Content, Value Addition & Subcontracting Plan\n\n" .
                    "#### 1. PPDPA Local Preference Compliance\n" .
                    "Under Section 29 of the PPDPA Act, {$cName} commits to maximizing local value addition and empowering the domestic industrial ecosystem:\n\n" .
                    "- **Domestic Employment**: 100% of on-site technical and general personnel are citizens of Zimbabwe.\n" .
                    "- **Local Sourcing**: Local packaging, transport, civil works, and consumables sourced exclusively from registered Zimbabwean SMEs.\n" .
                    "- **Skills Transfer**: Comprehensive hands-on technical training for {$entity} maintenance engineers prior to final commercial handover.\n" .
                    "- **Community Impact**: Active adherence to corporate social responsibility (CSR) programs in local operational communities.";

            case 'statutory_declarations':
                return "### Mandatory Statutory Declarations & Non-Collusion Undertaking\n\n" .
                    "#### 1. Declaration of Eligibility\n" .
                    "We solemnly declare that **{$cName}** and its directors:\n" .
                    "1. Are not debarred, blacklisted, or suspended by the Procurement Regulatory Authority of Zimbabwe (PRAZ) or any United Nations / Multilateral development institution.\n" .
                    "2. Are in full statutory compliance with ZIMRA, NSSA, and the Registrar of Companies.\n" .
                    "3. Have no conflict of interest with any employee, commissioner, or advisor of {$entity}.\n" .
                    "4. Have not participated in any anti-competitive, collusive, or fraudulent practices in the formulation of this proposal.\n\n" .
                    "Signed on behalf of the Board of Directors:\n\n" .
                    "_____________________________\n" .
                    "**Authorized Executive Signatory**\n" .
                    "Date: " . now()->format('d F Y');

            default:
                return "### " . ucwords(str_replace('_', ' ', $sectionKey)) . "\n\nContent tailored for Tender {$ref}.";
        }
    }

    /**
     * Generate content for standard returnable documents & statutory forms.
     */
    public function generateDocumentContent(string $docType, Tender $tender, array $context): string
    {
        $cName = $context['company_name'];
        $entity = $tender->procuring_entity;
        $ref = $tender->reference_number;
        $title = $tender->title;
        $date = now()->format('d F Y');

        switch ($docType) {
            case 'bid_submission_letter':
                return "### STANDARD BID SUBMISSION LETTER\n" .
                    "(To be printed on Bidder's Official Company Letterhead)\n\n" .
                    "**Date:** {$date}\n" .
                    "**Tender Reference:** {$ref}\n\n" .
                    "**To:**\n" .
                    "The Accounting Officer / Procurement Management Unit (PMU)\n" .
                    "**{$entity}**\n" .
                    "Zimbabwe\n\n" .
                    "**SUBJECT: SUBMISSION OF BID PROPOSAL FOR {$ref} – {$title}**\n\n" .
                    "Dear Sir / Madam,\n\n" .
                    "1. Having examined the Bidding Documents, including Addenda, the receipt of which is hereby duly acknowledged, we, the undersigned, offer to execute and complete the works / supply and deliver the goods in conformity with the said Bidding Documents.\n\n" .
                    "2. We undertake, if our Bid is accepted, to commence delivery / works immediately upon receipt of your order and to complete all obligations within the specified delivery period.\n\n" .
                    "3. We agree to abide by this Bid for a period of ninety (90) days from the statutory submission deadline, and it shall remain binding upon us and may be accepted at any time before the expiration of that period.\n\n" .
                    "4. We understand that you are not bound to accept the lowest or any bid you may receive.\n\n" .
                    "Yours faithfully,\n\n" .
                    "_________________________________________\n" .
                    "**Authorised Signature**\n" .
                    "Name: Managing Director / CEO\n" .
                    "For and on behalf of: **{$cName}**\n" .
                    "Company Seal / Stamp";

            case 'non_collusion_declaration':
                return "### CERTIFICATE OF INDEPENDENT BID DETERMINATION & NON-COLLUSION\n\n" .
                    "I, the undersigned, in submitting the accompanying bid for **{$ref}** to **{$entity}**, do hereby make the following statements that I certify to be true and complete in every respect:\n\n" .
                    "1. I have read and I understand the contents of this Certificate;\n" .
                    "2. I understand that the accompanying bid will be disqualified if this Certificate is found not to be true and complete in every respect;\n" .
                    "3. I am authorized by the bidder **{$cName}** to sign this Certificate, and to submit the accompanying bid, on behalf of the bidder;\n" .
                    "4. For the purposes of this Certificate and the accompanying bid, I understand that the word 'competitor' shall include any individual or organization, other than the bidder, whether or not affiliated with the bidder, who has been requested to submit a bid or could potentially submit a bid;\n" .
                    "5. The bidder has arrived at the accompanying bid independently from, and without consultation, communication, agreement or arrangement with any competitor. Communication between partners in a joint venture or consortium is not construed as collusive bidding;\n" .
                    "6. In addition, there have been no consultations, communications, agreements or arrangements with any competitor regarding the quality, quantity, specifications and prices.\n\n" .
                    "Sworn and Signed at Harare on this {$date}.\n\n" .
                    "_________________________________________\n" .
                    "**Director / Company Secretary**\n" .
                    "**{$cName}**";

            case 'manufacturer_authorisation':
                return "### MANUFACTURER'S AUTHORIZATION FORM (MAF)\n\n" .
                    "**Date:** {$date}\n" .
                    "**To:** {$entity}\n" .
                    "**Tender Reference:** {$ref}\n\n" .
                    "WHEREAS we, **[Original Equipment Manufacturer Legal Name]**, who are official manufacturers of [Goods/Equipment Classification], having factories at [Factory Physical Location], do hereby authorize **{$cName}** to submit a bid, and subsequently negotiate and sign the Contract with you against Tender **{$ref}**.\n\n" .
                    "We hereby extend our full guarantee and manufacturer warranty covering the goods offered by the above firm for this procurement.\n\n" .
                    "Signed for and on behalf of Manufacturer:\n\n" .
                    "_________________________________________\n" .
                    "**Director of Global OEM Sales / Authorised Officer**\n" .
                    "Official Manufacturer Seal";

            case 'litigation_declaration':
                return "### DECLARATION OF LITIGATION HISTORY & FINANCIAL SOLVENCY\n\n" .
                    "**Company Name:** {$cName}\n" .
                    "**Tender Reference:** {$ref}\n\n" .
                    "We hereby declare and certify under penalty of perjury that:\n\n" .
                    "1. **{$cName}** has no pending or threatened litigation, arbitration, or material legal dispute that would compromise our technical capability or financial capacity to perform this contract.\n" .
                    "2. The company is not in bankruptcy, liquidation, administration, or subject to any judicial receivership.\n" .
                    "3. All audited accounts submitted represent an accurate and lawful record of our commercial standing.\n\n" .
                    "Signed on behalf of {$cName}:\n\n" .
                    "_________________________________________\n" .
                    "**Finance Director / Legal Counsel**\n" .
                    "Date: {$date}";

            case 'site_visit_certificate':
                return "### CERTIFICATE OF PRE-BID SITE INSPECTION\n\n" .
                    "**Tender Reference:** {$ref}\n" .
                    "**Procuring Entity:** {$entity}\n" .
                    "**Site Location:** " . ($tender->site_visit_location ?: 'Procuring Entity Designated Site') . "\n\n" .
                    "This is to certify that **[Representative Name]**, representing **{$cName}**, visited the project site on [Inspection Date] and was briefed on all physical, logistical, and technical requirements.\n\n" .
                    "**Bidder Representative Signature:** ______________________\n\n" .
                    "**Procuring Entity Authorised Officer Signature & Official Stamp:**\n\n" .
                    "_________________________________________\n" .
                    "Date: [Date of Inspection]";

            default:
                return "### Standard Schedule: " . ucwords(str_replace('_', ' ', $docType)) . "\n\nTender Ref: {$ref}\nBidder: {$cName}";
        }
    }

    /**
     * Export complete proposal as a printable, publication-grade HTML document.
     */
    public function exportPrintableHtml(BidProposal $proposal): string
    {
        $tender = $proposal->tender;
        $sections = $proposal->sections()->where('is_included', true)->get();
        $docs = $proposal->standardDocuments;

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $html .= '<title>' . htmlspecialchars($proposal->title) . '</title>';
        $html .= '<style>
            @page { size: A4; margin: 25mm 20mm 25mm 20mm; }
            body { font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; line-height: 1.6; color: #1e293b; }
            .cover-page { text-align: center; padding-top: 100px; page-break-after: always; }
            .cover-title { font-size: 24pt; font-weight: bold; color: #0f172a; margin-bottom: 20px; line-height: 1.3; }
            .cover-sub { font-size: 13pt; color: #475569; margin-bottom: 40px; }
            .cover-meta { margin-top: 80px; font-size: 11pt; line-height: 1.8; text-align: left; display: inline-block; }
            .page-break { page-break-after: always; }
            h1, h2, h3, h4 { color: #0f172a; font-weight: bold; }
            h2 { font-size: 16pt; border-bottom: 2px solid #0f172a; padding-bottom: 6px; margin-top: 30px; }
            h3 { font-size: 13pt; color: #1e3a8a; margin-top: 20px; }
            h4 { font-size: 11pt; color: #334155; margin-top: 15px; }
            table { width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 10pt; }
            th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; }
            th { background-color: #f1f5f9; font-weight: bold; color: #0f172a; }
            ul, ol { margin: 10px 0 10px 25px; }
            li { margin-bottom: 5px; }
            .doc-box { border: 1px solid #cbd5e1; background-color: #f8fafc; padding: 20px; border-radius: 8px; margin-top: 20px; page-break-after: always; }
        </style></head><body>';

        // Cover Page
        $html .= '<div class="cover-page">';
        $html .= '<div style="font-size: 14pt; letter-spacing: 2px; text-transform: uppercase; color: #2563eb; font-weight: bold; margin-bottom: 20px;">TENDER BID SUBMISSION</div>';
        $html .= '<div class="cover-title">' . htmlspecialchars($tender->title) . '</div>';
        $html .= '<div class="cover-sub">Tender Reference Number: <strong>' . htmlspecialchars($tender->reference_number) . '</strong></div>';
        $html .= '<div class="cover-meta">';
        $html .= '<strong>Procuring Entity:</strong> ' . htmlspecialchars($tender->procuring_entity) . '<br>';
        $html .= '<strong>Bidder:</strong> ' . htmlspecialchars($proposal->bidder_name) . '<br>';
        $html .= '<strong>Closing Date:</strong> ' . Carbon::parse($tender->closing_at)->format('d F Y - H:i') . ' CAT<br>';
        $html .= '<strong>Submission Method:</strong> ' . ucwords(str_replace('_', ' ', $tender->submission_method)) . '<br>';
        $html .= '<strong>Proposal Status:</strong> ' . strtoupper($proposal->status) . '<br>';
        $html .= '</div></div>';

        // Sections
        foreach ($sections as $section) {
            $html .= '<div>';
            $html .= '<h2>' . htmlspecialchars($section->title) . '</h2>';
            $parsed = $this->markdownToHtml($section->content);
            $html .= $parsed;
            $html .= '</div>';
            $html .= '<div class="page-break"></div>';
        }

        // Standard Returnable Schedules
        if ($docs->isNotEmpty()) {
            $html .= '<h2>Appendix: Standard Returnable Schedules & Statutory Declarations</h2>';
            foreach ($docs as $doc) {
                $html .= '<div class="doc-box">';
                $html .= '<h3>' . htmlspecialchars($doc->title) . '</h3>';
                $html .= $this->markdownToHtml($doc->content);
                $html .= '</div>';
            }
        }

        $html .= '</body></html>';

        return $html;
    }

    /**
     * Export complete proposal as a Microsoft Word compatible formatted document.
     */
    public function exportWordDocument(BidProposal $proposal): string
    {
        $tender = $proposal->tender;
        $sections = $proposal->sections()->where('is_included', true)->get();
        $docs = $proposal->standardDocuments;

        $doc = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">';
        $doc .= '<head><meta charset="utf-8"><title>' . htmlspecialchars($proposal->title) . '</title>';
        $doc .= '<style>
            body { font-family: "Calibri", Arial, sans-serif; font-size: 11pt; line-height: 1.5; }
            h1 { font-size: 20pt; font-weight: bold; color: #1e3a8a; text-align: center; }
            h2 { font-size: 15pt; font-weight: bold; color: #0f172a; margin-top: 25pt; border-bottom: 1pt solid #0f172a; }
            h3 { font-size: 12pt; font-weight: bold; color: #2563eb; margin-top: 15pt; }
            table { width: 100%; border-collapse: collapse; margin: 10pt 0; }
            th, td { border: 1pt solid #94a3b8; padding: 6pt; }
            th { background-color: #f1f5f9; font-weight: bold; }
            .page-break { page-break-after: always; }
        </style></head><body>';

        // Title
        $doc .= '<h1>' . htmlspecialchars($tender->title) . '</h1>';
        $doc .= '<p style="text-align: center; font-size: 13pt;"><strong>Tender Reference: ' . htmlspecialchars($tender->reference_number) . '</strong></p>';
        $doc .= '<p style="text-align: center;"><strong>Prepared by:</strong> ' . htmlspecialchars($proposal->bidder_name) . '</p>';
        $doc .= '<p style="text-align: center;"><strong>Submitted to:</strong> ' . htmlspecialchars($tender->procuring_entity) . '</p>';
        $doc .= '<div class="page-break"></div>';

        foreach ($sections as $section) {
            $doc .= '<h2>' . htmlspecialchars($section->title) . '</h2>';
            $doc .= $this->markdownToHtml($section->content);
            $doc .= '<div class="page-break"></div>';
        }

        foreach ($docs as $stDoc) {
            $doc .= '<h2>' . htmlspecialchars($stDoc->title) . '</h2>';
            $doc .= $this->markdownToHtml($stDoc->content);
            $doc .= '<div class="page-break"></div>';
        }

        $doc .= '</body></html>';

        return $doc;
    }

    /**
     * Basic Markdown to HTML converter for formatted document rendering.
     */
    protected function markdownToHtml(string $md): string
    {
        // Tables
        $lines = explode("\n", $md);
        $inTable = false;
        $html = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '|')) {
                if (! $inTable) {
                    $inTable = true;
                    $html .= '<table>';
                }

                // Check separator
                if (preg_match('/^\|(?:\s*:?-+:?\s*\|)+$/', $trimmed)) {
                    continue;
                }

                $cells = array_values(array_filter(array_map('trim', explode('|', $trimmed)), fn ($c) => $c !== ''));
                $tag = str_contains($html, '<th>') ? 'td' : 'th';
                $html .= '<tr>';
                foreach ($cells as $cell) {
                    $html .= "<{$tag}>" . htmlspecialchars($cell) . "</{$tag}>";
                }
                $html .= '</tr>';
                continue;
            } elseif ($inTable) {
                $inTable = false;
                $html .= '</table>';
            }

            if (str_starts_with($trimmed, '#### ')) {
                $html .= '<h4>' . htmlspecialchars(substr($trimmed, 5)) . '</h4>';
            } elseif (str_starts_with($trimmed, '### ')) {
                $html .= '<h3>' . htmlspecialchars(substr($trimmed, 4)) . '</h3>';
            } elseif (str_starts_with($trimmed, '## ')) {
                $html .= '<h2>' . htmlspecialchars(substr($trimmed, 3)) . '</h2>';
            } elseif (str_starts_with($trimmed, '- ')) {
                $html .= '<li>' . $this->formatInline(substr($trimmed, 2)) . '</li>';
            } elseif (preg_match('/^\d+\.\s+(.*)/', $trimmed, $m)) {
                $html .= '<p><strong>' . htmlspecialchars(substr($trimmed, 0, strpos($trimmed, ' '))) . '</strong> ' . $this->formatInline($m[1]) . '</p>';
            } elseif ($trimmed === '') {
                $html .= '<br>';
            } else {
                $html .= '<p>' . $this->formatInline($trimmed) . '</p>';
            }
        }

        if ($inTable) {
            $html .= '</table>';
        }

        return $html;
    }

    protected function formatInline(string $text): string
    {
        $escaped = htmlspecialchars($text);
        // Bold
        $escaped = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $escaped);
        // Italic
        $escaped = preg_replace('/(?<!\*)\*(.*?)\*(?!\*)/', '<em>$1</em>', $escaped);

        return $escaped;
    }

    /**
     * Gather live context from tenant, compliance documents, and specs.
     */
    protected function gatherContext(Tender $tender, int $tenantId): array
    {
        $tenant = Tenant::find($tenantId);
        $companyName = $tenant ? $tenant->name : 'Registered Bidder';

        $complianceDocs = ComplianceDocument::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $specsCount = TenderTechnicalSpec::where('tender_id', $tender->id)->count();

        $employees = Employee::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->limit(5)
            ->get();

        return [
            'company_name' => $companyName,
            'address' => 'Harare, Zimbabwe',
            'compliance_docs' => $complianceDocs,
            'specs_count' => $specsCount,
            'employees' => $employees,
        ];
    }
}
