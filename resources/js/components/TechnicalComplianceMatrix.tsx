import React, { useState, useEffect } from "react";
import axios from "axios";
import {
    Layers,
    CheckCircle2,
    AlertTriangle,
    XCircle,
    HelpCircle,
    Plus,
    X,
    Download,
    Edit3,
    Trash2,
    RefreshCw,
    Clock,
    FileText,
    Check,
    Search,
    ChevronDown,
    ChevronUp,
    ExternalLink,
    AlertCircle,
    FileSpreadsheet,
    Sparkles,
} from "lucide-react";

export type TechnicalSpec = {
    id: number;
    tender_id: number;
    item_number: string;
    title: string;
    original_specification: string;
    plain_language_explanation: string | null;
    purpose: string | null;
    is_mandatory: boolean;
    required_evidence: string | null;
    suggested_solution: string | null;
    alternative_solutions: string | null;
    compliance_status: "comply" | "comply_with_deviation" | "alternative_offered" | "not_comply" | "needs_clarification";
    bidder_offered_specification: string | null;
    deviation_statement: string | null;
    evidence_reference: string | null;
    human_verified: boolean;
    order_index: number;
};

export type TechnicalSpecSummary = {
    total_specs: number;
    comply_count: number;
    deviation_count: number;
    alternative_count: number;
    non_comply_count: number;
    clarification_count: number;
    compliance_percentage: number;
    mandatory_deviations: {
        item_number: string;
        title: string;
        compliance_status: string;
        deviation_statement: string;
    }[];
    has_disqualifying_non_compliance: boolean;
};

export type TechnicalSpecResponse = {
    tender: {
        id: number;
        reference_number: string;
        title: string;
        procuring_entity: string;
        status: string;
    };
    specs: TechnicalSpec[];
    summary: TechnicalSpecSummary;
};

type Props = {
    tenderId: number;
    tenderTitle: string;
    tenderRef: string;
    canManage: boolean;
    onClose: () => void;
};

export default function TechnicalComplianceMatrix({
    tenderId,
    tenderTitle,
    tenderRef,
    canManage,
    onClose,
}: Props) {
    const [data, setData] = useState<TechnicalSpecResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [generating, setGenerating] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [filterStatus, setFilterStatus] = useState<string>("all");
    const [search, setSearch] = useState("");
    const [expandedIds, setExpandedIds] = useState<number[]>([]);

    // Edit modal state
    const [editingSpec, setEditingSpec] = useState<TechnicalSpec | null>(null);
    const [editStatus, setEditStatus] = useState<string>("comply");
    const [editOffered, setEditOffered] = useState<string>("");
    const [editDeviation, setEditDeviation] = useState<string>("");
    const [editEvidence, setEditEvidence] = useState<string>("");
    const [savingEdit, setSavingEdit] = useState(false);

    // Add modal state
    const [addModalOpen, setAddModalOpen] = useState(false);
    const [newNumber, setNewNumber] = useState("");
    const [newTitle, setNewTitle] = useState("");
    const [newSpec, setNewSpec] = useState("");
    const [newExplanation, setNewExplanation] = useState("");
    const [newPurpose, setNewPurpose] = useState("");
    const [newMandatory, setNewMandatory] = useState(true);
    const [newEvidence, setNewEvidence] = useState("");
    const [newSolution, setNewSolution] = useState("");
    const [newStatus, setNewStatus] = useState("comply");
    const [savingNew, setSavingNew] = useState(false);

    const fetchSpecs = async () => {
        try {
            setLoading(true);
            const res = await axios.get(`/tenders/${tenderId}/technical-specs`);
            setData(res.data.data);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to load technical specifications.");
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchSpecs();
    }, [tenderId]);

    const handleGenerate = async () => {
        if (!canManage) return;
        setGenerating(true);
        setError(null);
        try {
            const res = await axios.post(`/tenders/${tenderId}/technical-specs/generate`);
            setData(res.data.data);
            setSuccessMessage("Clause-by-clause technical matrix generated from tender requirements.");
            setTimeout(() => setSuccessMessage(null), 4000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to generate technical specifications.");
        } finally {
            setGenerating(false);
        }
    };

    const toggleExpand = (id: number) => {
        setExpandedIds((prev) =>
            prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
        );
    };

    const handleSaveEdit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingSpec) return;
        setSavingEdit(true);
        try {
            const res = await axios.patch(`/tenders/technical-specs/${editingSpec.id}`, {
                compliance_status: editStatus,
                bidder_offered_specification: editOffered.trim() || null,
                deviation_statement: editDeviation.trim() || null,
                evidence_reference: editEvidence.trim() || null,
                human_verified: true,
            });
            setData(res.data.data);
            setEditingSpec(null);
            setSuccessMessage("Technical specification clause updated.");
            setTimeout(() => setSuccessMessage(null), 3000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to update specification.");
        } finally {
            setSavingEdit(false);
        }
    };

    const handleSaveNew = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!newTitle.trim() || !newSpec.trim()) return;
        setSavingNew(true);
        try {
            const res = await axios.post(`/tenders/${tenderId}/technical-specs`, {
                item_number: newNumber.trim() || undefined,
                title: newTitle.trim(),
                original_specification: newSpec.trim(),
                plain_language_explanation: newExplanation.trim() || null,
                purpose: newPurpose.trim() || null,
                is_mandatory: newMandatory,
                required_evidence: newEvidence.trim() || null,
                suggested_solution: newSolution.trim() || null,
                bidder_offered_specification: newSolution.trim() || null,
                compliance_status: newStatus,
            });
            setData(res.data.data);
            setAddModalOpen(false);
            setNewNumber("");
            setNewTitle("");
            setNewSpec("");
            setNewExplanation("");
            setNewPurpose("");
            setNewEvidence("");
            setNewSolution("");
            setSuccessMessage("Technical clause added to matrix.");
            setTimeout(() => setSuccessMessage(null), 3000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to add specification.");
        } finally {
            setSavingNew(false);
        }
    };

    const handleDeleteSpec = async (id: number, title: string) => {
        if (!confirm(`Delete technical clause "${title}"?`)) return;
        try {
            const res = await axios.delete(`/tenders/technical-specs/${id}`);
            setData(res.data.data);
            setSuccessMessage("Clause removed.");
            setTimeout(() => setSuccessMessage(null), 3000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to delete clause.");
        }
    };

    const renderComplianceBadge = (status: string) => {
        switch (status) {
            case "comply":
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-900 border border-emerald-300">
                        <Check className="w-3.5 h-3.5 text-emerald-600" /> Fully Complies
                    </span>
                );
            case "comply_with_deviation":
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-900 border border-amber-300">
                        <AlertTriangle className="w-3.5 h-3.5 text-amber-600" /> Comply with Deviation
                    </span>
                );
            case "alternative_offered":
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-100 text-blue-900 border border-blue-300">
                        <Sparkles className="w-3.5 h-3.5 text-blue-600" /> Alternative Solution
                    </span>
                );
            case "not_comply":
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-900 border border-red-300">
                        <X className="w-3.5 h-3.5 text-red-600" /> Does Not Comply
                    </span>
                );
            default:
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-900 border border-purple-300">
                        <HelpCircle className="w-3.5 h-3.5 text-purple-600" /> Clarification Needed
                    </span>
                );
        }
    };

    const filteredSpecs = (data?.specs ?? []).filter((s) => {
        if (filterStatus !== "all" && s.compliance_status !== filterStatus) return false;
        if (search.trim()) {
            const q = search.toLowerCase();
            return (
                s.item_number.toLowerCase().includes(q) ||
                s.title.toLowerCase().includes(q) ||
                s.original_specification.toLowerCase().includes(q) ||
                (s.bidder_offered_specification && s.bidder_offered_specification.toLowerCase().includes(q))
            );
        }
        return true;
    });

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-3 sm:p-6">
            <div className="bg-white rounded-2xl shadow-2xl max-w-6xl w-full max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                {/* Header */}
                <div className="p-5 sm:p-6 border-b border-slate-200 flex flex-wrap items-start justify-between gap-4 bg-slate-50/80">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-xs font-bold text-emerald-800 bg-emerald-100 px-2 py-0.5 rounded border border-emerald-200">
                                {tenderRef}
                            </span>
                            <span className="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 bg-blue-100 text-blue-900 rounded border border-blue-200">
                                <Layers className="w-3.5 h-3.5 text-blue-600" /> Technical Compliance Matrix
                            </span>
                        </div>
                        <h2 className="text-lg font-bold text-slate-900">{tenderTitle}</h2>
                        <p className="text-xs text-slate-500">
                            Clause-by-clause technical schedule verifying adherence to Procuring Entity specifications and recording deviations.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {data?.specs && data.specs.length > 0 && (
                            <a
                                href={`/tenders/${tenderId}/technical-specs/export`}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1.5 px-3 py-2 bg-white text-slate-700 border border-slate-300 rounded-lg text-xs font-semibold hover:bg-slate-50 shadow-sm transition-all"
                            >
                                <Download className="w-3.5 h-3.5 text-slate-500" />
                                Export Schedule (CSV)
                            </a>
                        )}

                        {canManage && (
                            <button
                                onClick={handleGenerate}
                                disabled={generating}
                                className="inline-flex items-center gap-1.5 px-3.5 py-2 bg-indigo-600 text-white rounded-lg text-xs font-semibold hover:bg-indigo-700 shadow-sm transition-all disabled:opacity-50"
                            >
                                <RefreshCw className={`w-3.5 h-3.5 ${generating ? "animate-spin" : ""}`} />
                                {generating ? "Generating..." : data?.specs && data.specs.length > 0 ? "Re-Sync from RFP" : "Generate Matrix"}
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

                {/* Alerts */}
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

                {/* Body */}
                <div className="p-6 overflow-y-auto space-y-6 flex-1">
                    {loading && !data ? (
                        <div className="py-16 text-center text-slate-500 space-y-2">
                            <Clock className="w-8 h-8 animate-spin mx-auto text-blue-600" />
                            <p className="text-sm font-medium">Loading Technical Compliance Matrix...</p>
                        </div>
                    ) : !data?.specs || data.specs.length === 0 ? (
                        <div className="py-12 text-center bg-slate-50 rounded-xl border border-dashed border-slate-300 p-8 space-y-3">
                            <Layers className="w-10 h-10 text-blue-400 mx-auto" />
                            <h3 className="text-base font-bold text-slate-800">No technical compliance matrix generated yet</h3>
                            <p className="text-xs text-slate-500 max-w-md mx-auto">
                                Automatically compile the clause-by-clause technical schedule from the extracted RFP requirements or add custom clauses manually.
                            </p>
                            {canManage && (
                                <button
                                    onClick={handleGenerate}
                                    disabled={generating}
                                    className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg text-xs font-semibold hover:bg-indigo-700 shadow-sm"
                                >
                                    <Sparkles className="w-4 h-4" /> Generate Technical Matrix
                                </button>
                            )}
                        </div>
                    ) : (
                        <>
                            {/* Summary KPI Strip */}
                            <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
                                <div className="bg-slate-50 p-3 rounded-xl border border-slate-200">
                                    <span className="text-[11px] font-semibold text-slate-500 uppercase tracking-wider block">
                                        Total Clauses
                                    </span>
                                    <div className="text-xl font-bold text-slate-900 mt-1">
                                        {data.summary.total_specs}
                                    </div>
                                    <span className="text-[10px] text-slate-500 block">Technical parameters</span>
                                </div>

                                <div className="bg-emerald-50/80 p-3 rounded-xl border border-emerald-200">
                                    <span className="text-[11px] font-semibold text-emerald-800 uppercase tracking-wider block">
                                        Conformity Rate
                                    </span>
                                    <div className="text-xl font-bold text-emerald-900 mt-1">
                                        {data.summary.compliance_percentage}%
                                    </div>
                                    <span className="text-[10px] text-emerald-700 block">Overall compliance</span>
                                </div>

                                <div className="bg-emerald-50/80 p-3 rounded-xl border border-emerald-200">
                                    <span className="text-[11px] font-semibold text-emerald-800 uppercase tracking-wider block">
                                        Full Comply
                                    </span>
                                    <div className="text-xl font-bold text-emerald-900 mt-1">
                                        {data.summary.comply_count}
                                    </div>
                                    <span className="text-[10px] text-emerald-700 block">Exact conformity</span>
                                </div>

                                <div className="bg-amber-50/80 p-3 rounded-xl border border-amber-200">
                                    <span className="text-[11px] font-semibold text-amber-800 uppercase tracking-wider block">
                                        Deviations
                                    </span>
                                    <div className="text-xl font-bold text-amber-900 mt-1">
                                        {data.summary.deviation_count}
                                    </div>
                                    <span className="text-[10px] text-amber-700 block">Minor differences</span>
                                </div>

                                <div className="bg-red-50/70 p-3 rounded-xl border border-red-200">
                                    <span className="text-[11px] font-semibold text-red-700 uppercase tracking-wider block">
                                        Non-Compliant
                                    </span>
                                    <div className="text-xl font-bold text-red-900 mt-1">
                                        {data.summary.non_comply_count}
                                    </div>
                                    <span className="text-[10px] text-red-600 block">Does not comply</span>
                                </div>
                            </div>

                            {/* Mandatory Deviation Alert Banner */}
                            {data.summary.mandatory_deviations.length > 0 && (
                                <div className="bg-amber-50 border border-amber-200 rounded-xl p-4 space-y-2">
                                    <div className="flex items-center gap-2 text-xs font-bold text-amber-900 uppercase tracking-wider">
                                        <AlertTriangle className="w-4 h-4 text-amber-600" />
                                        Mandatory Specification Deviations ({data.summary.mandatory_deviations.length})
                                    </div>
                                    <p className="text-xs text-amber-800">
                                        Under PRAZ regulations, deviations on mandatory clauses require explicit technical justification to avoid rejection during preliminary evaluation.
                                    </p>
                                    <div className="divide-y divide-amber-200/60 pt-1">
                                        {data.summary.mandatory_deviations.map((m, idx) => (
                                            <div key={idx} className="py-1.5 text-xs text-amber-900 flex items-start justify-between gap-2">
                                                <div>
                                                    <span className="font-mono font-bold">{m.item_number}:</span> {m.title}
                                                    <p className="text-[11px] text-amber-800 italic mt-0.5">"{m.deviation_statement}"</p>
                                                </div>
                                                <span className="uppercase text-[10px] font-bold px-1.5 py-0.5 rounded bg-amber-200/80">
                                                    {m.compliance_status.replace(/_/g, " ")}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Filter and Search Bar */}
                            <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <div className="relative min-w-[200px]">
                                        <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
                                        <input
                                            type="text"
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            placeholder="Search technical clauses..."
                                            className="w-full pl-8 pr-3 py-1.5 text-xs border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                        />
                                    </div>

                                    <select
                                        value={filterStatus}
                                        onChange={(e) => setFilterStatus(e.target.value)}
                                        className="text-xs border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white text-slate-700"
                                    >
                                        <option value="all">All Compliance Statuses</option>
                                        <option value="comply">Fully Complies</option>
                                        <option value="comply_with_deviation">Comply with Deviation</option>
                                        <option value="alternative_offered">Alternative Solution</option>
                                        <option value="not_comply">Does Not Comply</option>
                                        <option value="needs_clarification">Clarification Needed</option>
                                    </select>
                                </div>

                                {canManage && (
                                    <button
                                        onClick={() => setAddModalOpen(true)}
                                        className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100"
                                    >
                                        <Plus className="w-3.5 h-3.5" /> Add Technical Clause
                                    </button>
                                )}
                            </div>

                            {/* Technical Specifications Matrix Rows */}
                            <div className="space-y-3">
                                {filteredSpecs.length === 0 ? (
                                    <div className="p-8 text-center text-xs text-slate-500 bg-slate-50 rounded-xl border border-slate-200">
                                        No specifications match your search filter.
                                    </div>
                                ) : (
                                    filteredSpecs.map((spec) => {
                                        const isExpanded = expandedIds.includes(spec.id);
                                        return (
                                            <div
                                                key={spec.id}
                                                className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm hover:border-blue-300 transition-all space-y-3"
                                            >
                                                <div className="flex flex-wrap items-start justify-between gap-3">
                                                    <div className="space-y-1 max-w-3xl">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-mono text-xs font-bold text-blue-800 bg-blue-50 px-2 py-0.5 rounded border border-blue-200">
                                                                {spec.item_number}
                                                            </span>
                                                            {spec.is_mandatory ? (
                                                                <span className="text-[10px] font-bold px-1.5 py-0.2 rounded bg-red-100 text-red-800 border border-red-200">
                                                                    MANDATORY
                                                                </span>
                                                            ) : (
                                                                <span className="text-[10px] font-semibold px-1.5 py-0.2 rounded bg-slate-100 text-slate-600">
                                                                    OPTIONAL
                                                                </span>
                                                            )}
                                                            {renderComplianceBadge(spec.compliance_status)}
                                                            {spec.human_verified && (
                                                                <span className="text-[10px] text-emerald-700 font-semibold flex items-center gap-0.5">
                                                                    <Check className="w-2.5 h-2.5" /> Verified
                                                                </span>
                                                            )}
                                                        </div>

                                                        <h4 className="text-sm font-bold text-slate-900">
                                                            {spec.title}
                                                        </h4>

                                                        <div className="text-xs text-slate-700 bg-slate-50/70 p-2.5 rounded-lg border border-slate-200/80">
                                                            <strong className="text-slate-800 block text-[11px]">Procuring Entity Specification:</strong>
                                                            <p className="mt-0.5 leading-relaxed">{spec.original_specification}</p>
                                                        </div>

                                                        <div className="text-xs text-blue-900 bg-blue-50/50 p-2.5 rounded-lg border border-blue-200/80 space-y-1">
                                                            <strong className="text-blue-950 block text-[11px]">Bidder Offered Specification:</strong>
                                                            <p className="font-medium">{spec.bidder_offered_specification || spec.suggested_solution || "Offered specification not yet populated."}</p>
                                                            {spec.deviation_statement && (
                                                                <div className="pt-1 text-[11px] text-amber-900 border-t border-blue-200/60 mt-1">
                                                                    <strong className="text-amber-950">Deviation Statement:</strong> {spec.deviation_statement}
                                                                </div>
                                                            )}
                                                            {spec.evidence_reference && (
                                                                <div className="text-[11px] text-slate-500">
                                                                    <strong>Evidence / Page Citation:</strong> {spec.evidence_reference}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>

                                                    <div className="flex items-center gap-1 shrink-0">
                                                        {canManage && (
                                                            <button
                                                                onClick={() => {
                                                                    setEditingSpec(spec);
                                                                    setEditStatus(spec.compliance_status);
                                                                    setEditOffered(spec.bidder_offered_specification || spec.suggested_solution || "");
                                                                    setEditDeviation(spec.deviation_statement || "");
                                                                    setEditEvidence(spec.evidence_reference || "");
                                                                }}
                                                                className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded"
                                                                title="Edit offered solution & deviation"
                                                            >
                                                                <Edit3 className="w-3.5 h-3.5" />
                                                            </button>
                                                        )}

                                                        {canManage && (
                                                            <button
                                                                onClick={() => handleDeleteSpec(spec.id, spec.title)}
                                                                className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded"
                                                                title="Delete clause"
                                                            >
                                                                <Trash2 className="w-3.5 h-3.5" />
                                                            </button>
                                                        )}

                                                        <button
                                                            onClick={() => toggleExpand(spec.id)}
                                                            className="p-1.5 text-slate-400 hover:text-slate-700 rounded"
                                                            title="Toggle explanation & purpose"
                                                        >
                                                            {isExpanded ? <ChevronUp className="w-3.5 h-3.5" /> : <ChevronDown className="w-3.5 h-3.5" />}
                                                        </button>
                                                    </div>
                                                </div>

                                                {/* Expanded Details Drawer */}
                                                {isExpanded && (
                                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3 bg-slate-50 rounded-lg border border-slate-200 text-xs">
                                                        {spec.plain_language_explanation && (
                                                            <div>
                                                                <span className="font-semibold text-slate-600 block text-[11px]">Plain-Language Meaning:</span>
                                                                <p className="text-slate-700 mt-0.5">{spec.plain_language_explanation}</p>
                                                            </div>
                                                        )}
                                                        {spec.purpose && (
                                                            <div>
                                                                <span className="font-semibold text-slate-600 block text-[11px]">Requirement Purpose:</span>
                                                                <p className="text-slate-700 mt-0.5">{spec.purpose}</p>
                                                            </div>
                                                        )}
                                                        {spec.required_evidence && (
                                                            <div>
                                                                <span className="font-semibold text-slate-600 block text-[11px]">Required Evidence / Standards:</span>
                                                                <p className="text-slate-700 mt-0.5">{spec.required_evidence}</p>
                                                            </div>
                                                        )}
                                                        {spec.alternative_solutions && (
                                                            <div>
                                                                <span className="font-semibold text-slate-600 block text-[11px]">Permitted Alternatives:</span>
                                                                <p className="text-slate-700 mt-0.5">{spec.alternative_solutions}</p>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })
                                )}
                            </div>
                        </>
                    )}
                </div>

                {/* EDIT CLAUSE MODAL */}
                {editingSpec && (
                    <div className="fixed inset-0 z-60 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
                        <div className="bg-white rounded-xl shadow-2xl max-w-xl w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                            <div className="flex items-start justify-between border-b border-slate-100 pb-2">
                                <div>
                                    <h3 className="text-base font-bold text-slate-900">
                                        Edit Technical Compliance: {editingSpec.item_number}
                                    </h3>
                                    <p className="text-xs text-slate-500">{editingSpec.title}</p>
                                </div>
                                <button onClick={() => setEditingSpec(null)}><X className="w-4 h-4 text-slate-400" /></button>
                            </div>

                            <div className="bg-slate-50 p-2.5 rounded-lg border border-slate-200 text-xs">
                                <span className="font-semibold text-slate-700 block text-[11px]">Original Specification:</span>
                                <p className="text-slate-600 italic mt-0.5">{editingSpec.original_specification}</p>
                            </div>

                            <form onSubmit={handleSaveEdit} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Bidder Compliance Status *
                                    </label>
                                    <select
                                        value={editStatus}
                                        onChange={(e) => setEditStatus(e.target.value)}
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2 bg-white"
                                    >
                                        <option value="comply">Comply (Fully conforms without deviation)</option>
                                        <option value="comply_with_deviation">Comply with Deviation (Minor variation)</option>
                                        <option value="alternative_offered">Alternative Solution Offered</option>
                                        <option value="not_comply">Does Not Comply</option>
                                        <option value="needs_clarification">Needs Pre-Bid Clarification</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Bidder Offered Specification *
                                    </label>
                                    <textarea
                                        rows={3}
                                        required
                                        value={editOffered}
                                        onChange={(e) => setEditOffered(e.target.value)}
                                        placeholder="Describe the exact brand, model, rating, and parameters offered..."
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2"
                                    />
                                </div>

                                {(editStatus === "comply_with_deviation" || editStatus === "alternative_offered") && (
                                    <div>
                                        <label className="block text-xs font-semibold text-amber-900 mb-1">
                                            Technical Deviation Justification / Alternative Detail *
                                        </label>
                                        <textarea
                                            rows={2}
                                            required
                                            value={editDeviation}
                                            onChange={(e) => setEditDeviation(e.target.value)}
                                            placeholder="Detail the technical reasoning and demonstrate that the offered parameter meets or exceeds the tender requirement..."
                                            className="w-full text-xs border border-amber-300 rounded-lg p-2 bg-amber-50/40"
                                        />
                                    </div>
                                )}

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Evidence Reference / Page Citation
                                    </label>
                                    <input
                                        type="text"
                                        value={editEvidence}
                                        onChange={(e) => setEditEvidence(e.target.value)}
                                        placeholder="e.g. Technical Brochure Page 8, Test Certificate KEMA-2025-10"
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2"
                                    />
                                </div>

                                <div className="flex justify-end gap-2 pt-2 border-t border-slate-100">
                                    <button
                                        type="button"
                                        onClick={() => setEditingSpec(null)}
                                        className="px-3 py-1.5 text-xs text-slate-600 bg-slate-100 rounded-lg"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={savingEdit}
                                        className="px-4 py-1.5 text-xs font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700"
                                    >
                                        {savingEdit ? "Saving..." : "Save Technical Clause"}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* ADD CLAUSE MODAL */}
                {addModalOpen && (
                    <div className="fixed inset-0 z-60 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
                        <div className="bg-white rounded-xl shadow-2xl max-w-xl w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                            <div className="flex items-start justify-between border-b border-slate-100 pb-2">
                                <h3 className="text-base font-bold text-slate-900">Add Technical Clause</h3>
                                <button onClick={() => setAddModalOpen(false)}><X className="w-4 h-4 text-slate-400" /></button>
                            </div>

                            <form onSubmit={handleSaveNew} className="space-y-3">
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Clause / Item No
                                        </label>
                                        <input
                                            type="text"
                                            value={newNumber}
                                            onChange={(e) => setNewNumber(e.target.value)}
                                            placeholder="e.g. TS-04 or Clause 3.2"
                                            className="w-full text-xs border border-slate-300 rounded-lg p-2 font-mono"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Compliance Status
                                        </label>
                                        <select
                                            value={newStatus}
                                            onChange={(e) => setNewStatus(e.target.value)}
                                            className="w-full text-xs border border-slate-300 rounded-lg p-2 bg-white"
                                        >
                                            <option value="comply">Comply</option>
                                            <option value="comply_with_deviation">Comply with Deviation</option>
                                            <option value="alternative_offered">Alternative Solution</option>
                                            <option value="not_comply">Does Not Comply</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Specification Title *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        value={newTitle}
                                        onChange={(e) => setNewTitle(e.target.value)}
                                        placeholder="e.g. Rated Frequency & Phase Configuration"
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Procuring Entity Specification Wording *
                                    </label>
                                    <textarea
                                        rows={2}
                                        required
                                        value={newSpec}
                                        onChange={(e) => setNewSpec(e.target.value)}
                                        placeholder="Exact extract from the tender technical schedule..."
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2"
                                    />
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Plain Language Summary
                                        </label>
                                        <input
                                            type="text"
                                            value={newExplanation}
                                            onChange={(e) => setNewExplanation(e.target.value)}
                                            placeholder="Simplified description"
                                            className="w-full text-xs border border-slate-300 rounded-lg p-2"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Purpose / Function
                                        </label>
                                        <input
                                            type="text"
                                            value={newPurpose}
                                            onChange={(e) => setNewPurpose(e.target.value)}
                                            placeholder="Why it is required"
                                            className="w-full text-xs border border-slate-300 rounded-lg p-2"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Bidder Offered Solution / Model
                                    </label>
                                    <input
                                        type="text"
                                        value={newSolution}
                                        onChange={(e) => setNewSolution(e.target.value)}
                                        placeholder="e.g. 50Hz, 3-Phase, Dyn11 vector group"
                                        className="w-full text-xs border border-slate-300 rounded-lg p-2"
                                    />
                                </div>

                                <div className="flex items-center gap-2 pt-1">
                                    <input
                                        type="checkbox"
                                        id="new_ts_mand"
                                        checked={newMandatory}
                                        onChange={(e) => setNewMandatory(e.target.checked)}
                                        className="rounded text-blue-600"
                                    />
                                    <label htmlFor="new_ts_mand" className="text-xs font-semibold text-slate-800 cursor-pointer">
                                        Mandatory Specification (Non-compliance disqualifies bid)
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
                                        className="px-4 py-1.5 text-xs font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700"
                                    >
                                        {savingNew ? "Adding..." : "Add Clause"}
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

