import React, { useState } from "react";
import axios from "axios";
import {
    Briefcase,
    Building2,
    Calendar,
    Clock,
    FileText,
    Filter,
    Plus,
    Search,
    Tag,
    X,
    UploadCloud,
    Download,
    Trash2,
    CheckCircle2,
    AlertTriangle,
    XCircle,
    ArrowRight,
    MapPin,
    DollarSign,
    Users,
    ShieldAlert,
    ExternalLink,
    HelpCircle,
    Layers,
    ChevronDown,
    FileCheck,
    FileSpreadsheet,
    FileCode,
    Sparkles,
} from "lucide-react";
import ComplianceCenter, { ComplianceData } from "./ComplianceCenter";
import TenderAnalysisModal from "./TenderAnalysisModal";
import TechnicalComplianceMatrix from "./TechnicalComplianceMatrix";

export type TenderSummary = {
    total_tenders: number;
    active_bidding: number;
    closing_soon: number;
    submitted: number;
    awarded: number;
    pipeline_value: string;
    awarded_value: string;
};

export type TenderDoc = {
    id: number;
    file_name: string;
    file_size: number;
    mime_type: string;
    document_category: string;
    description: string | null;
    created_at: string;
};

export type TenderItem = {
    id: number;
    reference_number: string;
    title: string;
    procuring_entity: string;
    entity_type: string;
    procurement_category: string;
    procurement_method: string;
    opportunity_type: string;
    praz_categories: string[];
    unspsc_code: string | null;
    currency: string;
    estimated_value: number | null;
    tender_fee: number | null;
    bid_security_required: boolean;
    bid_security_amount: number | null;
    bid_security_details: string | null;
    closing_at: string | null;
    clarification_deadline: string | null;
    site_visit_required: boolean;
    site_visit_at: string | null;
    site_visit_location: string | null;
    internal_deadline: string | null;
    submission_method: string;
    submission_address: string | null;
    source_url: string | null;
    contact_person: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    status: string;
    status_reason: string | null;
    notes: string | null;
    assigned_employee: { id: number; name: string; designation?: string } | null;
    documents_count: number;
    days_remaining: number | null;
    hours_remaining: number | null;
    is_closed: boolean;
    created_at: string;
    documents?: TenderDoc[];
};

export type TenderData = {
    tenders: TenderItem[];
    summary: TenderSummary;
    catalog: {
        categories: { key: string; label: string }[];
        methods: { key: string; label: string }[];
        opportunity_types: { key: string; label: string }[];
        entity_types: { key: string; label: string }[];
        praz_categories: { code: string; name: string }[];
        procuring_entity_presets: string[];
    };
    employees: { id: number; name: string; designation?: string }[];
};

type Props = {
    initialData?: TenderData;
    complianceData?: ComplianceData;
    canManage: boolean;
    canManageCompliance: boolean;
};

export default function TenderRegistry({
    initialData,
    complianceData,
    canManage,
    canManageCompliance,
}: Props) {
    const [subTab, setSubTab] = useState<"opportunities" | "compliance" | "ai_analysis" | "tech_specs">("opportunities");
    const [data, setData] = useState<TenderData | null>(initialData ?? null);
    const [loading, setLoading] = useState(!initialData);
    const [search, setSearch] = useState("");
    const [categoryFilter, setCategoryFilter] = useState("all");
    const [statusFilter, setStatusFilter] = useState("all");
    const [entityTypeFilter, setEntityTypeFilter] = useState("all");

    // Modal & Drawer states
    const [createModalOpen, setCreateModalOpen] = useState(false);
    const [selectedTender, setSelectedTender] = useState<TenderItem | null>(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [statusModalTender, setStatusModalTender] = useState<TenderItem | null>(null);
    const [newStatus, setNewStatus] = useState("");
    const [statusReason, setStatusReason] = useState("");
    const [uploadDocModalTender, setUploadDocModalTender] = useState<TenderItem | null>(null);
    const [analysisModalTender, setAnalysisModalTender] = useState<TenderItem | null>(null);
    const [technicalMatrixTender, setTechnicalMatrixTender] = useState<TenderItem | null>(null);

    // Form feedback
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);

    // Create Tender Form State
    const [formData, setFormData] = useState({
        reference_number: "",
        title: "",
        procuring_entity: "",
        custom_entity: "",
        entity_type: "government",
        procurement_category: "goods",
        procurement_method: "open_competitive_bidding",
        opportunity_type: "tender",
        praz_categories: [] as string[],
        unspsc_code: "",
        currency: "USD",
        estimated_value: "",
        tender_fee: "",
        bid_security_required: false,
        bid_security_amount: "",
        bid_security_details: "",
        closing_at: "",
        clarification_deadline: "",
        site_visit_required: false,
        site_visit_at: "",
        site_visit_location: "",
        internal_deadline: "",
        submission_method: "electronic",
        submission_address: "",
        source_url: "",
        contact_person: "",
        contact_email: "",
        contact_phone: "",
        assigned_employee_id: "",
        notes: "",
    });
    const [formFiles, setFormFiles] = useState<FileList | null>(null);

    // Document upload for existing tender
    const [uploadFile, setUploadFile] = useState<File | null>(null);
    const [uploadDocCategory, setUploadDocCategory] = useState("tender_rfp");
    const [uploadDocDesc, setUploadDocDesc] = useState("");

    const refreshData = async () => {
        try {
            setLoading(true);
            const res = await axios.get("/tenders/data");
            setData(res.data.data);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to load tender data.");
        } finally {
            setLoading(false);
        }
    };

    const openTenderDetail = async (tenderId: number) => {
        setDetailLoading(true);
        try {
            const res = await axios.get(`/tenders/${tenderId}`);
            setSelectedTender(res.data.data.tender);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to load tender details.");
        } finally {
            setDetailLoading(false);
        }
    };

    const handleCreateTender = async (e: React.FormEvent) => {
        e.preventDefault();
        setError(null);
        setSubmitting(true);

        const entity = formData.procuring_entity === "Other" 
            ? formData.custom_entity.trim() 
            : (formData.procuring_entity || formData.custom_entity.trim());

        if (!entity) {
            setError("Procuring entity is required.");
            setSubmitting(false);
            return;
        }

        const fd = new FormData();
        fd.append("reference_number", formData.reference_number.trim());
        fd.append("title", formData.title.trim());
        fd.append("procuring_entity", entity);
        fd.append("entity_type", formData.entity_type);
        fd.append("procurement_category", formData.procurement_category);
        fd.append("procurement_method", formData.procurement_method);
        fd.append("opportunity_type", formData.opportunity_type);
        fd.append("currency", formData.currency);
        formData.praz_categories.forEach((c) => fd.append("praz_categories[]", c));

        if (formData.unspsc_code) fd.append("unspsc_code", formData.unspsc_code.trim());
        if (formData.estimated_value) fd.append("estimated_value", formData.estimated_value);
        if (formData.tender_fee) fd.append("tender_fee", formData.tender_fee);
        
        fd.append("bid_security_required", formData.bid_security_required ? "1" : "0");
        if (formData.bid_security_required && formData.bid_security_amount) {
            fd.append("bid_security_amount", formData.bid_security_amount);
        }
        if (formData.bid_security_details) fd.append("bid_security_details", formData.bid_security_details);

        if (formData.closing_at) fd.append("closing_at", formData.closing_at);
        if (formData.clarification_deadline) fd.append("clarification_deadline", formData.clarification_deadline);
        
        fd.append("site_visit_required", formData.site_visit_required ? "1" : "0");
        if (formData.site_visit_required && formData.site_visit_at) {
            fd.append("site_visit_at", formData.site_visit_at);
        }
        if (formData.site_visit_location) fd.append("site_visit_location", formData.site_visit_location);
        if (formData.internal_deadline) fd.append("internal_deadline", formData.internal_deadline);

        fd.append("submission_method", formData.submission_method);
        if (formData.submission_address) fd.append("submission_address", formData.submission_address);
        if (formData.source_url) fd.append("source_url", formData.source_url);
        if (formData.contact_person) fd.append("contact_person", formData.contact_person);
        if (formData.contact_email) fd.append("contact_email", formData.contact_email);
        if (formData.contact_phone) fd.append("contact_phone", formData.contact_phone);
        if (formData.assigned_employee_id) fd.append("assigned_employee_id", formData.assigned_employee_id);
        if (formData.notes) fd.append("notes", formData.notes);

        if (formFiles && formFiles.length > 0) {
            for (let i = 0; i < formFiles.length; i++) {
                fd.append("documents[]", formFiles[i]);
            }
        }

        try {
            const res = await axios.post("/tenders", fd, {
                headers: { "Content-Type": "multipart/form-data" },
            });
            setSuccessMessage(`Tender "${res.data.data.reference_number}" created successfully.`);
            setCreateModalOpen(false);
            resetCreateForm();
            refreshData();
            setTimeout(() => setSuccessMessage(null), 5000);
        } catch (e: any) {
            const msg = e.response?.data?.errors
                ? Object.values(e.response.data.errors).flat().join(" ")
                : e.response?.data?.message || "Failed to create tender.";
            setError(msg);
        } finally {
            setSubmitting(false);
        }
    };

    const handleUpdateStatus = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!statusModalTender || !newStatus) return;
        setSubmitting(true);
        setError(null);

        try {
            await axios.patch(`/tenders/${statusModalTender.id}/status`, {
                status: newStatus,
                status_reason: statusReason.trim() || null,
            });
            setSuccessMessage(`Tender status updated to ${newStatus.replace(/_/g, " ")}.`);
            setStatusModalTender(null);
            setNewStatus("");
            setStatusReason("");
            refreshData();
            if (selectedTender && selectedTender.id === statusModalTender.id) {
                openTenderDetail(selectedTender.id);
            }
            setTimeout(() => setSuccessMessage(null), 4000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to update tender status.");
        } finally {
            setSubmitting(false);
        }
    };

    const handleUploadDoc = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!uploadDocModalTender || !uploadFile) return;
        setSubmitting(true);
        setError(null);

        const fd = new FormData();
        fd.append("file", uploadFile);
        fd.append("document_category", uploadDocCategory);
        if (uploadDocDesc.trim()) fd.append("description", uploadDocDesc.trim());

        try {
            await axios.post(`/tenders/${uploadDocModalTender.id}/documents`, fd, {
                headers: { "Content-Type": "multipart/form-data" },
            });
            setSuccessMessage(`Document "${uploadFile.name}" attached successfully.`);
            setUploadDocModalTender(null);
            setUploadFile(null);
            setUploadDocDesc("");
            if (selectedTender && selectedTender.id === uploadDocModalTender.id) {
                openTenderDetail(selectedTender.id);
            }
            refreshData();
            setTimeout(() => setSuccessMessage(null), 4000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to upload document.");
        } finally {
            setSubmitting(false);
        }
    };

    const handleDeleteDoc = async (tenderId: number, docId: number, docName: string) => {
        if (!confirm(`Delete document "${docName}" from this tender?`)) return;
        try {
            await axios.delete(`/tenders/${tenderId}/documents/${docId}`);
            setSuccessMessage(`Document removed.`);
            if (selectedTender && selectedTender.id === tenderId) {
                openTenderDetail(tenderId);
            }
            refreshData();
            setTimeout(() => setSuccessMessage(null), 3000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to delete document.");
        }
    };

    const resetCreateForm = () => {
        setFormData({
            reference_number: "",
            title: "",
            procuring_entity: "",
            custom_entity: "",
            entity_type: "government",
            procurement_category: "goods",
            procurement_method: "open_competitive_bidding",
            opportunity_type: "tender",
            praz_categories: [],
            unspsc_code: "",
            currency: "USD",
            estimated_value: "",
            tender_fee: "",
            bid_security_required: false,
            bid_security_amount: "",
            bid_security_details: "",
            closing_at: "",
            clarification_deadline: "",
            site_visit_required: false,
            site_visit_at: "",
            site_visit_location: "",
            internal_deadline: "",
            submission_method: "electronic",
            submission_address: "",
            source_url: "",
            contact_person: "",
            contact_email: "",
            contact_phone: "",
            assigned_employee_id: "",
            notes: "",
        });
        setFormFiles(null);
    };

    const togglePrazCategory = (code: string) => {
        setFormData((prev) => {
            const exists = prev.praz_categories.includes(code);
            return {
                ...prev,
                praz_categories: exists
                    ? prev.praz_categories.filter((c) => c !== code)
                    : [...prev.praz_categories, code],
            };
        });
    };

    const formatCurrency = (amount: number | null, curr: string = "USD") => {
        if (amount === null || amount === undefined) return "Not specified";
        return `${curr} ${Number(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    };

    const formatFileSize = (bytes: number | null) => {
        if (!bytes) return "";
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
    };

    const renderCountdownBadge = (tender: TenderItem) => {
        if (tender.is_closed) {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-300">
                    <Clock className="w-3 h-3 text-slate-500" /> Closed
                </span>
            );
        }
        if (tender.days_remaining === null) {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-500">
                    No deadline set
                </span>
            );
        }
        if (tender.days_remaining <= 2) {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-800 border border-red-300 animate-pulse">
                    <AlertTriangle className="w-3 h-3 text-red-600" />
                    {tender.hours_remaining !== null ? `${tender.hours_remaining}h left` : "Urgent"}
                </span>
            );
        }
        if (tender.days_remaining <= 7) {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-900 border border-amber-300">
                    <Clock className="w-3 h-3 text-amber-700" />
                    {tender.days_remaining}d remaining
                </span>
            );
        }
        return (
            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-800 border border-emerald-200">
                <Clock className="w-3 h-3 text-emerald-600" />
                {tender.days_remaining}d left
            </span>
        );
    };

    const renderStatusBadge = (status: string) => {
        switch (status) {
            case "awarded":
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-900 border border-emerald-300">
                        <CheckCircle2 className="w-3 h-3 text-emerald-600" /> Awarded
                    </span>
                );
            case "active_bidding":
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-900 border border-blue-300">
                        <Briefcase className="w-3 h-3 text-blue-600" /> Active Bidding
                    </span>
                );
            case "submitted":
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-100 text-purple-900 border border-purple-300">
                        <FileCheck className="w-3 h-3 text-purple-600" /> Submitted
                    </span>
                );
            case "under_evaluation":
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-900 border border-indigo-300">
                        <Clock className="w-3 h-3 text-indigo-600" /> Under Evaluation
                    </span>
                );
            case "evaluating_go_no_go":
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-900 border border-amber-300">
                        <HelpCircle className="w-3 h-3 text-amber-600" /> Go / No-Go
                    </span>
                );
            case "lost":
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 border border-slate-300">
                        <XCircle className="w-3 h-3 text-slate-500" /> Not Awarded
                    </span>
                );
            case "cancelled":
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700 border border-red-300">
                        <X className="w-3 h-3 text-red-500" /> Cancelled
                    </span>
                );
            default:
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 border border-slate-300">
                        Draft
                    </span>
                );
        }
    };

    if (loading && !data) {
        return (
            <div className="p-12 text-center text-slate-500">
                <Clock className="w-8 h-8 animate-spin mx-auto mb-3 text-emerald-600" />
                <p className="font-medium">Loading Zimbabwean Tender Registry...</p>
            </div>
        );
    }

    if (!data) return null;

    const summary = data.summary;
    const catalog = data.catalog;

    const filteredTenders = data.tenders.filter((t) => {
        if (categoryFilter !== "all" && t.procurement_category !== categoryFilter) return false;
        if (statusFilter !== "all" && t.status !== statusFilter) return false;
        if (entityTypeFilter !== "all" && t.entity_type !== entityTypeFilter) return false;
        if (search.trim()) {
            const q = search.toLowerCase();
            return (
                t.reference_number.toLowerCase().includes(q) ||
                t.title.toLowerCase().includes(q) ||
                t.procuring_entity.toLowerCase().includes(q) ||
                (t.unspsc_code && t.unspsc_code.toLowerCase().includes(q)) ||
                (t.praz_categories && t.praz_categories.some((c) => c.toLowerCase().includes(q)))
            );
        }
        return true;
    });

    return (
        <div className="space-y-6">
            {/* Top Navigation Sub-Tabs */}
            <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-3">
                <div className="flex items-center gap-2">
                    <button
                        onClick={() => setSubTab("opportunities")}
                        className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition-colors ${
                            subTab === "opportunities"
                                ? "bg-emerald-600 text-white shadow-sm"
                                : "bg-white text-slate-600 hover:bg-slate-100 border border-slate-200"
                        }`}
                    >
                        <Briefcase className="w-4 h-4" />
                        Tender Opportunities & Register
                        <span className="ml-1 text-xs px-1.5 py-0.5 rounded-full bg-white/20 font-bold">
                            {summary.total_tenders}
                        </span>
                    </button>

                    <button
                        onClick={() => setSubTab("compliance")}
                        className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition-colors ${
                            subTab === "compliance"
                                ? "bg-emerald-600 text-white shadow-sm"
                                : "bg-white text-slate-600 hover:bg-slate-100 border border-slate-200"
                        }`}
                    >
                        <ShieldAlert className="w-4 h-4" />
                        Company Compliance Vault (PRAZ / ZIMRA / NSSA)
                    </button>

                    <button
                        onClick={() => setSubTab("ai_analysis")}
                        className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition-colors ${
                            subTab === "ai_analysis"
                                ? "bg-indigo-600 text-white shadow-sm"
                                : "bg-white text-slate-600 hover:bg-slate-100 border border-slate-200"
                        }`}
                    >
                        <Sparkles className="w-4 h-4 text-amber-400" />
                        AI Tender Document Assistant
                    </button>

                    <button
                        onClick={() => setSubTab("tech_specs")}
                        className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition-colors ${
                            subTab === "tech_specs"
                                ? "bg-blue-600 text-white shadow-sm"
                                : "bg-white text-slate-600 hover:bg-slate-100 border border-slate-200"
                        }`}
                    >
                        <Layers className="w-4 h-4 text-sky-400" />
                        Technical Specifications Matrix
                        <span className="text-[10px] uppercase font-bold tracking-wider px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">
                            Phase 4
                        </span>
                    </button>
                </div>

                {subTab === "opportunities" && canManage && (
                    <button
                        onClick={() => {
                            resetCreateForm();
                            setCreateModalOpen(true);
                        }}
                        className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 shadow-sm transition-all"
                    >
                        <Plus className="w-4 h-4" />
                        New Tender Opportunity
                    </button>
                )}
            </div>

            {/* Sub-Tab 1: Compliance Vault */}
            {subTab === "compliance" && (
                <ComplianceCenter
                    initialData={complianceData}
                    canManage={canManageCompliance}
                />
            )}

            {/* Sub-Tab 2: AI Document Analysis Dashboard */}
            {subTab === "ai_analysis" && (
                <div className="space-y-6">
                    <div className="bg-white rounded-xl border border-slate-200 p-6 sm:p-8 shadow-sm">
                        <div className="max-w-3xl mx-auto text-center space-y-3">
                            <div className="w-14 h-14 rounded-2xl bg-indigo-50 border border-indigo-100 text-indigo-600 flex items-center justify-center mx-auto shadow-inner">
                                <Sparkles className="w-7 h-7 text-indigo-600" />
                            </div>
                            <h2 className="text-xl font-bold text-slate-900">
                                AI Tender Document Ingestion & Requirement Extraction
                            </h2>
                            <p className="text-slate-600 text-xs sm:text-sm leading-relaxed">
                                Deep multi-format ingestion (PDF, DOCX, XLSX, ZIP, scanned OCR) extracting statutory compliance checklists,
                                technical specification matrices, mandatory submission deadlines, bid bonds, and personnel requirements.
                            </p>
                        </div>
                    </div>

                    <div className="bg-white rounded-xl border border-slate-200 p-6 shadow-sm space-y-4">
                        <div className="flex items-center justify-between border-b border-slate-200 pb-3">
                            <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <Briefcase className="w-4 h-4 text-indigo-600" />
                                Select a Tender to Ingest & Analyze ({data.tenders.length})
                            </h3>
                        </div>

                        {data.tenders.length === 0 ? (
                            <p className="text-xs text-slate-500 py-6 text-center">
                                No tender opportunities registered yet. Add a tender from the Register tab first.
                            </p>
                        ) : (
                            <div className="divide-y divide-slate-100 border border-slate-200 rounded-xl overflow-hidden">
                                {data.tenders.map((t) => (
                                    <div key={t.id} className="p-4 flex flex-wrap items-center justify-between gap-3 hover:bg-slate-50 transition-colors">
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-mono text-xs font-bold text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                                    {t.reference_number}
                                                </span>
                                                {renderStatusBadge(t.status)}
                                                <span className="text-xs text-slate-400 font-medium">
                                                    {t.documents_count} RFP document{t.documents_count === 1 ? "" : "s"} attached
                                                </span>
                                            </div>
                                            <h4 className="text-sm font-bold text-slate-900">{t.title}</h4>
                                            <p className="text-xs text-slate-500">
                                                Entity: <strong className="text-slate-700">{t.procuring_entity}</strong> ({t.entity_type}) · Category: <span className="capitalize">{t.procurement_category}</span>
                                            </p>
                                        </div>

                                        <button
                                            onClick={() => setAnalysisModalTender(t)}
                                            className="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white rounded-lg text-xs font-semibold hover:bg-indigo-700 shadow-sm transition-all"
                                        >
                                            <Sparkles className="w-3.5 h-3.5" />
                                            Analyze & View Matrix
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Sub-Tab 3: Technical Specifications Matrix (Phase 4 View) */}
            {subTab === "tech_specs" && (
                <div className="space-y-6">
                    <div className="bg-white rounded-xl border border-slate-200 p-6 sm:p-8 shadow-sm">
                        <div className="max-w-3xl mx-auto text-center space-y-3">
                            <div className="w-14 h-14 rounded-2xl bg-blue-50 border border-blue-100 text-blue-600 flex items-center justify-center mx-auto shadow-inner">
                                <Layers className="w-7 h-7 text-blue-600" />
                            </div>
                            <h2 className="text-xl font-bold text-slate-900">
                                Clause-by-Clause Technical Specification Matrix
                            </h2>
                            <p className="text-slate-600 text-xs sm:text-sm leading-relaxed">
                                Formulate compliant technical proposals, compare specifications against offered solutions, record required evidence,
                                and document justified deviations in accordance with standard Zimbabwean public procurement requirements.
                            </p>
                        </div>
                    </div>

                    <div className="bg-white rounded-xl border border-slate-200 p-6 shadow-sm space-y-4">
                        <div className="flex items-center justify-between border-b border-slate-200 pb-3">
                            <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <Layers className="w-4 h-4 text-blue-600" />
                                Select a Tender to View Technical Compliance Schedule ({data.tenders.length})
                            </h3>
                        </div>

                        {data.tenders.length === 0 ? (
                            <p className="text-xs text-slate-500 py-6 text-center">
                                No tender opportunities registered yet. Add a tender from the Register tab first.
                            </p>
                        ) : (
                            <div className="divide-y divide-slate-100 border border-slate-200 rounded-xl overflow-hidden">
                                {data.tenders.map((t) => (
                                    <div key={t.id} className="p-4 flex flex-wrap items-center justify-between gap-3 hover:bg-slate-50 transition-colors">
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-mono text-xs font-bold text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                                    {t.reference_number}
                                                </span>
                                                {renderStatusBadge(t.status)}
                                                {renderCountdownBadge(t)}
                                            </div>
                                            <h4 className="text-sm font-bold text-slate-900">{t.title}</h4>
                                            <p className="text-xs text-slate-500">
                                                Entity: <strong className="text-slate-700">{t.procuring_entity}</strong> ({t.entity_type}) · Category: <span className="capitalize">{t.procurement_category}</span>
                                            </p>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <button
                                                onClick={() => setTechnicalMatrixTender(t)}
                                                className="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white rounded-lg text-xs font-semibold hover:bg-blue-700 shadow-sm transition-all"
                                            >
                                                <Layers className="w-3.5 h-3.5" />
                                                Open Technical Matrix
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}
            {subTab === "opportunities" && (
                <>
                    {/* Feedback Alerts */}
                    {error && (
                        <div className="p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg text-sm flex items-start gap-2">
                            <AlertTriangle className="w-5 h-5 text-red-600 shrink-0 mt-0.5" />
                            <div className="flex-1 font-medium">{error}</div>
                            <button onClick={() => setError(null)} className="text-red-500 hover:text-red-700">
                                <X className="w-4 h-4" />
                            </button>
                        </div>
                    )}

                    {successMessage && (
                        <div className="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg text-sm flex items-start gap-2">
                            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
                            <div className="flex-1 font-medium">{successMessage}</div>
                            <button onClick={() => setSuccessMessage(null)} className="text-emerald-500 hover:text-emerald-700">
                                <X className="w-4 h-4" />
                            </button>
                        </div>
                    )}

                    {/* Executive KPI Summary Cards */}
                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                        <div className="bg-white p-3.5 rounded-xl border border-slate-200/80 shadow-sm">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider block">
                                Pipeline Value
                            </span>
                            <div className="text-lg font-bold text-slate-900 mt-1 truncate">
                                ${summary.pipeline_value}
                            </div>
                            <span className="text-[11px] text-slate-500 block mt-0.5">Active bids total</span>
                        </div>

                        <div className="bg-blue-50/70 p-3.5 rounded-xl border border-blue-200 shadow-sm">
                            <span className="text-xs font-semibold text-blue-700 uppercase tracking-wider block">
                                Active Bidding
                            </span>
                            <div className="flex items-center gap-1.5 mt-1">
                                <Briefcase className="w-5 h-5 text-blue-600" />
                                <span className="text-2xl font-bold text-blue-900">{summary.active_bidding}</span>
                            </div>
                            <span className="text-[11px] text-blue-700 block mt-0.5">Under preparation</span>
                        </div>

                        <div className="bg-amber-50/80 p-3.5 rounded-xl border border-amber-200 shadow-sm">
                            <span className="text-xs font-semibold text-amber-800 uppercase tracking-wider block">
                                Closing &lt; 7 Days
                            </span>
                            <div className="flex items-center gap-1.5 mt-1">
                                <Clock className="w-5 h-5 text-amber-600" />
                                <span className="text-2xl font-bold text-amber-900">{summary.closing_soon}</span>
                            </div>
                            <span className="text-[11px] text-amber-700 block mt-0.5">Immediate priority</span>
                        </div>

                        <div className="bg-purple-50/70 p-3.5 rounded-xl border border-purple-200 shadow-sm">
                            <span className="text-xs font-semibold text-purple-700 uppercase tracking-wider block">
                                Submitted Bids
                            </span>
                            <div className="flex items-center gap-1.5 mt-1">
                                <FileCheck className="w-5 h-5 text-purple-600" />
                                <span className="text-2xl font-bold text-purple-900">{summary.submitted}</span>
                            </div>
                            <span className="text-[11px] text-purple-700 block mt-0.5">Awaiting decision</span>
                        </div>

                        <div className="bg-emerald-50/80 p-3.5 rounded-xl border border-emerald-200 shadow-sm">
                            <span className="text-xs font-semibold text-emerald-800 uppercase tracking-wider block">
                                Awarded Wins
                            </span>
                            <div className="flex items-center gap-1.5 mt-1">
                                <CheckCircle2 className="w-5 h-5 text-emerald-600" />
                                <span className="text-2xl font-bold text-emerald-900">{summary.awarded}</span>
                            </div>
                            <span className="text-[11px] text-emerald-700 block mt-0.5">Successful contracts</span>
                        </div>

                        <div className="bg-emerald-50/80 p-3.5 rounded-xl border border-emerald-200 shadow-sm">
                            <span className="text-xs font-semibold text-emerald-800 uppercase tracking-wider block">
                                Won Value
                            </span>
                            <div className="text-lg font-bold text-emerald-900 mt-1 truncate">
                                ${summary.awarded_value}
                            </div>
                            <span className="text-[11px] text-emerald-700 block mt-0.5">Contracted revenue</span>
                        </div>
                    </div>

                    {/* Filter & Search Bar */}
                    <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm space-y-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <div className="relative flex-1 min-w-[240px]">
                                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search by tender ref, title, procuring entity, or UNSPSC..."
                                    className="w-full pl-9 pr-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                                {search && (
                                    <button
                                        onClick={() => setSearch("")}
                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                                    >
                                        <X className="w-4 h-4" />
                                    </button>
                                )}
                            </div>

                            <div className="flex items-center gap-2">
                                <Filter className="w-4 h-4 text-slate-400 shrink-0" />
                                <select
                                    value={categoryFilter}
                                    onChange={(e) => setCategoryFilter(e.target.value)}
                                    className="text-xs border border-slate-300 rounded-lg px-2.5 py-2 bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    <option value="all">All Procurement Categories</option>
                                    {(catalog?.categories ?? []).map((c) => (
                                        <option key={c.key} value={c.key}>
                                            {c.label}
                                        </option>
                                    ))}
                                </select>

                                <select
                                    value={statusFilter}
                                    onChange={(e) => setStatusFilter(e.target.value)}
                                    className="text-xs border border-slate-300 rounded-lg px-2.5 py-2 bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    <option value="all">All Statuses</option>
                                    <option value="draft">Draft</option>
                                    <option value="evaluating_go_no_go">Go / No-Go Evaluation</option>
                                    <option value="active_bidding">Active Bidding</option>
                                    <option value="submitted">Submitted</option>
                                    <option value="under_evaluation">Under Evaluation</option>
                                    <option value="awarded">Awarded</option>
                                    <option value="lost">Lost</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>

                                <select
                                    value={entityTypeFilter}
                                    onChange={(e) => setEntityTypeFilter(e.target.value)}
                                    className="text-xs border border-slate-300 rounded-lg px-2.5 py-2 bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    <option value="all">All Entity Sectors</option>
                                    {(catalog?.entity_types ?? []).map((e) => (
                                        <option key={e.key} value={e.key}>
                                            {e.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Tender Opportunities List */}
                    <div className="space-y-3">
                        {filteredTenders.length === 0 ? (
                            <div className="bg-white rounded-xl border border-dashed border-slate-300 p-12 text-center">
                                <Briefcase className="w-10 h-10 text-slate-300 mx-auto mb-2" />
                                <h3 className="text-base font-semibold text-slate-800">No tender opportunities found</h3>
                                <p className="text-xs text-slate-500 mt-1 max-w-sm mx-auto">
                                    {search || categoryFilter !== "all" || statusFilter !== "all"
                                        ? "No tenders match your search filters. Try resetting the filters."
                                        : "You have not captured any tender opportunities yet. Click below to add your first tender."}
                                </p>
                                {canManage && (
                                    <button
                                        onClick={() => {
                                            resetCreateForm();
                                            setCreateModalOpen(true);
                                        }}
                                        className="mt-4 inline-flex items-center gap-1.5 px-3.5 py-2 bg-emerald-600 text-white rounded-lg text-xs font-semibold hover:bg-emerald-700 shadow-sm"
                                    >
                                        <Plus className="w-4 h-4" />
                                        Add First Tender
                                    </button>
                                )}
                            </div>
                        ) : (
                            filteredTenders.map((tender) => (
                                <div
                                    key={tender.id}
                                    className="bg-white rounded-xl border border-slate-200/90 p-5 shadow-sm hover:border-emerald-300 transition-all space-y-4"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="space-y-1 max-w-3xl">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-mono text-xs font-bold text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                                    {tender.reference_number}
                                                </span>
                                                {renderStatusBadge(tender.status)}
                                                {renderCountdownBadge(tender)}
                                                {tender.site_visit_required && (
                                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-200">
                                                        <MapPin className="w-3 h-3 text-amber-600" /> Site Visit Req.
                                                    </span>
                                                )}
                                                {tender.bid_security_required && (
                                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-800 border border-blue-200">
                                                        <DollarSign className="w-3 h-3 text-blue-600" /> Bid Bond Req.
                                                    </span>
                                                )}
                                            </div>
                                            <h3 className="text-base font-bold text-slate-900 hover:text-emerald-700 cursor-pointer"
                                                onClick={() => openTenderDetail(tender.id)}>
                                                {tender.title}
                                            </h3>
                                            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500 pt-0.5">
                                                <span className="flex items-center gap-1 font-medium text-slate-700">
                                                    <Building2 className="w-3.5 h-3.5 text-slate-400" />
                                                    {tender.procuring_entity}
                                                    <span className="text-[10px] text-slate-400 capitalize">
                                                        ({tender.entity_type.replace(/_/g, " ")})
                                                    </span>
                                                </span>
                                                <span>·</span>
                                                <span>
                                                    Category: <strong className="text-slate-700 capitalize">{tender.procurement_category.replace(/_/g, " ")}</strong>
                                                </span>
                                                <span>·</span>
                                                <span>
                                                    Method: <span className="text-slate-700 capitalize">{tender.procurement_method.replace(/_/g, " ")}</span>
                                                </span>
                                                {tender.assigned_employee && (
                                                    <>
                                                        <span>·</span>
                                                        <span className="flex items-center gap-1 text-slate-600">
                                                            <Users className="w-3 h-3 text-slate-400" />
                                                            Lead: {tender.assigned_employee.name}
                                                        </span>
                                                    </>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <button
                                                onClick={() => setAnalysisModalTender(tender)}
                                                className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 rounded-lg border border-indigo-200 transition-colors"
                                                title="AI Tender Document Analysis & Matrix"
                                            >
                                                <Sparkles className="w-3.5 h-3.5 text-indigo-600" />
                                                AI Analysis
                                            </button>

                                            <button
                                                onClick={() => setTechnicalMatrixTender(tender)}
                                                className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 rounded-lg border border-blue-200 transition-colors"
                                                title="Technical Specification Compliance Matrix"
                                            >
                                                <Layers className="w-3.5 h-3.5 text-blue-600" />
                                                Tech Specs
                                            </button>

                                            {canManage && (
                                                <button
                                                    onClick={() => {
                                                        setStatusModalTender(tender);
                                                        setNewStatus(tender.status);
                                                        setStatusReason(tender.status_reason || "");
                                                    }}
                                                    className="px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:text-slate-900 bg-slate-50 hover:bg-slate-100 rounded-lg border border-slate-200 transition-colors"
                                                >
                                                    Status
                                                </button>
                                            )}

                                            <button
                                                onClick={() => openTenderDetail(tender.id)}
                                                className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:text-emerald-800 bg-emerald-50 hover:bg-emerald-100 rounded-lg border border-emerald-200 transition-colors"
                                            >
                                                Details & Documents ({tender.documents_count})
                                                <ArrowRight className="w-3 h-3" />
                                            </button>
                                        </div>
                                    </div>

                                    {/* Tender Details Strip */}
                                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2 border-t border-slate-100 text-xs">
                                        <div>
                                            <span className="text-slate-400 block text-[11px]">Estimated Value:</span>
                                            <span className="font-semibold text-slate-800">
                                                {formatCurrency(tender.estimated_value, tender.currency)}
                                            </span>
                                        </div>
                                        <div>
                                            <span className="text-slate-400 block text-[11px]">Closing Deadline:</span>
                                            <span className="font-medium text-slate-800">
                                                {tender.closing_at
                                                    ? new Date(tender.closing_at).toLocaleString()
                                                    : "Unspecified"}
                                            </span>
                                        </div>
                                        <div>
                                            <span className="text-slate-400 block text-[11px]">Submission Mode:</span>
                                            <span className="font-medium text-slate-800 capitalize">
                                                {tender.submission_method.replace(/_/g, " ")}
                                            </span>
                                        </div>
                                        <div>
                                            <span className="text-slate-400 block text-[11px]">PRAZ Categories:</span>
                                            <div className="flex flex-wrap gap-1 mt-0.5">
                                                {tender.praz_categories.length > 0 ? (
                                                    tender.praz_categories.map((c) => (
                                                        <span
                                                            key={c}
                                                            className="text-[10px] font-mono px-1.5 py-0.2 rounded bg-slate-100 text-slate-700 border border-slate-200"
                                                        >
                                                            {c}
                                                        </span>
                                                    ))
                                                ) : (
                                                    <span className="text-slate-400 text-[11px]">General</span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </>
            )}

            {/* DETAIL & DOCUMENTS DRAWER / MODAL */}
            {selectedTender && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex justify-end">
                    <div className="bg-white w-full max-w-3xl min-h-screen p-6 sm:p-8 shadow-2xl flex flex-col space-y-6 overflow-y-auto">
                        <div className="flex items-start justify-between gap-4 border-b border-slate-200 pb-4">
                            <div className="space-y-1">
                                <div className="flex items-center gap-2">
                                    <span className="font-mono text-xs font-bold text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                        {selectedTender.reference_number}
                                    </span>
                                    {renderStatusBadge(selectedTender.status)}
                                    {renderCountdownBadge(selectedTender)}
                                </div>
                                <h2 className="text-xl font-bold text-slate-900 mt-1">
                                    {selectedTender.title}
                                </h2>
                                <p className="text-xs text-slate-500">
                                    Procuring Entity: <strong className="text-slate-700">{selectedTender.procuring_entity}</strong> ({selectedTender.entity_type})
                                </p>
                            </div>
                            <button
                                onClick={() => setSelectedTender(null)}
                                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Quick Action Toolbar */}
                        <div className="flex flex-wrap items-center justify-between gap-2 bg-slate-50 p-3 rounded-lg border border-slate-200">
                            <div className="text-xs text-slate-600 font-medium">
                                Lead Person: {selectedTender.assigned_employee ? selectedTender.assigned_employee.name : "Unassigned"}
                            </div>
                            <div className="flex items-center gap-2">
                                {canManage && (
                                    <button
                                        onClick={() => {
                                            setStatusModalTender(selectedTender);
                                            setNewStatus(selectedTender.status);
                                            setStatusReason(selectedTender.status_reason || "");
                                        }}
                                        className="px-2.5 py-1 text-xs font-semibold bg-white border border-slate-300 rounded text-slate-700 hover:bg-slate-50"
                                    >
                                        Change Status
                                    </button>
                                )}
                                <button
                                    onClick={() => setAnalysisModalTender(selectedTender)}
                                    className="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold bg-indigo-600 text-white rounded hover:bg-indigo-700 shadow-sm"
                                >
                                    <Sparkles className="w-3.5 h-3.5" /> AI Analysis Matrix
                                </button>
                                <button
                                    onClick={() => setTechnicalMatrixTender(selectedTender)}
                                    className="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold bg-blue-600 text-white rounded hover:bg-blue-700 shadow-sm"
                                >
                                    <Layers className="w-3.5 h-3.5" /> Technical Matrix
                                </button>
                                {canManage && (
                                    <button
                                        onClick={() => {
                                            setUploadDocModalTender(selectedTender);
                                            setUploadFile(null);
                                            setUploadDocDesc("");
                                        }}
                                        className="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold bg-emerald-600 text-white rounded hover:bg-emerald-700 shadow-sm"
                                    >
                                        <UploadCloud className="w-3.5 h-3.5" /> Attach Document
                                    </button>
                                )}
                            </div>
                        </div>

                        {/* Specifications & Milestones */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div className="bg-slate-50/70 p-4 rounded-lg border border-slate-200/80 space-y-2">
                                <h4 className="font-semibold text-slate-800 uppercase tracking-wider text-[11px]">
                                    Procurement Details
                                </h4>
                                <div>
                                    <span className="text-slate-500">Procurement Category:</span>{" "}
                                    <strong className="text-slate-800 capitalize">{selectedTender.procurement_category.replace(/_/g, " ")}</strong>
                                </div>
                                <div>
                                    <span className="text-slate-500">Procurement Method:</span>{" "}
                                    <span className="text-slate-800 capitalize">{selectedTender.procurement_method.replace(/_/g, " ")}</span>
                                </div>
                                <div>
                                    <span className="text-slate-500">Opportunity Type:</span>{" "}
                                    <span className="text-slate-800 capitalize">{selectedTender.opportunity_type.replace(/_/g, " ")}</span>
                                </div>
                                <div>
                                    <span className="text-slate-500">PRAZ Categories:</span>{" "}
                                    <span className="text-slate-800 font-mono">
                                        {selectedTender.praz_categories.join(", ") || "None specified"}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-slate-500">UNSPSC Code:</span>{" "}
                                    <span className="text-slate-800">{selectedTender.unspsc_code || "N/A"}</span>
                                </div>
                            </div>

                            <div className="bg-slate-50/70 p-4 rounded-lg border border-slate-200/80 space-y-2">
                                <h4 className="font-semibold text-slate-800 uppercase tracking-wider text-[11px]">
                                    Financials & Securities
                                </h4>
                                <div>
                                    <span className="text-slate-500">Estimated Value:</span>{" "}
                                    <strong className="text-emerald-800">
                                        {formatCurrency(selectedTender.estimated_value, selectedTender.currency)}
                                    </strong>
                                </div>
                                <div>
                                    <span className="text-slate-500">Tender Document Fee:</span>{" "}
                                    <span className="text-slate-800">
                                        {formatCurrency(selectedTender.tender_fee, selectedTender.currency)}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-slate-500">Bid Security / Bond:</span>{" "}
                                    {selectedTender.bid_security_required ? (
                                        <span className="font-semibold text-blue-700">
                                            Required ({formatCurrency(selectedTender.bid_security_amount, selectedTender.currency)})
                                        </span>
                                    ) : (
                                        <span className="text-slate-500">Not required</span>
                                    )}
                                </div>
                                {selectedTender.bid_security_details && (
                                    <div className="text-[11px] text-slate-600 bg-white p-2 rounded border border-slate-200">
                                        {selectedTender.bid_security_details}
                                    </div>
                                )}
                            </div>

                            <div className="bg-slate-50/70 p-4 rounded-lg border border-slate-200/80 space-y-2">
                                <h4 className="font-semibold text-slate-800 uppercase tracking-wider text-[11px]">
                                    Deadlines & Site Visit
                                </h4>
                                <div>
                                    <span className="text-slate-500">Closing Date & Time:</span>{" "}
                                    <strong className="text-slate-900">
                                        {selectedTender.closing_at ? new Date(selectedTender.closing_at).toLocaleString() : "Unspecified"}
                                    </strong>
                                </div>
                                <div>
                                    <span className="text-slate-500">Clarification Deadline:</span>{" "}
                                    <span className="text-slate-800">
                                        {selectedTender.clarification_deadline ? new Date(selectedTender.clarification_deadline).toLocaleString() : "None"}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-slate-500">Internal Target Deadline:</span>{" "}
                                    <span className="text-slate-800">
                                        {selectedTender.internal_deadline ? new Date(selectedTender.internal_deadline).toLocaleString() : "None"}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-slate-500">Site Visit:</span>{" "}
                                    {selectedTender.site_visit_required ? (
                                        <span className="font-semibold text-amber-800">
                                            Required on {selectedTender.site_visit_at ? new Date(selectedTender.site_visit_at).toLocaleString() : "TBD"}
                                            {selectedTender.site_visit_location ? ` at ${selectedTender.site_visit_location}` : ""}
                                        </span>
                                    ) : (
                                        <span className="text-slate-500">No site visit required</span>
                                    )}
                                </div>
                            </div>

                            <div className="bg-slate-50/70 p-4 rounded-lg border border-slate-200/80 space-y-2">
                                <h4 className="font-semibold text-slate-800 uppercase tracking-wider text-[11px]">
                                    Submission & Contacts
                                </h4>
                                <div>
                                    <span className="text-slate-500">Submission Method:</span>{" "}
                                    <strong className="text-slate-800 capitalize">{selectedTender.submission_method.replace(/_/g, " ")}</strong>
                                </div>
                                {selectedTender.submission_address && (
                                    <div>
                                        <span className="text-slate-500">Address / Portal:</span>{" "}
                                        <span className="text-slate-800">{selectedTender.submission_address}</span>
                                    </div>
                                )}
                                {selectedTender.source_url && (
                                    <div>
                                        <span className="text-slate-500">Source URL:</span>{" "}
                                        <a href={selectedTender.source_url} target="_blank" rel="noreferrer" className="text-emerald-700 underline flex items-center gap-1 inline">
                                            Open notice <ExternalLink className="w-3 h-3 inline" />
                                        </a>
                                    </div>
                                )}
                                {selectedTender.contact_person && (
                                    <div>
                                        <span className="text-slate-500">Contact:</span>{" "}
                                        <span className="text-slate-800">
                                            {selectedTender.contact_person}
                                            {selectedTender.contact_email ? ` (${selectedTender.contact_email})` : ""}
                                            {selectedTender.contact_phone ? ` - ${selectedTender.contact_phone}` : ""}
                                        </span>
                                    </div>
                                )}
                            </div>
                        </div>

                        {selectedTender.notes && (
                            <div className="bg-amber-50/60 p-3.5 rounded-lg border border-amber-200 text-xs space-y-1">
                                <span className="font-semibold text-amber-900 block">Internal Tender Notes:</span>
                                <p className="text-amber-800 whitespace-pre-wrap">{selectedTender.notes}</p>
                            </div>
                        )}

                        {/* Tender Documents Attachment Repository */}
                        <div className="space-y-3 pt-2">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                                    <FileText className="w-4 h-4 text-emerald-600" />
                                    Tender Bidding Documents & Attachments ({selectedTender.documents?.length || 0})
                                </h4>
                                {canManage && (
                                    <button
                                        onClick={() => {
                                            setUploadDocModalTender(selectedTender);
                                            setUploadFile(null);
                                            setUploadDocDesc("");
                                        }}
                                        className="text-xs font-semibold text-emerald-700 hover:text-emerald-800 flex items-center gap-1"
                                    >
                                        <Plus className="w-3.5 h-3.5" /> Upload File
                                    </button>
                                )}
                            </div>

                            {!selectedTender.documents || selectedTender.documents.length === 0 ? (
                                <div className="p-6 bg-slate-50 rounded-lg border border-dashed border-slate-300 text-center text-xs text-slate-500">
                                    No documents attached to this tender yet. Attach RFPs, technical specifications, or addenda.
                                </div>
                            ) : (
                                <div className="divide-y divide-slate-100 border border-slate-200 rounded-lg overflow-hidden bg-white">
                                    {selectedTender.documents.map((doc) => (
                                        <div key={doc.id} className="p-3.5 flex items-center justify-between gap-3 text-xs hover:bg-slate-50">
                                            <div className="flex items-center gap-3 min-w-0">
                                                <div className="p-2 rounded bg-emerald-50 text-emerald-700">
                                                    <FileText className="w-4 h-4" />
                                                </div>
                                                <div className="truncate">
                                                    <a
                                                        href={`/tenders/${selectedTender.id}/documents/${doc.id}/download`}
                                                        className="font-semibold text-slate-900 hover:text-emerald-700 truncate block"
                                                    >
                                                        {doc.file_name}
                                                    </a>
                                                    <div className="flex items-center gap-2 text-[11px] text-slate-500 mt-0.5">
                                                        <span className="capitalize px-1.5 py-0.2 bg-slate-100 rounded text-slate-600 font-medium">
                                                            {doc.document_category.replace(/_/g, " ")}
                                                        </span>
                                                        <span>·</span>
                                                        <span>{formatFileSize(doc.file_size)}</span>
                                                        <span>·</span>
                                                        <span>{new Date(doc.created_at).toLocaleDateString()}</span>
                                                    </div>
                                                    {doc.description && (
                                                        <p className="text-[11px] text-slate-600 mt-0.5">{doc.description}</p>
                                                    )}
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-1 shrink-0">
                                                <a
                                                    href={`/tenders/${selectedTender.id}/documents/${doc.id}/download`}
                                                    className="p-1.5 text-slate-500 hover:text-emerald-700 hover:bg-emerald-50 rounded transition-colors"
                                                    title="Download document"
                                                >
                                                    <Download className="w-4 h-4" />
                                                </a>
                                                {canManage && (
                                                    <button
                                                        onClick={() => handleDeleteDoc(selectedTender.id, doc.id, doc.file_name)}
                                                        className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors"
                                                        title="Delete document"
                                                    >
                                                        <Trash2 className="w-4 h-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* CREATE TENDER MODAL */}
            {createModalOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto p-6 sm:p-8 space-y-6">
                        <div className="flex items-start justify-between border-b border-slate-200 pb-3">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900">
                                    Register New Tender Opportunity
                                </h3>
                                <p className="text-xs text-slate-500">
                                    Capture procurement notices across Government, Parastatals, PRAZ/eGP, NGOs and Private Sector.
                                </p>
                            </div>
                            <button
                                onClick={() => setCreateModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600 p-1 rounded-lg"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleCreateTender} className="space-y-6">
                            {/* Section 1: Basic Identifiers */}
                            <div className="space-y-4">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-emerald-800 border-b border-slate-100 pb-1">
                                    1. Tender Identification & Procuring Entity
                                </h4>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Tender Reference Number *
                                        </label>
                                        <input
                                            type="text"
                                            required
                                            value={formData.reference_number}
                                            onChange={(e) => setFormData({ ...formData, reference_number: e.target.value })}
                                            placeholder="e.g. ZETDC/INTER/04/2026"
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 font-mono uppercase focus:ring-2 focus:ring-emerald-500"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Procuring Entity Sector *
                                        </label>
                                        <select
                                            value={formData.entity_type}
                                            onChange={(e) => setFormData({ ...formData, entity_type: e.target.value })}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-emerald-500"
                                        >
                                            {(catalog?.entity_types ?? []).map((e) => (
                                                <option key={e.key} value={e.key}>{e.label}</option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Tender Title / Subject Matter *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        value={formData.title}
                                        onChange={(e) => setFormData({ ...formData, title: e.target.value })}
                                        placeholder="e.g. Supply and Delivery of 11kV Distribution Transformers"
                                        className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Procuring Entity *
                                        </label>
                                        <select
                                            value={formData.procuring_entity}
                                            onChange={(e) => setFormData({ ...formData, procuring_entity: e.target.value })}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-emerald-500"
                                        >
                                            <option value="">Select known Zimbabwean Procuring Entity...</option>
                                            {catalog.procuring_entity_presets.map((ent) => (
                                                <option key={ent} value={ent}>{ent}</option>
                                            ))}
                                            <option value="Other">-- Other (specify below) --</option>
                                        </select>
                                    </div>

                                    {(formData.procuring_entity === "Other" || !formData.procuring_entity) && (
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700 mb-1">
                                                Custom Entity Name *
                                            </label>
                                            <input
                                                type="text"
                                                value={formData.custom_entity}
                                                onChange={(e) => setFormData({ ...formData, custom_entity: e.target.value })}
                                                placeholder="e.g. Rural Electrification Agency (REA)"
                                                className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500"
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Section 2: Procurement Classification */}
                            <div className="space-y-4">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-emerald-800 border-b border-slate-100 pb-1">
                                    2. Classification, Method & PRAZ Categories
                                </h4>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Procurement Category *
                                        </label>
                                        <select
                                            value={formData.procurement_category}
                                            onChange={(e) => setFormData({ ...formData, procurement_category: e.target.value })}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-emerald-500"
                                        >
                                            {(catalog?.categories ?? []).map((c) => (
                                                <option key={c.key} value={c.key}>{c.label}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Procurement Method *
                                        </label>
                                        <select
                                            value={formData.procurement_method}
                                            onChange={(e) => setFormData({ ...formData, procurement_method: e.target.value })}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-emerald-500"
                                        >
                                            {(catalog?.methods ?? []).map((m) => (
                                                <option key={m.key} value={m.key}>{m.label}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Opportunity Type *
                                        </label>
                                        <select
                                            value={formData.opportunity_type}
                                            onChange={(e) => setFormData({ ...formData, opportunity_type: e.target.value })}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-emerald-500"
                                        >
                                            {(catalog?.opportunity_types ?? []).map((o) => (
                                                <option key={o.key} value={o.key}>{o.label}</option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Required PRAZ Supplier Categories
                                    </label>
                                    <div className="flex flex-wrap gap-1.5 p-2 bg-slate-50 border border-slate-200 rounded-lg max-h-32 overflow-y-auto">
                                        {(catalog?.praz_categories ?? []).map((p) => {
                                            const active = formData.praz_categories.includes(p.code);
                                            return (
                                                <button
                                                    type="button"
                                                    key={p.code}
                                                    onClick={() => togglePrazCategory(p.code)}
                                                    className={`text-[11px] px-2 py-0.5 rounded font-mono transition-colors ${
                                                        active
                                                            ? "bg-emerald-600 text-white font-semibold"
                                                            : "bg-white border border-slate-300 text-slate-700 hover:bg-slate-100"
                                                    }`}
                                                >
                                                    {p.code} - {p.name}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>

                            {/* Section 3: Financials & Securities */}
                            <div className="space-y-4">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-emerald-800 border-b border-slate-100 pb-1">
                                    3. Financial Values & Bid Security
                                </h4>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Currency *
                                        </label>
                                        <select
                                            value={formData.currency}
                                            onChange={(e) => setFormData({ ...formData, currency: e.target.value })}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-emerald-500"
                                        >
                                            <option value="USD">USD - United States Dollar</option>
                                            <option value="ZWG">ZWG - Zimbabwe Gold</option>
                                            <option value="ZAR">ZAR - South African Rand</option>
                                            <option value="EUR">EUR - Euro</option>
                                            <option value="GBP">GBP - British Pound</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Estimated Value (optional)
                                        </label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            value={formData.estimated_value}
                                            onChange={(e) => setFormData({ ...formData, estimated_value: e.target.value })}
                                            placeholder="e.g. 85000.00"
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Tender Purchase Fee (optional)
                                        </label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            value={formData.tender_fee}
                                            onChange={(e) => setFormData({ ...formData, tender_fee: e.target.value })}
                                            placeholder="e.g. 10.00"
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500"
                                        />
                                    </div>
                                </div>

                                <div className="p-3.5 bg-blue-50/60 border border-blue-200 rounded-lg space-y-3">
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            id="bid_sec_req"
                                            checked={formData.bid_security_required}
                                            onChange={(e) => setFormData({ ...formData, bid_security_required: e.target.checked })}
                                            className="rounded text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <label htmlFor="bid_sec_req" className="text-xs font-bold text-blue-900 cursor-pointer">
                                            Bid Security / Bid Bond is required for this tender
                                        </label>
                                    </div>

                                    {formData.bid_security_required && (
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                                            <div>
                                                <label className="block text-xs font-semibold text-blue-900 mb-1">
                                                    Bid Security Amount ({formData.currency})
                                                </label>
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    value={formData.bid_security_amount}
                                                    onChange={(e) => setFormData({ ...formData, bid_security_amount: e.target.value })}
                                                    placeholder="e.g. 2500.00"
                                                    className="w-full text-xs border border-blue-300 rounded-lg px-3 py-2 bg-white"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-blue-900 mb-1">
                                                    Security Format / Guarantee Terms
                                                </label>
                                                <input
                                                    type="text"
                                                    value={formData.bid_security_details}
                                                    onChange={(e) => setFormData({ ...formData, bid_security_details: e.target.value })}
                                                    placeholder="e.g. Commercial Bank Guarantee or PRAZ Bid Bond Valid 90 days"
                                                    className="w-full text-xs border border-blue-300 rounded-lg px-3 py-2 bg-white"
                                                />
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Section 4: Critical Deadlines & Site Visits */}
                            <div className="space-y-4">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-emerald-800 border-b border-slate-100 pb-1">
                                    4. Deadlines, Milestones & Site Visit
                                </h4>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Submission Closing Date & Time *
                                        </label>
                                        <input
                                            type="datetime-local"
                                            required
                                            value={formData.closing_at}
                                            onChange={(e) => setFormData({ ...formData, closing_at: e.target.value })}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500"
                                        />
                                        <span className="text-[10px] text-slate-500 mt-0.5 block">Official closing in Zimbabwe (CAT)</span>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Clarification Deadline (optional)
                                        </label>
                                        <input
                                            type="datetime-local"
                                            value={formData.clarification_deadline}
                                            onChange={(e) => setFormData({ ...formData, clarification_deadline: e.target.value })}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Internal Target Submission (optional)
                                        </label>
                                        <input
                                            type="datetime-local"
                                            value={formData.internal_deadline}
                                            onChange={(e) => setFormData({ ...formData, internal_deadline: e.target.value })}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500"
                                        />
                                    </div>
                                </div>

                                <div className="p-3.5 bg-amber-50/60 border border-amber-200 rounded-lg space-y-3">
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            id="site_vis_req"
                                            checked={formData.site_visit_required}
                                            onChange={(e) => setFormData({ ...formData, site_visit_required: e.target.checked })}
                                            className="rounded text-amber-600 focus:ring-amber-500"
                                        />
                                        <label htmlFor="site_vis_req" className="text-xs font-bold text-amber-900 cursor-pointer">
                                            Mandatory / Voluntary Site Visit / Pre-Bid Meeting is required
                                        </label>
                                    </div>

                                    {formData.site_visit_required && (
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                                            <div>
                                                <label className="block text-xs font-semibold text-amber-900 mb-1">
                                                    Site Visit Date & Time
                                                </label>
                                                <input
                                                    type="datetime-local"
                                                    value={formData.site_visit_at}
                                                    onChange={(e) => setFormData({ ...formData, site_visit_at: e.target.value })}
                                                    className="w-full text-xs border border-amber-300 rounded-lg px-3 py-2 bg-white"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-amber-900 mb-1">
                                                    Site Visit Location / Meeting Point
                                                </label>
                                                <input
                                                    type="text"
                                                    value={formData.site_visit_location}
                                                    onChange={(e) => setFormData({ ...formData, site_visit_location: e.target.value })}
                                                    placeholder="e.g. ZETDC Southern Region Office, Bulawayo"
                                                    className="w-full text-xs border border-amber-300 rounded-lg px-3 py-2 bg-white"
                                                />
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Section 5: Submission Method & Personnel */}
                            <div className="space-y-4">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-emerald-800 border-b border-slate-100 pb-1">
                                    5. Submission Delivery & Lead Assignment
                                </h4>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Submission Method *
                                        </label>
                                        <select
                                            value={formData.submission_method}
                                            onChange={(e) => setFormData({ ...formData, submission_method: e.target.value })}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-emerald-500"
                                        >
                                            <option value="electronic">Electronic / PRAZ eGP Portal</option>
                                            <option value="physical">Physical Tender Box Submission</option>
                                            <option value="both">Both Electronic & Physical Copies</option>
                                            <option value="email">Direct Email Submission</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Assigned Lead Employee
                                        </label>
                                        <select
                                            value={formData.assigned_employee_id}
                                            onChange={(e) => setFormData({ ...formData, assigned_employee_id: e.target.value })}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-emerald-500"
                                        >
                                            <option value="">Select bid coordinator...</option>
                                            {data.employees.map((emp) => (
                                                <option key={emp.id} value={emp.id}>
                                                    {emp.name} {emp.designation ? `(${emp.designation})` : ""}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Original Source URL (optional)
                                        </label>
                                        <input
                                            type="url"
                                            value={formData.source_url}
                                            onChange={(e) => setFormData({ ...formData, source_url: e.target.value })}
                                            placeholder="https://egp.praz.org.zw/..."
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Submission Address / Delivery Box Instructions
                                    </label>
                                    <input
                                        type="text"
                                        value={formData.submission_address}
                                        onChange={(e) => setFormData({ ...formData, submission_address: e.target.value })}
                                        placeholder="e.g. Tender Box, Ground Floor, Old Mutual Centre, Jason Moyo Ave, Harare"
                                        className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Initial RFP / Bidding Documents (PDF, Word, Excel, ZIP)
                                    </label>
                                    <input
                                        type="file"
                                        multiple
                                        onChange={(e) => setFormFiles(e.target.files)}
                                        className="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100"
                                    />
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
                                <button
                                    type="button"
                                    onClick={() => setCreateModalOpen(false)}
                                    className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    className="inline-flex items-center gap-1.5 px-5 py-2 text-xs font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 shadow-sm disabled:opacity-50"
                                >
                                    {submitting ? (
                                        <>
                                            <Clock className="w-4 h-4 animate-spin" /> Saving Opportunity...
                                        </>
                                    ) : (
                                        <>
                                            <CheckCircle2 className="w-4 h-4" /> Save Tender Opportunity
                                        </>
                                    )}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* STATUS TRANSITION MODAL */}
            {statusModalTender && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white rounded-xl shadow-xl max-w-md w-full p-6 space-y-4">
                        <div className="flex items-start justify-between border-b border-slate-100 pb-2">
                            <h3 className="text-base font-bold text-slate-900">Update Tender Status</h3>
                            <button
                                onClick={() => setStatusModalTender(null)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <p className="text-xs text-slate-500">
                            Updating status for: <strong>{statusModalTender.reference_number}</strong>
                        </p>
                        <form onSubmit={handleUpdateStatus} className="space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    New Pipeline Status *
                                </label>
                                <select
                                    value={newStatus}
                                    onChange={(e) => setNewStatus(e.target.value)}
                                    className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white"
                                >
                                    <option value="draft">Draft</option>
                                    <option value="evaluating_go_no_go">Go / No-Go Evaluation</option>
                                    <option value="active_bidding">Active Bidding (Preparation)</option>
                                    <option value="submitted">Submitted</option>
                                    <option value="under_evaluation">Under Evaluation by Procuring Entity</option>
                                    <option value="awarded">Awarded (Contract Won)</option>
                                    <option value="lost">Lost / Not Awarded</option>
                                    <option value="cancelled">Cancelled by Entity</option>
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Status Notes / Decision Reason
                                </label>
                                <textarea
                                    rows={3}
                                    value={statusReason}
                                    onChange={(e) => setStatusReason(e.target.value)}
                                    placeholder="e.g. Submitted before 10:00 CAT deadline; receipt acknowledged."
                                    className="w-full text-xs border border-slate-300 rounded-lg p-2.5"
                                />
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setStatusModalTender(null)}
                                    className="px-3 py-1.5 text-xs text-slate-600 bg-slate-100 rounded-lg"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    className="px-4 py-1.5 text-xs font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700"
                                >
                                    {submitting ? "Updating..." : "Update Status"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* UPLOAD DOCUMENT MODAL */}
            {uploadDocModalTender && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white rounded-xl shadow-xl max-w-md w-full p-6 space-y-4">
                        <div className="flex items-start justify-between border-b border-slate-100 pb-2">
                            <h3 className="text-base font-bold text-slate-900">Attach Tender Document</h3>
                            <button
                                onClick={() => setUploadDocModalTender(null)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <p className="text-xs text-slate-500">
                            Upload RFP, addendum, or specifications for: <strong>{uploadDocModalTender.reference_number}</strong>
                        </p>
                        <form onSubmit={handleUploadDoc} className="space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Document Classification *
                                </label>
                                <select
                                    value={uploadDocCategory}
                                    onChange={(e) => setUploadDocCategory(e.target.value)}
                                    className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white"
                                >
                                    <option value="tender_rfp">Tender RFP / Bidding Document</option>
                                    <option value="specifications">Technical Specifications / Bills of Quantities</option>
                                    <option value="addendum">Tender Addendum / Notice of Extension</option>
                                    <option value="clarification">Clarification Responses</option>
                                    <option value="bid_submission">Our Prepared Bid Proposal</option>
                                    <option value="other">Other Supporting Attachment</option>
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Select File (PDF, DOCX, XLSX, ZIP, max 50MB) *
                                </label>
                                <input
                                    type="file"
                                    required
                                    onChange={(e) => setUploadFile(e.target.files?.[0] || null)}
                                    className="w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:bg-emerald-50 file:text-emerald-700"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Description / Notes (optional)
                                </label>
                                <input
                                    type="text"
                                    value={uploadDocDesc}
                                    onChange={(e) => setUploadDocDesc(e.target.value)}
                                    placeholder="e.g. Addendum 1 extending closing date to 15 Nov"
                                    className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2"
                                />
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setUploadDocModalTender(null)}
                                    className="px-3 py-1.5 text-xs text-slate-600 bg-slate-100 rounded-lg"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting || !uploadFile}
                                    className="px-4 py-1.5 text-xs font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 disabled:opacity-50"
                                >
                                    {submitting ? "Uploading..." : "Upload Document"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* AI DOCUMENT ANALYSIS MODAL */}
            {analysisModalTender && (
                <TenderAnalysisModal
                    tenderId={analysisModalTender.id}
                    tenderTitle={analysisModalTender.title}
                    tenderRef={analysisModalTender.reference_number}
                    canManage={canManage}
                    onClose={() => setAnalysisModalTender(null)}
                />
            )}

            {/* TECHNICAL COMPLIANCE MATRIX MODAL / DRAWER */}
            {technicalMatrixTender && (
                <TechnicalComplianceMatrix
                    tenderId={technicalMatrixTender.id}
                    tenderTitle={technicalMatrixTender.title}
                    tenderRef={technicalMatrixTender.reference_number}
                    canManage={canManage}
                    onClose={() => setTechnicalMatrixTender(null)}
                />
            )}
        </div>
    );
}

