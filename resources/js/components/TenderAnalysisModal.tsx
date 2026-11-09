import React, { useState, useEffect } from "react";
import axios from "axios";
import {
    Sparkles,
    ShieldAlert,
    CheckCircle2,
    AlertTriangle,
    XCircle,
    Clock,
    FileText,
    Plus,
    X,
    Filter,
    Edit3,
    Trash2,
    HelpCircle,
    Layers,
    ChevronDown,
    ChevronUp,
    ExternalLink,
    Building2,
    DollarSign,
    Check,
} from "lucide-react";

export type AnalysisRequirement = {
    id: number;
    category: string;
    title: string;
    original_wording: string;
    simplified_explanation: string;
    is_mandatory: boolean;
    evidence_required: string | null;
    source_document_name: string | null;
    page_number: number | null;
    verification_status: "pending" | "compliant" | "non_compliant" | "clarification_needed";
    confidence_score: number;
    human_verified: boolean;
    human_notes: string | null;
};

export type GroupedRequirementCategory = {
    category: string;
    label: string;
    count: number;
    mandatory_count: number;
    compliant_count: number;
    items: AnalysisRequirement[];
};

export type AnalysisReportData = {
    tender: {
        id: number;
        reference_number: string;
        title: string;
        procuring_entity: string;
        status: string;
        documents_count: number;
    };
    analysis: {
        id: number;
        status: string;
        extracted_count: number;
        mandatory_count: number;
        statutory_count: number;
        technical_count: number;
        compliance_score: number;
        eligibility_verdict: "eligible" | "conditionally_eligible" | "not_eligible" | "needs_clarification";
        executive_summary: string | null;
        key_risks: string[];
        missing_evidence: string[];
        engine_used: string;
        completed_at: string | null;
    } | null;
    grouped_requirements: GroupedRequirementCategory[];
    total_requirements: number;
};

type Props = {
    tenderId: number;
    tenderTitle: string;
    tenderRef: string;
    canManage: boolean;
    onClose: () => void;
};

export default function TenderAnalysisModal({
    tenderId,
    tenderTitle,
    tenderRef,
    canManage,
    onClose,
}: Props) {
    const [data, setData] = useState<AnalysisReportData | null>(null);
    const [loading, setLoading] = useState(true);
    const [running, setRunning] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [activeCategory, setActiveCategory] = useState<string>("all");
    const [expandedIds, setExpandedIds] = useState<number[]>([]);

    // Edit Requirement Modal State
    const [editingReq, setEditingReq] = useState<AnalysisRequirement | null>(null);
    const [editStatus, setEditStatus] = useState<string>("pending");
    const [editNotes, setEditNotes] = useState<string>("");
    const [savingEdit, setSavingEdit] = useState(false);

    // Add Custom Requirement Modal State
    const [addModalOpen, setAddModalOpen] = useState(false);
    const [newCategory, setNewCategory] = useState("statutory_compliance");
    const [newTitle, setNewTitle] = useState("");
    const [newOriginal, setNewOriginal] = useState("");
    const [newSimplified, setNewSimplified] = useState("");
    const [newIsMandatory, setNewIsMandatory] = useState(true);
    const [newEvidence, setNewEvidence] = useState("");
    const [newNotes, setNewNotes] = useState("");
    const [savingNew, setSavingNew] = useState(false);

    const fetchAnalysis = async () => {
        try {
            setLoading(true);
            const res = await axios.get(`/tenders/${tenderId}/analysis`);
            setData(res.data.data);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to load tender analysis.");
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchAnalysis();
    }, [tenderId]);

    const runAnalysis = async () => {
        if (!canManage) return;
        setRunning(true);
        setError(null);
        try {
            const res = await axios.post(`/tenders/${tenderId}/analysis/run`);
            setData(res.data.data);
            setSuccessMessage("AI Document ingestion and requirement extraction completed.");
            setTimeout(() => setSuccessMessage(null), 5000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Analysis failed to execute.");
        } finally {
            setRunning(false);
        }
    };

    const toggleExpand = (id: number) => {
        setExpandedIds((prev) =>
            prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
        );
    };

    const handleSaveEdit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingReq) return;
        setSavingEdit(true);
        try {
            const res = await axios.patch(`/tenders/requirements/${editingReq.id}`, {
                verification_status: editStatus,
                human_notes: editNotes.trim() || null,
                human_verified: true,
            });
            setData(res.data.data);
            setEditingReq(null);
            setSuccessMessage("Requirement verification updated.");
            setTimeout(() => setSuccessMessage(null), 3000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to update requirement.");
        } finally {
            setSavingEdit(false);
        }
    };

    const handleSaveNew = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!newTitle.trim()) return;
        setSavingNew(true);
        try {
            const res = await axios.post(`/tenders/${tenderId}/requirements`, {
                category: newCategory,
                title: newTitle.trim(),
                original_wording: newOriginal.trim() || newTitle.trim(),
                simplified_explanation: newSimplified.trim() || newTitle.trim(),
                is_mandatory: newIsMandatory,
                evidence_required: newEvidence.trim() || null,
                human_notes: newNotes.trim() || null,
                verification_status: "pending",
            });
            setData(res.data.data);
            setAddModalOpen(false);
            setNewTitle("");
            setNewOriginal("");
            setNewSimplified("");
            setNewEvidence("");
            setNewNotes("");
            setSuccessMessage("Requirement added to matrix.");
            setTimeout(() => setSuccessMessage(null), 3000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to add requirement.");
        } finally {
            setSavingNew(false);
        }
    };

    const handleDeleteReq = async (id: number, title: string) => {
        if (!confirm(`Remove requirement "${title}" from the matrix?`)) return;
        try {
            const res = await axios.delete(`/tenders/requirements/${id}`);
            setData(res.data.data);
            setSuccessMessage("Requirement removed.");
            setTimeout(() => setSuccessMessage(null), 3000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to delete requirement.");
        }
    };

    const renderVerdictBadge = (verdict?: string) => {
        switch (verdict) {
            case "eligible":
                return (
                    <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-900 border border-emerald-300">
                        <CheckCircle2 className="w-4 h-4 text-emerald-600" /> Eligible to Bid
                    </span>
                );
            case "conditionally_eligible":
                return (
                    <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-900 border border-amber-300">
                        <AlertTriangle className="w-4 h-4 text-amber-600" /> Conditionally Eligible
                    </span>
                );
            case "not_eligible":
                return (
                    <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-red-100 text-red-900 border border-red-300">
                        <XCircle className="w-4 h-4 text-red-600" /> Disqualification Risk / Ineligible
                    </span>
                );
            default:
                return (
                    <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-purple-100 text-purple-900 border border-purple-300">
                        <HelpCircle className="w-4 h-4 text-purple-600" /> Needs Clarification / In Review
                    </span>
                );
        }
    };

    const renderStatusBadge = (status: string) => {
        switch (status) {
            case "compliant":
                return (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-100 text-emerald-900 border border-emerald-200">
                        <Check className="w-3 h-3 text-emerald-600" /> Compliant
                    </span>
                );
            case "non_compliant":
                return (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-red-100 text-red-800 border border-red-200">
                        <X className="w-3 h-3 text-red-600" /> Non-Compliant
                    </span>
                );
            case "clarification_needed":
                return (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-purple-100 text-purple-800 border border-purple-200">
                        <HelpCircle className="w-3 h-3 text-purple-600" /> Clarification
                    </span>
                );
            default:
                return (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium bg-amber-50 text-amber-800 border border-amber-200">
                        <Clock className="w-3 h-3 text-amber-600" /> Pending Review
                    </span>
                );
        }
    };

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-3 sm:p-6">
            <div className="bg-white rounded-2xl shadow-2xl max-w-5xl w-full max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                {/* Modal Header */}
                <div className="p-5 sm:p-6 border-b border-slate-200 flex flex-wrap items-start justify-between gap-4 bg-slate-50/70">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-xs font-bold text-emerald-800 bg-emerald-100 px-2 py-0.5 rounded border border-emerald-200">
                                {tenderRef}
                            </span>
                            <span className="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 bg-indigo-100 text-indigo-900 rounded border border-indigo-200">
                                <Sparkles className="w-3 h-3 text-indigo-600" /> AI Document Assistant
                            </span>
                            {data?.analysis && renderVerdictBadge(data.analysis.eligibility_verdict)}
                        </div>
                        <h2 className="text-lg font-bold text-slate-900">{tenderTitle}</h2>
                        <p className="text-xs text-slate-500">
                            Automated extraction and statutory compliance assessment against your Company Vault.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        {canManage && (
                            <button
                                onClick={runAnalysis}
                                disabled={running}
                                className="inline-flex items-center gap-1.5 px-3.5 py-2 bg-indigo-600 text-white rounded-lg text-xs font-semibold hover:bg-indigo-700 shadow-sm transition-all disabled:opacity-50"
                            >
                                <Sparkles className={`w-3.5 h-3.5 ${running ? "animate-spin" : ""}`} />
                                {running ? "Analyzing Documents..." : data?.analysis ? "Re-Run Analysis" : "Run AI Analysis"}
                            </button>
                        )}
                        <button
                            onClick={onClose}
                            className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-200"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {/* Notifications */}
                {error && (
                    <div className="m-4 p-3 bg-red-50 border border-red-200 text-red-800 rounded-lg text-xs flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <AlertTriangle className="w-4 h-4 text-red-600 shrink-0" />
                            <span>{error}</span>
                        </div>
                        <button onClick={() => setError(null)}><X className="w-3.5 h-3.5" /></button>
                    </div>
                )}

                {successMessage && (
                    <div className="m-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg text-xs flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                            <span>{successMessage}</span>
                        </div>
                        <button onClick={() => setSuccessMessage(null)}><X className="w-3.5 h-3.5" /></button>
                    </div>
                )}

                {/* Modal Body */}
                <div className="p-6 overflow-y-auto space-y-6 flex-1">
                    {loading && !data ? (
                        <div className="py-16 text-center text-slate-500 space-y-2">
                            <Clock className="w-8 h-8 animate-spin mx-auto text-indigo-600" />
                            <p className="text-sm font-medium">Extracting and mapping tender requirements...</p>
                        </div>
                    ) : !data?.analysis ? (
                        <div className="py-12 text-center bg-slate-50 rounded-xl border border-dashed border-slate-300 p-8 space-y-3">
                            <Sparkles className="w-10 h-10 text-indigo-400 mx-auto" />
                            <h3 className="text-base font-bold text-slate-800">No AI analysis run on this tender yet</h3>
                            <p className="text-xs text-slate-500 max-w-md mx-auto">
                                Click "Run AI Analysis" above to ingest all attached RFP documents, extract statutory compliance rules,
                                compile the technical compliance matrix, and verify against your Company Compliance Vault.
                            </p>
                            {canManage && (
                                <button
                                    onClick={runAnalysis}
                                    disabled={running}
                                    className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg text-xs font-semibold hover:bg-indigo-700 shadow-sm"
                                >
                                    <Sparkles className="w-4 h-4" /> Start AI Extraction
                                </button>
                            )}
                        </div>
                    ) : (
                        <>
                            {/* Analysis Executive Summary Cards */}
                            <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
                                <div className="bg-slate-50 p-3 rounded-xl border border-slate-200">
                                    <span className="text-[11px] font-semibold text-slate-500 uppercase tracking-wider block">
                                        Requirements
                                    </span>
                                    <div className="text-xl font-bold text-slate-900 mt-1">
                                        {data.analysis.extracted_count}
                                    </div>
                                    <span className="text-[10px] text-slate-500 block">Total identified</span>
                                </div>

                                <div className="bg-red-50/70 p-3 rounded-xl border border-red-200">
                                    <span className="text-[11px] font-semibold text-red-700 uppercase tracking-wider block">
                                        Mandatory
                                    </span>
                                    <div className="text-xl font-bold text-red-900 mt-1">
                                        {data.analysis.mandatory_count}
                                    </div>
                                    <span className="text-[10px] text-red-600 block">Disqualifying if absent</span>
                                </div>

                                <div className="bg-blue-50/70 p-3 rounded-xl border border-blue-200">
                                    <span className="text-[11px] font-semibold text-blue-700 uppercase tracking-wider block">
                                        Statutory
                                    </span>
                                    <div className="text-xl font-bold text-blue-900 mt-1">
                                        {data.analysis.statutory_count}
                                    </div>
                                    <span className="text-[10px] text-blue-600 block">PRAZ, ZIMRA, NSSA</span>
                                </div>

                                <div className="bg-indigo-50/70 p-3 rounded-xl border border-indigo-200">
                                    <span className="text-[11px] font-semibold text-indigo-700 uppercase tracking-wider block">
                                        Technical Specs
                                    </span>
                                    <div className="text-xl font-bold text-indigo-900 mt-1">
                                        {data.analysis.technical_count}
                                    </div>
                                    <span className="text-[10px] text-indigo-600 block">Conformity matrices</span>
                                </div>

                                <div className="bg-emerald-50/80 p-3 rounded-xl border border-emerald-200">
                                    <span className="text-[11px] font-semibold text-emerald-800 uppercase tracking-wider block">
                                        Vault Score
                                    </span>
                                    <div className="text-xl font-bold text-emerald-900 mt-1">
                                        {data.analysis.compliance_score}%
                                    </div>
                                    <span className="text-[10px] text-emerald-700 block">Statutory matched</span>
                                </div>
                            </div>

                            {/* Critical Risks / Gaps Alerts */}
                            {data.analysis.key_risks.length > 0 && (
                                <div className="bg-red-50 border border-red-200 rounded-xl p-4 space-y-2">
                                    <div className="flex items-center gap-2 text-xs font-bold text-red-900 uppercase tracking-wider">
                                        <AlertTriangle className="w-4 h-4 text-red-600" />
                                        Disqualification Risks Detected ({data.analysis.key_risks.length})
                                    </div>
                                    <ul className="text-xs text-red-800 space-y-1 list-disc list-inside">
                                        {data.analysis.key_risks.map((risk, idx) => (
                                            <li key={idx} className="font-medium">{risk}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {/* Category Filter Pills & Add Action */}
                            <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <button
                                        onClick={() => setActiveCategory("all")}
                                        className={`px-3 py-1 rounded-lg text-xs font-semibold transition-colors ${
                                            activeCategory === "all"
                                                ? "bg-slate-900 text-white"
                                                : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                                        }`}
                                    >
                                        All Requirements ({data.total_requirements})
                                    </button>
                                    {data.grouped_requirements.map((grp) => (
                                        <button
                                            key={grp.category}
                                            onClick={() => setActiveCategory(grp.category)}
                                            className={`px-3 py-1 rounded-lg text-xs font-semibold transition-colors ${
                                                activeCategory === grp.category
                                                    ? "bg-indigo-600 text-white"
                                                    : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                                            }`}
                                        >
                                            {grp.label} ({grp.count})
                                        </button>
                                    ))}
                                </div>

                                {canManage && (
                                    <button
                                        onClick={() => setAddModalOpen(true)}
                                        className="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg hover:bg-emerald-100"
                                    >
                                        <Plus className="w-3.5 h-3.5" /> Add Requirement
                                    </button>
                                )}
                            </div>

                            {/* Requirements Matrix */}
                            <div className="space-y-4">
                                {data.grouped_requirements
                                    .filter((grp) => activeCategory === "all" || grp.category === activeCategory)
                                    .map((grp) => (
                                        <div key={grp.category} className="space-y-2">
                                            <div className="flex items-center justify-between border-b border-slate-200 pb-1.5">
                                                <h4 className="text-xs font-bold uppercase tracking-wider text-slate-800 flex items-center gap-2">
                                                    <Layers className="w-3.5 h-3.5 text-indigo-600" />
                                                    {grp.label}
                                                    <span className="text-[11px] font-normal text-slate-500">
                                                        ({grp.compliant_count} of {grp.count} verified)
                                                    </span>
                                                </h4>
                                            </div>

                                            <div className="divide-y divide-slate-100 border border-slate-200 rounded-xl overflow-hidden bg-white shadow-sm">
                                                {grp.items.map((item) => {
                                                    const isExpanded = expandedIds.includes(item.id);
                                                    return (
                                                        <div key={item.id} className="p-4 space-y-2 hover:bg-slate-50/50 transition-colors">
                                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                                <div className="space-y-1 max-w-2xl">
                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        {item.is_mandatory ? (
                                                                            <span className="text-[10px] font-bold px-1.5 py-0.2 rounded bg-red-100 text-red-800 border border-red-200">
                                                                                MANDATORY
                                                                            </span>
                                                                        ) : (
                                                                            <span className="text-[10px] font-semibold px-1.5 py-0.2 rounded bg-slate-100 text-slate-600">
                                                                                OPTIONAL
                                                                            </span>
                                                                        )}
                                                                        {renderStatusBadge(item.verification_status)}
                                                                        <span className="text-[10px] text-slate-400">
                                                                            {item.confidence_score}% confidence
                                                                        </span>
                                                                        {item.human_verified && (
                                                                            <span className="text-[10px] text-emerald-700 font-semibold flex items-center gap-0.5">
                                                                                <Check className="w-2.5 h-2.5" /> Reviewed
                                                                            </span>
                                                                        )}
                                                                    </div>

                                                                    <h5 className="text-sm font-bold text-slate-900">
                                                                        {item.title}
                                                                    </h5>

                                                                    <p className="text-xs text-slate-600 leading-relaxed">
                                                                        {item.simplified_explanation}
                                                                    </p>

                                                                    {item.evidence_required && (
                                                                        <div className="text-[11px] text-slate-500">
                                                                            <strong className="text-slate-700">Evidence Required:</strong>{" "}
                                                                            {item.evidence_required}
                                                                        </div>
                                                                    )}
                                                                </div>

                                                                <div className="flex items-center gap-1 shrink-0">
                                                                    {canManage && (
                                                                        <button
                                                                            onClick={() => {
                                                                                setEditingReq(item);
                                                                                setEditStatus(item.verification_status);
                                                                                setEditNotes(item.human_notes || "");
                                                                            }}
                                                                            className="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded"
                                                                            title="Edit verification"
                                                                        >
                                                                            <Edit3 className="w-3.5 h-3.5" />
                                                                        </button>
                                                                    )}
                                                                    {canManage && (
                                                                        <button
                                                                            onClick={() => handleDeleteReq(item.id, item.title)}
                                                                            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded"
                                                                            title="Delete requirement"
                                                                        >
                                                                            <Trash2 className="w-3.5 h-3.5" />
                                                                        </button>
                                                                    )}
                                                                    <button
                                                                        onClick={() => toggleExpand(item.id)}
                                                                        className="p-1.5 text-slate-400 hover:text-slate-700 rounded"
                                                                        title="View document citation"
                                                                    >
                                                                        {isExpanded ? <ChevronUp className="w-3.5 h-3.5" /> : <ChevronDown className="w-3.5 h-3.5" />}
                                                                    </button>
                                                                </div>
                                                            </div>

                                                            {/* Document citation & Notes Drawer */}
                                                            {isExpanded && (
                                                                <div className="p-3 bg-slate-50 rounded-lg border border-slate-200 text-xs space-y-2 mt-2">
                                                                    <div>
                                                                        <span className="font-semibold text-slate-500 text-[11px] block">
                                                                            Original RFP Wording:
                                                                        </span>
                                                                        <blockquote className="italic text-slate-700 pl-2 border-l-2 border-indigo-400 mt-1 whitespace-pre-wrap">
                                                                            "{item.original_wording}"
                                                                        </blockquote>
                                                                    </div>
                                                                    <div className="flex flex-wrap items-center gap-4 text-[11px] text-slate-500 pt-1 border-t border-slate-200">
                                                                        <span>Source Document: <strong>{item.source_document_name}</strong></span>
                                                                        {item.page_number && (
                                                                            <span>Page: <strong>{item.page_number}</strong></span>
                                                                        )}
                                                                    </div>
                                                                    {item.human_notes && (
                                                                        <div className="bg-amber-50 p-2 rounded text-[11px] text-amber-900 border border-amber-200">
                                                                            <strong>Human Reviewer Note:</strong> {item.human_notes}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    ))}
                            </div>
                        </>
                    )}
                </div>

                {/* EDIT REQUIREMENT MODAL */}
                {editingReq && (
                    <div className="fixed inset-0 z-60 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
                        <div className="bg-white rounded-xl shadow-xl max-w-md w-full p-6 space-y-4">
                            <div className="flex items-start justify-between border-b border-slate-100 pb-2">
                                <h3 className="text-base font-bold text-slate-900">Review Requirement</h3>
                                <button onClick={() => setEditingReq(null)}><X className="w-4 h-4 text-slate-400" /></button>
                            </div>
                            <p className="text-xs text-slate-600 font-semibold">{editingReq.title}</p>
                            <form onSubmit={handleSaveEdit} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Verification Status *
                                    </label>
                                    <select
                                        value={editStatus}
                                        onChange={(e) => setEditStatus(e.target.value)}
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2 bg-white"
                                    >
                                        <option value="compliant">Compliant (Verified & Ready)</option>
                                        <option value="pending">Pending (Awaiting Document/Review)</option>
                                        <option value="clarification_needed">Clarification Needed from Procuring Entity</option>
                                        <option value="non_compliant">Non-Compliant (Missing or Non-conforming)</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Reviewer Notes / Evidence Reference
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={editNotes}
                                        onChange={(e) => setEditNotes(e.target.value)}
                                        placeholder="e.g. Verified with Finance department; accounts ready."
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2"
                                    />
                                </div>
                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setEditingReq(null)}
                                        className="px-3 py-1.5 text-xs text-slate-600 bg-slate-100 rounded-lg"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={savingEdit}
                                        className="px-4 py-1.5 text-xs font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700"
                                    >
                                        {savingEdit ? "Saving..." : "Save Review"}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* ADD REQUIREMENT MODAL */}
                {addModalOpen && (
                    <div className="fixed inset-0 z-60 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
                        <div className="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 space-y-4">
                            <div className="flex items-start justify-between border-b border-slate-100 pb-2">
                                <h3 className="text-base font-bold text-slate-900">Add Requirement to Matrix</h3>
                                <button onClick={() => setAddModalOpen(false)}><X className="w-4 h-4 text-slate-400" /></button>
                            </div>
                            <form onSubmit={handleSaveNew} className="space-y-3">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Category *
                                    </label>
                                    <select
                                        value={newCategory}
                                        onChange={(e) => setNewCategory(e.target.value)}
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2 bg-white"
                                    >
                                        <option value="statutory_compliance">Statutory & Legal Compliance</option>
                                        <option value="administrative">Administrative & Eligibility</option>
                                        <option value="technical_specification">Technical Specifications</option>
                                        <option value="financial">Financial & Banking</option>
                                        <option value="bid_security">Bid Security & Bonds</option>
                                        <option value="experience">Previous Experience & References</option>
                                        <option value="personnel">Key Personnel & Qualifications</option>
                                        <option value="equipment">Equipment & Plant</option>
                                        <option value="insurance">Insurance Requirements</option>
                                        <option value="submission_instruction">Submission Instructions</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Requirement Title *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        value={newTitle}
                                        onChange={(e) => setNewTitle(e.target.value)}
                                        placeholder="e.g. ISO 9001:2015 Quality Management Certification"
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Original RFP Clause Wording
                                    </label>
                                    <textarea
                                        rows={2}
                                        value={newOriginal}
                                        onChange={(e) => setNewOriginal(e.target.value)}
                                        placeholder="Exact extract from tender document..."
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Evidence Required
                                    </label>
                                    <input
                                        type="text"
                                        value={newEvidence}
                                        onChange={(e) => setNewEvidence(e.target.value)}
                                        placeholder="e.g. Valid certified copy of ISO certificate"
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2"
                                    />
                                </div>
                                <div className="flex items-center gap-2 pt-1">
                                    <input
                                        type="checkbox"
                                        id="new_mand"
                                        checked={newIsMandatory}
                                        onChange={(e) => setNewIsMandatory(e.target.checked)}
                                        className="rounded text-indigo-600"
                                    />
                                    <label htmlFor="new_mand" className="text-xs font-semibold text-slate-800 cursor-pointer">
                                        Mandatory (Non-compliance disqualifies bid)
                                    </label>
                                </div>
                                <div className="flex justify-end gap-2 pt-3 border-t border-slate-100">
                                    <button
                                        type="button"
                                        onClick={() => setAddModalOpen(false)}
                                        className="px-3 py-1.5 text-xs text-slate-600 bg-slate-100 rounded-lg"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={savingNew}
                                        className="px-4 py-1.5 text-xs font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700"
                                    >
                                        {savingNew ? "Adding..." : "Add Requirement"}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

