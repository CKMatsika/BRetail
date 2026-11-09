<?php

namespace App\Services\Compliance;

final class ComplianceCatalog
{
    /**
     * Categories of compliance documents
     */
    public const CATEGORIES = [
        'statutory' => [
            'key' => 'statutory',
            'label' => 'Statutory Compliance',
            'description' => 'Mandatory legal clearances from ZIMRA, PRAZ, NSSA, NEC, and regulatory authorities.',
            'icon' => 'ShieldCheck',
        ],
        'company_registration' => [
            'key' => 'company_registration',
            'label' => 'Company Registration',
            'description' => 'Foundational corporate documents from the Registrar of Companies (CR14, CR6, Incorporation).',
            'icon' => 'Building2',
        ],
        'financial_professional' => [
            'key' => 'financial_professional',
            'label' => 'Financial & Professional',
            'description' => 'Audited accounts, bank reference letters, professional accreditations, and client references.',
            'icon' => 'FileSpreadsheet',
        ],
        'technical_personnel' => [
            'key' => 'technical_personnel',
            'label' => 'Technical & Personnel',
            'description' => 'Asset registers, key personnel qualifications, CVs, and health & safety manuals.',
            'icon' => 'Wrench',
        ],
    ];

    /**
     * Standard pre-defined Zimbabwean tender & compliance templates
     */
    public static function standards(): array
    {
        return [
            // Statutory
            [
                'type_key' => 'praz_certificate',
                'category' => 'statutory',
                'title' => 'PRAZ Supplier Registration Certificate',
                'issuing_authority' => 'Procurement Regulatory Authority of Zimbabwe (PRAZ)',
                'is_mandatory' => true,
                'has_expiry' => true,
                'supports_praz_categories' => true,
                'description' => 'Annual registration certificate to participate in public procurement under PPDPA Act.',
            ],
            [
                'type_key' => 'zimra_itf263',
                'category' => 'statutory',
                'title' => 'ZIMRA ITF263 Tax Clearance Certificate',
                'issuing_authority' => 'Zimbabwe Revenue Authority (ZIMRA)',
                'is_mandatory' => true,
                'has_expiry' => true,
                'supports_praz_categories' => false,
                'description' => 'Valid tax clearance certificate exempting the business from 30% withholding tax.',
            ],
            [
                'type_key' => 'nssa_clearance',
                'category' => 'statutory',
                'title' => 'NSSA Compliance Clearance Certificate',
                'issuing_authority' => 'National Social Security Authority (NSSA)',
                'is_mandatory' => true,
                'has_expiry' => true,
                'supports_praz_categories' => false,
                'description' => 'Proof of compliance with National Social Security contributions for employees.',
            ],
            [
                'type_key' => 'zimra_vat',
                'category' => 'statutory',
                'title' => 'ZIMRA VAT Registration Certificate',
                'issuing_authority' => 'Zimbabwe Revenue Authority (ZIMRA)',
                'is_mandatory' => false, // Conditional on turnover
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Certificate showing value-added tax registration (or N/A if below threshold).',
            ],
            [
                'type_key' => 'nec_clearance',
                'category' => 'statutory',
                'title' => 'NEC Clearance Certificate',
                'issuing_authority' => 'National Employment Council (NEC)',
                'is_mandatory' => false, // Conditional on industry
                'has_expiry' => true,
                'supports_praz_categories' => false,
                'description' => 'Certificate of good standing with your sector National Employment Council.',
            ],
            [
                'type_key' => 'ema_licence',
                'category' => 'statutory',
                'title' => 'EMA Environmental Licence / EIA Clearance',
                'issuing_authority' => 'Environmental Management Agency (EMA)',
                'is_mandatory' => false, // Conditional on sector
                'has_expiry' => true,
                'supports_praz_categories' => false,
                'description' => 'Environmental impact or operational licence where project activities warrant.',
            ],
            [
                'type_key' => 'insurance_certificate',
                'category' => 'statutory',
                'title' => 'Public Liability / Insurance Certificate',
                'issuing_authority' => 'Registered Insurance Provider',
                'is_mandatory' => false,
                'has_expiry' => true,
                'supports_praz_categories' => false,
                'description' => 'Insurance cover policy certificate (e.g. Public Liability, Contractors All Risks).',
            ],

            // Company Registration
            [
                'type_key' => 'cert_incorporation',
                'category' => 'company_registration',
                'title' => 'Certificate of Incorporation',
                'issuing_authority' => 'Registrar of Companies (DCIP)',
                'is_mandatory' => true,
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Primary certificate of legal business incorporation in Zimbabwe.',
            ],
            [
                'type_key' => 'cr14_directors',
                'category' => 'company_registration',
                'title' => 'CR14 / CR6 List of Directors & Secretaries',
                'issuing_authority' => 'Registrar of Companies (DCIP)',
                'is_mandatory' => true,
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Official registry return specifying active company directors and company secretary.',
            ],
            [
                'type_key' => 'cr6_address',
                'category' => 'company_registration',
                'title' => 'CR6 Notice of Situation of Registered Office',
                'issuing_authority' => 'Registrar of Companies (DCIP)',
                'is_mandatory' => true,
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Official registry notification of the company physical registered address.',
            ],
            [
                'type_key' => 'memo_articles',
                'category' => 'company_registration',
                'title' => 'Memorandum and Articles of Association',
                'issuing_authority' => 'Registrar of Companies (DCIP)',
                'is_mandatory' => true,
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Constitution of the company defining corporate objects and powers.',
            ],
            [
                'type_key' => 'company_profile',
                'category' => 'company_registration',
                'title' => 'Corporate Company Profile',
                'issuing_authority' => 'Internal / Company Management',
                'is_mandatory' => true,
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Comprehensive capability statement, executive summary, and track record.',
            ],
            [
                'type_key' => 'bank_confirmation',
                'category' => 'company_registration',
                'title' => 'Bank Account Confirmation Letter',
                'issuing_authority' => 'Commercial Bank',
                'is_mandatory' => true,
                'has_expiry' => true,
                'supports_praz_categories' => false,
                'description' => 'Original bank letter on bank letterhead confirming active business account details.',
            ],
            [
                'type_key' => 'board_resolution',
                'category' => 'company_registration',
                'title' => 'Board Resolution / Power of Attorney',
                'issuing_authority' => 'Company Board of Directors',
                'is_mandatory' => false,
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Formal resolution granting signatory authority for tender submissions.',
            ],
            [
                'type_key' => 'proof_of_address',
                'category' => 'company_registration',
                'title' => 'Proof of Business Address',
                'issuing_authority' => 'Local Authority / Utility / Landlord',
                'is_mandatory' => true,
                'has_expiry' => true,
                'supports_praz_categories' => false,
                'description' => 'Recent rates bill or lease agreement establishing verifiable physical presence.',
            ],

            // Financial & Professional
            [
                'type_key' => 'audited_financials',
                'category' => 'financial_professional',
                'title' => 'Audited Financial Statements (Last 2-3 Years)',
                'issuing_authority' => 'Registered Public Auditors (PAAB accredited)',
                'is_mandatory' => true,
                'has_expiry' => true,
                'supports_praz_categories' => false,
                'description' => 'Independent audit reports, balance sheets, income statements, and cash flows.',
            ],
            [
                'type_key' => 'management_accounts',
                'category' => 'financial_professional',
                'title' => 'Current Year Management Accounts',
                'issuing_authority' => 'Company Finance Department',
                'is_mandatory' => false,
                'has_expiry' => true,
                'supports_praz_categories' => false,
                'description' => 'Year-to-date trial balance and profit and loss signed by management.',
            ],
            [
                'type_key' => 'bank_reference_letter',
                'category' => 'financial_professional',
                'title' => 'Bank Reference & Credit Standing Letter',
                'issuing_authority' => 'Commercial Bank',
                'is_mandatory' => false,
                'has_expiry' => true,
                'supports_praz_categories' => false,
                'description' => 'Bank rating or line of credit comfort letter verifying financial capacity.',
            ],
            [
                'type_key' => 'project_references',
                'category' => 'financial_professional',
                'title' => 'Trade References & Client Letters',
                'issuing_authority' => 'Previous Clients / Procuring Entities',
                'is_mandatory' => true,
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Formal reference letters from previous corporate and government buyers.',
            ],
            [
                'type_key' => 'completion_certificates',
                'category' => 'financial_professional',
                'title' => 'Certificates of Completion / Delivery Notes',
                'issuing_authority' => 'Clients / Supervising Engineers',
                'is_mandatory' => false,
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Signed completion certificates proving on-time, compliant project delivery.',
            ],

            // Technical & Personnel
            [
                'type_key' => 'staff_cvs',
                'category' => 'technical_personnel',
                'title' => 'Key Personnel CVs & Qualification Certificates',
                'issuing_authority' => 'Key Technical Staff / Certified Universities',
                'is_mandatory' => true,
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Profiles, degrees, professional licences, and CVs of core project team members.',
            ],
            [
                'type_key' => 'equipment_register',
                'category' => 'technical_personnel',
                'title' => 'Equipment and Plant Asset Register',
                'issuing_authority' => 'Company Operations / Asset Registry',
                'is_mandatory' => false,
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Inventory of owned, leased, or contracted heavy plant, vehicles, and tools.',
            ],
            [
                'type_key' => 'quality_policy',
                'category' => 'technical_personnel',
                'title' => 'Quality Assurance & Safety Policy Statement',
                'issuing_authority' => 'Company Management (ISO Aligned)',
                'is_mandatory' => false,
                'has_expiry' => false,
                'supports_praz_categories' => false,
                'description' => 'Corporate policy on quality assurance, safety (SHE), and environmental protection.',
            ],
        ];
    }
}
