import React, { useState, useEffect } from "react";
import axios from "axios";
import {
    ShieldCheck,
    ShieldAlert,
    AlertTriangle,
    CheckCircle2,
    XCircle,
    Download,
    RefreshCw,
    X,
    Clock,
    DollarSign,
    UserCheck,
    Check,
    Edit3,
    FileText,
    Filter,
    ChevronDown,
    ChevronUp,
    Sparkles,
    Building2,
    Briefcase,
    TrendingUp,
    HelpCircle,
    Award,
    Calendar,
} from "lucide-react";

export type RemediationItem = {
    id: string;
    title: string;
    category: "statutory" | "technical" | "financial" | "experience" | "bid_security" | "eligibility";
    description: string;
    priority: "critical" | "high" | "medium" | "low";
    estimated_days: number;
    estimated_cost: number;
    responsible_person: string;
    status: "pending" | "in_progress" | "resolved";
    due_date: string;
    notes?: string;
    updated_at?: string;
};

export type CategoryScore = {
    name: string;
    weight: number;
    score: number;
    status: "compliant" | "warning" | "failed";
    details: string[];
};

export type GapAnalysisData = {
    id: number;
    overall_score: number;
    recommendation: "strong_bid" | "conditional_bid" | "high_risk" | "do_not_bid";
    recommendation_reason: string;
    is_mandatory_passed: boolean;
    fatal_flaws_count: number;
    category_scores: Record<string, CategoryScore>;
    fatal_flaws: string[];
    action_plan: RemediationItem[];
    manual_override: boolean;
    override_recommendation?: string;
    override_notes?: string;
    override_by_user_id?: number;
    override_at?: string;
    analyzed_at?: string;
};

interface Props {
    tenderId: number;
    tenderTitle: string;
    tenderRef: string;
    canManage: boolean;
    onClose: () => void;
}

export default function TenderGapAnalysisModal({
    tenderId,
    tenderTitle,
    tenderRef,
    canManage,
    onClose,
}: Props) {
    const [loading, setLoading] = useState(true);
    const [reEvaluating, setReEvaluating] = useState(false);
    const [analysis, setAnalysis] = useState<GapAnalysisData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);

    // Filter states for remediation plan
    const [priorityFilter, setPriorityFilter] = useState<string>("all");
    const [statusFilter, setStatusFilter] = useState<string>("all");
    const [categoryFilter, setCategoryFilter] = useState<string>("all");

    // Override dialog states
    const [showOverrideModal, setShowOverrideModal] = useState(false);
    const [overrideVerdict, setOverrideVerdict] = useState<string>("conditional_bid");
    const [overrideNotes, setOverrideNotes] = useState<string>("");
    const [savingOverride, setSavingOverride] = useState(false);

    // Updating action item state
    const [updatingItemId, setUpdatingItemId] = useState<string | null>(null);

    const fetchAnalysis = async () => {
        try {
            setLoading(true);
            setError(null);
            const res = await axios.get(`/tenders/${tenderId}/gap-analysis`);
            setAnalysis(res.data.gap_analysis);
            if (res.data.gap_analysis?.override_recommendation) {
                setOverrideVerdict(res.data.gap_analysis.override_recommendation);
            } else if (res.data.gap_analysis?.recommendation) {
                setOverrideVerdict(res.data.gap_analysis.recommendation);
            }
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to load compliance gap analysis.");
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchAnalysis();
    }, [tenderId]);

    const handleReEvaluate = async () => {
        try {
            setReEvaluating(true);
            setError(null);
            const res = await axios.post(`/tenders/${tenderId}/gap-analysis/evaluate`);
            setAnalysis(res.data.gap_analysis);
            setSuccessMessage("Gap analysis and readiness assessment re-calculated successfully against document vault.");
            setTimeout(() => setSuccessMessage(null), 4000);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to evaluate compliance gaps.");
        } finally {
            setReEvaluating(false);
        }
    };

    const handleSaveOverride = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!overrideNotes.trim()) {
            setError("Justification notes are mandatory when overriding bid recommendations.");
            return;
        }

        try {
            setSavingOverride(true);
            setError(null);
            const res = await axios.post(`/tenders/${tenderId}/gap-analysis/override`, {
                override_recommendation: overrideVerdict,
                override_notes: overrideNotes.trim(),
            });
            setAnalysis(res.data.gap_analysis);
            setShowOverrideModal(false);
            setSuccessMessage("Manual recommendation override recorded with executive audit trail.");
            setTimeout(() => setSuccessMessage(null), 4000);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to save recommendation override.");
        } finally {
            setSavingOverride(false);
        }
    };

    const handleUpdateItemStatus = async (itemId: string, newStatus: "pending" | "in_progress" | "resolved") => {
        try {
            setUpdatingItemId(itemId);
            const res = await axios.post(`/tenders/${tenderId}/gap-analysis/action-item`, {
                item_id: itemId,
                status: newStatus,
            });
            setAnalysis(res.data.gap_analysis);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to update remediation task status.");
        } finally {
            setUpdatingItemId(null);
        }
    };

    const handleExportCsv = () => {
        window.location.href = `/tenders/${tenderId}/gap-analysis/export`;
    };

    // Filtered action plan items
    const filteredActionPlan = (analysis?.action_plan || []).filter((item) => {
        if (priorityFilter !== "all" && item.priority !== priorityFilter) return false;
        if (statusFilter !== "all" && item.status !== statusFilter) return false;
        if (categoryFilter !== "all" && item.category !== categoryFilter) return false;
        return true;
    });

    const renderVerdictBadge = (recommendation: string) => {
        switch (recommendation) {
            case "strong_bid":
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black tracking-wide uppercase bg-emerald-100 text-emerald-800 border border-emerald-300 shadow-sm">
                        <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                        Strong Bid (Recommended)
                    </span>
                );
            case "conditional_bid":
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black tracking-wide uppercase bg-amber-100 text-amber-900 border border-amber-300 shadow-sm">
                        <Clock className="w-4 h-4 text-amber-600" />
                        Conditional Bid (Remediate Gaps)
                    </span>
                );
            case "high_risk":
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black tracking-wide uppercase bg-orange-100 text-orange-900 border border-orange-300 shadow-sm">
                        <AlertTriangle className="w-4 h-4 text-orange-600" />
                        High Risk Opportunity
                    </span>
                );
            case "do_not_bid":
            default:
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black tracking-wide uppercase bg-rose-100 text-rose-900 border border-rose-300 shadow-sm">
                        <XCircle className="w-4 h-4 text-rose-600" />
                        Do Not Bid (Disqualification Risk)
                    </span>
                );
        }
    };

    const getScoreColor = (score: number) => {
        if (score >= 80) return "text-emerald-600";
        if (score >= 60) return "text-amber-600";
        if (score >= 40) return "text-orange-600";
        return "text-rose-600";
    };

    const getScoreBarBg = (score: number) => {
        if (score >= 80) return "bg-emerald-500";
        if (score >= 60) return "bg-amber-500";
        if (score >= 40) return "bg-orange-500";
        return "bg-rose-500";
    };

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-3 sm:p-6 animate-in fade-in duration-200">
            <div className="bg-white rounded-2xl shadow-2xl max-w-5xl w-full max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                {/* Header */}
                <div className="bg-slate-900 px-6 py-4 flex items-center justify-between text-white shrink-0">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-xs px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 font-semibold border border-emerald-500/30">
                                {tenderRef}
                            </span>
                            <span className="text-xs text-slate-400">
                                Tender Compliance Assessment & Gap Analysis
                            </span>
                        </div>
                        <h2 className="text-base font-bold text-white line-clamp-1">
                            {tenderTitle}
                        </h2>
                    </div>

                    <div className="flex items-center gap-2">
                        {canManage && (
                            <button
                                onClick={handleReEvaluate}
                                disabled={reEvaluating || loading}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-semibold border border-slate-700 transition-colors disabled:opacity-50"
                                title="Re-evaluate against updated Compliance Documents"
                            >
                                <RefreshCw className={`w-3.5 h-3.5 ${reEvaluating ? "animate-spin text-emerald-400" : ""}`} />
                                <span className="hidden sm:inline">Re-Evaluate Vault</span>
                            </button>
                        )}
                        <button
                            onClick={handleExportCsv}
                            disabled={loading || !analysis}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-semibold border border-slate-700 transition-colors disabled:opacity-50"
                            title="Export Action Plan CSV"
                        >
                            <Download className="w-3.5 h-3.5" />
                            <span className="hidden sm:inline">Export Plan</span>
                        </button>
                        <button
                            onClick={onClose}
                            className="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition-colors"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {/* Body */}
                <div className="p-6 overflow-y-auto space-y-6 flex-1 bg-slate-50/50">
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
                                Running comprehensive Zimbabwean procurement compliance assessment...
                            </p>
                        </div>
                    ) : analysis ? (
                        <>
                            {/* Executive Bid / No-Bid Decision Card */}
                            <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-5">
                                <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
                                    <div className="space-y-1">
                                        <div className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                            Executive Recommendation & Readiness
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {renderVerdictBadge(analysis.recommendation)}
                                            {analysis.manual_override && (
                                                <span className="text-[11px] font-semibold text-purple-700 bg-purple-50 px-2 py-0.5 rounded border border-purple-200">
                                                    Manual Executive Override
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    {/* Overall Gauge Score */}
                                    <div className="flex items-center gap-4">
                                        <div className="text-right">
                                            <div className="text-[11px] text-slate-400 font-semibold uppercase">
                                                Readiness Score
                                            </div>
                                            <div className={`text-3xl font-black font-mono ${getScoreColor(analysis.overall_score)}`}>
                                                {analysis.overall_score}%
                                            </div>
                                        </div>
                                        {canManage && (
                                            <button
                                                onClick={() => setShowOverrideModal(true)}
                                                className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg border border-slate-200 transition-colors"
                                            >
                                                <Edit3 className="w-3.5 h-3.5 text-slate-600" />
                                                Override Verdict
                                            </button>
                                        )}
                                    </div>
                                </div>

                                <div className="text-xs sm:text-sm text-slate-700 leading-relaxed bg-slate-50/70 p-4 rounded-xl border border-slate-100">
                                    <strong>Assessment Finding: </strong>
                                    {analysis.recommendation_reason}
                                </div>

                                {/* Manual Override Justification Notes if Present */}
                                {analysis.manual_override && analysis.override_notes && (
                                    <div className="text-xs bg-purple-50/70 border border-purple-200 p-3.5 rounded-xl text-purple-900 space-y-1">
                                        <div className="font-bold flex items-center gap-1.5">
                                            <UserCheck className="w-3.5 h-3.5 text-purple-700" />
                                            Executive Override Justification:
                                        </div>
                                        <p className="text-purple-800 italic">"{analysis.override_notes}"</p>
                                        {analysis.override_at && (
                                            <span className="text-[11px] text-purple-600 block pt-0.5">
                                                Recorded on {new Date(analysis.override_at).toLocaleDateString()}
                                            </span>
                                        )}
                                    </div>
                                )}

                                {/* Fatal Flaws Callout */}
                                {analysis.fatal_flaws && analysis.fatal_flaws.length > 0 && (
                                    <div className="bg-rose-50 border border-rose-200 rounded-xl p-4 space-y-2">
                                        <div className="flex items-center gap-2 text-rose-900 font-bold text-xs uppercase tracking-wider">
                                            <ShieldAlert className="w-4 h-4 text-rose-600" />
                                            Fatal Disqualification Flaws ({analysis.fatal_flaws.length})
                                        </div>
                                        <p className="text-xs text-rose-700">
                                            The following statutory or technical non-compliances will cause immediate administrative rejection under standard Zimbabwean public procurement evaluation rules:
                                        </p>
                                        <ul className="list-disc list-inside text-xs text-rose-800 space-y-1 font-medium pl-1">
                                            {analysis.fatal_flaws.map((flaw, i) => (
                                                <li key={i}>{flaw}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>

                            {/* Category Scorecards Breakdown */}
                            <div className="space-y-3">
                                <h3 className="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-1.5">
                                    <TrendingUp className="w-3.5 h-3.5 text-emerald-600" />
                                    Categorised Compliance Breakdown (6 Evaluation Pillars)
                                </h3>

                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                    {Object.entries(analysis.category_scores || {}).map(([key, cat]) => (
                                        <div
                                            key={key}
                                            className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm space-y-3 flex flex-col justify-between"
                                        >
                                            <div className="space-y-2">
                                                <div className="flex items-start justify-between gap-2">
                                                    <span className="text-xs font-bold text-slate-800 line-clamp-1">
                                                        {cat.name}
                                                    </span>
                                                    <span className="text-[11px] font-mono font-semibold text-slate-400">
                                                        Weight: {cat.weight}%
                                                    </span>
                                                </div>

                                                <div className="space-y-1">
                                                    <div className="flex items-center justify-between text-xs">
                                                        <span className="text-slate-500">Pillar Score</span>
                                                        <span className={`font-mono font-bold ${getScoreColor(cat.score)}`}>
                                                            {cat.score}%
                                                        </span>
                                                    </div>
                                                    <div className="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                                                        <div
                                                            className={`h-2 rounded-full transition-all duration-500 ${getScoreBarBg(cat.score)}`}
                                                            style={{ width: `${Math.min(100, Math.max(0, cat.score))}%` }}
                                                        />
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="border-t border-slate-100 pt-2 space-y-1">
                                                {cat.details.slice(0, 3).map((det, idx) => (
                                                    <div key={idx} className="text-[11px] text-slate-600 flex items-start gap-1">
                                                        <span className="text-slate-400 shrink-0">•</span>
                                                        <span className="line-clamp-1">{det}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* Actionable Remediation Checklist (Corrective Action Plan) */}
                            <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
                                    <div className="space-y-0.5">
                                        <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                                            <Award className="w-4 h-4 text-emerald-600" />
                                            Remediation Action Plan & Gap Checklist ({analysis.action_plan?.length || 0})
                                        </h3>
                                        <p className="text-xs text-slate-500">
                                            Prioritised list of actionable steps required to resolve identified non-compliances before tender closing.
                                        </p>
                                    </div>

                                    {/* Filters */}
                                    <div className="flex flex-wrap items-center gap-2">
                                        <select
                                            value={priorityFilter}
                                            onChange={(e) => setPriorityFilter(e.target.value)}
                                            className="text-xs border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white text-slate-700 font-medium"
                                        >
                                            <option value="all">All Priorities</option>
                                            <option value="critical">Critical</option>
                                            <option value="high">High</option>
                                            <option value="medium">Medium</option>
                                            <option value="low">Low</option>
                                        </select>

                                        <select
                                            value={statusFilter}
                                            onChange={(e) => setStatusFilter(e.target.value)}
                                            className="text-xs border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white text-slate-700 font-medium"
                                        >
                                            <option value="all">All Statuses</option>
                                            <option value="pending">Pending</option>
                                            <option value="in_progress">In Progress</option>
                                            <option value="resolved">Resolved</option>
                                        </select>
                                    </div>
                                </div>

                                {filteredActionPlan.length === 0 ? (
                                    <div className="py-8 text-center text-xs text-slate-500 space-y-2">
                                        <CheckCircle2 className="w-8 h-8 text-emerald-500 mx-auto" />
                                        <p>No remediation items matching the current filter criteria.</p>
                                    </div>
                                ) : (
                                    <div className="divide-y divide-slate-100">
                                        {filteredActionPlan.map((item, idx) => (
                                            <div
                                                key={item.id}
                                                className={`py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 transition-colors ${
                                                    item.status === "resolved" ? "opacity-60 bg-slate-50/50 rounded-lg px-2" : ""
                                                }`}
                                            >
                                                <div className="space-y-1.5 max-w-2xl">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-mono text-[10px] font-bold text-slate-400">
                                                            ACT-{idx + 1}
                                                        </span>
                                                        <span
                                                            className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full border ${
                                                                item.priority === "critical"
                                                                    ? "bg-rose-100 text-rose-800 border-rose-300"
                                                                    : item.priority === "high"
                                                                    ? "bg-orange-100 text-orange-800 border-orange-300"
                                                                    : "bg-slate-100 text-slate-700 border-slate-300"
                                                            }`}
                                                        >
                                                            {item.priority}
                                                        </span>
                                                        <span className="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">
                                                            {item.category.replace(/_/g, " ")}
                                                        </span>
                                                        <span className="text-xs font-bold text-slate-900">
                                                            {item.title}
                                                        </span>
                                                    </div>

                                                    <p className="text-xs text-slate-600 leading-relaxed">
                                                        {item.description}
                                                    </p>

                                                    <div className="flex flex-wrap items-center gap-3 text-[11px] text-slate-500 pt-1">
                                                        <span>
                                                            Assignee: <strong className="text-slate-700">{item.responsible_person}</strong>
                                                        </span>
                                                        <span>•</span>
                                                        <span>
                                                            Time: <strong className="text-slate-700">{item.estimated_days} day(s)</strong>
                                                        </span>
                                                        {item.estimated_cost > 0 && (
                                                            <>
                                                                <span>•</span>
                                                                <span>
                                                                    Est. Cost: <strong className="text-slate-700">${item.estimated_cost}</strong>
                                                                </span>
                                                            </>
                                                        )}
                                                        <span>•</span>
                                                        <span className="flex items-center gap-1">
                                                            <Calendar className="w-3 h-3 text-slate-400" />
                                                            Due: <strong className="text-slate-700">{item.due_date}</strong>
                                                        </span>
                                                    </div>
                                                </div>

                                                {/* Status Selector */}
                                                <div className="flex items-center gap-2 self-start sm:self-center shrink-0">
                                                    <select
                                                        disabled={!canManage || updatingItemId === item.id}
                                                        value={item.status}
                                                        onChange={(e) =>
                                                            handleUpdateItemStatus(
                                                                item.id,
                                                                e.target.value as "pending" | "in_progress" | "resolved"
                                                            )
                                                        }
                                                        className={`text-xs font-semibold rounded-lg px-2.5 py-1.5 border transition-colors ${
                                                            item.status === "resolved"
                                                                ? "bg-emerald-50 text-emerald-800 border-emerald-300"
                                                                : item.status === "in_progress"
                                                                ? "bg-blue-50 text-blue-800 border-blue-300"
                                                                : "bg-amber-50 text-amber-800 border-amber-300"
                                                        }`}
                                                    >
                                                        <option value="pending">Pending</option>
                                                        <option value="in_progress">In Progress</option>
                                                        <option value="resolved">Resolved</option>
                                                    </select>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </>
                    ) : null}
                </div>

                {/* Footer */}
                <div className="bg-slate-100 px-6 py-3 border-t border-slate-200 flex items-center justify-between text-xs text-slate-500 shrink-0">
                    <div>
                        Assessment grounded in PRAZ guidelines & Zimbabwean PPDPA statutory compliance frameworks.
                    </div>
                    <button
                        onClick={onClose}
                        className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 rounded-lg border border-slate-300 transition-colors shadow-sm"
                    >
                        Close
                    </button>
                </div>
            </div>

            {/* Recommendation Override Modal */}
            {showOverrideModal && (
                <div className="fixed inset-0 z-60 overflow-y-auto bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 space-y-4 border border-slate-200">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                            <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <UserCheck className="w-4 h-4 text-purple-600" />
                                Override Bid / No-Bid Recommendation
                            </h3>
                            <button
                                onClick={() => setShowOverrideModal(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={handleSaveOverride} className="space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    New Executive Verdict *
                                </label>
                                <select
                                    value={overrideVerdict}
                                    onChange={(e) => setOverrideVerdict(e.target.value)}
                                    className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white font-medium"
                                >
                                    <option value="strong_bid">Strong Bid (Recommended)</option>
                                    <option value="conditional_bid">Conditional Bid (Remediation Required)</option>
                                    <option value="high_risk">High Risk Opportunity</option>
                                    <option value="do_not_bid">Do Not Bid (Reject Opportunity)</option>
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Executive Justification Notes *
                                </label>
                                <textarea
                                    required
                                    rows={4}
                                    value={overrideNotes}
                                    onChange={(e) => setOverrideNotes(e.target.value)}
                                    placeholder="State business rationale, management approvals, risk mitigation arrangements, or fast-track compliance actions..."
                                    className="w-full text-xs border border-slate-300 rounded-lg p-3"
                                />
                                <span className="text-[11px] text-slate-400 block pt-1">
                                    This override is recorded in the permanent tender audit log with your user identity.
                                </span>
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setShowOverrideModal(false)}
                                    className="px-3 py-1.5 text-xs text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingOverride}
                                    className="px-4 py-1.5 text-xs font-semibold text-white bg-purple-600 rounded-lg hover:bg-purple-700 disabled:opacity-50"
                                >
                                    {savingOverride ? "Saving Override..." : "Confirm & Save Override"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}

