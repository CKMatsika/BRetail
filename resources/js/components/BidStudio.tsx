import React, { useState, useEffect } from "react";
import axios from "axios";
import {
    FileText,
    Sparkles,
    CheckCircle2,
    Clock,
    Download,
    Printer,
    Edit3,
    Check,
    X,
    AlertTriangle,
    Eye,
    Layers,
    Save,
    RefreshCw,
    Building2,
    Calendar,
    ChevronRight,
    Award,
    FileSpreadsheet,
    FileCode,
    Sliders,
    HelpCircle,
    Send,
    UserCheck,
} from "lucide-react";

export type ProposalSection = {
    id: number;
    section_key: string;
    title: string;
    content: string;
    order_index: number;
    is_included: boolean;
    status: "draft" | "in_review" | "approved" | "finalized";
    ai_generated: boolean;
};

export type StandardDoc = {
    id: number;
    doc_type: string;
    title: string;
    content: string;
    status: "draft" | "ready_to_sign" | "signed_attached";
};

export type ProposalData = {
    id: number;
    title: string;
    bidder_name: string;
    bidder_address: string | null;
    status: "draft" | "in_review" | "approved" | "finalized";
    overall_notes: string | null;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
    sections: ProposalSection[];
    standard_documents: StandardDoc[];
};

interface Props {
    tenderId: number;
    tenderTitle: string;
    tenderRef: string;
    canManage: boolean;
    onClose: () => void;
}

export default function BidStudio({
    tenderId,
    tenderTitle,
    tenderRef,
    canManage,
    onClose,
}: Props) {
    const [loading, setLoading] = useState(true);
    const [generating, setGenerating] = useState(false);
    const [proposal, setProposal] = useState<ProposalData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);

    // Active sub-tab inside the Studio: 'sections' | 'standard_docs' | 'preview'
    const [activeTab, setActiveTab] = useState<"sections" | "standard_docs" | "preview">("sections");

    // Active selected section in the editor
    const [activeSectionId, setActiveSectionId] = useState<number | null>(null);
    const [editedContent, setEditedContent] = useState<string>("");
    const [editedTitle, setEditedTitle] = useState<string>("");
    const [editedStatus, setEditedStatus] = useState<string>("draft");
    const [savingSection, setSavingSection] = useState(false);

    // AI Regenerate Section Modal
    const [showRegenModal, setShowRegenModal] = useState(false);
    const [customInstructions, setCustomInstructions] = useState("");
    const [regeneratingSection, setRegeneratingSection] = useState(false);

    // Active Standard Doc
    const [activeDocId, setActiveDocId] = useState<number | null>(null);
    const [editedDocContent, setEditedDocContent] = useState<string>("");
    const [editedDocStatus, setEditedDocStatus] = useState<string>("draft");
    const [savingDoc, setSavingDoc] = useState(false);

    // Master Status Modal
    const [showMasterStatusModal, setShowMasterStatusModal] = useState(false);
    const [masterStatus, setMasterStatus] = useState<string>("draft");
    const [masterNotes, setMasterNotes] = useState<string>("");
    const [savingMasterStatus, setSavingMasterStatus] = useState(false);

    const fetchProposal = async () => {
        try {
            setLoading(true);
            setError(null);
            const res = await axios.get(`/tenders/${tenderId}/bid-proposal`);
            const p = res.data.proposal;
            setProposal(p);
            setMasterStatus(p.status);
            setMasterNotes(p.overall_notes || "");

            if (p.sections && p.sections.length > 0 && !activeSectionId) {
                const first = p.sections[0];
                setActiveSectionId(first.id);
                setEditedContent(first.content);
                setEditedTitle(first.title);
                setEditedStatus(first.status);
            }

            if (p.standard_documents && p.standard_documents.length > 0 && !activeDocId) {
                const firstDoc = p.standard_documents[0];
                setActiveDocId(firstDoc.id);
                setEditedDocContent(firstDoc.content);
                setEditedDocStatus(firstDoc.status);
            }
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to load bid proposal studio.");
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchProposal();
    }, [tenderId]);

    const activeSection = proposal?.sections.find((s) => s.id === activeSectionId);
    const activeDoc = proposal?.standard_documents.find((d) => d.id === activeDocId);

    const handleSelectSection = (s: ProposalSection) => {
        setActiveSectionId(s.id);
        setEditedContent(s.content);
        setEditedTitle(s.title);
        setEditedStatus(s.status);
    };

    const handleSelectDoc = (d: StandardDoc) => {
        setActiveDocId(d.id);
        setEditedDocContent(d.content);
        setEditedDocStatus(d.status);
    };

    const handleSaveSection = async () => {
        if (!activeSectionId) return;
        try {
            setSavingSection(true);
            setError(null);
            const res = await axios.patch(`/tenders/bid-proposal/sections/${activeSectionId}`, {
                title: editedTitle,
                content: editedContent,
                status: editedStatus,
            });

            // Update in local state
            if (proposal) {
                const updated = proposal.sections.map((s) => (s.id === activeSectionId ? res.data.section : s));
                setProposal({ ...proposal, sections: updated });
            }
            setSuccessMessage("Section content saved successfully.");
            setTimeout(() => setSuccessMessage(null), 3000);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to save section.");
        } finally {
            setSavingSection(false);
        }
    };

    const handleRegenerateSection = async () => {
        if (!activeSectionId) return;
        try {
            setRegeneratingSection(true);
            setError(null);
            const res = await axios.post(`/tenders/bid-proposal/sections/${activeSectionId}/regenerate`, {
                instructions: customInstructions.trim() || null,
            });

            setEditedContent(res.data.section.content);
            if (proposal) {
                const updated = proposal.sections.map((s) => (s.id === activeSectionId ? res.data.section : s));
                setProposal({ ...proposal, sections: updated });
            }
            setShowRegenModal(false);
            setCustomInstructions("");
            setSuccessMessage("Section regenerated with tailored AI business language.");
            setTimeout(() => setSuccessMessage(null), 3500);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to regenerate section.");
        } finally {
            setRegeneratingSection(false);
        }
    };

    const handleSaveDoc = async () => {
        if (!activeDocId) return;
        try {
            setSavingDoc(true);
            setError(null);
            const res = await axios.patch(`/tenders/bid-proposal/documents/${activeDocId}`, {
                content: editedDocContent,
                status: editedDocStatus,
            });

            if (proposal) {
                const updated = proposal.standard_documents.map((d) => (d.id === activeDocId ? res.data.document : d));
                setProposal({ ...proposal, standard_documents: updated });
            }
            setSuccessMessage("Returnable schedule saved.");
            setTimeout(() => setSuccessMessage(null), 3000);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to save document.");
        } finally {
            setSavingDoc(false);
        }
    };

    const handleGenerateAll = async () => {
        if (!confirm("Regenerate entire bid proposal package? Any unsaved edits will be replaced.")) return;
        try {
            setGenerating(true);
            setError(null);
            const res = await axios.post(`/tenders/${tenderId}/bid-proposal/generate-all`);
            setProposal(res.data.proposal);
            if (res.data.proposal.sections.length > 0) {
                handleSelectSection(res.data.proposal.sections[0]);
            }
            setSuccessMessage("Complete bid proposal package freshly synthesized.");
            setTimeout(() => setSuccessMessage(null), 4000);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to regenerate proposal.");
        } finally {
            setGenerating(false);
        }
    };

    const handleSaveMasterStatus = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            setSavingMasterStatus(true);
            setError(null);
            const res = await axios.patch(`/tenders/${tenderId}/bid-proposal/status`, {
                status: masterStatus,
                overall_notes: masterNotes.trim() || null,
            });
            setProposal(res.data.proposal);
            setShowMasterStatusModal(false);
            setSuccessMessage("Proposal approval status updated.");
            setTimeout(() => setSuccessMessage(null), 3500);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to update status.");
        } finally {
            setSavingMasterStatus(false);
        }
    };

    const handleExportHtml = () => {
        window.open(`/tenders/${tenderId}/bid-proposal/export-html`, "_blank");
    };

    const handleExportDoc = () => {
        window.location.href = `/tenders/${tenderId}/bid-proposal/export-doc`;
    };

    const countWords = (str: string) => {
        return str.trim() ? str.trim().split(/\s+/).length : 0;
    };

    const approvedSectionsCount = proposal?.sections.filter((s) => s.status === "approved" || s.status === "finalized").length || 0;
    const totalSectionsCount = proposal?.sections.length || 10;
    const completionPct = Math.round((approvedSectionsCount / totalSectionsCount) * 100);

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-2 sm:p-4 animate-in fade-in duration-200">
            <div className="bg-white rounded-2xl shadow-2xl max-w-6xl w-full max-h-[94vh] flex flex-col overflow-hidden border border-slate-200">
                {/* Header */}
                <div className="bg-slate-900 px-6 py-4 flex items-center justify-between text-white shrink-0">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-xs px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 font-semibold border border-emerald-500/30">
                                {tenderRef}
                            </span>
                            <span className="text-xs text-slate-400">
                                AI Bid Preparation Studio
                            </span>
                        </div>
                        <h2 className="text-base font-bold text-white line-clamp-1">
                            {tenderTitle}
                        </h2>
                    </div>

                    <div className="flex items-center gap-2">
                        {canManage && (
                            <button
                                onClick={handleGenerateAll}
                                disabled={generating || loading}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-semibold border border-slate-700 transition-colors disabled:opacity-50"
                                title="Regenerate All Proposal Sections"
                            >
                                <RefreshCw className={`w-3.5 h-3.5 ${generating ? "animate-spin text-emerald-400" : ""}`} />
                                <span className="hidden sm:inline">Regen All</span>
                            </button>
                        )}
                        <button
                            onClick={handleExportHtml}
                            disabled={loading || !proposal}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-semibold border border-slate-700 transition-colors disabled:opacity-50"
                            title="Printable HTML / PDF View"
                        >
                            <Printer className="w-3.5 h-3.5 text-blue-400" />
                            <span className="hidden sm:inline">Print / PDF</span>
                        </button>
                        <button
                            onClick={handleExportDoc}
                            disabled={loading || !proposal}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-semibold border border-slate-700 transition-colors disabled:opacity-50"
                            title="Export Microsoft Word Document (.doc)"
                        >
                            <Download className="w-3.5 h-3.5 text-emerald-400" />
                            <span className="hidden sm:inline">Export Word</span>
                        </button>
                        <button
                            onClick={onClose}
                            className="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition-colors"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {/* Sub-Tabs Nav & Proposal Approval Status Banner */}
                <div className="bg-slate-100 border-b border-slate-200 px-6 py-2.5 flex flex-wrap items-center justify-between gap-3 text-xs font-semibold text-slate-600 shrink-0">
                    <div className="flex items-center gap-2">
                        <button
                            onClick={() => setActiveTab("sections")}
                            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-colors ${
                                activeTab === "sections"
                                    ? "bg-white text-slate-900 shadow-sm border border-slate-200"
                                    : "text-slate-600 hover:bg-slate-200/70"
                            }`}
                        >
                            <FileText className="w-3.5 h-3.5 text-emerald-600" />
                            Proposal Sections (10)
                        </button>
                        <button
                            onClick={() => setActiveTab("standard_docs")}
                            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-colors ${
                                activeTab === "standard_docs"
                                    ? "bg-white text-slate-900 shadow-sm border border-slate-200"
                                    : "text-slate-600 hover:bg-slate-200/70"
                            }`}
                        >
                            <Award className="w-3.5 h-3.5 text-indigo-600" />
                            Returnable Schedules & Letters ({proposal?.standard_documents.length || 6})
                        </button>
                        <button
                            onClick={() => setActiveTab("preview")}
                            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-colors ${
                                activeTab === "preview"
                                    ? "bg-white text-slate-900 shadow-sm border border-slate-200"
                                    : "text-slate-600 hover:bg-slate-200/70"
                            }`}
                        >
                            <Eye className="w-3.5 h-3.5 text-blue-600" />
                            Executive Document Preview
                        </button>
                    </div>

                    {/* Proposal Workflow State */}
                    {proposal && (
                        <div className="flex items-center gap-3">
                            <div className="flex items-center gap-2 text-xs">
                                <span className="text-slate-500">Readiness:</span>
                                <div className="w-20 bg-slate-200 rounded-full h-2 overflow-hidden">
                                    <div
                                        className="h-2 rounded-full bg-emerald-500 transition-all duration-300"
                                        style={{ width: `${completionPct}%` }}
                                    />
                                </div>
                                <span className="font-mono text-slate-700 font-bold">{completionPct}%</span>
                            </div>

                            <div className="flex items-center gap-1.5">
                                <span
                                    className={`px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${
                                        proposal.status === "finalized"
                                            ? "bg-emerald-100 text-emerald-800 border-emerald-300"
                                            : proposal.status === "approved"
                                            ? "bg-blue-100 text-blue-800 border-blue-300"
                                            : proposal.status === "in_review"
                                            ? "bg-amber-100 text-amber-800 border-amber-300"
                                            : "bg-slate-100 text-slate-700 border-slate-300"
                                    }`}
                                >
                                    {proposal.status}
                                </span>
                                {canManage && (
                                    <button
                                        onClick={() => setShowMasterStatusModal(true)}
                                        className="text-xs text-blue-600 hover:text-blue-800 font-semibold"
                                    >
                                        Change Status
                                    </button>
                                )}
                            </div>
                        </div>
                    )}
                </div>

                {/* Body Content */}
                <div className="p-4 sm:p-6 overflow-y-auto space-y-4 flex-1 bg-slate-50/50">
                    {/* Alerts */}
                    {error && (
                        <div className="p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs flex items-start gap-2 shadow-sm">
                            <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
                            <div className="flex-1 font-medium">{error}</div>
                            <button onClick={() => setError(null)} className="text-rose-500 hover:text-rose-700">
                                <X className="w-3.5 h-3.5" />
                            </button>
                        </div>
                    )}

                    {successMessage && (
                        <div className="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs flex items-start gap-2 shadow-sm">
                            <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                            <div className="flex-1 font-medium">{successMessage}</div>
                            <button onClick={() => setSuccessMessage(null)} className="text-emerald-500 hover:text-emerald-700">
                                <X className="w-3.5 h-3.5" />
                            </button>
                        </div>
                    )}

                    {loading ? (
                        <div className="py-20 text-center space-y-3">
                            <RefreshCw className="w-8 h-8 text-emerald-600 animate-spin mx-auto" />
                            <p className="text-xs text-slate-500 font-medium">
                                Initializing AI Bid Studio & assembling proposal sections...
                            </p>
                        </div>
                    ) : proposal ? (
                        <>
                            {/* TAB 1: PROPOSAL SECTIONS STUDIO */}
                            {activeTab === "sections" && (
                                <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
                                    {/* Left Sidebar: Section Navigator */}
                                    <div className="lg:col-span-4 bg-white rounded-2xl border border-slate-200 p-3 shadow-sm space-y-2 flex flex-col justify-between max-h-[70vh] overflow-y-auto">
                                        <div className="space-y-1">
                                            <div className="px-2 py-1 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                                Proposal Structure
                                            </div>
                                            <div className="space-y-1">
                                                {proposal.sections.map((s, idx) => (
                                                    <button
                                                        key={s.id}
                                                        onClick={() => handleSelectSection(s)}
                                                        className={`w-full text-left p-2.5 rounded-xl transition-all flex items-start justify-between gap-2 border ${
                                                            activeSectionId === s.id
                                                                ? "bg-emerald-50/70 border-emerald-300 text-emerald-950 font-bold shadow-sm"
                                                                : "bg-white border-transparent hover:bg-slate-50 text-slate-700"
                                                        }`}
                                                    >
                                                        <div className="space-y-0.5 min-w-0">
                                                            <div className="flex items-center gap-1.5">
                                                                <span className="font-mono text-[10px] text-slate-400">
                                                                    0{idx + 1}
                                                                </span>
                                                                <span className="text-xs truncate block">
                                                                    {s.title.replace(/^\d+\.\s*/, "")}
                                                                </span>
                                                            </div>
                                                            <div className="text-[10px] text-slate-400">
                                                                {countWords(s.content)} words
                                                            </div>
                                                        </div>

                                                        <span
                                                            className={`shrink-0 px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider ${
                                                                s.status === "approved" || s.status === "finalized"
                                                                    ? "bg-emerald-100 text-emerald-800"
                                                                    : s.status === "in_review"
                                                                    ? "bg-amber-100 text-amber-800"
                                                                    : "bg-slate-100 text-slate-600"
                                                            }`}
                                                        >
                                                            {s.status}
                                                        </span>
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Right Main Editor */}
                                    <div className="lg:col-span-8 bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
                                        {activeSection ? (
                                            <>
                                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
                                                    <div className="flex-1 min-w-[200px]">
                                                        <input
                                                            type="text"
                                                            value={editedTitle}
                                                            onChange={(e) => setEditedTitle(e.target.value)}
                                                            className="text-sm sm:text-base font-bold text-slate-900 border-b border-transparent hover:border-slate-300 focus:border-emerald-500 outline-none w-full bg-transparent px-1"
                                                        />
                                                        <span className="text-[11px] text-slate-400 px-1">
                                                            Word count: {countWords(editedContent)} · AI Assisted
                                                        </span>
                                                    </div>

                                                    <div className="flex items-center gap-2">
                                                        <select
                                                            disabled={!canManage}
                                                            value={editedStatus}
                                                            onChange={(e) => setEditedStatus(e.target.value)}
                                                            className="text-xs border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white font-medium"
                                                        >
                                                            <option value="draft">Draft</option>
                                                            <option value="in_review">In Review</option>
                                                            <option value="approved">Approved</option>
                                                            <option value="finalized">Finalized</option>
                                                        </select>

                                                        {canManage && (
                                                            <>
                                                                <button
                                                                    onClick={() => setShowRegenModal(true)}
                                                                    className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-purple-700 bg-purple-50 hover:bg-purple-100 border border-purple-200 rounded-lg transition-colors"
                                                                    title="Regenerate with AI instructions"
                                                                >
                                                                    <Sparkles className="w-3.5 h-3.5 text-purple-600" />
                                                                    AI Refine
                                                                </button>

                                                                <button
                                                                    onClick={handleSaveSection}
                                                                    disabled={savingSection}
                                                                    className="inline-flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg shadow-sm transition-colors disabled:opacity-50"
                                                                >
                                                                    <Save className="w-3.5 h-3.5" />
                                                                    {savingSection ? "Saving..." : "Save Section"}
                                                                </button>
                                                            </>
                                                        )}
                                                    </div>
                                                </div>

                                                <textarea
                                                    rows={16}
                                                    value={editedContent}
                                                    onChange={(e) => setEditedContent(e.target.value)}
                                                    className="w-full font-mono text-xs leading-relaxed p-4 border border-slate-200 rounded-xl focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 outline-none bg-slate-50/50"
                                                    placeholder="Enter or revise section narrative..."
                                                />
                                            </>
                                        ) : (
                                            <div className="py-20 text-center text-xs text-slate-400">
                                                Select a section on the left to begin editing.
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* TAB 2: RETURNABLE SCHEDULES & LETTERS */}
                            {activeTab === "standard_docs" && (
                                <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
                                    <div className="lg:col-span-4 bg-white rounded-2xl border border-slate-200 p-3 shadow-sm space-y-2">
                                        <div className="px-2 py-1 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                            Returnable Schedules
                                        </div>
                                        <div className="space-y-1">
                                            {proposal.standard_documents.map((d) => (
                                                <button
                                                    key={d.id}
                                                    onClick={() => handleSelectDoc(d)}
                                                    className={`w-full text-left p-2.5 rounded-xl transition-all flex items-start justify-between gap-2 border ${
                                                        activeDocId === d.id
                                                            ? "bg-indigo-50/70 border-indigo-300 text-indigo-950 font-bold shadow-sm"
                                                            : "bg-white border-transparent hover:bg-slate-50 text-slate-700"
                                                    }`}
                                                >
                                                    <span className="text-xs truncate block">{d.title}</span>
                                                    <span
                                                        className={`shrink-0 px-2 py-0.5 rounded text-[9px] font-bold uppercase ${
                                                            d.status === "signed_attached"
                                                                ? "bg-emerald-100 text-emerald-800"
                                                                : "bg-slate-100 text-slate-600"
                                                        }`}
                                                    >
                                                        {d.status.replace(/_/g, " ")}
                                                    </span>
                                                </button>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="lg:col-span-8 bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
                                        {activeDoc ? (
                                            <>
                                                <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                                    <h3 className="text-sm font-bold text-slate-900">{activeDoc.title}</h3>
                                                    <div className="flex items-center gap-2">
                                                        <select
                                                            disabled={!canManage}
                                                            value={editedDocStatus}
                                                            onChange={(e) => setEditedDocStatus(e.target.value)}
                                                            className="text-xs border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white font-medium"
                                                        >
                                                            <option value="draft">Draft</option>
                                                            <option value="ready_to_sign">Ready to Sign</option>
                                                            <option value="signed_attached">Signed & Attached</option>
                                                        </select>

                                                        {canManage && (
                                                            <button
                                                                onClick={handleSaveDoc}
                                                                disabled={savingDoc}
                                                                className="inline-flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-sm transition-colors disabled:opacity-50"
                                                            >
                                                                <Save className="w-3.5 h-3.5" />
                                                                {savingDoc ? "Saving..." : "Save Letter"}
                                                            </button>
                                                        )}
                                                    </div>
                                                </div>

                                                <textarea
                                                    rows={16}
                                                    value={editedDocContent}
                                                    onChange={(e) => setEditedDocContent(e.target.value)}
                                                    className="w-full font-mono text-xs leading-relaxed p-4 border border-slate-200 rounded-xl focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-slate-50/50"
                                                />
                                            </>
                                        ) : null}
                                    </div>
                                </div>
                            )}

                            {/* TAB 3: EXECUTIVE DOCUMENT PREVIEW */}
                            {activeTab === "preview" && (
                                <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 space-y-8 max-w-4xl mx-auto">
                                    <div className="border-b border-slate-200 pb-6 text-center space-y-2">
                                        <div className="text-xs font-bold uppercase tracking-widest text-emerald-600">
                                            REPUBLIC OF ZIMBABWE · TENDER BID SUBMISSION
                                        </div>
                                        <h1 className="text-2xl font-black text-slate-900">
                                            {tenderTitle}
                                        </h1>
                                        <p className="text-sm font-semibold text-slate-500">
                                            Tender Reference: <strong>{tenderRef}</strong>
                                        </p>
                                        <div className="pt-2 text-xs text-slate-600">
                                            Prepared by: <strong>{proposal.bidder_name}</strong> · Submitted to: <strong>{proposal.tender?.procuring_entity || "Procuring Entity"}</strong>
                                        </div>
                                    </div>

                                    <div className="space-y-6 divide-y divide-slate-100">
                                        {proposal.sections
                                            .filter((s) => s.is_included)
                                            .map((s) => (
                                                <div key={s.id} className="pt-6 space-y-3">
                                                    <h3 className="text-base font-bold text-slate-900 border-b border-slate-100 pb-1">
                                                        {s.title}
                                                    </h3>
                                                    <div className="text-xs leading-relaxed text-slate-700 whitespace-pre-line font-sans">
                                                        {s.content}
                                                    </div>
                                                </div>
                                            ))}
                                    </div>
                                </div>
                            )}
                        </>
                    ) : null}
                </div>

                {/* Footer */}
                <div className="bg-slate-100 px-6 py-3 border-t border-slate-200 flex items-center justify-between text-xs text-slate-500 shrink-0">
                    <div>
                        Formatted to PPDPA statutory specifications. Exports include printable cover page, returnable schedules and signature blocks.
                    </div>
                    <button
                        onClick={onClose}
                        className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 rounded-lg border border-slate-300 transition-colors shadow-sm"
                    >
                        Close
                    </button>
                </div>
            </div>

            {/* AI REGNERATE MODAL */}
            {showRegenModal && (
                <div className="fixed inset-0 z-60 overflow-y-auto bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 space-y-4 border border-slate-200">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                            <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <Sparkles className="w-4 h-4 text-purple-600" />
                                AI Regenerate Section
                            </h3>
                            <button
                                onClick={() => setShowRegenModal(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <div className="space-y-3">
                            <p className="text-xs text-slate-600">
                                Provide specific guidance, key strengths, or commercial conditions to incorporate into: <strong>{activeSection?.title}</strong>
                            </p>

                            <textarea
                                rows={4}
                                value={customInstructions}
                                onChange={(e) => setCustomInstructions(e.target.value)}
                                placeholder="e.g. Highlight our 24-month local warranty, Zimbabwean warehouse buffer stock in Harare, and free technical training for engineers..."
                                className="w-full text-xs border border-slate-300 rounded-lg p-3"
                            />

                            <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setShowRegenModal(false)}
                                    className="px-3 py-1.5 text-xs text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={handleRegenerateSection}
                                    disabled={regeneratingSection}
                                    className="px-4 py-1.5 text-xs font-semibold text-white bg-purple-600 rounded-lg hover:bg-purple-700 disabled:opacity-50"
                                >
                                    {regeneratingSection ? "Synthesizing..." : "Regenerate Content"}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* MASTER STATUS APPROVAL MODAL */}
            {showMasterStatusModal && (
                <div className="fixed inset-0 z-60 overflow-y-auto bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 space-y-4 border border-slate-200">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                            <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <UserCheck className="w-4 h-4 text-emerald-600" />
                                Update Proposal Approval Status
                            </h3>
                            <button
                                onClick={() => setShowMasterStatusModal(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={handleSaveMasterStatus} className="space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Workflow State *
                                </label>
                                <select
                                    value={masterStatus}
                                    onChange={(e) => setMasterStatus(e.target.value)}
                                    className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white font-medium"
                                >
                                    <option value="draft">Draft (Work in Progress)</option>
                                    <option value="in_review">In Review (Internal Verification)</option>
                                    <option value="approved">Approved (Executive Sign-Off)</option>
                                    <option value="finalized">Finalized (Ready for Submission)</option>
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Approval / Review Notes
                                </label>
                                <textarea
                                    rows={3}
                                    value={masterNotes}
                                    onChange={(e) => setMasterNotes(e.target.value)}
                                    placeholder="Enter review remarks, director sign-off notes, or submission instructions..."
                                    className="w-full text-xs border border-slate-300 rounded-lg p-3"
                                />
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setShowMasterStatusModal(false)}
                                    className="px-3 py-1.5 text-xs text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingMasterStatus}
                                    className="px-4 py-1.5 text-xs font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 disabled:opacity-50"
                                >
                                    {savingMasterStatus ? "Saving..." : "Update Status"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
