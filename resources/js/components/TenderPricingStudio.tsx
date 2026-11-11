import React, { useState, useEffect } from "react";
import axios from "axios";
import {
    DollarSign,
    Calculator,
    TrendingUp,
    AlertCircle,
    CheckCircle2,
    ShieldCheck,
    Download,
    Plus,
    Trash2,
    Edit3,
    Save,
    X,
    RefreshCw,
    Layers,
    FileSpreadsheet,
    Building2,
    HelpCircle,
    Sliders,
    ArrowUpRight,
    ArrowDownRight,
    PieChart,
    Scale,
    Lock,
    Unlock,
    Info,
    Search,
    Globe,
    Upload,
} from "lucide-react";

export type PricingItem = {
    id: number;
    item_code: string | null;
    description: string;
    category: "material" | "labour" | "equipment" | "subcontractor" | "service" | "overheads" | "other";
    unit: string;
    quantity: number;
    unit_direct_cost: number;
    markup_percent: number;
    unit_selling_price: number;
    total_direct_cost: number;
    total_selling_price: number;
    notes: string | null;
    order_index: number;
    source_type?: "inventory" | "supplier_quote" | "web" | null;
    source_label?: string | null;
    source_url?: string | null;
    source_verified_at?: string | null;
};

type PriceSource = {id:number;pricing_item_id:number;source_type:"inventory"|"supplier_quote"|"web";source_label:string;unit_cost:string;currency:string;source_url:string|null;confidence:number|null;match_reason:string|null;status:"pending"|"approved"|"rejected";created_at:string};

export type PricingEstimate = {
    id: number;
    tender_id: number;
    primary_currency: string;
    target_margin_percent: number;
    risk_buffer_percent: number;
    overheads_percent: number;
    customs_duty_percent: number;
    is_split_currency: boolean;
    foreign_currency: string;
    local_currency: string;
    foreign_split_ratio: number;
    local_split_ratio: number;
    exchange_rate: number;
    praz_bid_fee: number;
    praz_levy_percent: number;
    bid_security_cost: number;
    other_statutory_costs: number;
    vat_rate_percent: number;
    status: "draft" | "under_review" | "approved" | "locked";
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
    approval_notes: string | null;
    items: PricingItem[];
};

export type FinancialSummary = {
    primary_currency: string;
    item_count: number;
    direct_cost_total: number;
    risk_buffer_cost: number;
    overheads_cost: number;
    customs_duty_cost: number;
    total_cost_base: number;
    fixed_statutory_costs: number;
    commercial_subtotal: number;
    praz_levy_amount: number;
    bid_sum_before_vat: number;
    vat_amount: number;
    total_bid_price: number;
    gross_margin: number;
    gross_margin_percent: number;
    net_profit: number;
    net_margin_percent: number;
    break_even_floor_price: number;
    split_currency: {
        foreign_currency: string;
        foreign_amount: number;
        foreign_ratio_percent: number;
        local_currency: string;
        local_base_amount: number;
        local_converted_amount: number;
        local_ratio_percent: number;
        exchange_rate: number;
    } | null;
    budget_comparison: {
        estimated_budget: number | null;
        total_bid_price: number;
        difference: number | null;
        difference_percent: number | null;
        competitiveness_status: string;
        label: string;
    };
    scenarios: {
        realistic: {
            name: string;
            margin_percent: number;
            total_bid_price: number;
            net_profit: number;
            description: string;
        };
        optimistic: {
            name: string;
            margin_percent: number;
            total_bid_price: number;
            net_profit: number;
            description: string;
        };
        conservative: {
            name: string;
            margin_percent: number;
            total_bid_price: number;
            net_profit: number;
            description: string;
        };
    };
};

type TenderPricingStudioProps = {
    tenderId: number;
    canManage: boolean;
    onClose: () => void;
};

export default function TenderPricingStudio({ tenderId, canManage, onClose }: TenderPricingStudioProps) {
    const [estimate, setEstimate] = useState<PricingEstimate | null>(null);
    const [summary, setSummary] = useState<FinancialSummary | null>(null);
    const [tender, setTender] = useState<any>(null);
    const [loading, setLoading] = useState(true);
    const [activeTab, setActiveTab] = useState<"boq" | "assumptions" | "split" | "scenarios">("boq");
    const [categoryFilter, setCategoryFilter] = useState<string>("all");
    const [savingSettings, setSavingSettings] = useState(false);

    // Form modal state for adding/editing BOQ item
    const [itemModalOpen, setItemModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<PricingItem | null>(null);
    const [itemForm, setItemForm] = useState({
        item_code: "",
        description: "",
        category: "material",
        unit: "unit",
        quantity: 1,
        unit_direct_cost: 0,
        markup_percent: 20,
        notes: "",
    });

    // Sign off state
    const [signOffModalOpen, setSignOffModalOpen] = useState(false);
    const [signOffNotes, setSignOffNotes] = useState("");
    const [submittingSignOff, setSubmittingSignOff] = useState(false);

    // Settings form state
    const [settingsForm, setSettingsForm] = useState<any>({});
    const [sources, setSources] = useState<PriceSource[]>([]);
    const [sourceItem, setSourceItem] = useState<PricingItem|null>(null);
    const [loadingSources, setLoadingSources] = useState(false);
    const [searchingWeb, setSearchingWeb] = useState(false);
    const [uploadingQuote, setUploadingQuote] = useState(false);
    const [sourceMessage, setSourceMessage] = useState("");

    useEffect(() => {
        fetchPricingData();
    }, [tenderId]);

    const fetchPricingData = async () => {
        try {
            setLoading(true);
            const res = await axios.get(`/tenders/${tenderId}/pricing`);
            setEstimate(res.data.estimate);
            setSummary(res.data.summary);
            setTender(res.data.tender);
            setSources(res.data.sources || []);

            // initialize settings form
            const est = res.data.estimate;
            setSettingsForm({
                primary_currency: est.primary_currency,
                target_margin_percent: est.target_margin_percent,
                risk_buffer_percent: est.risk_buffer_percent,
                overheads_percent: est.overheads_percent,
                customs_duty_percent: est.customs_duty_percent,
                is_split_currency: est.is_split_currency,
                foreign_currency: est.foreign_currency || "USD",
                local_currency: est.local_currency || "ZWG",
                foreign_split_ratio: est.foreign_split_ratio,
                local_split_ratio: est.local_split_ratio,
                exchange_rate: est.exchange_rate,
                praz_bid_fee: est.praz_bid_fee,
                praz_levy_percent: est.praz_levy_percent,
                bid_security_cost: est.bid_security_cost,
                other_statutory_costs: est.other_statutory_costs,
                vat_rate_percent: est.vat_rate_percent,
            });
        } catch (err) {
            console.error("Failed to load pricing data", err);
        } finally {
            setLoading(false);
        }
    };

    const openSources = async (item: PricingItem) => {
        setSourceItem(item);
        setSourceMessage("");
        setLoadingSources(true);
        try {
            const res = await axios.get(`/tenders/${tenderId}/pricing/items/${item.id}/inventory-sources`);
            setSources((old) => [...old.filter(s=>s.pricing_item_id!==item.id||s.status!=="pending"),...(res.data.sources||[])]);
        } catch (err:any) { setSourceMessage(err.response?.data?.message || "Could not match inventory items."); }
        finally { setLoadingSources(false); }
    };

    const searchWebPrices = async () => {
        if (!sourceItem) return;
        try {
            setSearchingWeb(true); setSourceMessage("Searching for current public listings. Results require your review.");
            const res=await axios.post(`/tenders/${tenderId}/pricing/items/${sourceItem.id}/web-sources`);
            setSources(old=>[...old.filter(s=>s.pricing_item_id!==sourceItem.id||s.status!=="pending"),...(res.data.sources||[])]);
            setSourceMessage(res.data.sources?.length ? `Found ${res.data.sources.length} web price suggestion(s). Confirm the listing, unit and tax basis before accepting.` : "No verified current price listing was returned. Add a supplier quote or enter a cost manually.");
        } catch(err:any) { setSourceMessage(err.response?.data?.errors?.ai?.[0] || err.response?.data?.message || "Web price search failed."); }
        finally { setSearchingWeb(false); }
    };

    const uploadSupplierQuote = async (file?:File) => {
        if(!file||!sourceItem)return;
        try {
            setUploadingQuote(true); setSourceMessage("Uploading and reading supplier quote…");
            const form=new FormData(); form.append("file",file); form.append("pricing_item_id",String(sourceItem.id));
            const res=await axios.post(`/tenders/${tenderId}/pricing/supplier-quotes`,form,{headers:{"Content-Type":"multipart/form-data"}});
            setSources(old=>[...old,...(res.data.sources||[])]); setSourceMessage(res.data.message||"Supplier quote uploaded.");
        } catch(err:any) { setSourceMessage(err.response?.data?.message||"Could not process this supplier quote."); }
        finally { setUploadingQuote(false); }
    };

    const reviewPriceSource = async (source:PriceSource, decision:"approve"|"reject") => {
        try {
            const res=await axios.patch(`/tenders/${tenderId}/pricing/sources/${source.id}`,{decision});
            setSources(old=>old.map(s=>s.id===source.id?res.data.source:s));
            if(res.data.estimate)setEstimate(res.data.estimate);
            if(res.data.summary)setSummary(res.data.summary);
            if(decision==="approve")setSourceMessage("Source approved. Direct cost and tender totals have been updated.");
        } catch(err:any) { const errors=err.response?.data?.errors; setSourceMessage((errors?Object.values(errors).flat()[0]:null) || err.response?.data?.message || "Could not review this price source."); }
    };

    const handleSaveSettings = async () => {
        try {
            setSavingSettings(true);
            const res = await axios.patch(`/tenders/${tenderId}/pricing/settings`, settingsForm);
            setEstimate(res.data.estimate);
            setSummary(res.data.summary);
            alert("Pricing settings & assumptions successfully updated!");
        } catch (err: any) {
            alert(err.response?.data?.message || "Failed to update pricing settings");
        } finally {
            setSavingSettings(false);
        }
    };

    const handleOpenAddItem = () => {
        setEditingItem(null);
        setItemForm({
            item_code: `ITM-${(estimate?.items.length || 0) + 1}`,
            description: "",
            category: "material",
            unit: "unit",
            quantity: 1,
            unit_direct_cost: 0,
            markup_percent: Number(estimate?.target_margin_percent) || 20,
            notes: "",
        });
        setItemModalOpen(true);
    };

    const handleOpenEditItem = (item: PricingItem) => {
        setEditingItem(item);
        setItemForm({
            item_code: item.item_code || "",
            description: item.description,
            category: item.category,
            unit: item.unit,
            quantity: item.quantity,
            unit_direct_cost: item.unit_direct_cost,
            markup_percent: item.markup_percent,
            notes: item.notes || "",
        });
        setItemModalOpen(true);
    };

    const handleSaveItem = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            if (editingItem) {
                const res = await axios.patch(`/tenders/pricing/items/${editingItem.id}`, itemForm);
                setEstimate(res.data.estimate);
                setSummary(res.data.summary);
            } else {
                const res = await axios.post(`/tenders/${tenderId}/pricing/items`, itemForm);
                setEstimate(res.data.estimate);
                setSummary(res.data.summary);
            }
            setItemModalOpen(false);
        } catch (err: any) {
            alert(err.response?.data?.message || "Failed to save BOQ item");
        }
    };

    const handleDeleteItem = async (itemId: number) => {
        if (!confirm("Are you sure you want to remove this line item?")) return;
        try {
            const res = await axios.delete(`/tenders/pricing/items/${itemId}`);
            setEstimate(res.data.estimate);
            setSummary(res.data.summary);
        } catch (err: any) {
            alert(err.response?.data?.message || "Failed to delete item");
        }
    };

    const handleSignOff = async () => {
        try {
            setSubmittingSignOff(true);
            const res = await axios.post(`/tenders/${tenderId}/pricing/approve`, {
                approval_notes: signOffNotes,
            });
            setEstimate(res.data.estimate);
            setSignOffModalOpen(false);
            alert("Commercial bid price approved and finalized!");
        } catch (err: any) {
            alert(err.response?.data?.message || "Failed to sign off pricing");
        } finally {
            setSubmittingSignOff(false);
        }
    };

    const filteredItems = (estimate?.items || []).filter((item) => {
        if (categoryFilter === "all") return true;
        return item.category === categoryFilter;
    });

    const formatCur = (val: number | null | undefined, cur: string = estimate?.primary_currency || "USD") => {
        if (val === null || val === undefined) return "-";
        return `${cur} ${Number(val).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    const getCompetitivenessBadge = (status: string) => {
        switch (status) {
            case "optimal":
                return <span className="bg-emerald-100 text-emerald-800 text-xs px-2.5 py-1 rounded-full font-semibold border border-emerald-200">Optimal (Competitive)</span>;
            case "abnormally_low_risk":
                return <span className="bg-amber-100 text-amber-800 text-xs px-2.5 py-1 rounded-full font-semibold border border-amber-200">Abnormally Low (PPDPA S.52 Risk)</span>;
            case "over_budget":
                return <span className="bg-rose-100 text-rose-800 text-xs px-2.5 py-1 rounded-full font-semibold border border-rose-200">Over Budget (High Price Risk)</span>;
            case "acceptable":
                return <span className="bg-blue-100 text-blue-800 text-xs px-2.5 py-1 rounded-full font-semibold border border-blue-200">Acceptable Range</span>;
            default:
                return <span className="bg-gray-100 text-gray-700 text-xs px-2.5 py-1 rounded-full font-semibold border border-gray-200">Benchmark Not Specified</span>;
        }
    };

    if (loading) {
        return (
            <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
                <div className="bg-white rounded-2xl p-8 max-w-md w-full text-center shadow-2xl">
                    <RefreshCw className="w-10 h-10 text-emerald-600 animate-spin mx-auto mb-4" />
                    <h3 className="text-lg font-bold text-gray-900 mb-1">Loading Tender Pricing Studio...</h3>
                    <p className="text-sm text-gray-500">Preparing Bill of Quantities & financial models</p>
                </div>
            </div>
        );
    }

    return (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
            <div className="bg-white rounded-2xl w-full max-w-7xl h-[92vh] flex flex-col shadow-2xl overflow-hidden border border-gray-100">
                {/* HEADER */}
                <div className="px-6 py-4 border-b border-slate-800 bg-slate-900 text-white flex items-center justify-between shrink-0">
                    <div className="flex items-center space-x-3.5">
                        <div className="p-2.5 bg-emerald-500/10 border border-emerald-500/20 rounded-xl text-emerald-400">
                            <Calculator className="w-5 h-5" />
                        </div>
                        <div>
                            <div className="flex items-center space-x-2.5">
                                <span className="font-mono text-xs text-slate-400">Tenders</span>
                                <span className="text-slate-600">/</span>
                                <span className="font-mono text-xs text-emerald-400 font-bold">{tender?.reference_number}</span>
                                <span className="text-slate-600">/</span>
                                <h2 className="text-sm font-bold tracking-tight text-white">Commercial Pricing & BOQ Studio</h2>
                                {estimate?.status === "approved" ? (
                                    <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-emerald-500/10 text-emerald-300 border border-emerald-500/30 flex items-center gap-1">
                                        <Lock className="w-3 h-3" /> Signed Off & Approved
                                    </span>
                                ) : (
                                    <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-amber-500/10 text-amber-300 border border-amber-500/30 flex items-center gap-1">
                                        <Unlock className="w-3 h-3" /> Working Estimate
                                    </span>
                                )}
                            </div>
                            <p className="text-xs text-slate-300 mt-0.5">
                                <span className="font-semibold text-white">{tender?.title}</span> &bull; <span className="text-slate-300">{tender?.procuring_entity}</span>
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center space-x-2.5">
                        <a
                            href={`/tenders/${tenderId}/pricing/export-csv`}
                            className="flex items-center space-x-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold rounded-lg border border-slate-700 transition"
                            title="Download standard Bill of Quantities CSV"
                        >
                            <FileSpreadsheet className="w-3.5 h-3.5 text-emerald-400" />
                            <span>Export BOQ CSV</span>
                        </a>

                        {canManage && estimate?.status !== "approved" && (
                            <button
                                onClick={() => setSignOffModalOpen(true)}
                                className="flex items-center space-x-1.5 px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-lg shadow-xs transition"
                            >
                                <ShieldCheck className="w-3.5 h-3.5" />
                                <span>Commercial Sign-Off</span>
                            </button>
                        )}

                        <button
                            onClick={onClose}
                            className="p-1.5 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {/* EXECUTIVE METRIC CARDS */}
                <div className="bg-slate-50/70 border-b border-slate-200/80 px-6 py-3.5 grid grid-cols-2 md:grid-cols-6 gap-3 shrink-0">
                    <div className="bg-white p-3.5 rounded-xl border border-slate-200/80 shadow-2xs">
                        <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Bid Price (Inc VAT)</div>
                        <div className="text-base font-bold font-mono text-slate-900 mt-1">
                            {formatCur(summary?.total_bid_price)}
                        </div>
                        <div className="text-[11px] text-slate-500 mt-0.5">
                            Before VAT: {formatCur(summary?.bid_sum_before_vat)}
                        </div>
                    </div>

                    <div className="bg-white p-3.5 rounded-xl border border-slate-200/80 shadow-2xs">
                        <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Direct Base Costs</div>
                        <div className="text-base font-bold font-mono text-indigo-700 mt-1">
                            {formatCur(summary?.direct_cost_total)}
                        </div>
                        <div className="text-[11px] text-slate-500 mt-0.5">
                            Total Base: {formatCur(summary?.total_cost_base)}
                        </div>
                    </div>

                    <div className="bg-white p-3.5 rounded-xl border border-slate-200/80 shadow-2xs">
                        <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Net Profit & Margin</div>
                        <div className="text-base font-bold font-mono text-emerald-700 mt-1 flex items-center gap-1">
                            {summary?.net_margin_percent}%
                            <ArrowUpRight className="w-3.5 h-3.5 text-emerald-600" />
                        </div>
                        <div className="text-[11px] text-emerald-700 font-medium mt-0.5">
                            Profit: {formatCur(summary?.net_profit)}
                        </div>
                    </div>

                    <div className="bg-white p-3.5 rounded-xl border border-slate-200/80 shadow-2xs">
                        <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Break-Even Floor Price</div>
                        <div className="text-base font-bold font-mono text-amber-800 mt-1">
                            {formatCur(summary?.break_even_floor_price)}
                        </div>
                        <div className="text-[11px] text-slate-400 mt-0.5">Zero profit bid floor</div>
                    </div>

                    <div className="bg-white p-3.5 rounded-xl border border-slate-200/80 shadow-2xs">
                        <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Statutory & VAT</div>
                        <div className="text-base font-bold font-mono text-purple-700 mt-1">
                            {formatCur((summary?.praz_levy_amount || 0) + (summary?.fixed_statutory_costs || 0) + (summary?.vat_amount || 0))}
                        </div>
                        <div className="text-[11px] text-slate-500 mt-0.5 truncate">
                            PRAZ 1%: {formatCur(summary?.praz_levy_amount)}
                        </div>
                    </div>

                    <div className="bg-white p-3.5 rounded-xl border border-slate-200/80 shadow-2xs">
                        <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Budget Competitiveness</div>
                        <div className="mt-1">
                            {getCompetitivenessBadge(summary?.budget_comparison?.competitiveness_status || "none")}
                        </div>
                        <div className="text-[11px] text-slate-500 mt-1 truncate">
                            {summary?.budget_comparison?.estimated_budget
                                ? `Budget: ${formatCur(summary?.budget_comparison?.estimated_budget)}`
                                : "No ceiling set"}
                        </div>
                    </div>
                </div>

                {/* NAVIGATION SUB-TABS */}
                <div className="px-6 border-b border-gray-200 flex items-center justify-between shrink-0 bg-white">
                    <div className="flex space-x-6">
                        <button
                            onClick={() => setActiveTab("boq")}
                            className={`py-3 text-sm font-semibold border-b-2 flex items-center space-x-2 transition ${
                                activeTab === "boq"
                                    ? "border-emerald-600 text-emerald-600"
                                    : "border-transparent text-gray-500 hover:text-gray-900"
                            }`}
                        >
                            <FileSpreadsheet className="w-4 h-4" />
                            <span>Bill of Quantities (BOQ) Items</span>
                            <span className="ml-1.5 px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-700 font-bold">
                                {estimate?.items?.length || 0}
                            </span>
                        </button>

                        <button
                            onClick={() => setActiveTab("assumptions")}
                            className={`py-3 text-sm font-semibold border-b-2 flex items-center space-x-2 transition ${
                                activeTab === "assumptions"
                                    ? "border-emerald-600 text-emerald-600"
                                    : "border-transparent text-gray-500 hover:text-gray-900"
                            }`}
                        >
                            <Sliders className="w-4 h-4" />
                            <span>Cost Assumptions & Statutory Fees</span>
                        </button>

                        <button
                            onClick={() => setActiveTab("split")}
                            className={`py-3 text-sm font-semibold border-b-2 flex items-center space-x-2 transition ${
                                activeTab === "split"
                                    ? "border-emerald-600 text-emerald-600"
                                    : "border-transparent text-gray-500 hover:text-gray-900"
                            }`}
                        >
                            <DollarSign className="w-4 h-4" />
                            <span>Dual / Split Currency (USD & ZWG)</span>
                            {estimate?.is_split_currency && (
                                <span className="ml-1 px-1.5 py-0.5 text-[10px] rounded bg-emerald-100 text-emerald-800 font-bold">
                                    ACTIVE
                                </span>
                            )}
                        </button>

                        <button
                            onClick={() => setActiveTab("scenarios")}
                            className={`py-3 text-sm font-semibold border-b-2 flex items-center space-x-2 transition ${
                                activeTab === "scenarios"
                                    ? "border-emerald-600 text-emerald-600"
                                    : "border-transparent text-gray-500 hover:text-gray-900"
                            }`}
                        >
                            <TrendingUp className="w-4 h-4" />
                            <span>Sensitivity & Scenario Analysis</span>
                        </button>
                    </div>

                    {activeTab === "boq" && (
                        <div className="flex items-center space-x-3 py-2">
                            <select
                                value={categoryFilter}
                                onChange={(e) => setCategoryFilter(e.target.value)}
                                className="text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500 bg-white"
                            >
                                <option value="all">All Categories</option>
                                <option value="material">Materials</option>
                                <option value="labour">Labour</option>
                                <option value="equipment">Equipment</option>
                                <option value="subcontractor">Subcontractors</option>
                                <option value="service">Services</option>
                                <option value="overheads">Overheads</option>
                            </select>

                            {canManage && <button
                                onClick={handleOpenAddItem}
                                className="flex items-center space-x-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold rounded-lg shadow-sm transition"
                            >
                                <Plus className="w-3.5 h-3.5" />
                                <span>Add Line Item</span>
                            </button>}
                        </div>
                    )}
                </div>

                {/* MAIN CONTENT AREA */}
                <div className="flex-1 overflow-y-auto p-6 bg-gray-50/50">
                    {/* TAB 1: BOQ ITEMS */}
                    {activeTab === "boq" && (
                        <div className="space-y-4">
                            <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-gray-100/75 border-b border-gray-200 text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                        <tr>
                                            <th className="py-3 px-4">Code</th>
                                            <th className="py-3 px-4">Item Description</th>
                                            <th className="py-3 px-4">Category</th>
                                            <th className="py-3 px-4 text-center">Unit</th>
                                            <th className="py-3 px-4 text-right">Qty</th>
                                            <th className="py-3 px-4 text-right">Direct Cost</th>
                                            <th className="py-3 px-4 text-right">Margin %</th>
                                            <th className="py-3 px-4 text-right">Selling Price</th>
                                            <th className="py-3 px-4 text-right">Total Bid Sum</th>
                                            <th className="py-3 px-4 text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {filteredItems.length === 0 ? (
                                            <tr>
                                                <td colSpan={10} className="py-12 text-center text-gray-500">
                                                    <FileSpreadsheet className="w-10 h-10 text-gray-300 mx-auto mb-2" />
                                                    <p className="text-sm font-medium">No BOQ items found in this category</p>
                                                    <button
                                                        onClick={handleOpenAddItem}
                                                        className="mt-3 text-xs text-emerald-600 hover:text-emerald-700 font-semibold"
                                                    >
                                                        + Add first line item
                                                    </button>
                                                </td>
                                            </tr>
                                        ) : (
                                            filteredItems.map((item) => (
                                                <tr key={item.id} className="hover:bg-gray-50/80 transition">
                                                    <td className="py-3 px-4 font-mono text-xs text-gray-500">
                                                        {item.item_code || "-"}
                                                    </td>
                                                    <td className="py-3 px-4">
                                                        <div className="font-medium text-gray-900">{item.description}</div>
                                                        {item.notes && (
                                                            <div className="text-xs text-gray-400 mt-0.5">{item.notes}</div>
                                                        )}
                                                        {item.source_label && <div className="mt-1 text-[11px] text-emerald-700">Cost source: {item.source_label}{item.source_verified_at ? ` · confirmed ${new Date(item.source_verified_at).toLocaleDateString()}` : ""}</div>}
                                                    </td>
                                                    <td className="py-3 px-4">
                                                        <span className="capitalize text-xs px-2 py-0.5 bg-gray-100 text-gray-700 rounded-md font-medium">
                                                            {item.category}
                                                        </span>
                                                    </td>
                                                    <td className="py-3 px-4 text-center text-xs text-gray-600 font-medium">
                                                        {item.unit}
                                                    </td>
                                                    <td className="py-3 px-4 text-right font-medium text-gray-900">
                                                        {Number(item.quantity).toLocaleString()}
                                                    </td>
                                                    <td className="py-3 px-4 text-right font-mono text-xs text-gray-600">
                                                        {formatCur(item.unit_direct_cost)}
                                                    </td>
                                                    <td className="py-3 px-4 text-right text-xs font-semibold text-emerald-600">
                                                        {item.markup_percent}%
                                                    </td>
                                                    <td className="py-3 px-4 text-right font-mono text-xs text-gray-700">
                                                        {formatCur(item.unit_selling_price)}
                                                    </td>
                                                    <td className="py-3 px-4 text-right font-mono text-xs font-bold text-gray-900">
                                                        {formatCur(item.total_selling_price)}
                                                    </td>
                                                    <td className="py-3 px-4 text-center">
                                                        <div className="flex items-center justify-center space-x-2">
                                                            {canManage && <button onClick={() => openSources(item)} className="p-1 text-gray-400 hover:text-blue-600 rounded transition" title="Find inventory, supplier quote or web prices"><Search className="w-4 h-4" /></button>}
                                                            {canManage && <button
                                                                onClick={() => handleOpenEditItem(item)}
                                                                className="p-1 text-gray-400 hover:text-emerald-600 rounded transition"
                                                                title="Edit line item"
                                                            >
                                                                <Edit3 className="w-4 h-4" />
                                                            </button>}
                                                            {canManage && <button
                                                                onClick={() => handleDeleteItem(item.id)}
                                                                className="p-1 text-gray-400 hover:text-rose-600 rounded transition"
                                                                title="Delete line item"
                                                            >
                                                                <Trash2 className="w-4 h-4" />
                                                            </button>}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                    {filteredItems.length > 0 && (
                                        <tfoot className="bg-gray-100/90 font-semibold text-xs border-t-2 border-gray-300">
                                            <tr>
                                                <td colSpan={5} className="py-3 px-4 text-gray-700 text-right">
                                                    Subtotal of Filtered Items:
                                                </td>
                                                <td className="py-3 px-4 text-right font-mono text-indigo-700">
                                                    {formatCur(
                                                        filteredItems.reduce((acc, it) => acc + Number(it.total_direct_cost), 0)
                                                    )}
                                                </td>
                                                <td className="py-3 px-4"></td>
                                                <td className="py-3 px-4"></td>
                                                <td className="py-3 px-4 text-right font-mono text-gray-900 font-bold">
                                                    {formatCur(
                                                        filteredItems.reduce((acc, it) => acc + Number(it.total_selling_price), 0)
                                                    )}
                                                </td>
                                                <td className="py-3 px-4"></td>
                                            </tr>
                                        </tfoot>
                                    )}
                                </table>
                            </div>
                        </div>
                    )}

                    {/* TAB 2: COST ASSUMPTIONS & STATUTORY FEES */}
                    {activeTab === "assumptions" && (
                        <div className="max-w-4xl mx-auto space-y-6">
                            <div className="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                                <h3 className="text-base font-bold text-gray-900 flex items-center gap-2 mb-4">
                                    <Sliders className="w-5 h-5 text-emerald-600" />
                                    Costing Overheads & Risk Buffers
                                </h3>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Base Commercial Margin (%)
                                        </label>
                                        <input
                                            type="number"
                                            step="0.1"
                                            value={settingsForm.target_margin_percent || 0}
                                            onChange={(e) =>
                                                setSettingsForm({ ...settingsForm, target_margin_percent: parseFloat(e.target.value) || 0 })
                                            }
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                        <p className="text-[11px] text-gray-500 mt-1">Target net profit margin applied to direct costs</p>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Unforeseen Risk & Contingency Buffer (%)
                                        </label>
                                        <input
                                            type="number"
                                            step="0.1"
                                            value={settingsForm.risk_buffer_percent || 0}
                                            onChange={(e) =>
                                                setSettingsForm({ ...settingsForm, risk_buffer_percent: parseFloat(e.target.value) || 0 })
                                            }
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                        <p className="text-[11px] text-gray-500 mt-1">Covers price volatility, supply chain friction & inflation</p>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            General Operational Overheads (%)
                                        </label>
                                        <input
                                            type="number"
                                            step="0.1"
                                            value={settingsForm.overheads_percent || 0}
                                            onChange={(e) =>
                                                setSettingsForm({ ...settingsForm, overheads_percent: parseFloat(e.target.value) || 0 })
                                            }
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                        <p className="text-[11px] text-gray-500 mt-1">Administrative, utilities, and company management allocation</p>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Customs / Import Duty Allocation (%)
                                        </label>
                                        <input
                                            type="number"
                                            step="0.1"
                                            value={settingsForm.customs_duty_percent || 0}
                                            onChange={(e) =>
                                                setSettingsForm({ ...settingsForm, customs_duty_percent: parseFloat(e.target.value) || 0 })
                                            }
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                        <p className="text-[11px] text-gray-500 mt-1">ZIMRA customs tariff & clearing costs on imported inputs</p>
                                    </div>
                                </div>
                            </div>

                            <div className="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                                <h3 className="text-base font-bold text-gray-900 flex items-center gap-2 mb-4">
                                    <Scale className="w-5 h-5 text-purple-600" />
                                    Zimbabwe Statutory Procurement Fees & Taxes (PPDPA)
                                </h3>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            PRAZ Statutory Administration Levy (%)
                                        </label>
                                        <input
                                            type="number"
                                            step="0.05"
                                            value={settingsForm.praz_levy_percent || 1.0}
                                            onChange={(e) =>
                                                setSettingsForm({ ...settingsForm, praz_levy_percent: parseFloat(e.target.value) || 0 })
                                            }
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500"
                                        />
                                        <p className="text-[11px] text-gray-500 mt-1">Standard PRAZ 1.00% fee payable on winning contract sum</p>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Value Added Tax (VAT Rate %)
                                        </label>
                                        <input
                                            type="number"
                                            step="0.5"
                                            value={settingsForm.vat_rate_percent || 15.0}
                                            onChange={(e) =>
                                                setSettingsForm({ ...settingsForm, vat_rate_percent: parseFloat(e.target.value) || 0 })
                                            }
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500"
                                        />
                                        <p className="text-[11px] text-gray-500 mt-1">Standard ZIMRA statutory VAT (currently 15%)</p>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Tender Document Purchase Fee ({estimate?.primary_currency})
                                        </label>
                                        <input
                                            type="number"
                                            step="1"
                                            value={settingsForm.praz_bid_fee || 0}
                                            onChange={(e) =>
                                                setSettingsForm({ ...settingsForm, praz_bid_fee: parseFloat(e.target.value) || 0 })
                                            }
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500"
                                        />
                                        <p className="text-[11px] text-gray-500 mt-1">Non-refundable fee to purchase tender dossier (e.g. $100)</p>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Bid Bond / Security Underwriting Cost ({estimate?.primary_currency})
                                        </label>
                                        <input
                                            type="number"
                                            step="1"
                                            value={settingsForm.bid_security_cost || 0}
                                            onChange={(e) =>
                                                setSettingsForm({ ...settingsForm, bid_security_cost: parseFloat(e.target.value) || 0 })
                                            }
                                            className="w-full text-sm border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500"
                                        />
                                        <p className="text-[11px] text-gray-500 mt-1">Bank or insurance commission to issue bid bond guarantee</p>
                                    </div>
                                </div>

                                <div className="mt-6 flex justify-end">
                                    <button
                                        onClick={handleSaveSettings}
                                        disabled={savingSettings}
                                        className="flex items-center space-x-2 px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-sm rounded-lg shadow transition"
                                    >
                                        <Save className="w-4 h-4" />
                                        <span>{savingSettings ? "Saving Settings..." : "Save Pricing Assumptions"}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* TAB 3: DUAL / SPLIT CURRENCY (USD & ZWG) */}
                    {activeTab === "split" && (
                        <div className="max-w-4xl mx-auto space-y-6">
                            <div className="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                                <div className="flex items-center justify-between pb-4 border-b border-gray-200">
                                    <div>
                                        <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">
                                            <DollarSign className="w-5 h-5 text-emerald-600" />
                                            Split-Currency Bidding (Foreign & Local Component)
                                        </h3>
                                        <p className="text-xs text-gray-500 mt-0.5">
                                            Submit bids partitioned into foreign currency (e.g. USD) and local currency (e.g. ZWG) to mitigate exchange fluctuations
                                        </p>
                                    </div>
                                    <label className="relative inline-flex items-center cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={settingsForm.is_split_currency || false}
                                            onChange={(e) =>
                                                setSettingsForm({ ...settingsForm, is_split_currency: e.target.checked })
                                            }
                                            className="sr-only peer"
                                        />
                                        <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                                    </label>
                                </div>

                                {settingsForm.is_split_currency ? (
                                    <div className="mt-6 space-y-6">
                                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 mb-1">
                                                    Foreign Ratio (% in USD)
                                                </label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    max="100"
                                                    value={settingsForm.foreign_split_ratio || 0}
                                                    onChange={(e) => {
                                                        const fVal = Math.min(100, Math.max(0, parseFloat(e.target.value) || 0));
                                                        setSettingsForm({
                                                            ...settingsForm,
                                                            foreign_split_ratio: fVal,
                                                            local_split_ratio: 100 - fVal,
                                                        });
                                                    }}
                                                    className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                                />
                                            </div>

                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 mb-1">
                                                    Local Ratio (% in ZWG)
                                                </label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    max="100"
                                                    value={settingsForm.local_split_ratio || 0}
                                                    onChange={(e) => {
                                                        const lVal = Math.min(100, Math.max(0, parseFloat(e.target.value) || 0));
                                                        setSettingsForm({
                                                            ...settingsForm,
                                                            local_split_ratio: lVal,
                                                            foreign_split_ratio: 100 - lVal,
                                                        });
                                                    }}
                                                    className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                                />
                                            </div>

                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 mb-1">
                                                    Exchange Rate (ZWG per 1 USD)
                                                </label>
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    value={settingsForm.exchange_rate || 27.5}
                                                    onChange={(e) =>
                                                        setSettingsForm({ ...settingsForm, exchange_rate: parseFloat(e.target.value) || 1.0 })
                                                    }
                                                    className="w-full text-sm border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                                />
                                            </div>
                                        </div>

                                        {summary?.split_currency && (
                                            <div className="bg-emerald-50/60 rounded-xl p-5 border border-emerald-200">
                                                <h4 className="text-sm font-bold text-emerald-900 mb-3">
                                                    Calculated Split Bid Payable Portions
                                                </h4>
                                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                    <div className="bg-white p-4 rounded-lg border border-emerald-100 shadow-sm">
                                                        <div className="text-xs text-gray-500 font-medium">Foreign Portion ({summary.split_currency.foreign_ratio_percent}%)</div>
                                                        <div className="text-xl font-bold text-gray-900 mt-1">
                                                            USD {Number(summary.split_currency.foreign_amount).toLocaleString("en-US", { minimumFractionDigits: 2 })}
                                                        </div>
                                                        <div className="text-xs text-emerald-600 mt-1">To be settled via Nostro account</div>
                                                    </div>

                                                    <div className="bg-white p-4 rounded-lg border border-emerald-100 shadow-sm">
                                                        <div className="text-xs text-gray-500 font-medium">Local Portion ({summary.split_currency.local_ratio_percent}%)</div>
                                                        <div className="text-xl font-bold text-gray-900 mt-1">
                                                            ZWG {Number(summary.split_currency.local_converted_amount).toLocaleString("en-US", { minimumFractionDigits: 2 })}
                                                        </div>
                                                        <div className="text-xs text-gray-500 mt-1">
                                                            (Equivalent to USD {Number(summary.split_currency.local_base_amount).toLocaleString()} @ {summary.split_currency.exchange_rate} rate)
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        <div className="flex justify-end">
                                            <button
                                                onClick={handleSaveSettings}
                                                disabled={savingSettings}
                                                className="flex items-center space-x-2 px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-sm rounded-lg shadow transition"
                                            >
                                                <Save className="w-4 h-4" />
                                                <span>Save Split Currency Settings</span>
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="py-8 text-center text-gray-500">
                                        <Info className="w-8 h-8 text-gray-400 mx-auto mb-2" />
                                        <p className="text-sm">Split-currency bidding is currently disabled for this tender.</p>
                                        <p className="text-xs text-gray-400 mt-1">Enable the toggle above if the tender document allows dual currency quotation.</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* TAB 4: SCENARIO & COMPETITIVENESS ANALYSIS */}
                    {activeTab === "scenarios" && (
                        <div className="max-w-5xl mx-auto space-y-6">
                            {/* Sensitivity Comparison Cards */}
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                                {summary?.scenarios && (
                                    <>
                                        {/* Realistic */}
                                        <div className="bg-white rounded-2xl border-2 border-emerald-400 shadow-md p-6 relative overflow-hidden">
                                            <div className="absolute top-0 right-0 bg-emerald-500 text-white text-[10px] font-bold px-3 py-1 rounded-bl-lg uppercase tracking-wider">
                                                Base Case
                                            </div>
                                            <h4 className="text-base font-bold text-gray-900">{summary.scenarios.realistic.name}</h4>
                                            <p className="text-xs text-gray-500 mt-1">{summary.scenarios.realistic.description}</p>

                                            <div className="mt-4 pt-4 border-t border-gray-100 space-y-2">
                                                <div className="flex justify-between text-xs">
                                                    <span className="text-gray-500">Target Margin:</span>
                                                    <span className="font-semibold text-emerald-600">{summary.scenarios.realistic.margin_percent}%</span>
                                                </div>
                                                <div className="flex justify-between text-xs">
                                                    <span className="text-gray-500">Net Profit:</span>
                                                    <span className="font-semibold text-gray-900">{formatCur(summary.scenarios.realistic.net_profit)}</span>
                                                </div>
                                                <div className="flex justify-between text-sm pt-2 border-t border-gray-100">
                                                    <span className="font-bold text-gray-700">Total Bid Price:</span>
                                                    <span className="font-bold text-emerald-700 font-mono">{formatCur(summary.scenarios.realistic.total_bid_price)}</span>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Optimistic */}
                                        <div className="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 relative overflow-hidden">
                                            <h4 className="text-base font-bold text-gray-900">{summary.scenarios.optimistic.name}</h4>
                                            <p className="text-xs text-gray-500 mt-1">{summary.scenarios.optimistic.description}</p>

                                            <div className="mt-4 pt-4 border-t border-gray-100 space-y-2">
                                                <div className="flex justify-between text-xs">
                                                    <span className="text-gray-500">Target Margin:</span>
                                                    <span className="font-semibold text-blue-600">{summary.scenarios.optimistic.margin_percent}%</span>
                                                </div>
                                                <div className="flex justify-between text-xs">
                                                    <span className="text-gray-500">Net Profit:</span>
                                                    <span className="font-semibold text-gray-900">{formatCur(summary.scenarios.optimistic.net_profit)}</span>
                                                </div>
                                                <div className="flex justify-between text-sm pt-2 border-t border-gray-100">
                                                    <span className="font-bold text-gray-700">Total Bid Price:</span>
                                                    <span className="font-bold text-blue-700 font-mono">{formatCur(summary.scenarios.optimistic.total_bid_price)}</span>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Conservative */}
                                        <div className="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 relative overflow-hidden">
                                            <h4 className="text-base font-bold text-gray-900">{summary.scenarios.conservative.name}</h4>
                                            <p className="text-xs text-gray-500 mt-1">{summary.scenarios.conservative.description}</p>

                                            <div className="mt-4 pt-4 border-t border-gray-100 space-y-2">
                                                <div className="flex justify-between text-xs">
                                                    <span className="text-gray-500">Target Margin:</span>
                                                    <span className="font-semibold text-amber-600">{summary.scenarios.conservative.margin_percent}%</span>
                                                </div>
                                                <div className="flex justify-between text-xs">
                                                    <span className="text-gray-500">Net Profit:</span>
                                                    <span className="font-semibold text-gray-900">{formatCur(summary.scenarios.conservative.net_profit)}</span>
                                                </div>
                                                <div className="flex justify-between text-sm pt-2 border-t border-gray-100">
                                                    <span className="font-bold text-gray-700">Total Bid Price:</span>
                                                    <span className="font-bold text-amber-700 font-mono">{formatCur(summary.scenarios.conservative.total_bid_price)}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </>
                                )}
                            </div>

                            {/* PPDPA Compliance Alert */}
                            <div className="bg-amber-50 rounded-xl p-5 border border-amber-200">
                                <div className="flex items-start space-x-3">
                                    <AlertCircle className="w-5 h-5 text-amber-600 mt-0.5 shrink-0" />
                                    <div className="text-xs text-amber-900 space-y-1">
                                        <div className="font-bold text-sm">PPDPA Section 52 – Abnormally Low Tenders Statutory Notice</div>
                                        <p>
                                            In Zimbabwean public procurement, any tender whose price is abnormally low (typically &gt; 30% below the procurement entity's official estimated value or below verifiable direct input costs) may be challenged by the Evaluation Committee.
                                        </p>
                                        <p>
                                            Your break-even minimum floor price is <strong>{formatCur(summary?.break_even_floor_price)}</strong>. Any quotation below this amount will result in financial loss or potential disqualification under Section 52.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                {sourceItem && (
                    <div className="fixed inset-0 z-[60] bg-black/50 backdrop-blur-sm flex items-center justify-center p-4" onClick={()=>setSourceItem(null)}>
                        <section className="bg-white rounded-2xl p-6 max-w-4xl w-full max-h-[88vh] overflow-y-auto shadow-2xl" onClick={e=>e.stopPropagation()}>
                            <div className="flex items-start justify-between gap-4"><div><span className="text-[10px] font-bold uppercase tracking-wider text-emerald-700">Cost sourcing · {sourceItem.item_code}</span><h3 className="text-lg font-bold text-gray-900 mt-1">{sourceItem.description}</h3><p className="text-xs text-gray-500 mt-1">Check specification, unit and price basis before applying a source.</p></div><button onClick={()=>setSourceItem(null)} className="p-1.5 text-gray-500 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5"/></button></div>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <button disabled={loadingSources} onClick={()=>openSources(sourceItem)} className="flex items-center gap-2 px-3 py-2 bg-slate-800 text-white rounded-lg text-xs font-semibold disabled:opacity-50"><Search size={15}/>{loadingSources?"Matching…":"Match inventory"}</button>
                                <button disabled={searchingWeb} onClick={searchWebPrices} className="flex items-center gap-2 px-3 py-2 bg-emerald-700 text-white rounded-lg text-xs font-semibold disabled:opacity-50"><Globe size={15}/>{searchingWeb?"Searching…":"Search web prices"}</button>
                                <label className="flex items-center gap-2 px-3 py-2 border border-gray-300 rounded-lg text-xs font-semibold cursor-pointer hover:bg-gray-50"><Upload size={15}/>{uploadingQuote?"Reading quote…":"Upload supplier quote"}<input type="file" className="hidden" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png" disabled={uploadingQuote} onChange={e=>{void uploadSupplierQuote(e.target.files?.[0]);e.currentTarget.value=""}}/></label>
                            </div>
                            {sourceMessage&&<div className="mt-3 p-3 rounded-lg bg-blue-50 border border-blue-100 text-xs text-blue-900" role="status">{sourceMessage}</div>}
                            <div className="mt-5 space-y-3">
                                {sources.filter(s=>s.pricing_item_id===sourceItem.id).length===0?<p className="p-5 text-center text-sm text-gray-500 bg-gray-50 rounded-xl">No price suggestions yet. Match inventory, upload a quote or search the web.</p>:sources.filter(s=>s.pricing_item_id===sourceItem.id).map(s=><article key={s.id} className={`p-4 rounded-xl border ${s.status==="approved"?"border-emerald-200 bg-emerald-50/40":s.status==="rejected"?"border-gray-200 bg-gray-50 opacity-70":"border-amber-200 bg-amber-50/30"}`}>
                                    <div className="flex flex-wrap justify-between gap-3"><div className="min-w-0"><div className="flex items-center gap-2"><strong className="text-sm text-gray-900">{s.source_label}</strong><span className="text-[10px] uppercase px-2 py-0.5 rounded-full bg-white border border-gray-200 text-gray-600">{s.source_type.replace("_"," ")}</span><span className="text-[10px] uppercase px-2 py-0.5 rounded-full bg-white border border-gray-200">{s.status}</span></div><p className="text-xs text-gray-600 mt-1">{s.match_reason}</p>{s.source_url&&<a className="inline-block mt-1 text-xs text-blue-700 underline break-all" href={s.source_url} target="_blank" rel="noopener noreferrer">{s.source_type==="web"?"Open source listing":s.source_type==="supplier_quote"?"View uploaded quotation":"Inventory item"}</a>}</div><div className="text-right"><strong className="font-mono text-base">{s.currency} {Number(s.unit_cost).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:4})}</strong><div className="text-[11px] text-gray-500">{s.confidence!==null?`Match confidence ${s.confidence}% · `:""}{new Date(s.created_at).toLocaleDateString()}</div></div></div>
                                    {canManage&&s.status==="pending"&&<div className="mt-3 flex justify-end gap-2"><button onClick={()=>reviewPriceSource(s,"reject")} className="px-3 py-1.5 border border-gray-300 rounded-lg text-xs font-semibold">Reject</button><button onClick={()=>reviewPriceSource(s,"approve")} className="px-3 py-1.5 bg-emerald-700 text-white rounded-lg text-xs font-semibold">Confirm and use cost</button></div>}
                                </article>)}
                            </div>
                            <p className="mt-4 text-[11px] text-gray-500">Web search uses the configured Gemini API and Google Search grounding; provider charges may apply. Web prices are indicative and may be stale or exclude delivery, tax, customs and local availability. Confirm terms with the source before approval.</p>
                        </section>
                    </div>
                )}

                {/* MODAL: ADD / EDIT BOQ LINE ITEM */}
                {itemModalOpen && (
                    <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
                        <div className="bg-white rounded-2xl p-6 max-w-lg w-full shadow-2xl">
                            <div className="flex items-center justify-between pb-3 border-b border-gray-200">
                                <h3 className="font-bold text-gray-900">
                                    {editingItem ? "Edit BOQ Line Item" : "Add New BOQ Line Item"}
                                </h3>
                                <button
                                    onClick={() => setItemModalOpen(false)}
                                    className="text-gray-400 hover:text-gray-600"
                                >
                                    <X className="w-5 h-5" />
                                </button>
                            </div>

                            <form onSubmit={handleSaveItem} className="mt-4 space-y-4">
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Item Code
                                        </label>
                                        <input
                                            type="text"
                                            value={itemForm.item_code}
                                            onChange={(e) => setItemForm({ ...itemForm, item_code: e.target.value })}
                                            className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Category *
                                        </label>
                                        <select
                                            value={itemForm.category}
                                            onChange={(e) => setItemForm({ ...itemForm, category: e.target.value as any })}
                                            className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        >
                                            <option value="material">Material</option>
                                            <option value="labour">Labour</option>
                                            <option value="equipment">Equipment</option>
                                            <option value="subcontractor">Subcontractor</option>
                                            <option value="service">Service</option>
                                            <option value="overheads">Overheads</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 mb-1">
                                        Item Description *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        value={itemForm.description}
                                        onChange={(e) => setItemForm({ ...itemForm, description: e.target.value })}
                                        className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                    />
                                </div>

                                <div className="grid grid-cols-3 gap-3">
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Unit *
                                        </label>
                                        <input
                                            type="text"
                                            required
                                            value={itemForm.unit}
                                            onChange={(e) => setItemForm({ ...itemForm, unit: e.target.value })}
                                            className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Quantity *
                                        </label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            required
                                            value={itemForm.quantity}
                                            onChange={(e) => setItemForm({ ...itemForm, quantity: parseFloat(e.target.value) || 0 })}
                                            className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                                            Unit Direct Cost *
                                        </label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            required
                                            value={itemForm.unit_direct_cost}
                                            onChange={(e) =>
                                                setItemForm({ ...itemForm, unit_direct_cost: parseFloat(e.target.value) || 0 })
                                            }
                                            className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 mb-1">
                                        Line Markup Margin (%)
                                    </label>
                                    <input
                                        type="number"
                                        step="0.1"
                                        value={itemForm.markup_percent}
                                        onChange={(e) =>
                                            setItemForm({ ...itemForm, markup_percent: parseFloat(e.target.value) || 0 })
                                        }
                                        className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 mb-1">
                                        Notes / Specification Reference
                                    </label>
                                    <input
                                        type="text"
                                        value={itemForm.notes}
                                        onChange={(e) => setItemForm({ ...itemForm, notes: e.target.value })}
                                        className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                    />
                                </div>

                                <div className="pt-3 border-t border-gray-200 flex justify-end space-x-3">
                                    <button
                                        type="button"
                                        onClick={() => setItemModalOpen(false)}
                                        className="px-4 py-2 border border-gray-300 text-xs font-medium rounded-lg text-gray-700 hover:bg-gray-50"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-xs font-semibold rounded-lg text-white shadow"
                                    >
                                        Save Item
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* MODAL: COMMERCIAL SIGN-OFF */}
                {signOffModalOpen && (
                    <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
                        <div className="bg-white rounded-2xl p-6 max-w-md w-full shadow-2xl">
                            <div className="flex items-center space-x-3 text-emerald-600 mb-3">
                                <ShieldCheck className="w-6 h-6" />
                                <h3 className="font-bold text-gray-900 text-base">Commercial Bid Price Sign-Off</h3>
                            </div>
                            <p className="text-xs text-gray-600 mb-4">
                                Signing off will finalize and lock the commercial bid pricing at <strong>{formatCur(summary?.total_bid_price)}</strong> for tender submission.
                            </p>

                            <div className="space-y-3">
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 mb-1">
                                        Approval Notes / Authorisation Reference
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={signOffNotes}
                                        onChange={(e) => setSignOffNotes(e.target.value)}
                                        placeholder="e.g. Approved by Finance Director in Tender Committee meeting #4"
                                        className="w-full text-xs border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500"
                                    />
                                </div>

                                <div className="pt-3 border-t border-gray-200 flex justify-end space-x-3">
                                    <button
                                        type="button"
                                        onClick={() => setSignOffModalOpen(false)}
                                        className="px-4 py-2 border border-gray-300 text-xs font-medium rounded-lg text-gray-700 hover:bg-gray-50"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        disabled={submittingSignOff}
                                        onClick={handleSignOff}
                                        className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-xs font-semibold rounded-lg text-white shadow flex items-center gap-1.5"
                                    >
                                        <CheckCircle2 className="w-4 h-4" />
                                        <span>{submittingSignOff ? "Signing Off..." : "Confirm Sign-Off"}</span>
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

