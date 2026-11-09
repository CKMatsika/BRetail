import React, { useState } from "react";
import axios from "axios";
import {
    ShieldCheck,
    Building2,
    FileSpreadsheet,
    Wrench,
    UploadCloud,
    Download,
    History,
    AlertTriangle,
    CheckCircle2,
    XCircle,
    Clock,
    FileText,
    HelpCircle,
    Search,
    Plus,
    X,
    Filter,
    Calendar,
    Tag,
} from "lucide-react";

export type ComplianceDoc = {
    id: number | null;
    is_persisted: boolean;
    type_key: string;
    category: string;
    title: string;
    issuing_authority: string | null;
    reference_number: string | null;
    praz_categories: string[];
    issue_date: string | null;
    expiry_date: string | null;
    verification_status: "valid" | "expiring" | "expired" | "missing" | "pending_verification" | "not_applicable";
    raw_status: string;
    status_reason: string | null;
    file_name: string | null;
    file_size: number | null;
    mime_type: string | null;
    version: number;
    notes: string | null;
    responsible_employee: { id: number; name: string; designation?: string } | null;
    days_until_expiry: number | null;
    is_mandatory: boolean;
    has_expiry: boolean;
    supports_praz_categories: boolean;
    description: string;
    history_count: number;
};

export type ComplianceData = {
    documents: ComplianceDoc[];
    categories: { key: string; label: string; description: string; icon: string }[];
    summary: {
        total_tracked: number;
        valid_count: number;
        expiring_count: number;
        expired_count: number;
        missing_count: number;
        na_count: number;
        pending_count: number;
        mandatory_total: number;
        mandatory_valid: number;
        health_score_percent: number;
        praz_categories: string[];
    };
    employees: { id: number; name: string; designation?: string }[];
};

type Props = {
    initialData?: ComplianceData;
    canManage: boolean;
};

export default function ComplianceCenter({ initialData, canManage }: Props) {
    const [data, setData] = useState<ComplianceData | null>(initialData ?? null);
    const [loading, setLoading] = useState(!initialData);
    const [search, setSearch] = useState("");
    const [activeTab, setActiveTab] = useState("all");
    const [statusFilter, setStatusFilter] = useState("all");
    const [error, setError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);

    // Modal states
    const [uploadModalDoc, setUploadModalDoc] = useState<ComplianceDoc | null>(null);
    const [isCustomUpload, setIsCustomUpload] = useState(false);
    const [historyModalDoc, setHistoryModalDoc] = useState<ComplianceDoc | null>(null);
    const [historyList, setHistoryList] = useState<any[]>([]);
    const [naModalDoc, setNaModalDoc] = useState<ComplianceDoc | null>(null);
    const [naReason, setNaReason] = useState("");

    // Form inputs
    const [formTitle, setFormTitle] = useState("");
    const [formCategory, setFormCategory] = useState("statutory");
    const [formTypeKey, setFormTypeKey] = useState("");
    const [formAuthority, setFormAuthority] = useState("");
    const [formRefNumber, setFormRefNumber] = useState("");
    const [formPrazInput, setFormPrazInput] = useState("");
    const [formIssueDate, setFormIssueDate] = useState("");
    const [formExpiryDate, setFormExpiryDate] = useState("");
    const [formEmployeeId, setFormEmployeeId] = useState("");
    const [formNotes, setFormNotes] = useState("");
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const reloadData = async () => {
        try {
            setLoading(true);
            const res = await axios.get("/compliance/data");
            setData(res.data.data);
            setError(null);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to load compliance data.");
        } finally {
            setLoading(false);
        }
    };

    const openUploadModal = (doc?: ComplianceDoc) => {
        if (doc) {
            setIsCustomUpload(false);
            setUploadModalDoc(doc);
            setFormTitle(doc.title);
            setFormCategory(doc.category);
            setFormTypeKey(doc.type_key);
            setFormAuthority(doc.issuing_authority || "");
            setFormRefNumber(doc.reference_number || "");
            setFormPrazInput(doc.praz_categories ? doc.praz_categories.join(", ") : "");
            setFormIssueDate(doc.issue_date || "");
            setFormExpiryDate(doc.expiry_date || "");
            setFormEmployeeId(doc.responsible_employee ? String(doc.responsible_employee.id) : "");
            setFormNotes(doc.notes || "");
        } else {
            setIsCustomUpload(true);
            setUploadModalDoc(null);
            setFormTitle("");
            setFormCategory("statutory");
            setFormTypeKey("custom_" + Date.now());
            setFormAuthority("");
            setFormRefNumber("");
            setFormPrazInput("");
            setFormIssueDate("");
            setFormExpiryDate("");
            setFormEmployeeId("");
            setFormNotes("");
        }
        setSelectedFile(null);
    };

    const handleFormSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);
        setError(null);

        try {
            const formData = new FormData();
            formData.append("category", formCategory);
            formData.append("type_key", formTypeKey);
            formData.append("title", formTitle);
            if (formAuthority) formData.append("issuing_authority", formAuthority);
            if (formRefNumber) formData.append("reference_number", formRefNumber);
            if (formIssueDate) formData.append("issue_date", formIssueDate);
            if (formExpiryDate) formData.append("expiry_date", formExpiryDate);
            if (formEmployeeId) formData.append("responsible_employee_id", formEmployeeId);
            if (formNotes) formData.append("notes", formNotes);
            if (selectedFile) formData.append("file", selectedFile);

            if (formPrazInput.trim()) {
                const cats = formPrazInput
                    .split(",")
                    .map((c) => c.trim().toUpperCase())
                    .filter(Boolean);
                cats.forEach((cat) => formData.append("praz_categories[]", cat));
            }

            const res = await axios.post("/compliance/store", formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });

            setData(res.data.data);
            setUploadModalDoc(null);
            setSuccessMessage("Compliance certificate/document updated successfully.");
            setTimeout(() => setSuccessMessage(null), 4000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to save compliance document.");
        } finally {
            setSubmitting(false);
        }
    };

    const handleMarkNa = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!naModalDoc || !naReason.trim()) return;

        setSubmitting(true);
        setError(null);

        try {
            const res = await axios.post("/compliance/mark-na", {
                type_key: naModalDoc.type_key,
                reason: naReason.trim(),
            });

            setData(res.data.data);
            setNaModalDoc(null);
            setNaReason("");
            setSuccessMessage(`Marked "${naModalDoc.title}" as Not Applicable.`);
            setTimeout(() => setSuccessMessage(null), 4000);
        } catch (e: any) {
            setError(e.response?.data?.message || "Failed to mark document as Not Applicable.");
        } finally {
            setSubmitting(false);
        }
    };

    const openHistory = async (doc: ComplianceDoc) => {
        if (!doc.id) return;
        setHistoryModalDoc(doc);
        setHistoryList([]);
        try {
            const res = await axios.get(`/compliance/history/${doc.id}`);
            setHistoryList(res.data.data);
        } catch (e: any) {
            setError("Could not retrieve version history.");
        }
    };

    if (loading && !data) {
        return (
            <div className="p-8 text-center text-slate-500">
                <Clock className="w-8 h-8 animate-spin mx-auto mb-2 text-emerald-600" />
                <p>Loading Zimbabwean Tender Compliance Vault...</p>
            </div>
        );
    }

    if (!data) return null;

    const summary = data.summary;

    const filteredDocs = data.documents.filter((doc) => {
        if (activeTab !== "all" && doc.category !== activeTab) return false;
        if (statusFilter !== "all" && doc.verification_status !== statusFilter) return false;
        if (search.trim()) {
            const q = search.toLowerCase();
            return (
                doc.title.toLowerCase().includes(q) ||
                (doc.issuing_authority && doc.issuing_authority.toLowerCase().includes(q)) ||
                (doc.reference_number && doc.reference_number.toLowerCase().includes(q)) ||
                (doc.description && doc.description.toLowerCase().includes(q)) ||
                (doc.praz_categories && doc.praz_categories.some((c) => c.toLowerCase().includes(q)))
            );
        }
        return true;
    });

    const formatFileSize = (bytes: number | null) => {
        if (!bytes) return "";
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    };

    return (
        <div className="space-y-6">
            {/* Header Banner */}
            <div className="bg-white border border-slate-200 rounded-xl p-6 shadow-xs">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="p-2 bg-emerald-50 text-emerald-700 rounded-lg">
                                <ShieldCheck className="w-6 h-6" />
                            </span>
                            <h2 className="text-xl font-bold text-slate-900">
                                Tender & Company Compliance Centre
                            </h2>
                        </div>
                        <p className="text-sm text-slate-500 mt-1">
                            Zimbabwean corporate compliance repository for PRAZ, ZIMRA, NSSA, NEC, and public procurement qualification.
                        </p>
                    </div>

                    {canManage && (
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => openUploadModal()}
                                className="inline-flex items-center gap-1.5 px-3.5 py-2 bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-semibold rounded-lg transition-colors cursor-pointer shadow-xs"
                            >
                                <Plus className="w-4 h-4" /> Add Supporting Document
                            </button>
                        </div>
                    )}
                </div>

                {/* Scorecard KPIs */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mt-6 pt-6 border-t border-slate-100">
                    <div className="bg-slate-50 p-3.5 rounded-lg border border-slate-200/70">
                        <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider block">
                            Tender Health
                        </span>
                        <div className="flex items-baseline gap-1 mt-1">
                            <span className="text-2xl font-black text-slate-900">
                                {summary.health_score_percent}%
                            </span>
                            <span className="text-xs text-slate-500 font-medium">mandatory</span>
                        </div>
                        <div className="w-full bg-slate-200 h-1.5 rounded-full mt-2 overflow-hidden">
                            <div
                                className={`h-full ${
                                    summary.health_score_percent >= 80
                                        ? "bg-emerald-600"
                                        : summary.health_score_percent >= 50
                                        ? "bg-amber-500"
                                        : "bg-red-500"
                                }`}
                                style={{ width: `${summary.health_score_percent}%` }}
                            />
                        </div>
                    </div>

                    <div className="bg-emerald-50/60 p-3.5 rounded-lg border border-emerald-100">
                        <span className="text-xs font-semibold text-emerald-700 uppercase tracking-wider block">
                            Valid Active
                        </span>
                        <div className="flex items-center gap-1.5 mt-1">
                            <CheckCircle2 className="w-5 h-5 text-emerald-600" />
                            <span className="text-2xl font-bold text-emerald-900">{summary.valid_count}</span>
                        </div>
                        <span className="text-xs text-emerald-700 mt-1 block">Certified compliant</span>
                    </div>

                    <div className="bg-amber-50/70 p-3.5 rounded-lg border border-amber-200">
                        <span className="text-xs font-semibold text-amber-700 uppercase tracking-wider block">
                            Expiring &lt;30d
                        </span>
                        <div className="flex items-center gap-1.5 mt-1">
                            <AlertTriangle className="w-5 h-5 text-amber-600" />
                            <span className="text-2xl font-bold text-amber-900">{summary.expiring_count}</span>
                        </div>
                        <span className="text-xs text-amber-700 mt-1 block">Needs renewal</span>
                    </div>

                    <div className="bg-red-50/60 p-3.5 rounded-lg border border-red-100">
                        <span className="text-xs font-semibold text-red-700 uppercase tracking-wider block">
                            Expired
                        </span>
                        <div className="flex items-center gap-1.5 mt-1">
                            <XCircle className="w-5 h-5 text-red-600" />
                            <span className="text-2xl font-bold text-red-900">{summary.expired_count}</span>
                        </div>
                        <span className="text-xs text-red-700 mt-1 block">Disqualifies bids</span>
                    </div>

                    <div className="bg-slate-50 p-3.5 rounded-lg border border-slate-200/70">
                        <span className="text-xs font-semibold text-slate-600 uppercase tracking-wider block">
                            Missing Mandatory
                        </span>
                        <div className="flex items-center gap-1.5 mt-1">
                            <FileText className="w-5 h-5 text-slate-400" />
                            <span className="text-2xl font-bold text-slate-800">{summary.missing_count}</span>
                        </div>
                        <span className="text-xs text-slate-500 mt-1 block">Required uploads</span>
                    </div>

                    <div className="bg-slate-50 p-3.5 rounded-lg border border-slate-200/70">
                        <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider block">
                            Exempt / N/A
                        </span>
                        <div className="flex items-center gap-1.5 mt-1">
                            <HelpCircle className="w-5 h-5 text-slate-400" />
                            <span className="text-2xl font-bold text-slate-700">{summary.na_count}</span>
                        </div>
                        <span className="text-xs text-slate-500 mt-1 block">Turnover/sector exempt</span>
                    </div>
                </div>

                {/* PRAZ Registration Categories Pill Bar */}
                {summary.praz_categories.length > 0 && (
                    <div className="mt-4 pt-3 border-t border-slate-100 flex flex-wrap items-center gap-2">
                        <span className="text-xs font-bold text-slate-700 uppercase tracking-wider flex items-center gap-1">
                            <Tag className="w-3.5 h-3.5 text-emerald-600" /> Registered PRAZ Codes:
                        </span>
                        {summary.praz_categories.map((code) => (
                            <span
                                key={code}
                                className="px-2.5 py-0.5 bg-emerald-100/70 text-emerald-800 font-semibold text-xs rounded-md border border-emerald-200"
                            >
                                {code}
                            </span>
                        ))}
                    </div>
                )}
            </div>

            {/* Notifications */}
            {error && (
                <div className="p-4 bg-red-50 border border-red-200 rounded-lg text-red-800 text-sm flex items-center gap-2">
                    <AlertTriangle className="w-5 h-5 text-red-600 shrink-0" />
                    <span>{error}</span>
                </div>
            )}
            {successMessage && (
                <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-800 text-sm flex items-center gap-2">
                    <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
                    <span>{successMessage}</span>
                </div>
            )}

            {/* Navigation Tabs & Filters */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-3 border-b border-slate-200 pb-3">
                <div className="flex flex-wrap gap-1">
                    <button
                        type="button"
                        onClick={() => setActiveTab("all")}
                        className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer ${
                            activeTab === "all"
                                ? "bg-slate-900 text-white"
                                : "text-slate-600 hover:bg-slate-100"
                        }`}
                    >
                        All Documents ({data.documents.length})
                    </button>
                    {data.categories.map((cat) => {
                        const count = data.documents.filter((d) => d.category === cat.key).length;
                        return (
                            <button
                                key={cat.key}
                                type="button"
                                onClick={() => setActiveTab(cat.key)}
                                className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer ${
                                    activeTab === cat.key
                                        ? "bg-slate-900 text-white"
                                        : "text-slate-600 hover:bg-slate-100"
                                }`}
                            >
                                {cat.label} ({count})
                            </button>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative">
                        <Search className="w-4 h-4 text-slate-400 absolute left-2.5 top-2.5" />
                        <input
                            type="text"
                            placeholder="Search document, authority..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="text-xs pl-8 pr-3 py-1.5 border border-slate-200 rounded-lg w-48 sm:w-60 focus:outline-emerald-600"
                        />
                    </div>

                    <select
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value)}
                        className="text-xs px-2.5 py-1.5 border border-slate-200 rounded-lg bg-white text-slate-700"
                    >
                        <option value="all">All Statuses</option>
                        <option value="valid">Valid Active</option>
                        <option value="expiring">Expiring Soon</option>
                        <option value="expired">Expired</option>
                        <option value="missing">Missing</option>
                        <option value="not_applicable">Not Applicable</option>
                    </select>
                </div>
            </div>

            {/* Document Cards Grid */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {filteredDocs.length === 0 ? (
                    <div className="col-span-full bg-white border border-slate-200 rounded-xl p-12 text-center text-slate-500">
                        <FileText className="w-10 h-10 text-slate-300 mx-auto mb-2" />
                        <p className="font-semibold text-slate-700">No compliance documents match your filter.</p>
                        <p className="text-xs text-slate-400 mt-1">Try resetting the search or category tab.</p>
                    </div>
                ) : (
                    filteredDocs.map((doc) => {
                        const statusColors: Record<string, string> = {
                            valid: "bg-emerald-50 text-emerald-800 border-emerald-200",
                            expiring: "bg-amber-50 text-amber-800 border-amber-200",
                            expired: "bg-red-50 text-red-800 border-red-200",
                            missing: "bg-slate-100 text-slate-700 border-slate-200",
                            not_applicable: "bg-slate-100 text-slate-500 border-slate-200",
                            pending_verification: "bg-blue-50 text-blue-800 border-blue-200",
                        };

                        return (
                            <div
                                key={doc.type_key}
                                className={`bg-white border rounded-xl p-5 shadow-2xs transition-shadow hover:shadow-xs flex flex-col justify-between ${
                                    doc.verification_status === "expired"
                                        ? "border-red-300"
                                        : doc.verification_status === "expiring"
                                        ? "border-amber-300"
                                        : "border-slate-200"
                                }`}
                            >
                                <div>
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <h3 className="font-bold text-slate-900 text-base">
                                                    {doc.title}
                                                </h3>
                                                {doc.is_mandatory && (
                                                    <span className="text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 bg-red-100 text-red-800 rounded">
                                                        Mandatory
                                                    </span>
                                                )}
                                            </div>
                                            <p className="text-xs text-slate-500 mt-0.5">
                                                {doc.issuing_authority || "Issuing authority not specified"}
                                            </p>
                                        </div>

                                        <span
                                            className={`text-xs font-semibold px-2.5 py-1 rounded-full border shrink-0 ${
                                                statusColors[doc.verification_status] || "bg-slate-50 text-slate-700"
                                            }`}
                                        >
                                            {doc.verification_status === "valid" && "✓ Valid"}
                                            {doc.verification_status === "expiring" && `⚠️ Expires in ${doc.days_until_expiry}d`}
                                            {doc.verification_status === "expired" && `✕ Expired ${Math.abs(doc.days_until_expiry || 0)}d ago`}
                                            {doc.verification_status === "missing" && "Missing"}
                                            {doc.verification_status === "not_applicable" && "Not Applicable"}
                                            {doc.verification_status === "pending_verification" && "Pending Review"}
                                        </span>
                                    </div>

                                    <p className="text-xs text-slate-600 mt-2 line-clamp-2">
                                        {doc.description}
                                    </p>

                                    {/* Reference & Validity metadata */}
                                    <div className="mt-3.5 grid grid-cols-2 gap-2 text-xs text-slate-600 bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                                        <div>
                                            <span className="text-slate-400 block text-[11px]">Cert / Ref No:</span>
                                            <span className="font-semibold text-slate-800">
                                                {doc.reference_number || "—"}
                                            </span>
                                        </div>

                                        <div>
                                            <span className="text-slate-400 block text-[11px]">Validity:</span>
                                            <span className="font-semibold text-slate-800">
                                                {doc.expiry_date ? doc.expiry_date : "Permanent / No Expiry"}
                                            </span>
                                        </div>

                                        {doc.responsible_employee && (
                                            <div className="col-span-2 pt-1 border-t border-slate-200/50">
                                                <span className="text-slate-400 text-[11px]">Custodian: </span>
                                                <span className="font-medium text-slate-700">
                                                    {doc.responsible_employee.name}
                                                    {doc.responsible_employee.designation
                                                        ? ` (${doc.responsible_employee.designation})`
                                                        : ""}
                                                </span>
                                            </div>
                                        )}

                                        {doc.status_reason && (
                                            <div className="col-span-2 pt-1 border-t border-slate-200/50 text-slate-500 italic">
                                                <span>Reason: </span>
                                                {doc.status_reason}
                                            </div>
                                        )}
                                    </div>

                                    {/* PRAZ categories if applicable */}
                                    {doc.praz_categories && doc.praz_categories.length > 0 && (
                                        <div className="mt-2.5 flex flex-wrap items-center gap-1">
                                            <span className="text-[11px] font-semibold text-slate-500">Categories:</span>
                                            {doc.praz_categories.map((c) => (
                                                <span
                                                    key={c}
                                                    className="px-1.5 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold rounded"
                                                >
                                                    {c}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                {/* Footer & Actions */}
                                <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                                    <div className="text-xs text-slate-400">
                                        {doc.file_name ? (
                                            <span className="flex items-center gap-1 font-medium text-slate-600">
                                                <FileText className="w-3.5 h-3.5 text-slate-400" />
                                                <span className="truncate max-w-[150px]">{doc.file_name}</span>
                                                <span>({formatFileSize(doc.file_size)})</span>
                                                <span className="px-1.5 py-0.2 bg-slate-200 text-slate-700 rounded text-[10px]">
                                                    v{doc.version}
                                                </span>
                                            </span>
                                        ) : (
                                            <span>No file uploaded</span>
                                        )}
                                    </div>

                                    <div className="flex items-center gap-1.5">
                                        {doc.id && doc.file_name && (
                                            <a
                                                href={`/compliance/download/${doc.id}`}
                                                target="_blank"
                                                rel="noreferrer"
                                                title="Download current certificate"
                                                className="p-1.5 text-slate-600 hover:text-emerald-700 hover:bg-emerald-50 rounded border border-slate-200 cursor-pointer"
                                            >
                                                <Download className="w-4 h-4" />
                                            </a>
                                        )}

                                        {doc.id && doc.history_count > 0 && (
                                            <button
                                                type="button"
                                                onClick={() => openHistory(doc)}
                                                title="View previous version history"
                                                className="p-1.5 text-slate-600 hover:text-blue-700 hover:bg-blue-50 rounded border border-slate-200 cursor-pointer"
                                            >
                                                <History className="w-4 h-4" />
                                            </button>
                                        )}

                                        {canManage && (
                                            <>
                                                {!doc.is_mandatory && doc.verification_status !== "not_applicable" && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setNaModalDoc(doc);
                                                            setNaReason("");
                                                        }}
                                                        className="px-2 py-1 text-xs text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded border border-slate-200 cursor-pointer"
                                                    >
                                                        Mark N/A
                                                    </button>
                                                )}

                                                <button
                                                    type="button"
                                                    onClick={() => openUploadModal(doc)}
                                                    className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200 rounded cursor-pointer"
                                                >
                                                    <UploadCloud className="w-3.5 h-3.5" />
                                                    {doc.file_name ? "Replace" : "Upload"}
                                                </button>
                                            </>
                                        )}
                                    </div>
                                </div>
                            </div>
                        );
                    })
                )}
            </div>

            {/* Upload / Replace Document Modal */}
            {(uploadModalDoc || isCustomUpload) && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto border border-slate-200 p-6">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <h3 className="font-bold text-lg text-slate-900">
                                {uploadModalDoc?.file_name
                                    ? `Replace Version – ${formTitle}`
                                    : isCustomUpload
                                    ? "Add Custom Supporting Document"
                                    : `Upload ${formTitle}`}
                            </h3>
                            <button
                                type="button"
                                onClick={() => {
                                    setUploadModalDoc(null);
                                    setIsCustomUpload(false);
                                }}
                                className="text-slate-400 hover:text-slate-600 cursor-pointer"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleFormSubmit} className="space-y-4 mt-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Document Title *
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={formTitle}
                                    onChange={(e) => setFormTitle(e.target.value)}
                                    placeholder="e.g. ZIMRA ITF263 Tax Clearance Certificate"
                                    className="w-full text-xs px-3 py-2 border border-slate-300 rounded-lg focus:outline-emerald-600"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Category *
                                    </label>
                                    <select
                                        value={formCategory}
                                        onChange={(e) => setFormCategory(e.target.value)}
                                        className="w-full text-xs px-2.5 py-2 border border-slate-300 rounded-lg bg-white"
                                    >
                                        <option value="statutory">Statutory Compliance</option>
                                        <option value="company_registration">Company Registration</option>
                                        <option value="financial_professional">Financial & Professional</option>
                                        <option value="technical_personnel">Technical & Personnel</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Issuing Authority
                                    </label>
                                    <input
                                        type="text"
                                        value={formAuthority}
                                        onChange={(e) => setFormAuthority(e.target.value)}
                                        placeholder="e.g. PRAZ, ZIMRA, NSSA"
                                        className="w-full text-xs px-3 py-2 border border-slate-300 rounded-lg"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Reference / Cert Number
                                    </label>
                                    <input
                                        type="text"
                                        value={formRefNumber}
                                        onChange={(e) => setFormRefNumber(e.target.value)}
                                        placeholder="e.g. ITF263-2026-00123"
                                        className="w-full text-xs px-3 py-2 border border-slate-300 rounded-lg"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Expiry Date
                                    </label>
                                    <input
                                        type="date"
                                        value={formExpiryDate}
                                        onChange={(e) => setFormExpiryDate(e.target.value)}
                                        className="w-full text-xs px-3 py-2 border border-slate-300 rounded-lg"
                                    />
                                </div>
                            </div>

                            {formCategory === "statutory" && (
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        PRAZ Procurement Categories (Comma separated)
                                    </label>
                                    <input
                                        type="text"
                                        value={formPrazInput}
                                        onChange={(e) => setFormPrazInput(e.target.value)}
                                        placeholder="e.g. GS001, SE002, SV003"
                                        className="w-full text-xs px-3 py-2 border border-slate-300 rounded-lg"
                                    />
                                    <span className="text-[11px] text-slate-400">
                                        Enter registered categories from your PRAZ supplier certificate.
                                    </span>
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Custodian / Responsible Staff
                                </label>
                                <select
                                    value={formEmployeeId}
                                    onChange={(e) => setFormEmployeeId(e.target.value)}
                                    className="w-full text-xs px-2.5 py-2 border border-slate-300 rounded-lg bg-white"
                                >
                                    <option value="">No specific custodian</option>
                                    {data.employees.map((emp) => (
                                        <option key={emp.id} value={emp.id}>
                                            {emp.name} {emp.designation ? `(${emp.designation})` : ""}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Upload Document File (PDF, Word, Excel, Images up to 20MB)
                                </label>
                                <input
                                    type="file"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.png,.jpg,.jpeg"
                                    onChange={(e) => setSelectedFile(e.target.files?.[0] || null)}
                                    className="w-full text-xs px-3 py-2 border border-slate-300 rounded-lg file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100"
                                />
                                {uploadModalDoc?.file_name && !selectedFile && (
                                    <span className="text-[11px] text-slate-500 mt-1 block">
                                        Leave empty to keep existing file: {uploadModalDoc.file_name}
                                    </span>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Notes & Renewal Guidelines
                                </label>
                                <textarea
                                    rows={2}
                                    value={formNotes}
                                    onChange={(e) => setFormNotes(e.target.value)}
                                    placeholder="e.g. Ensure renewal is submitted 3 weeks before expiry with audited tax return."
                                    className="w-full text-xs px-3 py-2 border border-slate-300 rounded-lg"
                                />
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setUploadModalDoc(null);
                                        setIsCustomUpload(false);
                                    }}
                                    className="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg cursor-pointer"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    className="px-4 py-2 text-xs font-semibold bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg cursor-pointer disabled:opacity-50"
                                >
                                    {submitting ? "Saving..." : "Save Compliance Document"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Mark Not Applicable Modal */}
            {naModalDoc && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white rounded-xl shadow-xl max-w-md w-full border border-slate-200 p-6">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <h3 className="font-bold text-base text-slate-900">
                                Mark as Not Applicable
                            </h3>
                            <button
                                type="button"
                                onClick={() => setNaModalDoc(null)}
                                className="text-slate-400 hover:text-slate-600 cursor-pointer"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleMarkNa} className="space-y-4 mt-4">
                            <p className="text-xs text-slate-600">
                                Specify why <strong>{naModalDoc.title}</strong> is exempt or not applicable to your business. This justification will be referenced in tender compliance matrices.
                            </p>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Justification / Exemption Reason *
                                </label>
                                <textarea
                                    required
                                    rows={3}
                                    value={naReason}
                                    onChange={(e) => setNaReason(e.target.value)}
                                    placeholder="e.g. Annual turnover is below the statutory threshold for ZIMRA VAT registration; or Our industry does not require an EMA operating licence."
                                    className="w-full text-xs px-3 py-2 border border-slate-300 rounded-lg"
                                />
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setNaModalDoc(null)}
                                    className="px-3.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg cursor-pointer"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    className="px-3.5 py-1.5 text-xs font-semibold bg-slate-800 hover:bg-slate-900 text-white rounded-lg cursor-pointer disabled:opacity-50"
                                >
                                    {submitting ? "Saving..." : "Confirm Exemption"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Version History Modal */}
            {historyModalDoc && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[80vh] overflow-y-auto border border-slate-200 p-6">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div>
                                <h3 className="font-bold text-base text-slate-900">
                                    Version History – {historyModalDoc.title}
                                </h3>
                                <p className="text-xs text-slate-400">
                                    Audit trail of active and archived certificates
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setHistoryModalDoc(null)}
                                className="text-slate-400 hover:text-slate-600 cursor-pointer"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="mt-4 space-y-3">
                            {historyList.length === 0 ? (
                                <p className="text-xs text-slate-500 py-4 text-center">Loading version history...</p>
                            ) : (
                                historyList.map((ver) => (
                                    <div
                                        key={ver.id}
                                        className={`p-3.5 rounded-lg border text-xs flex items-center justify-between gap-3 ${
                                            ver.is_active
                                                ? "bg-emerald-50/50 border-emerald-200"
                                                : "bg-slate-50 border-slate-200"
                                        }`}
                                    >
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-bold text-slate-900">
                                                    Version {ver.version}
                                                </span>
                                                {ver.is_active ? (
                                                    <span className="px-1.5 py-0.2 bg-emerald-600 text-white rounded text-[10px] font-bold">
                                                        Active Current
                                                    </span>
                                                ) : (
                                                    <span className="px-1.5 py-0.2 bg-slate-200 text-slate-600 rounded text-[10px]">
                                                        Archived
                                                    </span>
                                                )}
                                            </div>
                                            <span className="text-slate-500 block mt-1">
                                                Uploaded: {new Date(ver.created_at).toLocaleDateString()} · Ref:{" "}
                                                {ver.reference_number || "—"}
                                            </span>
                                            {ver.expiry_date && (
                                                <span className="text-slate-400 text-[11px] block">
                                                    Expiry: {ver.expiry_date}
                                                </span>
                                            )}
                                        </div>

                                        {ver.file_name && (
                                            <a
                                                href={`/compliance/download/${ver.id}`}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="px-2.5 py-1.5 bg-white hover:bg-slate-50 border border-slate-200 rounded text-slate-700 font-semibold inline-flex items-center gap-1 shrink-0"
                                            >
                                                <Download className="w-3.5 h-3.5" /> Download
                                            </a>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>

                        <div className="mt-4 pt-3 border-t border-slate-100 text-right">
                            <button
                                type="button"
                                onClick={() => setHistoryModalDoc(null)}
                                className="px-4 py-1.5 text-xs font-semibold bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg cursor-pointer"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
