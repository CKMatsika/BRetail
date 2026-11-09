import React, { useState, useEffect } from "react";
import axios from "axios";
import {
    CheckCircle2,
    XCircle,
    AlertTriangle,
    Clock,
    ShieldCheck,
    Send,
    FileSpreadsheet,
    FileText,
    Users,
    Building2,
    Download,
    X,
    RefreshCw,
    Check,
    UploadCloud,
    HelpCircle,
    Info,
    ArrowRight,
    MapPin,
    Eye,
    Plus,
    Trash2,
} from "lucide-react";

export type ApprovalStage = {
    id: number;
    stage: "technical" | "commercial" | "executive";
    status: "pending" | "approved" | "rejected" | "conditional";
    approver_id: number | null;
    approver_name: string | null;
    approver_role: string | null;
    comments: string | null;
    conditions: string | null;
    actioned_at: string | null;
};

export type ReadinessCheckItem = {
    key: string;
    label: string;
    passed: boolean;
    details: string;
};

export type SubmissionData = {
    id: number;
    submission_channel: string;
    egp_reference_number: string | null;
    physical_box_location: string | null;
    delivered_by_name: string | null;
    delivered_by_phone: string | null;
    receipt_number: string | null;
    submitted_at: string;
    technical_envelope_verified: boolean;
    financial_envelope_verified: boolean;
    bid_security_verified: boolean;
    copies_count: number;
    package_notes: string | null;
};

export type OpeningRecord = {
    id: number;
    attended_by_name: string | null;
    opening_date: string | null;
    venue: string | null;
    minutes_notes: string | null;
    competitor_bids: {
        competitor_name: string;
        currency: string;
        bid_amount: number;
        bid_security_provided: boolean;
        remarks: string;
    }[];
};

type TenderSubmissionModalProps = {
    tenderId: number;
    canManage: boolean;
    onClose: () => void;
    onStatusChange?: (newStatus: string) => void;
};

export default function TenderSubmissionModal({
    tenderId,
    canManage,
    onClose,
    onStatusChange,
}: TenderSubmissionModalProps) {
    const [tender, setTender] = useState<any>(null);
    const [approvals, setApprovals] = useState<ApprovalStage[]>([]);
    const [readiness, setReadiness] = useState<any>(null);
    const [submission, setSubmission] = useState<SubmissionData | null>(null);
    const [openingRecord, setOpeningRecord] = useState<OpeningRecord | null>(null);
    const [loading, setLoading] = useState(true);
    const [activeTab, setActiveTab] = useState<"approvals" | "submission" | "opening">("approvals");

    // Approval Action Dialog
    const [selectedStageForApproval, setSelectedStageForApproval] = useState<ApprovalStage | null>(null);
    const [approvalDecision, setApprovalDecision] = useState<"approved" | "rejected" | "conditional">("approved");
    const [approvalComments, setApprovalComments] = useState("");
    const [approvalConditions, setApprovalConditions] = useState("");
    const [submittingApproval, setSubmittingApproval] = useState(false);

    // Official Submission Form
    const [subForm, setSubForm] = useState({
        submission_channel: "praz_egp",
        egp_reference_number: "",
        physical_box_location: "",
        delivered_by_name: "",
        delivered_by_phone: "",
        receipt_number: "",
        submitted_at: new Date().toISOString().slice(0, 16),
        technical_envelope_verified: true,
        financial_envelope_verified: true,
        bid_security_verified: true,
        copies_count: 1,
        package_notes: "",
    });
    const [recordingSubmission, setRecordingSubmission] = useState(false);

    // Opening Minutes Form
    const [openingForm, setOpeningForm] = useState({
        attended_by_name: "",
        opening_date: new Date().toISOString().slice(0, 16),
        venue: "",
        minutes_notes: "",
        competitor_bids: [] as any[],
    });
    const [recordingOpening, setRecordingOpening] = useState(false);

    // New competitor row in opening
    const [compName, setCompName] = useState("");
    const [compPrice, setCompPrice] = useState("");
    const [compBond, setCompBond] = useState(true);
    const [compNotes, setCompNotes] = useState("");

    useEffect(() => {
        fetchSubmissionData();
    }, [tenderId]);

    const fetchSubmissionData = async () => {
        try {
            setLoading(true);
            const res = await axios.get(`/tenders/${tenderId}/submissions`);
            setTender(res.data.tender);
            setApprovals(res.data.approvals || []);
            setReadiness(res.data.readiness || null);
            setSubmission(res.data.submission || null);
            setOpeningRecord(res.data.opening_record || null);

            if (res.data.submission) {
                const s = res.data.submission;
                setSubForm({
                    submission_channel: s.submission_channel || "praz_egp",
                    egp_reference_number: s.egp_reference_number || "",
                    physical_box_location: s.physical_box_location || "",
                    delivered_by_name: s.delivered_by_name || "",
                    delivered_by_phone: s.delivered_by_phone || "",
                    receipt_number: s.receipt_number || "",
                    submitted_at: s.submitted_at ? s.submitted_at.slice(0, 16) : new Date().toISOString().slice(0, 16),
                    technical_envelope_verified: s.technical_envelope_verified ?? true,
                    financial_envelope_verified: s.financial_envelope_verified ?? true,
                    bid_security_verified: s.bid_security_verified ?? true,
                    copies_count: s.copies_count || 1,
                    package_notes: s.package_notes || "",
                });
            }

            if (res.data.opening_record) {
                const o = res.data.opening_record;
                setOpeningForm({
                    attended_by_name: o.attended_by_name || "",
                    opening_date: o.opening_date ? o.opening_date.slice(0, 16) : new Date().toISOString().slice(0, 16),
                    venue: o.venue || "",
                    minutes_notes: o.minutes_notes || "",
                    competitor_bids: o.competitor_bids || [],
                });
            }
        } catch (err) {
            console.error("Failed to load submission data", err);
        } finally {
            setLoading(false);
        }
    };

    const handleOpenApprovalModal = (stage: ApprovalStage) => {
        setSelectedStageForApproval(stage);
        setApprovalDecision("approved");
        setApprovalComments(stage.comments || "");
        setApprovalConditions(stage.conditions || "");
    };

    const handleSaveApprovalDecision = async () => {
        if (!selectedStageForApproval) return;
        try {
            setSubmittingApproval(true);
            const res = await axios.post(`/tenders/${tenderId}/approvals`, {
                stage: selectedStageForApproval.stage,
                status: approvalDecision,
                comments: approvalComments,
                conditions: approvalConditions,
            });

            setApprovals(res.data.approvals);
            setReadiness(res.data.readiness);
            setSelectedStageForApproval(null);
            alert(`Approval stage for ${selectedStageForApproval.stage} marked as ${approvalDecision}!`);
        } catch (err: any) {
            alert(err.response?.data?.message || "Failed to record approval");
        } finally {
            setSubmittingApproval(false);
        }
    };

    const handleRecordSubmission = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            setRecordingSubmission(true);
            const res = await axios.post(`/tenders/${tenderId}/submissions`, subForm);
            setSubmission(res.data.submission);
            if (tender) {
                setTender({ ...tender, status: res.data.tender_status });
            }
            if (onStatusChange) {
                onStatusChange(res.data.tender_status);
            }
            alert("Tender bid officially recorded as SUBMITTED!");
        } catch (err: any) {
            alert(err.response?.data?.message || "Failed to record submission");
        } finally {
            setRecordingSubmission(false);
        }
    };

    const handleAddCompetitor = () => {
        if (!compName.trim() || !compPrice) return;
        const newBid = {
            competitor_name: compName.trim(),
            currency: tender?.currency || "USD",
            bid_amount: parseFloat(compPrice) || 0,
            bid_security_provided: compBond,
            remarks: compNotes.trim(),
        };
        setOpeningForm({
            ...openingForm,
            competitor_bids: [...openingForm.competitor_bids, newBid],
        });
        setCompName("");
        setCompPrice("");
        setCompNotes("");
    };

    const handleRemoveCompetitor = (idx: number) => {
        setOpeningForm({
            ...openingForm,
            competitor_bids: openingForm.competitor_bids.filter((_, i) => i !== idx),
        });
    };

    const handleSaveOpeningMinutes = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            setRecordingOpening(true);
            const res = await axios.post(`/tenders/${tenderId}/opening-minutes`, openingForm);
            setOpeningRecord(res.data.opening_record);
            alert("Public opening minutes and competitor bids recorded successfully!");
        } catch (err: any) {
            alert(err.response?.data?.message || "Failed to save opening minutes");
        } finally {
            setRecordingOpening(false);
        }
    };

    if (loading) {
        return (
            <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
                <div className="bg-white rounded-2xl p-8 max-w-md w-full text-center shadow-2xl">
                    <RefreshCw className="w-10 h-10 text-emerald-600 animate-spin mx-auto mb-4" />
                    <h3 className="text-lg font-bold text-gray-900 mb-1">Loading Submission Management...</h3>
                    <p className="text-sm text-gray-500">Checking multi-tier approvals & readiness checklist</p>
                </div>
            </div>
        );
    }

    const getStageTitle = (stage: string) => {
        switch (stage) {
            case "technical":
                return "1. Technical & Compliance Verification";
            case "commercial":
                return "2. Commercial & Pricing Verification";
            case "executive":
                return "3. Executive Sign-Off & Power of Attorney";
            default:
                return stage;
        }
    };

    const getStatusBadge = (status: string) => {
        switch (status) {
            case "approved":
                return <span className="bg-emerald-100 text-emerald-800 text-xs px-2.5 py-1 rounded-full font-bold border border-emerald-200 flex items-center gap-1"><CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" /> Approved</span>;
            case "conditional":
                return <span className="bg-amber-100 text-amber-800 text-xs px-2.5 py-1 rounded-full font-bold border border-amber-200 flex items-center gap-1"><AlertTriangle className="w-3.5 h-3.5 text-amber-600" /> Conditional</span>;
            case "rejected":
                return <span className="bg-rose-100 text-rose-800 text-xs px-2.5 py-1 rounded-full font-bold border border-rose-200 flex items-center gap-1"><XCircle className="w-3.5 h-3.5 text-rose-600" /> Rejected</span>;
            default:
                return <span className="bg-gray-100 text-gray-600 text-xs px-2.5 py-1 rounded-full font-bold border border-gray-200 flex items-center gap-1"><Clock className="w-3.5 h-3.5 text-gray-400" /> Pending</span>;
        }
    };

    return (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
            <div className="bg-white rounded-2xl w-full max-w-6xl h-[90vh] flex flex-col shadow-2xl overflow-hidden border border-gray-100">
                {/* HEADER */}
                <div className="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-900 via-slate-800 to-gray-900 text-white flex items-center justify-between shrink-0">
                    <div className="flex items-center space-x-4">
                        <div className="p-3 bg-emerald-500/20 border border-emerald-400/30 rounded-xl text-emerald-400">
                            <ShieldCheck className="w-6 h-6" />
                        </div>
                        <div>
                            <div className="flex items-center space-x-3">
                                <h2 className="text-xl font-bold tracking-tight">Tender Approvals & Submission Studio</h2>
                                <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-400/30">
                                    Stage 9 Workflow
                                </span>
                                <span className="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-slate-800 text-slate-200 border border-slate-700">
                                    Status: {tender?.status}
                                </span>
                            </div>
                            <p className="text-xs text-gray-300 mt-1">
                                <span className="font-semibold text-white">{tender?.reference_number}</span> &bull; {tender?.title} &bull; <span className="text-emerald-300">{tender?.procuring_entity}</span>
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center space-x-3">
                        <a
                            href={`/tenders/${tenderId}/audit-export`}
                            className="flex items-center space-x-2 px-3 py-2 bg-gray-800 hover:bg-gray-700 text-gray-200 text-sm font-medium rounded-lg border border-gray-700 transition"
                            title="Download full audit log CSV"
                        >
                            <FileSpreadsheet className="w-4 h-4 text-emerald-400" />
                            <span>Export Audit Trail</span>
                        </a>

                        <button
                            onClick={onClose}
                            className="p-2 text-gray-400 hover:text-white hover:bg-gray-800 rounded-lg transition"
                        >
                            <X className="w-6 h-6" />
                        </button>
                    </div>
                </div>

                {/* TABS HEADER */}
                <div className="px-6 border-b border-gray-200 flex items-center justify-between shrink-0 bg-white">
                    <div className="flex space-x-6">
                        <button
                            onClick={() => setActiveTab("approvals")}
                            className={`py-3 text-sm font-semibold border-b-2 flex items-center space-x-2 transition ${
                                activeTab === "approvals"
                                    ? "border-emerald-600 text-emerald-600"
                                    : "border-transparent text-gray-500 hover:text-gray-900"
                            }`}
                        >
                            <ShieldCheck className="w-4 h-4" />
                            <span>Multi-Tier Approvals & Readiness</span>
                            <span className={`ml-1.5 px-2 py-0.5 text-xs rounded-full font-bold ${
                                readiness?.is_ready ? "bg-emerald-100 text-emerald-800" : "bg-amber-100 text-amber-800"
                            }`}>
                                {readiness?.passed_count || 0}/{readiness?.total_count || 6}
                            </span>
                        </button>

                        <button
                            onClick={() => setActiveTab("submission")}
                            className={`py-3 text-sm font-semibold border-b-2 flex items-center space-x-2 transition ${
                                activeTab === "submission"
                                    ? "border-emerald-600 text-emerald-600"
                                    : "border-transparent text-gray-500 hover:text-gray-900"
                            }`}
                        >
                            <Send className="w-4 h-4" />
                            <span>Packaging & Bid Delivery Proof</span>
                            {submission && (
                                <span className="ml-1 px-1.5 py-0.5 text-[10px] rounded bg-emerald-100 text-emerald-800 font-bold">
                                    SUBMITTED
                                </span>
                            )}
                        </button>

                        <button
                            onClick={() => setActiveTab("opening")}
                            className={`py-3 text-sm font-semibold border-b-2 flex items-center space-x-2 transition ${
                                activeTab === "opening"
                                    ? "border-emerald-600 text-emerald-600"
                                    : "border-transparent text-gray-500 hover:text-gray-900"
                            }`}
                        >
                            <Users className="w-4 h-4" />
                            <span>Public Opening & Competitor Bids</span>
                            {openingRecord?.competitor_bids?.length ? (
                                <span className="ml-1 px-1.5 py-0.5 text-[10px] rounded bg-purple-100 text-purple-800 font-bold">
                                    {openingRecord.competitor_bids.length} Competitors
                                </span>
                            ) : null}
                        </button>
                    </div>
                </div>

                {/* TAB CONTENT */}
                <div className="flex-1 overflow-y-auto p-6 bg-gray-50/50">
                    {/* TAB 1: APPROVALS & READINESS */}
                    {activeTab === "approvals" && (
                        <div className="max-w-5xl mx-auto space-y-6">
                            {/* READINESS BANNER */}
                            <div className={`p-5 rounded-2xl border shadow-sm ${
                                readiness?.is_ready ? "bg-emerald-50 border-emerald-200" : "bg-amber-50 border-amber-200"
                            }`}>
                                <div className="flex items-start justify-between">
                                    <div className="flex items-start space-x-3">
                                        {readiness?.is_ready ? (
                                            <CheckCircle2 className="w-6 h-6 text-emerald-600 shrink-0 mt-0.5" />
                                        ) : (
                                            <AlertTriangle className="w-6 h-6 text-amber-600 shrink-0 mt-0.5" />
                                        )}
                                        <div>
                                            <h3 className={`text-base font-bold ${
                                                readiness?.is_ready ? "text-emerald-900" : "text-amber-900"
                                            }`}>
                                                {readiness?.is_ready
                                                    ? "Submission Gate Cleared – Ready to Seal & Submit"
                                                    : "Submission Pre-Flight Gate – Action Items Pending"}
                                            </h3>
                                            <p className={`text-xs mt-1 ${
                                                readiness?.is_ready ? "text-emerald-700" : "text-amber-700"
                                            }`}>
                                                {readiness?.is_ready
                                                    ? "All 3 approval tiers passed, commercial pricing verified, and statutory documents active."
                                                    : "Review the checklist below. All criteria must be satisfied prior to formal tender closure."}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <div className="text-xl font-bold font-mono text-gray-900">
                                            {readiness?.passed_count} / {readiness?.total_count}
                                        </div>
                                        <div className="text-[11px] text-gray-500 uppercase font-semibold">Checks Passed</div>
                                    </div>
                                </div>

                                <div className="mt-4 pt-4 border-t border-gray-200/60 grid grid-cols-1 md:grid-cols-2 gap-3">
                                    {readiness?.checklist?.map((item: ReadinessCheckItem) => (
                                        <div
                                            key={item.key}
                                            className="flex items-center space-x-3 bg-white p-3 rounded-xl border border-gray-200 text-xs shadow-2xs"
                                        >
                                            {item.passed ? (
                                                <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                                            ) : (
                                                <XCircle className="w-4 h-4 text-rose-500 shrink-0" />
                                            )}
                                            <div className="flex-1 truncate">
                                                <div className="font-semibold text-gray-900 truncate">{item.label}</div>
                                                <div className="text-[11px] text-gray-500 truncate">{item.details}</div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* 3-TIER APPROVAL CARDS */}
                            <div className="space-y-4">
                                <h3 className="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                                    <ShieldCheck className="w-4 h-4 text-emerald-600" />
                                    Mandatory Corporate Approval Tiers
                                </h3>

                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    {approvals.map((stage) => (
                                        <div
                                            key={stage.id}
                                            className="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 flex flex-col justify-between"
                                        >
                                            <div>
                                                <div className="flex items-start justify-between">
                                                    <div>
                                                        <h4 className="font-bold text-gray-900 text-sm">
                                                            {getStageTitle(stage.stage)}
                                                        </h4>
                                                        <div className="text-xs text-gray-500 mt-0.5">
                                                            {stage.approver_role}
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="my-3">
                                                    {getStatusBadge(stage.status)}
                                                </div>

                                                {stage.actioned_at ? (
                                                    <div className="bg-gray-50 rounded-lg p-2.5 border border-gray-100 text-xs space-y-1">
                                                        <div className="text-gray-600">
                                                            Sign-off by: <span className="font-semibold text-gray-900">{stage.approver_name || "Manager"}</span>
                                                        </div>
                                                        <div className="text-gray-400 text-[11px]">
                                                            {new Date(stage.actioned_at).toLocaleString()}
                                                        </div>
                                                        {stage.comments && (
                                                            <div className="text-gray-700 italic pt-1 border-t border-gray-200/60">
                                                                "{stage.comments}"
                                                            </div>
                                                        )}
                                                        {stage.conditions && (
                                                            <div className="text-amber-800 font-medium pt-1">
                                                                Condition: {stage.conditions}
                                                            </div>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <div className="text-xs text-gray-400 italic py-2">
                                                        Awaiting sign-off decision
                                                    </div>
                                                )}
                                            </div>

                                            {canManage && (
                                                <div className="mt-4 pt-3 border-t border-gray-100">
                                                    <button
                                                        onClick={() => handleOpenApprovalModal(stage)}
                                                        className="w-full py-2 px-3 bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold rounded-lg shadow-sm transition flex items-center justify-center gap-1.5"
                                                    >
                                                        <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" />
                                                        <span>Record Sign-Off Decision</span>
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* TAB 2: PACKAGING & SUBMISSION */}
                    {activeTab === "submission" && (
                        <div className="max-w-4xl mx-auto space-y-6">
                            <form onSubmit={handleRecordSubmission} className="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm space-y-6">
                                <div>
                                    <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">
                                        <Send className="w-5 h-5 text-emerald-600" />
                                        Official Bid Packaging & Proof of Delivery
                                    </h3>
                                    <p className="text-xs text-gray-500 mt-1">
                                        Record formal dispatch to PRAZ eGP, physical tender box, or donor procurement portal
                                    </p>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Submission Channel *
                                        </label>
                                        <select
                                            value={subForm.submission_channel}
                                            onChange={(e) => setSubForm({ ...subForm, submission_channel: e.target.value })}
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        >
                                            <option value="praz_egp">PRAZ eGP Electronic Portal</option>
                                            <option value="physical_box">Physical Tender Box Deposit</option>
                                            <option value="email_portal">Donor / NGO Portal or Email</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Submission Timestamp *
                                        </label>
                                        <input
                                            type="datetime-local"
                                            value={subForm.submitted_at}
                                            onChange={(e) => setSubForm({ ...subForm, submitted_at: e.target.value })}
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>

                                    {subForm.submission_channel === "praz_egp" ? (
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 mb-1">
                                                eGP System Reference / Transaction Number
                                            </label>
                                            <input
                                                type="text"
                                                value={subForm.egp_reference_number}
                                                onChange={(e) => setSubForm({ ...subForm, egp_reference_number: e.target.value })}
                                                placeholder="e.g. EGP-2026-TX-98442"
                                                className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                            />
                                        </div>
                                    ) : (
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 mb-1">
                                                Physical Tender Box Location / Office Address
                                            </label>
                                            <input
                                                type="text"
                                                value={subForm.physical_box_location}
                                                onChange={(e) => setSubForm({ ...subForm, physical_box_location: e.target.value })}
                                                placeholder="e.g. Procurement Office, 2nd Floor, Tender Box A"
                                                className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                            />
                                        </div>
                                    )}

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Receipt / Acknowledgment Number
                                        </label>
                                        <input
                                            type="text"
                                            value={subForm.receipt_number}
                                            onChange={(e) => setSubForm({ ...subForm, receipt_number: e.target.value })}
                                            placeholder="e.g. REC-88771-ZETDC"
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Delivered By (Courier / Representative Name)
                                        </label>
                                        <input
                                            type="text"
                                            value={subForm.delivered_by_name}
                                            onChange={(e) => setSubForm({ ...subForm, delivered_by_name: e.target.value })}
                                            placeholder="e.g. Kudakwashe Chiwenga"
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Representative Contact Phone
                                        </label>
                                        <input
                                            type="text"
                                            value={subForm.delivered_by_phone}
                                            onChange={(e) => setSubForm({ ...subForm, delivered_by_phone: e.target.value })}
                                            placeholder="e.g. +263 77 123 4567"
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>
                                </div>

                                {/* ENVELOPE VERIFICATION */}
                                <div className="bg-gray-50 p-4 rounded-xl border border-gray-200">
                                    <div className="text-xs font-bold text-gray-900 mb-3">Two-Envelope Packaging Verification</div>
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <label className="flex items-center space-x-2 text-xs font-medium text-gray-700 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={subForm.technical_envelope_verified}
                                                onChange={(e) => setSubForm({ ...subForm, technical_envelope_verified: e.target.checked })}
                                                className="rounded text-emerald-600 focus:ring-emerald-500"
                                            />
                                            <span>Technical Envelope Sealed & Labelled</span>
                                        </label>

                                        <label className="flex items-center space-x-2 text-xs font-medium text-gray-700 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={subForm.financial_envelope_verified}
                                                onChange={(e) => setSubForm({ ...subForm, financial_envelope_verified: e.target.checked })}
                                                className="rounded text-emerald-600 focus:ring-emerald-500"
                                            />
                                            <span>Financial Envelope Sealed Separately</span>
                                        </label>

                                        <label className="flex items-center space-x-2 text-xs font-medium text-gray-700 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={subForm.bid_security_verified}
                                                onChange={(e) => setSubForm({ ...subForm, bid_security_verified: e.target.checked })}
                                                className="rounded text-emerald-600 focus:ring-emerald-500"
                                            />
                                            <span>Bid Security / Bond Attached</span>
                                        </label>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 mb-1">
                                        Delivery Notes & Comments
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={subForm.package_notes}
                                        onChange={(e) => setSubForm({ ...subForm, package_notes: e.target.value })}
                                        placeholder="e.g. Deposited into Main Reception Tender Box; received stamped delivery receipt."
                                        className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                    />
                                </div>

                                <div className="flex justify-end pt-3 border-t border-gray-200">
                                    <button
                                        type="submit"
                                        disabled={recordingSubmission}
                                        className="flex items-center space-x-2 px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-sm rounded-lg shadow-md transition"
                                    >
                                        <Send className="w-4 h-4" />
                                        <span>{recordingSubmission ? "Recording..." : "Record Official Bid Submission"}</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}

                    {/* TAB 3: OPENING MINUTES & COMPETITORS */}
                    {activeTab === "opening" && (
                        <div className="max-w-4xl mx-auto space-y-6">
                            <form onSubmit={handleSaveOpeningMinutes} className="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm space-y-6">
                                <div>
                                    <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">
                                        <Users className="w-5 h-5 text-purple-600" />
                                        Public Tender Opening Attendance & Competitor Bids
                                    </h3>
                                    <p className="text-xs text-gray-500 mt-1">
                                        Log the official read-out prices and notes announced during the public opening session
                                    </p>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Representative Attending
                                        </label>
                                        <input
                                            type="text"
                                            value={openingForm.attended_by_name}
                                            onChange={(e) => setOpeningForm({ ...openingForm, attended_by_name: e.target.value })}
                                            placeholder="e.g. Eng. Munyaradzi Moyo"
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Opening Date & Time
                                        </label>
                                        <input
                                            type="datetime-local"
                                            value={openingForm.opening_date}
                                            onChange={(e) => setOpeningForm({ ...openingForm, opening_date: e.target.value })}
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Opening Venue
                                        </label>
                                        <input
                                            type="text"
                                            value={openingForm.venue}
                                            onChange={(e) => setOpeningForm({ ...openingForm, venue: e.target.value })}
                                            placeholder="e.g. Main Boardroom, 2nd Floor"
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>
                                </div>

                                {/* COMPETITOR BIDS LIST */}
                                <div className="border border-gray-200 rounded-xl overflow-hidden">
                                    <div className="bg-gray-100/75 px-4 py-2.5 border-b border-gray-200 text-xs font-bold text-gray-700 flex justify-between items-center">
                                        <span>Bidders & Read-Out Commercial Prices</span>
                                        <span className="text-[11px] text-gray-500 font-normal">
                                            {openingForm.competitor_bids.length} competitor bids recorded
                                        </span>
                                    </div>

                                    {openingForm.competitor_bids.length > 0 ? (
                                        <div className="divide-y divide-gray-100">
                                            {openingForm.competitor_bids.map((b, idx) => (
                                                <div key={idx} className="p-3 flex items-center justify-between text-xs hover:bg-gray-50">
                                                    <div>
                                                        <div className="font-bold text-gray-900">{b.competitor_name}</div>
                                                        <div className="text-gray-500 text-[11px]">
                                                            Bond: {b.bid_security_provided ? "Compliant" : "Missing / Non-Compliant"} &bull; {b.remarks || "No remarks"}
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center space-x-3">
                                                        <span className="font-mono font-bold text-gray-900">
                                                            {b.currency} {Number(b.bid_amount).toLocaleString()}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRemoveCompetitor(idx)}
                                                            className="text-gray-400 hover:text-rose-600"
                                                        >
                                                            <Trash2 className="w-4 h-4" />
                                                        </button>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="py-6 text-center text-xs text-gray-400">
                                            No competitor bids recorded yet. Add them below.
                                        </div>
                                    )}

                                    {/* ADD COMPETITOR ROW */}
                                    <div className="bg-gray-50 p-3 border-t border-gray-200 grid grid-cols-1 md:grid-cols-4 gap-2">
                                        <input
                                            type="text"
                                            value={compName}
                                            onChange={(e) => setCompName(e.target.value)}
                                            placeholder="Competitor Name *"
                                            className="text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                        <input
                                            type="number"
                                            step="0.01"
                                            value={compPrice}
                                            onChange={(e) => setCompPrice(e.target.value)}
                                            placeholder={`Bid Price (${tender?.currency || "USD"}) *`}
                                            className="text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                        <input
                                            type="text"
                                            value={compNotes}
                                            onChange={(e) => setCompNotes(e.target.value)}
                                            placeholder="Notes / Bid Bond remarks"
                                            className="text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                        <button
                                            type="button"
                                            onClick={handleAddCompetitor}
                                            className="px-3 py-1.5 bg-purple-600 hover:bg-purple-500 text-white text-xs font-semibold rounded-lg shadow-sm flex items-center justify-center gap-1"
                                        >
                                            <Plus className="w-3.5 h-3.5" />
                                            <span>Add Bid</span>
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 mb-1">
                                        General Public Opening Minutes & Committee Observations
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={openingForm.minutes_notes}
                                        onChange={(e) => setOpeningForm({ ...openingForm, minutes_notes: e.target.value })}
                                        placeholder="Record any committee announcements, sample submissions, or rejected late envelopes..."
                                        className="w-full text-xs border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500"
                                    />
                                </div>

                                <div className="flex justify-end pt-3 border-t border-gray-200">
                                    <button
                                        type="submit"
                                        disabled={recordingOpening}
                                        className="flex items-center space-x-2 px-5 py-2.5 bg-purple-600 hover:bg-purple-500 text-white font-semibold text-sm rounded-lg shadow-md transition"
                                    >
                                        <CheckCircle2 className="w-4 h-4" />
                                        <span>{recordingOpening ? "Saving..." : "Save Public Opening Minutes"}</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}
                </div>

                {/* MODAL: RECORD APPROVAL DECISION */}
                {selectedStageForApproval && (
                    <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
                        <div className="bg-white rounded-2xl p-6 max-w-md w-full shadow-2xl">
                            <div className="flex items-center justify-between pb-3 border-b border-gray-200">
                                <div>
                                    <h3 className="font-bold text-gray-900 text-sm">
                                        Sign-Off: {getStageTitle(selectedStageForApproval.stage)}
                                    </h3>
                                    <p className="text-xs text-gray-500">{selectedStageForApproval.approver_role}</p>
                                </div>
                                <button
                                    onClick={() => setSelectedStageForApproval(null)}
                                    className="text-gray-400 hover:text-gray-600"
                                >
                                    <X className="w-5 h-5" />
                                </button>
                            </div>

                            <div className="mt-4 space-y-4">
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 mb-1">
                                        Approval Status *
                                    </label>
                                    <select
                                        value={approvalDecision}
                                        onChange={(e) => setApprovalDecision(e.target.value as any)}
                                        className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                    >
                                        <option value="approved">Approved</option>
                                        <option value="conditional">Conditional Approval</option>
                                        <option value="rejected">Rejected (Needs Revision)</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 mb-1">
                                        Approval Comments / Feedback
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={approvalComments}
                                        onChange={(e) => setApprovalComments(e.target.value)}
                                        placeholder="Add comments on specifications, pricing, or compliance..."
                                        className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                    />
                                </div>

                                {approvalDecision === "conditional" && (
                                    <div>
                                        <label className="block text-xs font-semibold text-amber-800 mb-1">
                                            Conditions to be Satisfied Prior to Submission
                                        </label>
                                        <textarea
                                            rows={2}
                                            value={approvalConditions}
                                            onChange={(e) => setApprovalConditions(e.target.value)}
                                            placeholder="e.g. Ensure original bank bond is retrieved before 10 AM dispatch"
                                            className="w-full text-xs border-amber-300 bg-amber-50/50 rounded-lg focus:ring-amber-500 focus:border-amber-500"
                                        />
                                    </div>
                                )}

                                <div className="pt-3 border-t border-gray-200 flex justify-end space-x-3">
                                    <button
                                        type="button"
                                        onClick={() => setSelectedStageForApproval(null)}
                                        className="px-4 py-2 border border-gray-300 text-xs font-medium rounded-lg text-gray-700 hover:bg-gray-50"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        disabled={submittingApproval}
                                        onClick={handleSaveApprovalDecision}
                                        className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-xs font-semibold rounded-lg text-white shadow"
                                    >
                                        {submittingApproval ? "Saving..." : "Confirm Decision"}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
