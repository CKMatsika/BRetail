import React, { useState, useEffect } from "react";
import axios from "axios";
import {
    Calendar,
    Clock,
    AlertTriangle,
    CheckCircle2,
    XCircle,
    Bell,
    Plus,
    X,
    MapPin,
    Users,
    FileText,
    RefreshCw,
    Check,
    Edit3,
    Trash2,
    ChevronRight,
    ArrowUpRight,
    HelpCircle,
    Building2,
    AlertCircle,
    ShieldAlert,
    Send,
} from "lucide-react";

export type MilestoneItem = {
    id: number;
    type: string;
    title: string;
    description: string | null;
    venue_or_location: string | null;
    due_at: string;
    status: "pending" | "completed" | "at_risk" | "missed";
    is_completed: boolean;
    is_at_risk: boolean;
    is_missed: boolean;
    completed_at: string | null;
    completed_by: { id: number; name: string } | null;
    responsible_employee: { id: number; name: string; designation?: string } | null;
    hours_remaining: number | null;
    reminder_lead_times: string[];
    reminders_sent: string[];
};

export type ClarificationItem = {
    id: number;
    type: "clarification_request" | "addendum_received";
    reference_or_number: string;
    subject: string;
    question_or_content: string;
    response_or_amendment: string | null;
    submitted_or_received_at: string;
    response_due_at: string | null;
    status: "draft" | "submitted" | "answered" | "acknowledged";
    affects_closing_date: boolean;
    new_closing_date: string | null;
    affects_pricing: boolean;
    affects_technical_specs: boolean;
    created_by?: { id: number; name: string } | null;
};

export type MilestoneSchedule = {
    total: number;
    completed_count: number;
    at_risk_count: number;
    missed_count: number;
    pending_count: number;
    next_milestone: {
        id: number;
        title: string;
        due_at: string;
        hours_remaining: number;
    } | null;
    milestones: MilestoneItem[];
};

interface Props {
    tenderId: number;
    tenderTitle: string;
    tenderRef: string;
    canManage: boolean;
    onClose: () => void;
}

export default function TenderMilestonesTimeline({
    tenderId,
    tenderTitle,
    tenderRef,
    canManage,
    onClose,
}: Props) {
    const [loading, setLoading] = useState(true);
    const [schedule, setSchedule] = useState<MilestoneSchedule | null>(null);
    const [clarifications, setClarifications] = useState<ClarificationItem[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);

    // Active sub-tab inside the milestone modal: 'timeline' | 'clarifications' | 'alerts'
    const [activeTab, setActiveTab] = useState<"timeline" | "clarifications" | "alerts">("timeline");

    // Add / Edit Milestone Modal
    const [showAddMilestone, setShowAddMilestone] = useState(false);
    const [editingMilestone, setEditingMilestone] = useState<MilestoneItem | null>(null);
    const [milestoneTitle, setMilestoneTitle] = useState("");
    const [milestoneType, setMilestoneType] = useState("custom");
    const [milestoneDueAt, setMilestoneDueAt] = useState("");
    const [milestoneDesc, setMilestoneDesc] = useState("");
    const [milestoneVenue, setMilestoneVenue] = useState("");
    const [savingMilestone, setSavingMilestone] = useState(false);

    // Add Clarification / Addendum Modal
    const [showAddClarification, setShowAddClarification] = useState(false);
    const [clarType, setClarType] = useState<"clarification_request" | "addendum_received">("clarification_request");
    const [clarRef, setClarRef] = useState("");
    const [clarSubject, setClarSubject] = useState("");
    const [clarContent, setClarContent] = useState("");
    const [clarAffectsClosing, setClarAffectsClosing] = useState(false);
    const [clarNewClosing, setClarNewClosing] = useState("");
    const [savingClarification, setSavingClarification] = useState(false);

    // Alerts State
    const [alerts, setAlerts] = useState<any[]>([]);
    const [unreadAlertsCount, setUnreadAlertsCount] = useState(0);
    const [loadingAlerts, setLoadingAlerts] = useState(false);

    const fetchData = async () => {
        try {
            setLoading(true);
            setError(null);
            const res = await axios.get(`/tenders/${tenderId}/milestones`);
            setSchedule(res.data.schedule);
            setClarifications(res.data.clarifications || []);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to load tender milestones.");
        } finally {
            setLoading(false);
        }
    };

    const fetchAlerts = async () => {
        try {
            setLoadingAlerts(true);
            const res = await axios.get("/tenders/alerts");
            setAlerts(res.data.alerts || []);
            setUnreadAlertsCount(res.data.unread_count || 0);
        } catch (err) {
            console.error("Failed to load alerts", err);
        } finally {
            setLoadingAlerts(false);
        }
    };

    useEffect(() => {
        fetchData();
        fetchAlerts();
    }, [tenderId]);

    const handleGenerateDefaults = async () => {
        try {
            setLoading(true);
            await axios.post(`/tenders/${tenderId}/milestones/generate-defaults`);
            await fetchData();
            setSuccessMessage("Standard Zimbabwean procurement milestones generated successfully.");
            setTimeout(() => setSuccessMessage(null), 4000);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to generate standard milestones.");
            setLoading(false);
        }
    };

    const handleSaveMilestone = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!milestoneTitle.trim() || !milestoneDueAt) {
            setError("Title and due date are required.");
            return;
        }

        try {
            setSavingMilestone(true);
            setError(null);
            if (editingMilestone) {
                await axios.patch(`/tenders/milestones/${editingMilestone.id}`, {
                    title: milestoneTitle.trim(),
                    due_at: milestoneDueAt,
                    description: milestoneDesc.trim() || null,
                    venue_or_location: milestoneVenue.trim() || null,
                });
                setSuccessMessage("Milestone updated.");
            } else {
                await axios.post(`/tenders/${tenderId}/milestones`, {
                    title: milestoneTitle.trim(),
                    type: milestoneType,
                    due_at: milestoneDueAt,
                    description: milestoneDesc.trim() || null,
                    venue_or_location: milestoneVenue.trim() || null,
                });
                setSuccessMessage("Custom milestone created.");
            }
            setShowAddMilestone(false);
            setEditingMilestone(null);
            resetMilestoneForm();
            await fetchData();
            setTimeout(() => setSuccessMessage(null), 3000);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to save milestone.");
        } finally {
            setSavingMilestone(false);
        }
    };

    const handleToggleComplete = async (m: MilestoneItem) => {
        if (!canManage) return;
        try {
            const newStatus = m.status === "completed" ? "pending" : "completed";
            await axios.patch(`/tenders/milestones/${m.id}`, { status: newStatus });
            await fetchData();
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to update milestone status.");
        }
    };

    const handleDeleteMilestone = async (id: number) => {
        if (!confirm("Are you sure you want to delete this milestone?")) return;
        try {
            await axios.delete(`/tenders/milestones/${id}`);
            await fetchData();
            setSuccessMessage("Milestone removed.");
            setTimeout(() => setSuccessMessage(null), 3000);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to delete milestone.");
        }
    };

    const handleSaveClarification = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!clarRef.trim() || !clarSubject.trim() || !clarContent.trim()) {
            setError("Reference, subject, and content are required.");
            return;
        }

        try {
            setSavingClarification(true);
            setError(null);
            await axios.post(`/tenders/${tenderId}/clarifications`, {
                type: clarType,
                reference_or_number: clarRef.trim(),
                subject: clarSubject.trim(),
                question_or_content: clarContent.trim(),
                submitted_or_received_at: new Date().toISOString(),
                status: clarType === "addendum_received" ? "acknowledged" : "submitted",
                affects_closing_date: clarAffectsClosing,
                new_closing_date: clarAffectsClosing && clarNewClosing ? clarNewClosing : null,
            });
            setShowAddClarification(false);
            resetClarificationForm();
            await fetchData();
            await fetchAlerts();
            setSuccessMessage(
                clarType === "addendum_received"
                    ? "Addendum recorded. Tender closing date and milestones recalibrated."
                    : "Clarification request logged."
            );
            setTimeout(() => setSuccessMessage(null), 4000);
        } catch (err: any) {
            setError(err.response?.data?.message || "Failed to record clarification/addendum.");
        } finally {
            setSavingClarification(false);
        }
    };

    const handleMarkAllAlertsRead = async () => {
        try {
            await axios.post("/tenders/alerts/read-all");
            await fetchAlerts();
        } catch (err) {
            console.error(err);
        }
    };

    const handleDispatchAlertsNow = async () => {
        try {
            const res = await axios.post("/tenders/alerts/dispatch-now");
            await fetchAlerts();
            setSuccessMessage(res.data.message);
            setTimeout(() => setSuccessMessage(null), 3500);
        } catch (err: any) {
            setError("Failed to run deadline alert scan.");
        }
    };

    const resetMilestoneForm = () => {
        setMilestoneTitle("");
        setMilestoneType("custom");
        setMilestoneDueAt("");
        setMilestoneDesc("");
        setMilestoneVenue("");
    };

    const resetClarificationForm = () => {
        setClarType("clarification_request");
        setClarRef("");
        setClarSubject("");
        setClarContent("");
        setClarAffectsClosing(false);
        setClarNewClosing("");
    };

    const formatDueDate = (dateStr: string) => {
        const d = new Date(dateStr);
        return d.toLocaleDateString("en-GB", {
            day: "2-digit",
            month: "short",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        });
    };

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-3 sm:p-6 animate-in fade-in duration-200">
            <div className="bg-white rounded-2xl shadow-2xl max-w-5xl w-full max-h-[92vh] flex flex-col overflow-hidden border border-slate-200">
                {/* Header */}
                <div className="bg-slate-900 px-6 py-4 flex items-center justify-between text-white shrink-0">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-xs px-2 py-0.5 rounded bg-blue-500/20 text-blue-300 font-semibold border border-blue-500/30">
                                {tenderRef}
                            </span>
                            <span className="text-xs text-slate-400">
                                Tender Milestones, Deadlines & Clarification Engine
                            </span>
                        </div>
                        <h2 className="text-base font-bold text-white line-clamp-1">
                            {tenderTitle}
                        </h2>
                    </div>

                    <div className="flex items-center gap-2">
                        {canManage && (
                            <button
                                onClick={handleDispatchAlertsNow}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-semibold border border-slate-700 transition-colors"
                                title="Scan deadlines and dispatch reminders"
                            >
                                <Bell className="w-3.5 h-3.5 text-amber-400" />
                                <span className="hidden sm:inline">Scan Alerts</span>
                            </button>
                        )}
                        <button
                            onClick={onClose}
                            className="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition-colors"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {/* Sub-Tabs Nav */}
                <div className="bg-slate-100 border-b border-slate-200 px-6 py-2.5 flex items-center justify-between text-xs font-semibold text-slate-600 shrink-0">
                    <div className="flex items-center gap-2">
                        <button
                            onClick={() => setActiveTab("timeline")}
                            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-colors ${
                                activeTab === "timeline"
                                    ? "bg-white text-slate-900 shadow-sm border border-slate-200"
                                    : "text-slate-600 hover:bg-slate-200/70"
                            }`}
                        >
                            <Calendar className="w-3.5 h-3.5 text-blue-600" />
                            Milestones Schedule ({schedule?.total || 0})
                        </button>
                        <button
                            onClick={() => setActiveTab("clarifications")}
                            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-colors ${
                                activeTab === "clarifications"
                                    ? "bg-white text-slate-900 shadow-sm border border-slate-200"
                                    : "text-slate-600 hover:bg-slate-200/70"
                            }`}
                        >
                            <FileText className="w-3.5 h-3.5 text-indigo-600" />
                            Clarifications & Addenda ({clarifications.length})
                        </button>
                        <button
                            onClick={() => setActiveTab("alerts")}
                            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-colors ${
                                activeTab === "alerts"
                                    ? "bg-white text-slate-900 shadow-sm border border-slate-200"
                                    : "text-slate-600 hover:bg-slate-200/70"
                            }`}
                        >
                            <Bell className="w-3.5 h-3.5 text-amber-600" />
                            Notifications Log
                            {unreadAlertsCount > 0 && (
                                <span className="px-1.5 py-0.2 rounded-full text-[10px] bg-rose-600 text-white font-bold">
                                    {unreadAlertsCount}
                                </span>
                            )}
                        </button>
                    </div>

                    <div className="flex items-center gap-2">
                        {canManage && activeTab === "timeline" && (
                            <>
                                <button
                                    onClick={handleGenerateDefaults}
                                    className="px-2.5 py-1 bg-white hover:bg-slate-50 border border-slate-300 rounded text-slate-700 font-semibold"
                                >
                                    Auto-Init Standard Schedule
                                </button>
                                <button
                                    onClick={() => {
                                        resetMilestoneForm();
                                        setEditingMilestone(null);
                                        setShowAddMilestone(true);
                                    }}
                                    className="inline-flex items-center gap-1 px-3 py-1 bg-blue-600 text-white rounded font-semibold hover:bg-blue-700 shadow-sm"
                                >
                                    <Plus className="w-3.5 h-3.5" /> Add Milestone
                                </button>
                            </>
                        )}
                        {canManage && activeTab === "clarifications" && (
                            <button
                                onClick={() => {
                                    resetClarificationForm();
                                    setShowAddClarification(true);
                                }}
                                className="inline-flex items-center gap-1 px-3 py-1 bg-indigo-600 text-white rounded font-semibold hover:bg-indigo-700 shadow-sm"
                            >
                                <Plus className="w-3.5 h-3.5" /> Log Clarification / Addendum
                            </button>
                        )}
                    </div>
                </div>

                {/* Body Content */}
                <div className="p-6 overflow-y-auto space-y-6 flex-1 bg-slate-50/50">
                    {/* Feedback Alerts */}
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
                            <RefreshCw className="w-8 h-8 text-blue-600 animate-spin mx-auto" />
                            <p className="text-xs text-slate-500 font-medium">
                                Loading procurement milestones & deadline schedule...
                            </p>
                        </div>
                    ) : (
                        <>
                            {/* TAB 1: MILESTONES TIMELINE */}
                            {activeTab === "timeline" && (
                                <div className="space-y-6">
                                    {/* Stats KPI Ribbon */}
                                    {schedule && (
                                        <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
                                            <div className="bg-white rounded-xl border border-slate-200 p-3.5 shadow-sm space-y-1">
                                                <div className="text-[11px] text-slate-400 font-semibold uppercase">Total Milestones</div>
                                                <div className="text-2xl font-bold font-mono text-slate-800">{schedule.total}</div>
                                            </div>
                                            <div className="bg-white rounded-xl border border-emerald-200 p-3.5 shadow-sm space-y-1 bg-emerald-50/30">
                                                <div className="text-[11px] text-emerald-700 font-semibold uppercase">Completed</div>
                                                <div className="text-2xl font-bold font-mono text-emerald-600">{schedule.completed_count}</div>
                                            </div>
                                            <div className="bg-white rounded-xl border border-amber-200 p-3.5 shadow-sm space-y-1 bg-amber-50/30">
                                                <div className="text-[11px] text-amber-700 font-semibold uppercase">Due in 48h (At Risk)</div>
                                                <div className="text-2xl font-bold font-mono text-amber-600">{schedule.at_risk_count}</div>
                                            </div>
                                            <div className="bg-white rounded-xl border border-rose-200 p-3.5 shadow-sm space-y-1 bg-rose-50/30">
                                                <div className="text-[11px] text-rose-700 font-semibold uppercase">Missed / Overdue</div>
                                                <div className="text-2xl font-bold font-mono text-rose-600">{schedule.missed_count}</div>
                                            </div>
                                            <div className="bg-white rounded-xl border border-blue-200 p-3.5 shadow-sm space-y-1 bg-blue-50/30">
                                                <div className="text-[11px] text-blue-700 font-semibold uppercase">Pending Remaining</div>
                                                <div className="text-2xl font-bold font-mono text-blue-600">{schedule.pending_count}</div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Timeline Stream */}
                                    {schedule && schedule.milestones.length === 0 ? (
                                        <div className="bg-white rounded-xl border border-slate-200 p-8 text-center space-y-3 shadow-sm">
                                            <Calendar className="w-10 h-10 text-slate-400 mx-auto" />
                                            <h3 className="text-sm font-bold text-slate-800">No Milestones Recorded</h3>
                                            <p className="text-xs text-slate-500 max-w-sm mx-auto">
                                                Generate standard Zimbabwean public procurement milestones (Clarification, Site Visit, Technical Review, Pricing Sign-off, and Bid Submission).
                                            </p>
                                            {canManage && (
                                                <button
                                                    onClick={handleGenerateDefaults}
                                                    className="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white rounded-lg text-xs font-semibold hover:bg-blue-700 shadow-sm transition-all"
                                                >
                                                    <Plus className="w-3.5 h-3.5" /> Initialize Standard Milestones
                                                </button>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                                            <div className="divide-y divide-slate-100">
                                                {schedule?.milestones.map((m, idx) => (
                                                    <div
                                                        key={m.id}
                                                        className={`py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 transition-colors ${
                                                            m.status === "completed"
                                                                ? "opacity-60 bg-slate-50/50 rounded-xl px-3"
                                                                : m.status === "at_risk"
                                                                ? "bg-amber-50/40 rounded-xl px-3 border border-amber-200"
                                                                : m.status === "missed"
                                                                ? "bg-rose-50/40 rounded-xl px-3 border border-rose-200"
                                                                : ""
                                                        }`}
                                                    >
                                                        <div className="flex items-start gap-3">
                                                            {/* Complete Checkbox Button */}
                                                            <button
                                                                disabled={!canManage}
                                                                onClick={() => handleToggleComplete(m)}
                                                                className={`mt-0.5 w-6 h-6 rounded-lg flex items-center justify-center transition-all ${
                                                                    m.status === "completed"
                                                                        ? "bg-emerald-600 text-white"
                                                                        : m.status === "missed"
                                                                        ? "border-2 border-rose-400 text-transparent hover:border-rose-600"
                                                                        : m.status === "at_risk"
                                                                        ? "border-2 border-amber-500 text-transparent hover:border-amber-600"
                                                                        : "border-2 border-slate-300 text-transparent hover:border-slate-500"
                                                                }`}
                                                                title={m.status === "completed" ? "Mark Pending" : "Mark Completed"}
                                                            >
                                                                <Check className="w-4 h-4 stroke-[3]" />
                                                            </button>

                                                            <div className="space-y-1">
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <span className="font-mono text-[10px] font-bold text-slate-400">
                                                                        #{idx + 1}
                                                                    </span>
                                                                    <span
                                                                        className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full border ${
                                                                            m.status === "completed"
                                                                                ? "bg-emerald-100 text-emerald-800 border-emerald-300"
                                                                                : m.status === "at_risk"
                                                                                ? "bg-amber-100 text-amber-900 border-amber-300 animate-pulse"
                                                                                : m.status === "missed"
                                                                                ? "bg-rose-100 text-rose-900 border-rose-300"
                                                                                : "bg-blue-50 text-blue-800 border-blue-200"
                                                                        }`}
                                                                    >
                                                                        {m.status.replace(/_/g, " ")}
                                                                    </span>
                                                                    <span className="text-[10px] font-bold uppercase text-slate-400 tracking-wider">
                                                                        {m.type.replace(/_/g, " ")}
                                                                    </span>
                                                                    <h4 className="text-xs sm:text-sm font-bold text-slate-900">
                                                                        {m.title}
                                                                    </h4>
                                                                </div>

                                                                {m.description && (
                                                                    <p className="text-xs text-slate-600 leading-relaxed max-w-2xl">
                                                                        {m.description}
                                                                    </p>
                                                                )}

                                                                <div className="flex flex-wrap items-center gap-3 text-[11px] text-slate-500 pt-1">
                                                                    <span className="flex items-center gap-1 font-semibold text-slate-700">
                                                                        <Calendar className="w-3.5 h-3.5 text-blue-500" />
                                                                        {formatDueDate(m.due_at)}
                                                                    </span>
                                                                    {m.venue_or_location && (
                                                                        <>
                                                                            <span>•</span>
                                                                            <span className="flex items-center gap-1">
                                                                                <MapPin className="w-3 h-3 text-slate-400" />
                                                                                {m.venue_or_location}
                                                                            </span>
                                                                        </>
                                                                    )}
                                                                    {m.responsible_employee && (
                                                                        <>
                                                                            <span>•</span>
                                                                            <span className="flex items-center gap-1">
                                                                                <Users className="w-3 h-3 text-slate-400" />
                                                                                {m.responsible_employee.name}
                                                                            </span>
                                                                        </>
                                                                    )}
                                                                    {m.completed_by && (
                                                                        <>
                                                                            <span>•</span>
                                                                            <span className="text-emerald-700 font-medium">
                                                                                Completed by {m.completed_by.name} on {new Date(m.completed_at!).toLocaleDateString()}
                                                                            </span>
                                                                        </>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {/* Actions */}
                                                        {canManage && (
                                                            <div className="flex items-center gap-2 self-end sm:self-center shrink-0">
                                                                <button
                                                                    onClick={() => {
                                                                        setEditingMilestone(m);
                                                                        setMilestoneTitle(m.title);
                                                                        setMilestoneType(m.type);
                                                                        setMilestoneDueAt(m.due_at.substring(0, 16));
                                                                        setMilestoneDesc(m.description || "");
                                                                        setMilestoneVenue(m.venue_or_location || "");
                                                                        setShowAddMilestone(true);
                                                                    }}
                                                                    className="p-1.5 text-slate-400 hover:text-slate-700 rounded-lg hover:bg-slate-100"
                                                                    title="Edit Milestone"
                                                                >
                                                                    <Edit3 className="w-3.5 h-3.5" />
                                                                </button>
                                                                <button
                                                                    onClick={() => handleDeleteMilestone(m.id)}
                                                                    className="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50"
                                                                    title="Delete Milestone"
                                                                >
                                                                    <Trash2 className="w-3.5 h-3.5" />
                                                                </button>
                                                            </div>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* TAB 2: CLARIFICATIONS & ADDENDA */}
                            {activeTab === "clarifications" && (
                                <div className="space-y-4">
                                    <div className="flex items-center justify-between border-b border-slate-200 pb-3">
                                        <div>
                                            <h3 className="text-sm font-bold text-slate-900">
                                                Clarification Requests & Procuring Entity Addenda
                                            </h3>
                                            <p className="text-xs text-slate-500">
                                                Record formal clarification inquiries sent to the procuring entity and track published tender addenda.
                                            </p>
                                        </div>
                                    </div>

                                    {clarifications.length === 0 ? (
                                        <div className="bg-white rounded-xl border border-slate-200 p-8 text-center space-y-3 shadow-sm">
                                            <FileText className="w-8 h-8 text-slate-400 mx-auto" />
                                            <h4 className="text-xs font-bold text-slate-700">No Clarifications or Addenda Logged</h4>
                                            <p className="text-xs text-slate-500 max-w-sm mx-auto">
                                                Log questions submitted before the clarification deadline, or record addenda extending tender closing dates.
                                            </p>
                                            {canManage && (
                                                <button
                                                    onClick={() => {
                                                        resetClarificationForm();
                                                        setShowAddClarification(true);
                                                    }}
                                                    className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-indigo-600 text-white rounded-lg text-xs font-semibold hover:bg-indigo-700 shadow-sm"
                                                >
                                                    <Plus className="w-3.5 h-3.5" /> Log First Record
                                                </button>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="space-y-3">
                                            {clarifications.map((item) => (
                                                <div
                                                    key={item.id}
                                                    className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm space-y-2.5"
                                                >
                                                    <div className="flex items-start justify-between gap-3 border-b border-slate-100 pb-2">
                                                        <div className="space-y-0.5">
                                                            <div className="flex items-center gap-2">
                                                                <span
                                                                    className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full border ${
                                                                        item.type === "addendum_received"
                                                                            ? "bg-purple-100 text-purple-900 border-purple-300"
                                                                            : "bg-blue-100 text-blue-900 border-blue-300"
                                                                    }`}
                                                                >
                                                                    {item.type === "addendum_received" ? "Addendum Notice" : "Clarification Query"}
                                                                </span>
                                                                <span className="font-mono text-xs font-bold text-slate-800">
                                                                    {item.reference_or_number}
                                                                </span>
                                                                {item.affects_closing_date && (
                                                                    <span className="text-[10px] font-bold bg-amber-100 text-amber-900 border border-amber-300 px-2 py-0.5 rounded">
                                                                        Extends Closing Date
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <h4 className="text-xs sm:text-sm font-bold text-slate-900">
                                                                {item.subject}
                                                            </h4>
                                                        </div>

                                                        <span className="text-[11px] text-slate-400 font-mono">
                                                            {new Date(item.submitted_or_received_at).toLocaleDateString()}
                                                        </span>
                                                    </div>

                                                    <div className="text-xs text-slate-700 bg-slate-50 p-3 rounded-lg leading-relaxed">
                                                        <strong>Details: </strong>
                                                        {item.question_or_content}
                                                    </div>

                                                    {item.response_or_amendment && (
                                                        <div className="text-xs text-emerald-900 bg-emerald-50/70 border border-emerald-200 p-3 rounded-lg leading-relaxed">
                                                            <strong>Procuring Entity Response: </strong>
                                                            {item.response_or_amendment}
                                                        </div>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* TAB 3: NOTIFICATIONS LOG */}
                            {activeTab === "alerts" && (
                                <div className="space-y-4">
                                    <div className="flex items-center justify-between border-b border-slate-200 pb-3">
                                        <div>
                                            <h3 className="text-sm font-bold text-slate-900">
                                                Tender Deadline & Milestones Notification Log
                                            </h3>
                                            <p className="text-xs text-slate-500">
                                                Automated alerts dispatched across 7 days, 3 days, 24 hours, and 2 hours reminder windows.
                                            </p>
                                        </div>
                                        {alerts.length > 0 && (
                                            <button
                                                onClick={handleMarkAllAlertsRead}
                                                className="text-xs text-blue-600 hover:text-blue-800 font-semibold"
                                            >
                                                Mark All Read
                                            </button>
                                        )}
                                    </div>

                                    {alerts.length === 0 ? (
                                        <div className="bg-white rounded-xl border border-slate-200 p-8 text-center space-y-2 text-xs text-slate-500">
                                            <Bell className="w-8 h-8 text-slate-300 mx-auto" />
                                            <p>No notifications generated yet for active tenders.</p>
                                        </div>
                                    ) : (
                                        <div className="space-y-2">
                                            {alerts.map((al) => (
                                                <div
                                                    key={al.id}
                                                    className={`p-3.5 rounded-xl border transition-colors flex items-start gap-3 ${
                                                        al.is_read
                                                            ? "bg-white border-slate-200 text-slate-600"
                                                            : al.level === "critical"
                                                            ? "bg-rose-50/80 border-rose-200 text-rose-900 font-medium"
                                                            : "bg-amber-50/80 border-amber-200 text-amber-900 font-medium"
                                                    }`}
                                                >
                                                    <Bell
                                                        className={`w-4 h-4 shrink-0 mt-0.5 ${
                                                            al.level === "critical" ? "text-rose-600" : "text-amber-600"
                                                        }`}
                                                    />
                                                    <div className="flex-1 space-y-0.5">
                                                        <div className="flex items-center justify-between">
                                                            <span className="text-xs font-bold">{al.title}</span>
                                                            <span className="text-[10px] text-slate-400">
                                                                {new Date(al.sent_at).toLocaleDateString()}
                                                            </span>
                                                        </div>
                                                        <p className="text-xs leading-relaxed">{al.message}</p>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}
                        </>
                    )}
                </div>

                {/* Footer */}
                <div className="bg-slate-100 px-6 py-3 border-t border-slate-200 flex items-center justify-between text-xs text-slate-500 shrink-0">
                    <div>
                        Lead time reminders: 7 days, 3 days, 24 hours, and 2 hours prior to statutory closing.
                    </div>
                    <button
                        onClick={onClose}
                        className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 rounded-lg border border-slate-300 transition-colors shadow-sm"
                    >
                        Close
                    </button>
                </div>
            </div>

            {/* ADD / EDIT MILESTONE MODAL */}
            {showAddMilestone && (
                <div className="fixed inset-0 z-60 overflow-y-auto bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 space-y-4 border border-slate-200">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                            <h3 className="text-sm font-bold text-slate-900">
                                {editingMilestone ? "Edit Milestone" : "Add Tender Milestone"}
                            </h3>
                            <button
                                onClick={() => setShowAddMilestone(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={handleSaveMilestone} className="space-y-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Milestone Title *
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={milestoneTitle}
                                    onChange={(e) => setMilestoneTitle(e.target.value)}
                                    placeholder="e.g. Executive Pricing Sign-off"
                                    className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2"
                                />
                            </div>

                            {!editingMilestone && (
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Milestone Classification
                                    </label>
                                    <select
                                        value={milestoneType}
                                        onChange={(e) => setMilestoneType(e.target.value)}
                                        className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white"
                                    >
                                        <option value="custom">Custom Milestone</option>
                                        <option value="pricing_signoff">Pricing Sign-off</option>
                                        <option value="technical_review">Technical Review</option>
                                        <option value="internal_draft">Internal Proposal Draft</option>
                                        <option value="clarification_deadline">Clarification Cut-Off</option>
                                        <option value="site_visit">Pre-Bid Site Inspection</option>
                                        <option value="submission_closing">Final Submission Deadline</option>
                                    </select>
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Target Date & Time *
                                </label>
                                <input
                                    type="datetime-local"
                                    required
                                    value={milestoneDueAt}
                                    onChange={(e) => setMilestoneDueAt(e.target.value)}
                                    className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Venue / Physical Location (optional)
                                </label>
                                <input
                                    type="text"
                                    value={milestoneVenue}
                                    onChange={(e) => setMilestoneVenue(e.target.value)}
                                    placeholder="e.g. ZETDC Coventry Road Substation"
                                    className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Description / Action Notes
                                </label>
                                <textarea
                                    rows={2}
                                    value={milestoneDesc}
                                    onChange={(e) => setMilestoneDesc(e.target.value)}
                                    placeholder="Required actions, lead officers, or compliance checks..."
                                    className="w-full text-xs border border-slate-300 rounded-lg p-2.5"
                                />
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setShowAddMilestone(false)}
                                    className="px-3 py-1.5 text-xs text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingMilestone}
                                    className="px-4 py-1.5 text-xs font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
                                >
                                    {savingMilestone ? "Saving..." : "Save Milestone"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* ADD CLARIFICATION / ADDENDUM MODAL */}
            {showAddClarification && (
                <div className="fixed inset-0 z-60 overflow-y-auto bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 space-y-4 border border-slate-200">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                            <h3 className="text-sm font-bold text-slate-900">
                                Log Clarification Request / Addendum
                            </h3>
                            <button
                                onClick={() => setShowAddClarification(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={handleSaveClarification} className="space-y-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Record Classification *
                                </label>
                                <select
                                    value={clarType}
                                    onChange={(e) => setClarType(e.target.value as any)}
                                    className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white"
                                >
                                    <option value="clarification_request">Formal Clarification Request (Sent to Procuring Entity)</option>
                                    <option value="addendum_received">Published Addendum / Tender Extension Notice</option>
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Reference Number *
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={clarRef}
                                    onChange={(e) => setClarRef(e.target.value)}
                                    placeholder="e.g. Addendum No. 1 or Query #02"
                                    className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Subject / Topic *
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={clarSubject}
                                    onChange={(e) => setClarSubject(e.target.value)}
                                    placeholder="e.g. Clarification on Transformer Primary Voltage Specifications"
                                    className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Content / Notice Body *
                                </label>
                                <textarea
                                    rows={3}
                                    required
                                    value={clarContent}
                                    onChange={(e) => setClarContent(e.target.value)}
                                    placeholder="State the formal query submitted or copy the published addendum changes..."
                                    className="w-full text-xs border border-slate-300 rounded-lg p-2.5"
                                />
                            </div>

                            {clarType === "addendum_received" && (
                                <div className="space-y-2 p-3 bg-amber-50 rounded-xl border border-amber-200">
                                    <label className="flex items-center gap-2 text-xs font-semibold text-amber-900 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={clarAffectsClosing}
                                            onChange={(e) => setClarAffectsClosing(e.target.checked)}
                                            className="rounded text-amber-600 focus:ring-amber-500"
                                        />
                                        This Addendum Extends the Closing Date
                                    </label>

                                    {clarAffectsClosing && (
                                        <div>
                                            <label className="block text-[11px] font-bold text-amber-800 mb-1">
                                                New Extended Closing Date & Time *
                                            </label>
                                            <input
                                                type="datetime-local"
                                                required={clarAffectsClosing}
                                                value={clarNewClosing}
                                                onChange={(e) => setClarNewClosing(e.target.value)}
                                                className="w-full text-xs border border-amber-300 rounded-lg px-2.5 py-1.5 bg-white"
                                            />
                                        </div>
                                    )}
                                </div>
                            )}

                            <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setShowAddClarification(false)}
                                    className="px-3 py-1.5 text-xs text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingClarification}
                                    className="px-4 py-1.5 text-xs font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    {savingClarification ? "Recording..." : "Save Record"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}

