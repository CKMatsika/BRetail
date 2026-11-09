<?php

namespace App\Services\Tender;

final class TenderCatalog
{
    public const CATEGORIES = [
        ['key' => 'goods', 'label' => 'Goods & Consumables'],
        ['key' => 'works', 'label' => 'Construction & Works'],
        ['key' => 'consultancy', 'label' => 'Consultancy Services'],
        ['key' => 'non_consultancy', 'label' => 'Non-Consultancy Services'],
        ['key' => 'ict', 'label' => 'ICT & Telecommunications'],
        ['key' => 'energy', 'label' => 'Energy & Renewable Energy'],
        ['key' => 'mining', 'label' => 'Mining & Industrial Equipment'],
        ['key' => 'agriculture', 'label' => 'Agriculture & Agro-Processing'],
        ['key' => 'medical', 'label' => 'Medical & Pharmaceutical'],
        ['key' => 'engineering', 'label' => 'Engineering & Fabrication'],
        ['key' => 'transport', 'label' => 'Transport & Logistics'],
        ['key' => 'security', 'label' => 'Security & Guarding'],
        ['key' => 'facilities', 'label' => 'Facilities Management & Maintenance'],
        ['key' => 'environmental', 'label' => 'Environmental Services & Waste'],
        ['key' => 'infrastructure', 'label' => 'Infrastructure Projects'],
        ['key' => 'other', 'label' => 'Other Specialised Procurement'],
    ];

    public const METHODS = [
        ['key' => 'open_competitive_bidding', 'label' => 'Open Competitive Bidding (Domestic)'],
        ['key' => 'international_competitive_bidding', 'label' => 'International Competitive Bidding (ICB)'],
        ['key' => 'restricted_bidding', 'label' => 'Restricted Bidding (Shortlist)'],
        ['key' => 'rfq', 'label' => 'Request for Quotations (RFQ)'],
        ['key' => 'rfp', 'label' => 'Request for Proposals (RFP)'],
        ['key' => 'direct_procurement', 'label' => 'Direct Procurement (Sole Source)'],
        ['key' => 'two_stage', 'label' => 'Two-Stage Bidding'],
        ['key' => 'framework', 'label' => 'Framework Agreement / Call-off'],
    ];

    public const OPPORTUNITY_TYPES = [
        ['key' => 'tender', 'label' => 'Tender / Invitation to Bid (ITB)'],
        ['key' => 'eoi', 'label' => 'Expression of Interest (EOI)'],
        ['key' => 'prequalification', 'label' => 'Prequalification of Suppliers'],
        ['key' => 'supplier_registration', 'label' => 'Annual Supplier Database Registration'],
        ['key' => 'subcontracting', 'label' => 'Subcontracting / Consortium Joint Venture'],
        ['key' => 'framework_call_off', 'label' => 'Framework Contract Call-off'],
    ];

    public const ENTITY_TYPES = [
        ['key' => 'government', 'label' => 'Government Ministry / Department'],
        ['key' => 'parastatal', 'label' => 'Parastatal / State-Owned Enterprise'],
        ['key' => 'local_authority', 'label' => 'Local Authority (City/Town/Rural Council)'],
        ['key' => 'ngo', 'label' => 'NGO & Donor Funded Agency'],
        ['key' => 'private', 'label' => 'Private Corporate Sector'],
        ['key' => 'international', 'label' => 'International Development Institution'],
    ];

    public const PRAZ_CATEGORY_CODES = [
        ['code' => 'GS001', 'name' => 'General Stationery, Paper & Consumables'],
        ['code' => 'GS002', 'name' => 'Cleaning Materials & Janitorial Supplies'],
        ['code' => 'SE001', 'name' => 'Electrical Hardware, Cables & Distribution Equipment'],
        ['code' => 'SE002', 'name' => 'Solar Systems, Inverters, Batteries & Renewable Energy'],
        ['code' => 'SC001', 'name' => 'Building Construction, Painting & Civil Works'],
        ['code' => 'SC002', 'name' => 'Road Rehabilitation, Tar Surfacing & Civil Drainage'],
        ['code' => 'ICT001', 'name' => 'Computer Hardware, Laptops, Servers & Peripherals'],
        ['code' => 'ICT002', 'name' => 'Software Licences, ERP, Custom Apps & Cloud Hosting'],
        ['code' => 'SV001', 'name' => 'Physical Guarding, Cash-in-Transit & Security Alarm Services'],
        ['code' => 'SV002', 'name' => 'CCTV Surveillance, Biometric Access & Electric Fencing'],
        ['code' => 'CT001', 'name' => 'Corporate Catering Services, Foodstuffs & Provisions'],
        ['code' => 'ME001', 'name' => 'Medical Consumables, Hospital Sundries & Pharmaceuticals'],
        ['code' => 'TR001', 'name' => 'Motor Vehicles, Spare Parts, Heavy Tyres & Fleet Maintenance'],
        ['code' => 'AG001', 'name' => 'Agricultural Inputs, Certified Seeds, Fertilizer & Irrigation'],
        ['code' => 'MN001', 'name' => 'Mining Consumables, Grinding Media, Drill Steels & Earthmoving Spares'],
        ['code' => 'CS001', 'name' => 'Management, Technical, Financial & Legal Consultancy'],
    ];

    public const PROCURING_ENTITY_PRESETS = [
        'ZETDC / ZESA Holdings',
        'TelOne Zimbabwe',
        'NetOne Cellular',
        'City of Harare',
        'City of Bulawayo',
        'ZINWA (Zimbabwe National Water Authority)',
        'ZINARA (Zimbabwe National Road Administration)',
        'ZIMRA (Zimbabwe Revenue Authority)',
        'Grain Marketing Board (GMB)',
        'National Railways of Zimbabwe (NRZ)',
        'DDF / Rural Infrastructure Development Agency',
        'POTRAZ',
        'Petrotrade',
        'Civil Aviation Authority of Zimbabwe (CAAZ)',
        'Ministry of Health and Child Care',
        'Ministry of Primary and Secondary Education',
        'Ministry of Transport and Infrastructural Development',
        'UNDP Zimbabwe',
        'UNICEF Zimbabwe',
    ];
}

