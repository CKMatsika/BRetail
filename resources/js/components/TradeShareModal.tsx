import React, { useState, useEffect } from "react";
import axios from "axios";
import { Mail, MessageCircle, Link2, Copy, Check, ExternalLink, Send, X, AlertCircle, CheckCircle2 } from "lucide-react";

interface TradeShareModalProps {
    isOpen: boolean;
    onClose: () => void;
    documentId: number;
    documentNumber: string;
    documentKind: string;
    customerName: string;
    totalAmount: string;
    currency: string;
    initialEmail?: string;
    initialPhone?: string;
}

interface ShareInfo {
    document_id: number;
    number: string;
    kind: string;
    party_name: string;
    total: string;
    currency: string;
    recipient_email: string;
    recipient_phone: string;
    signed_url: string;
    whatsapp_url: string;
    default_subject: string;
    default_message: string;
}

export default function TradeShareModal({
    isOpen,
    onClose,
    documentId,
    documentNumber,
    documentKind,
    customerName,
    totalAmount,
    currency,
    initialEmail,
    initialPhone,
}: TradeShareModalProps) {
    const [activeTab, setActiveTab] = useState<"email" | "whatsapp" | "link">("email");
    const [loading, setLoading] = useState(true);
    const [shareInfo, setShareInfo] = useState<ShareInfo | null>(null);

    // Email form state
    const [email, setEmail] = useState("");
    const [subject, setSubject] = useState("");
    const [message, setMessage] = useState("");
    const [sendingEmail, setSendingEmail] = useState(false);
    const [emailSuccess, setEmailSuccess] = useState<string | null>(null);
    const [emailError, setEmailError] = useState<string | null>(null);

    // WhatsApp form state
    const [phone, setPhone] = useState("");
    const [waMessage, setWaMessage] = useState("");
    const [copiedWa, setCopiedWa] = useState(false);

    // Link state
    const [copiedLink, setCopiedLink] = useState(false);

    useEffect(() => {
        if (!isOpen) return;

        setEmailSuccess(null);
        setEmailError(null);
        setCopiedWa(false);
        setCopiedLink(false);
        setLoading(true);

        axios
            .get(`/trade/documents/${documentId}/share-info`)
            .then((res) => {
                const data: ShareInfo = res.data;
                setShareInfo(data);
                setEmail(data.recipient_email || initialEmail || "");
                setSubject(data.default_subject || "");
                setMessage(data.default_message || "");
                setPhone(data.recipient_phone || initialPhone || "");
                setWaMessage(data.default_message || "");
            })
            .catch((err) => {
                setEmailError(err.response?.data?.message || "Failed to load document sharing information.");
            })
            .finally(() => {
                setLoading(false);
            });
    }, [isOpen, documentId]);

    if (!isOpen) return null;

    const handleSendEmail = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!email.trim()) return;

        setSendingEmail(true);
        setEmailSuccess(null);
        setEmailError(null);

        try {
            const res = await axios.post(`/trade/documents/${documentId}/send-email`, {
                email: email.trim(),
                subject: subject.trim(),
                message: message.trim(),
            });

            setEmailSuccess(res.data.message || `Document successfully emailed to ${email}.`);
        } catch (err: any) {
            setEmailError(err.response?.data?.message || "Failed to send email. Check mail settings.");
        } finally {
            setSendingEmail(false);
        }
    };

    const handleOpenWhatsApp = () => {
        const cleanPhone = phone.replace(/[^0-9]/g, "");
        const waUrl = `https://wa.me/${cleanPhone}?text=${encodeURIComponent(waMessage)}`;
        window.open(waUrl, "_blank");
    };

    const handleCopyWhatsApp = () => {
        navigator.clipboard.writeText(waMessage);
        setCopiedWa(true);
        setTimeout(() => setCopiedWa(false), 3000);
    };

    const handleCopyLink = () => {
        if (!shareInfo?.signed_url) return;
        navigator.clipboard.writeText(shareInfo.signed_url);
        setCopiedLink(true);
        setTimeout(() => setCopiedLink(false), 3000);
    };

    const kindLabel = documentKind === "quote" ? "Quotation" : (documentKind === "invoice" ? "Invoice" : ucFirst(documentKind));

    function ucFirst(str: string) {
        return str.charAt(0).toUpperCase() + str.slice(1).replace(/_/g, " ");
    }

    return (
        <div className="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white rounded-2xl max-w-xl w-full shadow-2xl border border-slate-200 overflow-hidden flex flex-col max-h-[90vh]">
                {/* Header */}
                <div className="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="px-2 py-0.5 text-[11px] font-bold rounded-full uppercase tracking-wider bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                {kindLabel}
                            </span>
                            <h2 className="text-base font-bold text-white tracking-tight">
                                {documentNumber}
                            </h2>
                        </div>
                        <p className="text-xs text-slate-300 mt-0.5">
                            {customerName} · <strong className="text-white">{currency} {totalAmount}</strong>
                        </p>
                    </div>
                    <button
                        onClick={onClose}
                        className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                        aria-label="Close"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Tab Navigation */}
                <div className="flex border-b border-slate-200 bg-slate-50 px-6 pt-3 gap-2">
                    <button
                        type="button"
                        onClick={() => setActiveTab("email")}
                        className={`inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-t-lg transition-all border-b-2 -mb-[1px] ${
                            activeTab === "email"
                                ? "bg-white text-blue-600 border-blue-600 shadow-sm"
                                : "text-slate-600 hover:text-slate-900 border-transparent"
                        }`}
                    >
                        <Mail className="w-4 h-4" />
                        Send via Email
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab("whatsapp")}
                        className={`inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-t-lg transition-all border-b-2 -mb-[1px] ${
                            activeTab === "whatsapp"
                                ? "bg-white text-emerald-600 border-emerald-600 shadow-sm"
                                : "text-slate-600 hover:text-slate-900 border-transparent"
                        }`}
                    >
                        <MessageCircle className="w-4 h-4" />
                        Share on WhatsApp
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab("link")}
                        className={`inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-t-lg transition-all border-b-2 -mb-[1px] ${
                            activeTab === "link"
                                ? "bg-white text-indigo-600 border-indigo-600 shadow-sm"
                                : "text-slate-600 hover:text-slate-900 border-transparent"
                        }`}
                    >
                        <Link2 className="w-4 h-4" />
                        Public Link
                    </button>
                </div>

                {/* Body Content */}
                <div className="p-6 overflow-y-auto flex-1 space-y-4">
                    {loading ? (
                        <div className="py-12 text-center text-slate-400 space-y-2">
                            <div className="w-6 h-6 border-2 border-indigo-600 border-t-transparent rounded-full animate-spin mx-auto" />
                            <p className="text-xs">Preparing document share package...</p>
                        </div>
                    ) : (
                        <>
                            {/* TAB 1: EMAIL */}
                            {activeTab === "email" && (
                                <form onSubmit={handleSendEmail} className="space-y-4">
                                    {emailSuccess && (
                                        <div className="p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg text-xs flex items-center gap-2">
                                            <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                                            <span>{emailSuccess}</span>
                                        </div>
                                    )}

                                    {emailError && (
                                        <div className="p-3 bg-red-50 border border-red-200 text-red-800 rounded-lg text-xs flex items-center gap-2">
                                            <AlertCircle className="w-4 h-4 text-red-600 shrink-0" />
                                            <span>{emailError}</span>
                                        </div>
                                    )}

                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Recipient Email Address *
                                        </label>
                                        <input
                                            type="email"
                                            required
                                            value={email}
                                            onChange={(e) => setEmail(e.target.value)}
                                            placeholder="client@company.co.zw"
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Email Subject
                                        </label>
                                        <input
                                            type="text"
                                            value={subject}
                                            onChange={(e) => setSubject(e.target.value)}
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Cover Message / Notes
                                        </label>
                                        <textarea
                                            rows={4}
                                            value={message}
                                            onChange={(e) => setMessage(e.target.value)}
                                            className="w-full text-xs border border-slate-300 rounded-lg p-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-sans"
                                        />
                                        <p className="text-[11px] text-slate-400 mt-1">
                                            The email includes your company letterhead, document breakdown, and an online view/print link.
                                        </p>
                                    </div>

                                    <div className="flex justify-end gap-2 pt-3 border-t border-slate-100">
                                        <button
                                            type="button"
                                            onClick={onClose}
                                            className="px-4 py-2 text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            disabled={sendingEmail || !email.trim()}
                                            className="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 rounded-lg shadow-sm"
                                        >
                                            <Send className="w-3.5 h-3.5" />
                                            {sendingEmail ? "Sending Email..." : "Send Document Email"}
                                        </button>
                                    </div>
                                </form>
                            )}

                            {/* TAB 2: WHATSAPP */}
                            {activeTab === "whatsapp" && (
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Customer WhatsApp Phone Number (with Country Code)
                                        </label>
                                        <input
                                            type="tel"
                                            value={phone}
                                            onChange={(e) => setPhone(e.target.value)}
                                            placeholder="+263 77 123 4567"
                                            className="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                        <p className="text-[11px] text-slate-400 mt-1">
                                            Include international country code without spaces (e.g. 263771234567 for Zimbabwe).
                                        </p>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            WhatsApp Message Text
                                        </label>
                                        <textarea
                                            rows={6}
                                            value={waMessage}
                                            onChange={(e) => setWaMessage(e.target.value)}
                                            className="w-full text-xs border border-slate-300 rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 font-sans"
                                        />
                                    </div>

                                    <div className="flex items-center justify-between pt-3 border-t border-slate-100">
                                        <button
                                            type="button"
                                            onClick={handleCopyWhatsApp}
                                            className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg shadow-sm"
                                        >
                                            {copiedWa ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5 text-slate-500" />}
                                            {copiedWa ? "Message Copied!" : "Copy Message"}
                                        </button>

                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                onClick={onClose}
                                                className="px-4 py-2 text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg"
                                            >
                                                Close
                                            </button>
                                            <button
                                                type="button"
                                                onClick={handleOpenWhatsApp}
                                                className="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg shadow-sm"
                                            >
                                                <MessageCircle className="w-3.5 h-3.5" />
                                                Open in WhatsApp
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* TAB 3: PUBLIC LINK */}
                            {activeTab === "link" && (
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                                            Secure Public Document Link (Valid for 60 Days)
                                        </label>
                                        <div className="flex gap-2">
                                            <input
                                                type="text"
                                                readOnly
                                                value={shareInfo?.signed_url || ""}
                                                className="w-full text-xs bg-slate-50 font-mono border border-slate-300 rounded-lg px-3 py-2 text-slate-700 select-all"
                                            />
                                            <button
                                                type="button"
                                                onClick={handleCopyLink}
                                                className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg shadow-sm shrink-0"
                                            >
                                                {copiedLink ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5 text-slate-500" />}
                                                {copiedLink ? "Copied" : "Copy"}
                                            </button>
                                        </div>
                                        <p className="text-[11px] text-slate-400 mt-1">
                                            Anyone with this signed link can view, print, or download this {kindLabel.toLowerCase()} without an account.
                                        </p>
                                    </div>

                                    <div className="p-3 bg-indigo-50 border border-indigo-100 rounded-xl flex items-center justify-between text-xs text-indigo-900">
                                        <span>Preview how your customer will see this document:</span>
                                        <a
                                            href={shareInfo?.signed_url || "#"}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1 font-semibold text-indigo-700 hover:underline"
                                        >
                                            Open Client View <ExternalLink className="w-3.5 h-3.5" />
                                        </a>
                                    </div>

                                    <div className="flex justify-end pt-3 border-t border-slate-100">
                                        <button
                                            type="button"
                                            onClick={onClose}
                                            className="px-4 py-2 text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg"
                                        >
                                            Done
                                        </button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
