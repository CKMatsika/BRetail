import React, { useState, useEffect } from "react";
import axios from "axios";
import {
    Award,
    TrendingUp,
    TrendingDown,
    DollarSign,
    CheckCircle2,
    XCircle,
    Building2,
    PieChart,
    BarChart3,
    Briefcase,
    FileSpreadsheet,
    FileText,
    RefreshCw,
    X,
    Filter,
    ShieldAlert,
    AlertCircle,
    Sliders,
    ArrowUpRight,
    ArrowDownRight,
    Plus,
    Edit3,
} from "lucide-react";

export type ExecutiveMetrics = {
    total_tenders: number;
    active_bidding: number;
    submitted: number;
    awarded: number;
    unsuccessful: number;
    cancelled: number;
    pipeline_value: number;
    submitted_value: number;
    awarded_value: number;
    win_rate_percent: number;
    entity_type_breakdown: Record<string, {
        type: string;
        total_bids: number;
        awarded: number;
        unsuccessful: number;
        win_rate: number;
        awarded_value: number;
    }>;
    loss_reasons: Record<string, number>;
    top_competitors: Record<string, number>;
};

type TenderReportsDashboardProps = {
    tenders: any[];
    canManage: boolean;
    onRefreshTenders?: () => void;
};

export default function TenderReportsDashboard({
    tenders,
    canManage,
    onRefreshTenders,
}: TenderReportsDashboardProps) {
    const [metrics, setMetrics] = useState<ExecutiveMetrics | null>(null);
    const [loading, setLoading] = useState(true);

    // Record Outcome Modal
    const [outcomeModalTender, setOutcomeModalTender] = useState<any | null>(null);
    const [outcomeForm, setOutcomeForm] = useState({
        outcome: "awarded",
        winning_bidder_name: "",
        winning_price: "",
        winning_currency: "USD",
        price_variance_percent: "",
        loss_reason: "price_higher",
        debrief_notes: "",
        contract_reference: "",
        final_margin_percent: "",
    });
    const [recordingOutcome, setRecordingOutcome] = useState(false);

    useEffect(() => {
        fetchMetrics();
    }, []);

    const fetchMetrics = async () => {
        try {
            setLoading(true);
            const res = await axios.get("/tenders/analytics/dashboard");
            setMetrics(res.data);
        } catch (err) {
            console.error("Failed to fetch analytics metrics", err);
        } finally {
            setLoading(false);
        }
    };

    const handleOpenOutcomeModal = (tender: any) => {
        setOutcomeModalTender(tender);
        setOutcomeForm({
            outcome: tender.status === "awarded" ? "awarded" : "lost",
            winning_bidder_name: tender.outcome?.winning_bidder_name || "",
            winning_price: tender.outcome?.winning_price || "",
            winning_currency: tender.currency || "USD",
            price_variance_percent: tender.outcome?.price_variance_percent || "",
            loss_reason: tender.outcome?.loss_reason || "price_higher",
            debrief_notes: tender.outcome?.debrief_notes || "",
            contract_reference: tender.outcome?.contract_reference || "",
            final_margin_percent: tender.outcome?.final_margin_percent || "",
        });
    };

    const handleSaveOutcome = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!outcomeModalTender) return;
        try {
            setRecordingOutcome(true);
            await axios.post(`/tenders/${outcomeModalTender.id}/outcome`, outcomeForm);
            alert("Tender outcome and win/loss debrief saved!");
            setOutcomeModalTender(null);
            fetchMetrics();
            if (onRefreshTenders) onRefreshTenders();
        } catch (err: any) {
            alert(err.response?.data?.message || "Failed to save tender outcome");
        } finally {
            setRecordingOutcome(false);
        }
    };

    const formatCur = (val: number | null | undefined, cur: string = "USD") => {
        if (val === null || val === undefined) return "-";
        return `${cur} ${Number(val).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    if (loading) {
        return (
            <div className="bg-white rounded-xl border border-slate-200 p-12 text-center shadow-sm">
                <RefreshCw className="w-8 h-8 text-emerald-600 animate-spin mx-auto mb-3" />
                <h3 className="text-base font-bold text-slate-800">Compiling Tender Analytics & Win/Loss Reports...</h3>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* EXECUTIVE KPI HEADER */}
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <div className="text-xs text-slate-500 font-semibold uppercase tracking-wider">Overall Win Rate</div>
                    <div className="text-2xl font-black text-emerald-600 mt-1 flex items-center gap-1">
                        {metrics?.win_rate_percent}%
                        <TrendingUp className="w-5 h-5 text-emerald-500" />
                    </div>
                    <div className="text-xs text-slate-400 mt-1">
                        {metrics?.awarded} won &bull; {metrics?.unsuccessful} lost
                    </div>
                </div>

                <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <div className="text-xs text-slate-500 font-semibold uppercase tracking-wider">Awarded Contract Value</div>
                    <div className="text-xl font-bold text-slate-900 mt-1 truncate">
                        {formatCur(metrics?.awarded_value)}
                    </div>
                    <div className="text-xs text-emerald-600 mt-1 font-medium">Secured revenue</div>
                </div>

                <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <div className="text-xs text-slate-500 font-semibold uppercase tracking-wider">Active Pipeline Value</div>
                    <div className="text-xl font-bold text-indigo-700 mt-1 truncate">
                        {formatCur(metrics?.pipeline_value)}
                    </div>
                    <div className="text-xs text-slate-400 mt-1">{metrics?.active_bidding} active tenders</div>
                </div>

                <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <div className="text-xs text-slate-500 font-semibold uppercase tracking-wider">Submitted Value</div>
                    <div className="text-xl font-bold text-purple-700 mt-1 truncate">
                        {formatCur(metrics?.submitted_value)}
                    </div>
                    <div className="text-xs text-slate-400 mt-1">{metrics?.submitted} bids under evaluation</div>
                </div>

                <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <div className="text-xs text-slate-500 font-semibold uppercase tracking-wider">Total Tenders Handled</div>
                    <div className="text-2xl font-bold text-slate-900 mt-1">
                        {metrics?.total_tenders}
                    </div>
                    <div className="text-xs text-slate-400 mt-1">In institutional repository</div>
                </div>
            </div>

            {/* SEGMENT 1: WIN RATE BY PROCURING ENTITY TYPE */}
            <div className="bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
                <h3 className="text-base font-bold text-slate-900 mb-4 flex items-center gap-2">
                    <Building2 className="w-5 h-5 text-emerald-600" />
                    Performance & Win Rate by Procurement Sector
                </h3>

                <div className="grid grid-cols-1 md:grid-cols-5 gap-3">
                    {metrics?.entity_type_breakdown &&
                        Object.entries(metrics.entity_type_breakdown).map(([typeKey, data]) => (
                            <div key={typeKey} className="bg-slate-50 p-3.5 rounded-xl border border-slate-200 flex flex-col justify-between">
                                <div>
                                    <div className="text-xs font-bold text-slate-700 uppercase tracking-wider truncate">
                                        {typeKey.replace("_", " ")}
                                    </div>
                                    <div className="text-lg font-black text-slate-900 mt-1">
                                        {data.win_rate}% Win
                                    </div>
                                    <div className="w-full bg-slate-200 h-1.5 rounded-full mt-2 overflow-hidden">
                                        <div
                                            className="bg-emerald-500 h-1.5 rounded-full"
                                            style={{ width: `${Math.min(100, data.win_rate)}%` }}
                                        ></div>
                                    </div>
                                </div>
                                <div className="text-[11px] text-slate-500 mt-3 pt-2 border-t border-slate-200/70">
                                    Won: <strong>{data.awarded}</strong> &bull; Total: <strong>{data.total_bids}</strong>
                                    <div className="text-emerald-700 font-semibold mt-0.5">{formatCur(data.awarded_value)}</div>
                                </div>
                            </div>
                        ))}
                </div>
            </div>

            {/* SEGMENT 2: LOSS REASONS & COMPETITOR INTELLIGENCE */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
                    <h3 className="text-base font-bold text-slate-900 mb-4 flex items-center gap-2">
                        <AlertCircle className="w-5 h-5 text-amber-600" />
                        Loss Root-Cause Distribution
                    </h3>

                    {metrics?.loss_reasons && Object.keys(metrics.loss_reasons).length > 0 ? (
                        <div className="space-y-3">
                            {Object.entries(metrics.loss_reasons).map(([reason, count]) => (
                                <div key={reason} className="flex items-center justify-between text-xs">
                                    <span className="capitalize text-slate-700 font-medium">{reason.replace(/_/g, " ")}</span>
                                    <span className="font-bold text-rose-700 px-2.5 py-0.5 rounded-full bg-rose-50 border border-rose-200">
                                        {count} lost
                                    </span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="text-xs text-slate-400 py-6 text-center">
                            No lost tenders with root-cause debriefs logged yet.
                        </div>
                    )}
                </div>

                <div className="bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
                    <h3 className="text-base font-bold text-slate-900 mb-4 flex items-center gap-2">
                        <Briefcase className="w-5 h-5 text-purple-600" />
                        Winning Competitor Leaderboard
                    </h3>

                    {metrics?.top_competitors && Object.keys(metrics.top_competitors).length > 0 ? (
                        <div className="space-y-3">
                            {Object.entries(metrics.top_competitors).map(([name, count]) => (
                                <div key={name} className="flex items-center justify-between text-xs">
                                    <span className="font-semibold text-slate-800 truncate">{name}</span>
                                    <span className="font-bold text-purple-800 px-2.5 py-0.5 rounded-full bg-purple-50 border border-purple-200">
                                        {count} wins against us
                                    </span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="text-xs text-slate-400 py-6 text-center">
                            No competitor win intelligence logged yet.
                        </div>
                    )}
                </div>
            </div>

            {/* SEGMENT 3: TENDER OUTCOME & DEBRIEF MANAGER */}
            <div className="bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
                <div className="flex items-center justify-between pb-4 border-b border-slate-100">
                    <div>
                        <h3 className="text-base font-bold text-slate-900 flex items-center gap-2">
                            <Award className="w-5 h-5 text-emerald-600" />
                            Tender Outcome & Debriefing Log
                        </h3>
                        <p className="text-xs text-slate-500 mt-0.5">
                            Record final awards, debriefing feedback, winning competitor details, and export audit trails
                        </p>
                    </div>
                </div>

                <div className="divide-y divide-slate-100 mt-3">
                    {tenders.map((t) => (
                        <div key={t.id} className="py-3.5 flex flex-col md:flex-row md:items-center justify-between gap-3 hover:bg-slate-50 p-2 rounded-lg transition">
                            <div className="space-y-0.5">
                                <div className="flex items-center gap-2">
                                    <span className="font-mono text-xs font-bold text-slate-700 bg-slate-100 px-2 py-0.5 rounded">
                                        {t.reference_number}
                                    </span>
                                    <span className="font-bold text-sm text-slate-900">{t.title}</span>
                                    <span className={`text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full ${
                                        t.status === "awarded"
                                            ? "bg-emerald-100 text-emerald-800 border border-emerald-200"
                                            : t.status === "unsuccessful"
                                            ? "bg-rose-100 text-rose-800 border border-rose-200"
                                            : "bg-slate-100 text-slate-700"
                                    }`}>
                                        {t.status}
                                    </span>
                                </div>
                                <p className="text-xs text-slate-500">
                                    Entity: <strong>{t.procuring_entity}</strong> &bull; Value: {formatCur(t.estimated_value, t.currency)}
                                </p>
                            </div>

                            <div className="flex items-center space-x-2">
                                <a
                                    href={`/tenders/${t.id}/audit-export`}
                                    className="px-2.5 py-1.5 text-xs text-slate-600 hover:text-slate-900 bg-white border border-slate-200 rounded-lg shadow-2xs hover:bg-slate-50 flex items-center gap-1 font-medium transition"
                                    title="Export full tender audit trail"
                                >
                                    <FileSpreadsheet className="w-3.5 h-3.5 text-emerald-600" />
                                    <span>Audit CSV</span>
                                </a>

                                {canManage && (
                                    <button
                                        onClick={() => handleOpenOutcomeModal(t)}
                                        className="px-3 py-1.5 text-xs font-semibold text-white bg-slate-900 hover:bg-slate-800 rounded-lg shadow-sm flex items-center gap-1 transition"
                                    >
                                        <Edit3 className="w-3.5 h-3.5" />
                                        <span>Record Outcome</span>
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* MODAL: RECORD / UPDATE OUTCOME */}
            {outcomeModalTender && (
                <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white rounded-2xl p-6 max-w-lg w-full shadow-2xl">
                        <div className="flex items-center justify-between pb-3 border-b border-gray-200">
                            <div>
                                <h3 className="font-bold text-gray-900 text-base">
                                    Record Tender Outcome & Debrief
                                </h3>
                                <p className="text-xs text-gray-500">{outcomeModalTender.reference_number}</p>
                            </div>
                            <button
                                onClick={() => setOutcomeModalTender(null)}
                                className="text-gray-400 hover:text-gray-600"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleSaveOutcome} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700 mb-1">
                                    Final Procurement Outcome *
                                </label>
                                <select
                                    value={outcomeForm.outcome}
                                    onChange={(e) => setOutcomeForm({ ...outcomeForm, outcome: e.target.value })}
                                    className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                >
                                    <option value="awarded">Awarded (Contract Won)</option>
                                    <option value="lost">Lost to Competitor</option>
                                    <option value="disqualified">Disqualified by Committee</option>
                                    <option value="cancelled">Cancelled by Procuring Entity</option>
                                    <option value="under_evaluation">Under Evaluation</option>
                                </select>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 mb-1">
                                        Winning Bidder Name
                                    </label>
                                    <input
                                        type="text"
                                        value={outcomeForm.winning_bidder_name}
                                        onChange={(e) => setOutcomeForm({ ...outcomeForm, winning_bidder_name: e.target.value })}
                                        placeholder="e.g. MedPharm Pvt Ltd"
                                        className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 mb-1">
                                        Winning Contract Price
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={outcomeForm.winning_price}
                                        onChange={(e) => setOutcomeForm({ ...outcomeForm, winning_price: e.target.value })}
                                        placeholder="0.00"
                                        className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                    />
                                </div>
                            </div>

                            {outcomeForm.outcome === "lost" && (
                                <div>
                                    <label className="block text-xs font-semibold text-rose-700 mb-1">
                                        Primary Reason for Loss *
                                    </label>
                                    <select
                                        value={outcomeForm.loss_reason}
                                        onChange={(e) => setOutcomeForm({ ...outcomeForm, loss_reason: e.target.value })}
                                        className="w-full text-xs border-rose-300 bg-rose-50/50 rounded-lg focus:ring-rose-500 focus:border-rose-500"
                                    >
                                        <option value="price_higher">Price Higher than Winner</option>
                                        <option value="non_responsive_technical">Technical Specification Non-Responsive</option>
                                        <option value="statutory_omission">Statutory Document Omission / Expired</option>
                                        <option value="post_qualification">Failed Post-Qualification Due Diligence</option>
                                        <option value="other">Other Commercial Reason</option>
                                    </select>
                                </div>
                            )}

                            {outcomeForm.outcome === "awarded" && (
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-semibold text-emerald-800 mb-1">
                                            Contract Reference Number
                                        </label>
                                        <input
                                            type="text"
                                            value={outcomeForm.contract_reference}
                                            onChange={(e) => setOutcomeForm({ ...outcomeForm, contract_reference: e.target.value })}
                                            placeholder="e.g. ZETDC/CON/2026/04"
                                            className="w-full text-xs border-emerald-300 bg-emerald-50/50 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-emerald-800 mb-1">
                                            Realized Net Margin (%)
                                        </label>
                                        <input
                                            type="number"
                                            step="0.1"
                                            value={outcomeForm.final_margin_percent}
                                            onChange={(e) => setOutcomeForm({ ...outcomeForm, final_margin_percent: e.target.value })}
                                            placeholder="e.g. 19.5"
                                            className="w-full text-xs border-emerald-300 bg-emerald-50/50 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-gray-700 mb-1">
                                    Official Debriefing Notes & Committee Feedback
                                </label>
                                <textarea
                                    rows={3}
                                    value={outcomeForm.debrief_notes}
                                    onChange={(e) => setOutcomeForm({ ...outcomeForm, debrief_notes: e.target.value })}
                                    placeholder="Enter debrief notes received from procurement entity..."
                                    className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                />
                            </div>

                            <div className="pt-3 border-t border-gray-200 flex justify-end space-x-3">
                                <button
                                    type="button"
                                    onClick={() => setOutcomeModalTender(null)}
                                    className="px-4 py-2 border border-gray-300 text-xs font-medium rounded-lg text-gray-700 hover:bg-gray-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={recordingOutcome}
                                    className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-xs font-semibold rounded-lg text-white shadow"
                                >
                                    {recordingOutcome ? "Saving..." : "Save Outcome"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
