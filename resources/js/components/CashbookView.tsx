import React, { useState } from "react";
import axios from "axios";

export type CashbookTransaction = {
    id: number;
    number: string;
    date: string;
    branch_id: number;
    branch_name: string;
    kind: "receipt" | "payment" | "transfer";
    cash_account_id?: number | null;
    cash_account_code?: string | null;
    cash_account_name?: string | null;
    offset_account_id?: number | null;
    offset_account_code?: string | null;
    offset_account_name?: string | null;
    amount: string;
    tax: string;
    reference: string;
    description: string;
    cashflow: string;
    is_reversed: boolean;
    reversal_number?: string | null;
    reversal_id?: number | null;
};

type Account = {
    id: number;
    code: string;
    name: string;
    type: string;
    report_group: string;
    is_cash: boolean;
    active: boolean;
};

type CashbookViewProps = {
    transactions: CashbookTransaction[];
    accounts: Account[];
    branches: { id: number; name: string }[];
    today: string;
    book: string;
    currency: string;
    canPost: boolean;
    canReverse: boolean;
    onPost: (action: string, payload: unknown) => Promise<void>;
};

function parseAxiosError(e: unknown): string {
    if (axios.isAxiosError(e)) {
        const d = e.response?.data;
        return d?.errors
            ? Object.values(d.errors).flat().join(" ")
            : (d?.message ?? e.message);
    }
    return e instanceof Error ? e.message : "Unable to save.";
}

export function CashbookView({
    transactions = [],
    accounts,
    branches,
    today,
    book,
    currency,
    canPost,
    canReverse,
    onPost,
}: CashbookViewProps) {
    const cashAccounts = accounts.filter((a) => a.active && a.is_cash);
    const nonCashAccounts = accounts.filter((a) => a.active && !a.is_cash);

    // Group accounts for clear selection
    const cogsAndExpenses = nonCashAccounts.filter(
        (a) =>
            a.type.toLowerCase().includes("expense") ||
            a.type.toLowerCase().includes("cost") ||
            a.report_group.toLowerCase().includes("cost") ||
            a.report_group.toLowerCase().includes("expense")
    );
    const revenueAccounts = nonCashAccounts.filter(
        (a) =>
            a.type.toLowerCase().includes("revenue") ||
            a.type.toLowerCase().includes("income") ||
            a.report_group.toLowerCase().includes("revenue") ||
            a.report_group.toLowerCase().includes("income")
    );
    const otherAccounts = nonCashAccounts.filter(
        (a) =>
            !cogsAndExpenses.some((e) => e.id === a.id) &&
            !revenueAccounts.some((r) => r.id === a.id)
    );

    // Filter & search state
    const [filterKind, setFilterKind] = useState<"all" | "payment" | "receipt" | "transfer">("all");
    const [search, setSearch] = useState("");
    const [showReversed, setShowReversed] = useState(false);

    // Form states
    const [editingTx, setEditingTx] = useState<CashbookTransaction | null>(null);
    const [reversingTx, setReversingTx] = useState<CashbookTransaction | null>(null);
    const [reverseDate, setReverseDate] = useState(today);
    const [reverseReason, setReverseReason] = useState("");

    const [formOpen, setFormOpen] = useState(true);
    const [formKind, setFormKind] = useState<"payment" | "receipt" | "transfer">("payment");
    const [branchId, setBranchId] = useState(String(branches[0]?.id ?? ""));
    const [date, setDate] = useState(today);
    const [reference, setReference] = useState("");
    const [description, setDescription] = useState("");
    const [cashflow, setCashflow] = useState("operating");
    const [cashAccountId, setCashAccountId] = useState(String(cashAccounts[0]?.id ?? ""));
    const [offsetAccountId, setOffsetAccountId] = useState(
        String(cogsAndExpenses[0]?.id ?? nonCashAccounts[0]?.id ?? "")
    );
    const [amount, setAmount] = useState("");
    const [taxRate, setTaxRate] = useState("");

    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");

    // Calculate metrics
    const activeTxs = transactions.filter((t) => !t.is_reversed);
    const totalReceipts = activeTxs
        .filter((t) => t.kind === "receipt")
        .reduce((sum, t) => sum + parseFloat(t.amount || "0"), 0);
    const totalPayments = activeTxs
        .filter((t) => t.kind === "payment")
        .reduce((sum, t) => sum + parseFloat(t.amount || "0"), 0);
    const netCashflow = totalReceipts - totalPayments;

    // Filter transactions
    const filteredTxs = transactions.filter((t) => {
        if (!showReversed && t.is_reversed) return false;
        if (filterKind !== "all" && t.kind !== filterKind) return false;
        if (search.trim()) {
            const q = search.toLowerCase();
            const matchDesc = t.description?.toLowerCase().includes(q);
            const matchRef = t.reference?.toLowerCase().includes(q);
            const matchNum = t.number?.toLowerCase().includes(q);
            const matchCash = t.cash_account_name?.toLowerCase().includes(q) || t.cash_account_code?.toLowerCase().includes(q);
            const matchOffset = t.offset_account_name?.toLowerCase().includes(q) || t.offset_account_code?.toLowerCase().includes(q);
            return matchDesc || matchRef || matchNum || matchCash || matchOffset;
        }
        return true;
    });

    // Start editing/correcting a transaction
    const startEdit = (tx: CashbookTransaction) => {
        setEditingTx(tx);
        setFormKind(tx.kind);
        setBranchId(String(tx.branch_id));
        setDate(tx.date);
        setReference(tx.reference ?? "");
        setDescription(tx.description ?? "");
        setCashflow(tx.cashflow ?? "operating");
        setCashAccountId(String(tx.cash_account_id ?? cashAccounts[0]?.id ?? ""));
        setOffsetAccountId(String(tx.offset_account_id ?? nonCashAccounts[0]?.id ?? ""));
        setAmount(tx.amount);
        setTaxRate(parseFloat(tx.tax) > 0 ? "15" : "");
        setFormOpen(true);
        setError("");
        setSuccess("");
        window.scrollTo({ top: 200, behavior: "smooth" });
    };

    const cancelEdit = () => {
        setEditingTx(null);
        setFormKind("payment");
        setReference("");
        setDescription("");
        setAmount("");
        setTaxRate("");
        setError("");
        setSuccess("");
    };

    const handleFormSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setError("");
        setSuccess("");
        setBusy(true);

        try {
            const payload = {
                book,
                branch_id: Number(branchId),
                date,
                kind: formKind,
                cash_account_id: Number(cashAccountId),
                offset_account_id: Number(offsetAccountId),
                amount,
                reference: reference.trim() || `${formKind.toUpperCase()}-${date}`,
                description: description.trim() || `Cashbook ${formKind}`,
                cashflow: formKind === "transfer" ? "none" : cashflow,
                tax_rate: taxRate ? taxRate : undefined,
                request_id: crypto.randomUUID(),
            };

            if (editingTx) {
                await onPost(`cashbook-correct/${editingTx.id}`, payload);
                setSuccess(`Transaction #${editingTx.number} was successfully reversed and the corrected entry posted.`);
                cancelEdit();
            } else {
                await onPost("cashbook", payload);
                setSuccess("Cashbook entry successfully posted.");
                setReference("");
                setDescription("");
                setAmount("");
                setTaxRate("");
            }
        } catch (err) {
            setError(parseAxiosError(err));
        } finally {
            setBusy(false);
        }
    };

    const handleReverseSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!reversingTx) return;
        setError("");
        setBusy(true);

        try {
            await onPost(`cashbook-reverse/${reversingTx.id}`, {
                date: reverseDate,
                reason: reverseReason.trim() || "Reversed via Cashbook management",
            });
            setSuccess(`Transaction #${reversingTx.number} was successfully reversed.`);
            setReversingTx(null);
            setReverseReason("");
        } catch (err) {
            setError(parseAxiosError(err));
        } finally {
            setBusy(false);
        }
    };

    // Live tax preview
    const parsedAmount = parseFloat(amount || "0");
    const parsedTaxRate = parseFloat(taxRate || "0");
    let taxPreview = null;
    if (parsedAmount > 0 && parsedTaxRate > 0 && formKind !== "transfer") {
        const net = parsedAmount / (1 + parsedTaxRate / 100);
        const tax = parsedAmount - net;
        taxPreview = {
            net: net.toFixed(2),
            tax: tax.toFixed(2),
            total: parsedAmount.toFixed(2),
        };
    }

    return (
        <div className="cashbook-manager">
            <style>{`
                .cashbook-manager { margin: 16px 0; }
                .cb-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px; }
                .cb-kpi-card { background: var(--surface, #fff); border: 1px solid #dce4df; border-radius: 10px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
                .cb-kpi-card.receipts { border-left: 4px solid #10b981; }
                .cb-kpi-card.payments { border-left: 4px solid #ef4444; }
                .cb-kpi-card.net { border-left: 4px solid #3b82f6; }
                .cb-kpi-card.count { border-left: 4px solid #8b5cf6; }
                .cb-kpi-label { font-size: 12px; text-transform: uppercase; color: #64748b; font-weight: 600; letter-spacing: 0.5px; }
                .cb-kpi-val { font-size: 22px; font-weight: 700; margin-top: 6px; }
                .cb-kpi-val.pos { color: #059669; }
                .cb-kpi-val.neg { color: #dc2626; }
                .cb-kpi-val.neut { color: #2563eb; }

                .cb-toolbar { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center; margin: 20px 0 14px; }
                .cb-pills { display: flex; gap: 6px; flex-wrap: wrap; }
                .cb-pill { padding: 7px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid #cbd5e1; background: #fff; color: #475569; transition: all 0.15s; }
                .cb-pill:hover { background: #f8fafc; }
                .cb-pill.selected { background: #1e293b; color: #fff; border-color: #1e293b; }
                .cb-pill.payment.selected { background: #dc2626; border-color: #dc2626; color: #fff; }
                .cb-pill.receipt.selected { background: #059669; border-color: #059669; color: #fff; }
                .cb-pill.transfer.selected { background: #2563eb; border-color: #2563eb; color: #fff; }

                .cb-form-card { background: #ffffff; border: 1px solid #dce4df; border-radius: 12px; padding: 22px; margin-bottom: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
                .cb-form-card.editing { border: 2px solid #f59e0b; background: #fffdfa; }

                .cb-segmented-kinds { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: 16px 0 20px; }
                .cb-kind-btn { padding: 14px; border: 2px solid #e2e8f0; border-radius: 10px; background: #fff; text-align: left; cursor: pointer; transition: all 0.2s; }
                .cb-kind-btn:hover { border-color: #cbd5e1; background: #f8fafc; }
                .cb-kind-btn.selected-payment { border-color: #ef4444; background: #fef2f2; }
                .cb-kind-btn.selected-receipt { border-color: #10b981; background: #ecfdf5; }
                .cb-kind-btn.selected-transfer { border-color: #3b82f6; background: #eff6ff; }
                .cb-kind-title { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
                .cb-kind-desc { font-size: 12px; color: #64748b; margin-top: 4px; line-height: 1.4; }

                .cb-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 12px; font-size: 12px; font-weight: 600; }
                .cb-badge.payment { background: #fee2e2; color: #991b1b; }
                .cb-badge.receipt { background: #d1fae5; color: #065f46; }
                .cb-badge.transfer { background: #dbeafe; color: #1e40af; }
                .cb-badge.reversed { background: #f1f5f9; color: #64748b; text-decoration: line-through; }
                .cb-badge.active { background: #ecfdf5; color: #047857; }

                .cb-table-card { background: #fff; border: 1px solid #dce4df; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
                .cb-table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
                .cb-table th { background: #f8fafc; color: #475569; font-weight: 600; padding: 12px 14px; border-bottom: 1px solid #e2e8f0; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
                .cb-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
                .cb-table tr:hover td { background: #fbfcfe; }
                .cb-table tr.row-reversed td { opacity: 0.65; background: #fafafa; }

                .cb-action-btn { padding: 5px 10px; font-size: 12px; font-weight: 600; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; transition: all 0.15s; }
                .cb-action-btn:hover { background: #f1f5f9; }
                .cb-action-btn.edit { color: #b45309; border-color: #fde68a; background: #fffbeb; }
                .cb-action-btn.edit:hover { background: #fef3c7; }
                .cb-action-btn.reverse { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
                .cb-action-btn.reverse:hover { background: #fee2e2; }

                .cb-reversal-modal { background: #fef2f2; border: 2px solid #ef4444; border-radius: 10px; padding: 18px; margin: 16px 0; }
            `}</style>

            {/* KPI Summary Cards */}
            <div className="cb-kpis">
                <div className="cb-kpi-card receipts">
                    <div className="cb-kpi-label">Total Receipts (Money In)</div>
                    <div className="cb-kpi-val pos">+{currency} {totalReceipts.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                    <small style={{ color: "#64748b" }}>{activeTxs.filter(t => t.kind === "receipt").length} active receipt entries</small>
                </div>
                <div className="cb-kpi-card payments">
                    <div className="cb-kpi-label">Total Payments (Money Out)</div>
                    <div className="cb-kpi-val neg">-{currency} {totalPayments.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                    <small style={{ color: "#64748b" }}>{activeTxs.filter(t => t.kind === "payment").length} active payment entries</small>
                </div>
                <div className="cb-kpi-card net">
                    <div className="cb-kpi-label">Net Cash Flow</div>
                    <div className={`cb-kpi-val ${netCashflow >= 0 ? "pos" : "neg"}`}>
                        {netCashflow >= 0 ? "+" : "-"}{currency} {Math.abs(netCashflow).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </div>
                    <small style={{ color: "#64748b" }}>Active ledger movements</small>
                </div>
                <div className="cb-kpi-card count">
                    <div className="cb-kpi-label">Transactions Total</div>
                    <div className="cb-kpi-val neut">{transactions.length}</div>
                    <small style={{ color: "#64748b" }}>{transactions.filter(t => t.is_reversed).length} reversed / corrected</small>
                </div>
            </div>

            {/* Notifications */}
            {error && (
                <div style={{ background: "#fef2f2", color: "#991b1b", border: "1px solid #fca5a5", borderRadius: "8px", padding: "12px 16px", marginBottom: "16px", fontSize: "13px" }}>
                    <strong>Error:</strong> {error}
                </div>
            )}
            {success && (
                <div style={{ background: "#ecfdf5", color: "#065f46", border: "1px solid #6ee7b7", borderRadius: "8px", padding: "12px 16px", marginBottom: "16px", fontSize: "13px" }}>
                    <strong>Success:</strong> {success}
                </div>
            )}

            {/* Reversal Confirmation Box */}
            {reversingTx && (
                <div className="cb-reversal-modal">
                    <h4 style={{ margin: "0 0 10px", color: "#991b1b", fontSize: "16px" }}>
                        Reverse Cashbook Entry #{reversingTx.number}
                    </h4>
                    <p style={{ margin: "0 0 14px", fontSize: "13px", color: "#7f1d1d" }}>
                        You are about to reverse <strong>{reversingTx.kind.toUpperCase()}</strong> of <strong>{currency} {reversingTx.amount}</strong> posted on {reversingTx.date}.
                        This will generate a GAAP double-entry reversal journal batch to negate its balances while maintaining immutable audit history.
                    </p>
                    <form onSubmit={handleReverseSubmit} style={{ display: "flex", gap: "12px", flexWrap: "wrap", alignItems: "flex-end" }}>
                        <label style={{ fontSize: "12px", fontWeight: "600" }}>
                            Reversal Date
                            <input
                                type="date"
                                value={reverseDate}
                                onChange={(e) => setReverseDate(e.target.value)}
                                style={{ display: "block", marginTop: "4px", padding: "8px", border: "1px solid #cbd5e1", borderRadius: "6px" }}
                                required
                            />
                        </label>
                        <label style={{ fontSize: "12px", fontWeight: "600", flex: "1", minWidth: "200px" }}>
                            Reason for Reversal
                            <input
                                type="text"
                                placeholder="e.g. Captured in error, duplicate entry, incorrect amount"
                                value={reverseReason}
                                onChange={(e) => setReverseReason(e.target.value)}
                                style={{ display: "block", width: "100%", marginTop: "4px", padding: "8px", border: "1px solid #cbd5e1", borderRadius: "6px" }}
                                required
                            />
                        </label>
                        <div style={{ display: "flex", gap: "8px" }}>
                            <button
                                type="submit"
                                disabled={busy}
                                style={{ background: "#dc2626", color: "#fff", border: "none", padding: "9px 18px", borderRadius: "6px", fontWeight: "600", cursor: "pointer" }}
                            >
                                {busy ? "Reversing..." : "Confirm Reversal"}
                            </button>
                            <button
                                type="button"
                                onClick={() => setReversingTx(null)}
                                style={{ background: "#fff", border: "1px solid #cbd5e1", padding: "9px 14px", borderRadius: "6px", cursor: "pointer" }}
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* Entry / Correction Form */}
            {canPost && formOpen && (
                <div className={`cb-form-card ${editingTx ? "editing" : ""}`}>
                    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "12px" }}>
                        <div>
                            <h3 style={{ margin: 0, fontSize: "18px", display: "flex", alignItems: "center", gap: "8px" }}>
                                {editingTx ? `✏️ Correct Cashbook Entry #${editingTx.number}` : "➕ Record Cashbook Transaction"}
                            </h3>
                            <p style={{ margin: "4px 0 0", fontSize: "12px", color: "#64748b" }}>
                                {editingTx
                                    ? "Edit mistake (e.g. flip Receipt to Payment, change accounts or amounts). Saving will atomically reverse the erroneous journal and post the corrected entry."
                                    : "Record money entering, leaving, or transferring between your accounts."}
                            </p>
                        </div>
                        {editingTx ? (
                            <button
                                type="button"
                                onClick={cancelEdit}
                                style={{ background: "#f1f5f9", border: "1px solid #cbd5e1", padding: "6px 12px", borderRadius: "6px", fontSize: "12px", cursor: "pointer", fontWeight: "600" }}
                            >
                                ✕ Cancel Edit
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={() => setFormOpen(false)}
                                style={{ background: "transparent", border: "none", color: "#64748b", fontSize: "13px", cursor: "pointer" }}
                            >
                                Hide Form ▲
                            </button>
                        )}
                    </div>

                    <form onSubmit={handleFormSubmit}>
                        {/* Transaction Type Segmented Buttons */}
                        <div className="cb-segmented-kinds">
                            <div
                                className={`cb-kind-btn ${formKind === "payment" ? "selected-payment" : ""}`}
                                onClick={() => setFormKind("payment")}
                            >
                                <div className="cb-kind-title" style={{ color: formKind === "payment" ? "#dc2626" : "inherit" }}>
                                    🔴 Payment (Money Out)
                                </div>
                                <div className="cb-kind-desc">
                                    Funds leaving your account: <strong>Cost of Sales</strong>, supplier expenses, purchases, wages, utility bills, or withdrawals.
                                </div>
                            </div>

                            <div
                                className={`cb-kind-btn ${formKind === "receipt" ? "selected-receipt" : ""}`}
                                onClick={() => setFormKind("receipt")}
                            >
                                <div className="cb-kind-title" style={{ color: formKind === "receipt" ? "#059669" : "inherit" }}>
                                    🟢 Receipt (Money In)
                                </div>
                                <div className="cb-kind-desc">
                                    Funds entering your account: Customer payments, sales revenues, owner capital injections, or refunds.
                                </div>
                            </div>

                            <div
                                className={`cb-kind-btn ${formKind === "transfer" ? "selected-transfer" : ""}`}
                                onClick={() => setFormKind("transfer")}
                            >
                                <div className="cb-kind-title" style={{ color: formKind === "transfer" ? "#2563eb" : "inherit" }}>
                                    🔵 Transfer (Between Accounts)
                                </div>
                                <div className="cb-kind-desc">
                                    Moving money between your own accounts: Bank deposits, transfers from drawer to safe, or bank to bank.
                                </div>
                            </div>
                        </div>

                        {/* Fields Grid */}
                        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))", gap: "16px", marginBottom: "16px" }}>
                            {/* Branch */}
                            <label style={{ fontSize: "12px", fontWeight: "600" }}>
                                Branch
                                <select
                                    value={branchId}
                                    onChange={(e) => setBranchId(e.target.value)}
                                    style={{ display: "block", width: "100%", marginTop: "4px", padding: "8px", border: "1px solid #cbd5e1", borderRadius: "6px" }}
                                    required
                                >
                                    {branches.map((b) => (
                                        <option key={b.id} value={String(b.id)}>
                                            {b.name}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            {/* Date */}
                            <label style={{ fontSize: "12px", fontWeight: "600" }}>
                                Posting Date
                                <input
                                    type="date"
                                    value={date}
                                    onChange={(e) => setDate(e.target.value)}
                                    style={{ display: "block", width: "100%", marginTop: "4px", padding: "8px", border: "1px solid #cbd5e1", borderRadius: "6px" }}
                                    required
                                />
                            </label>

                            {/* Cash Account */}
                            <label style={{ fontSize: "12px", fontWeight: "600" }}>
                                {formKind === "payment"
                                    ? "Paid From (Cash / Bank Account) *"
                                    : formKind === "receipt"
                                    ? "Received Into (Cash / Bank Account) *"
                                    : "From Account (Source Cash / Bank) *"}
                                <select
                                    value={cashAccountId}
                                    onChange={(e) => setCashAccountId(e.target.value)}
                                    style={{ display: "block", width: "100%", marginTop: "4px", padding: "8px", border: "1px solid #cbd5e1", borderRadius: "6px" }}
                                    required
                                >
                                    {cashAccounts.map((a) => (
                                        <option key={a.id} value={String(a.id)}>
                                            [{a.code}] {a.name}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            {/* Counterpart Ledger Account */}
                            <label style={{ fontSize: "12px", fontWeight: "600" }}>
                                {formKind === "payment"
                                    ? "Allocate To Ledger Account (Cost of Sales, Expense, etc.) *"
                                    : formKind === "receipt"
                                    ? "Allocate To Ledger Account (Revenue, Income, etc.) *"
                                    : "To Account (Destination Cash / Bank) *"}
                                <select
                                    value={offsetAccountId}
                                    onChange={(e) => setOffsetAccountId(e.target.value)}
                                    style={{ display: "block", width: "100%", marginTop: "4px", padding: "8px", border: "1px solid #cbd5e1", borderRadius: "6px" }}
                                    required
                                >
                                    {formKind === "transfer" ? (
                                        cashAccounts
                                            .filter((a) => String(a.id) !== cashAccountId)
                                            .map((a) => (
                                                <option key={a.id} value={String(a.id)}>
                                                    [{a.code}] {a.name}
                                                </option>
                                            ))
                                    ) : (
                                        <>
                                            {formKind === "payment" && (
                                                <optgroup label="── Cost of Sales & Direct Expenses ──">
                                                    {cogsAndExpenses.map((a) => (
                                                        <option key={a.id} value={String(a.id)}>
                                                            [{a.code}] {a.name} ({a.type})
                                                        </option>
                                                    ))}
                                                </optgroup>
                                            )}
                                            {formKind === "receipt" && (
                                                <optgroup label="── Revenue & Income Accounts ──">
                                                    {revenueAccounts.map((a) => (
                                                        <option key={a.id} value={String(a.id)}>
                                                            [{a.code}] {a.name} ({a.type})
                                                        </option>
                                                    ))}
                                                </optgroup>
                                            )}
                                            {formKind === "payment" && (
                                                <optgroup label="── Revenue Accounts ──">
                                                    {revenueAccounts.map((a) => (
                                                        <option key={a.id} value={String(a.id)}>
                                                            [{a.code}] {a.name} ({a.type})
                                                        </option>
                                                    ))}
                                                </optgroup>
                                            )}
                                            {formKind === "receipt" && (
                                                <optgroup label="── Expense & Cost Accounts ──">
                                                    {cogsAndExpenses.map((a) => (
                                                        <option key={a.id} value={String(a.id)}>
                                                            [{a.code}] {a.name} ({a.type})
                                                        </option>
                                                    ))}
                                                </optgroup>
                                            )}
                                            <optgroup label="── Other Ledger Accounts (Assets, Liabilities, Equity) ──">
                                                {otherAccounts.map((a) => (
                                                    <option key={a.id} value={String(a.id)}>
                                                        [{a.code}] {a.name} ({a.type})
                                                    </option>
                                                ))}
                                            </optgroup>
                                        </>
                                    )}
                                </select>
                            </label>

                            {/* Amount */}
                            <label style={{ fontSize: "12px", fontWeight: "600" }}>
                                Amount ({currency}) *
                                <input
                                    type="text"
                                    placeholder="0.00"
                                    value={amount}
                                    onChange={(e) => setAmount(e.target.value)}
                                    style={{ display: "block", width: "100%", marginTop: "4px", padding: "8px", border: "1px solid #cbd5e1", borderRadius: "6px" }}
                                    required
                                />
                            </label>

                            {/* Tax Rate % */}
                            {formKind !== "transfer" && (
                                <label style={{ fontSize: "12px", fontWeight: "600" }}>
                                    VAT / Tax % (optional)
                                    <input
                                        type="number"
                                        placeholder="e.g. 15 to split input VAT"
                                        value={taxRate}
                                        onChange={(e) => setTaxRate(e.target.value)}
                                        style={{ display: "block", width: "100%", marginTop: "4px", padding: "8px", border: "1px solid #cbd5e1", borderRadius: "6px" }}
                                        step="0.01"
                                        min="0"
                                        max="100"
                                    />
                                </label>
                            )}

                            {/* Reference */}
                            <label style={{ fontSize: "12px", fontWeight: "600" }}>
                                Reference / Slip # *
                                <input
                                    type="text"
                                    placeholder="e.g. INV-1049, Slip #828"
                                    value={reference}
                                    onChange={(e) => setReference(e.target.value)}
                                    style={{ display: "block", width: "100%", marginTop: "4px", padding: "8px", border: "1px solid #cbd5e1", borderRadius: "6px" }}
                                    required
                                />
                            </label>

                            {/* Cash Flow */}
                            {formKind !== "transfer" && (
                                <label style={{ fontSize: "12px", fontWeight: "600" }}>
                                    Cash Flow Classification
                                    <select
                                        value={cashflow}
                                        onChange={(e) => setCashflow(e.target.value)}
                                        style={{ display: "block", width: "100%", marginTop: "4px", padding: "8px", border: "1px solid #cbd5e1", borderRadius: "6px" }}
                                    >
                                        <option value="operating">Operating (Trading, expenses, inventory)</option>
                                        <option value="investing">Investing (Equipment, assets)</option>
                                        <option value="financing">Financing (Loans, owner equity)</option>
                                        <option value="none">None</option>
                                    </select>
                                </label>
                            )}
                        </div>

                        {/* Description */}
                        <label style={{ fontSize: "12px", fontWeight: "600", display: "block", marginBottom: "16px" }}>
                            Description / Memo *
                            <input
                                type="text"
                                placeholder="e.g. Direct purchase of steel materials from supplier"
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                style={{ display: "block", width: "100%", marginTop: "4px", padding: "8px", border: "1px solid #cbd5e1", borderRadius: "6px" }}
                                required
                            />
                        </label>

                        {/* Tax Breakdown Preview */}
                        {taxPreview && (
                            <div style={{ padding: "10px 14px", background: "#f0fdf4", border: "1px solid #bbf7d0", borderRadius: "8px", fontSize: "12px", color: "#166534", marginBottom: "16px" }}>
                                📊 <strong>VAT Breakdown:</strong> Net {formKind === "payment" ? "Expense" : "Income"}: {currency} {taxPreview.net} | {parsedTaxRate}% VAT {formKind === "payment" ? "Receivable" : "Payable"}: {currency} {taxPreview.tax} | Total Cash: {currency} {taxPreview.total}
                            </div>
                        )}

                        {/* Action Buttons */}
                        <div style={{ display: "flex", gap: "10px", alignItems: "center" }}>
                            <button
                                type="submit"
                                disabled={busy}
                                style={{
                                    background: editingTx ? "#d97706" : "#236744",
                                    color: "#fff",
                                    border: "none",
                                    padding: "10px 20px",
                                    borderRadius: "6px",
                                    fontSize: "14px",
                                    fontWeight: "600",
                                    cursor: "pointer",
                                    boxShadow: "0 1px 2px rgba(0,0,0,0.05)",
                                }}
                            >
                                {busy
                                    ? "Processing..."
                                    : editingTx
                                    ? `Save Correction for #${editingTx.number}`
                                    : "Post Cashbook Entry"}
                            </button>
                            {editingTx && (
                                <button
                                    type="button"
                                    onClick={cancelEdit}
                                    style={{ background: "#fff", border: "1px solid #cbd5e1", padding: "10px 16px", borderRadius: "6px", fontSize: "14px", cursor: "pointer" }}
                                >
                                    Cancel
                                </button>
                            )}
                        </div>
                    </form>
                </div>
            )}

            {/* Transactions Toolbar & Filters */}
            <div className="cb-toolbar">
                <div className="cb-pills">
                    <button
                        type="button"
                        className={`cb-pill ${filterKind === "all" ? "selected" : ""}`}
                        onClick={() => setFilterKind("all")}
                    >
                        All ({transactions.length})
                    </button>
                    <button
                        type="button"
                        className={`cb-pill payment ${filterKind === "payment" ? "selected" : ""}`}
                        onClick={() => setFilterKind("payment")}
                    >
                        🔴 Payments ({transactions.filter((t) => t.kind === "payment").length})
                    </button>
                    <button
                        type="button"
                        className={`cb-pill receipt ${filterKind === "receipt" ? "selected" : ""}`}
                        onClick={() => setFilterKind("receipt")}
                    >
                        🟢 Receipts ({transactions.filter((t) => t.kind === "receipt").length})
                    </button>
                    <button
                        type="button"
                        className={`cb-pill transfer ${filterKind === "transfer" ? "selected" : ""}`}
                        onClick={() => setFilterKind("transfer")}
                    >
                        🔵 Transfers ({transactions.filter((t) => t.kind === "transfer").length})
                    </button>
                </div>

                <div style={{ display: "flex", gap: "12px", alignItems: "center" }}>
                    <input
                        type="search"
                        placeholder="Search ref, memo, account, #..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        style={{ padding: "7px 12px", border: "1px solid #cbd5e1", borderRadius: "6px", fontSize: "13px", minWidth: "220px" }}
                    />
                    <label style={{ fontSize: "12px", display: "flex", alignItems: "center", gap: "6px", cursor: "pointer" }}>
                        <input
                            type="checkbox"
                            checked={showReversed}
                            onChange={(e) => setShowReversed(e.target.checked)}
                        />
                        Include reversed
                    </label>
                    {canPost && !formOpen && (
                        <button
                            type="button"
                            onClick={() => {
                                cancelEdit();
                                setFormOpen(true);
                            }}
                            style={{ background: "#236744", color: "#fff", border: "none", padding: "7px 14px", borderRadius: "6px", fontSize: "13px", fontWeight: "600", cursor: "pointer" }}
                        >
                            + Record Entry
                        </button>
                    )}
                </div>
            </div>

            {/* Transactions Table */}
            <div className="cb-table-card">
                <div style={{ overflowX: "auto" }}>
                    <table className="cb-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Number</th>
                                <th>Type</th>
                                <th>Cash / Bank Account</th>
                                <th>Ledger Account</th>
                                <th>Reference & Description</th>
                                <th>Tax</th>
                                <th style={{ textAlign: "right" }}>Amount ({currency})</th>
                                <th>Status</th>
                                <th style={{ textAlign: "center" }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filteredTxs.length === 0 ? (
                                <tr>
                                    <td colSpan={10} style={{ textAlign: "center", padding: "30px", color: "#64748b" }}>
                                        No cashbook transactions found matching your criteria.
                                    </td>
                                </tr>
                            ) : (
                                filteredTxs.map((tx) => (
                                    <tr key={tx.id} className={tx.is_reversed ? "row-reversed" : ""}>
                                        <td>{tx.date}</td>
                                        <td style={{ fontWeight: "600" }}>{tx.number}</td>
                                        <td>
                                            <span className={`cb-badge ${tx.kind}`}>
                                                {tx.kind === "payment" && "↑ Payment"}
                                                {tx.kind === "receipt" && "↓ Receipt"}
                                                {tx.kind === "transfer" && "⇄ Transfer"}
                                            </span>
                                        </td>
                                        <td>
                                            {tx.cash_account_code ? `[${tx.cash_account_code}] ` : ""}
                                            {tx.cash_account_name ?? "—"}
                                        </td>
                                        <td>
                                            {tx.offset_account_code ? `[${tx.offset_account_code}] ` : ""}
                                            {tx.offset_account_name ?? "—"}
                                        </td>
                                        <td>
                                            <div style={{ fontWeight: "500" }}>{tx.reference || "—"}</div>
                                            <small style={{ color: "#64748b" }}>{tx.description}</small>
                                        </td>
                                        <td>
                                            {parseFloat(tx.tax || "0") > 0 ? `${currency} ${tx.tax}` : "—"}
                                        </td>
                                        <td style={{ textAlign: "right", fontWeight: "700" }}>
                                            <span
                                                style={{
                                                    color:
                                                        tx.kind === "payment"
                                                            ? "#dc2626"
                                                            : tx.kind === "receipt"
                                                            ? "#059669"
                                                            : "#2563eb",
                                                }}
                                            >
                                                {tx.kind === "payment" ? "-" : tx.kind === "receipt" ? "+" : ""}
                                                {currency} {tx.amount}
                                            </span>
                                        </td>
                                        <td>
                                            {tx.is_reversed ? (
                                                <span className="cb-badge reversed" title={`Reversed by ${tx.reversal_number}`}>
                                                    Reversed {tx.reversal_number ? `(${tx.reversal_number})` : ""}
                                                </span>
                                            ) : (
                                                <span className="cb-badge active">Active</span>
                                            )}
                                        </td>
                                        <td style={{ textAlign: "center", whiteSpace: "nowrap" }}>
                                            {!tx.is_reversed && (
                                                <div style={{ display: "inline-flex", gap: "6px" }}>
                                                    {canPost && (
                                                        <button
                                                            type="button"
                                                            className="cb-action-btn edit"
                                                            title="Edit and correct this transaction (reverses original and posts correction)"
                                                            onClick={() => startEdit(tx)}
                                                        >
                                                            ✏️ Edit / Correct
                                                        </button>
                                                    )}
                                                    {canReverse && (
                                                        <button
                                                            type="button"
                                                            className="cb-action-btn reverse"
                                                            title="Reverse this transaction"
                                                            onClick={() => {
                                                                setReversingTx(tx);
                                                                setReverseDate(today);
                                                                setReverseReason(`Reversal of ${tx.number}`);
                                                            }}
                                                        >
                                                            ✕ Reverse
                                                        </button>
                                                    )}
                                                </div>
                                            )}
                                            {tx.is_reversed && (
                                                <small style={{ color: "#94a3b8" }}>No actions</small>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

