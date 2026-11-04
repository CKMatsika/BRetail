import React, { useState } from "react";
import {
    Download,
    Printer,
    ArrowUpRight,
    FileText,
    Search,
} from "lucide-react";
import {
    AgingReport,
    AgingSnapshot,
    FinancialReport,
    FinancialSnapshot,
    Metric,
    Empty,
    amount,
    dateText,
} from "../components/ReportViews";

type RegisterRow = {
    id: number;
    kind: string;
    date: string;
    due_on: string | null;
    number: string;
    party_name: string;
    net: string;
    tax: string;
    total: string;
    sign: number;
    currency: string | null;
    original_total: string | null;
    reference: string;
};
type PartyTotal = {
    id: number;
    name: string;
    net: string;
    tax: string;
    total: string;
};
type Business = {
    sales: null | {
        by_party?: PartyTotal[];
        net: string;
        tax: string;
        total: string;
        receipts: string;
        documents: RegisterRow[];
    };
    procurement: null | {
        by_party?: PartyTotal[];
        billed: string;
        net: string;
        tax: string;
        paid: string;
        received: string;
        order_count: number;
        orders: {
            id: number;
            number: string;
            date: string;
            expected_on: string | null;
            status: string;
            supplier_name: string;
            total: string;
            currency: string | null;
            original_total: string | null;
        }[];
        documents: RegisterRow[];
    };
    aging: AgingSnapshot | null;
};
export type AnalyticsData = {
    filters: {
        from: string;
        to: string;
        branch_id: number | null;
        book: "training" | "live";
    };
    branches: { id: number; name: string }[];
    currency_totals: {
        currency: string;
        sales: string;
        refunds: string;
        net: string;
        tax: string;
        count: number;
    }[];
    sales_count: number;
    daily_sales: { date: string; transactions: number }[];
    top_items: { name: string; sku: string; quantity: string }[];
    inventory: {
        variant_count: number;
        quantity: string;
        value: string;
        low_stock_count: number;
        low_stock: {
            name: string;
            sku: string;
            quantity: string;
            reorder_level: string;
        }[];
    };
    accounting: FinancialSnapshot | null;
    business?: Business;
    company_name?: string;
    generated_at?: string;
    base_currency: string | null;
    timezone: string;
};
const titles: Record<string, string> = {
    overview: "Business overview",
    sales: "Credit sales register",
    pos_sales: "POS sales",
    procurement: "Procurement",
    debtors: "Debtors ageing",
    creditors: "Creditors ageing",
    profit_loss: "Profit & loss",
    balance_sheet: "Balance sheet",
    trial_balance: "Trial balance",
    general_ledger: "General ledger",
    cash_flow: "Cash flow",
};
export default function Reports({
    initial: d,
    permissions,
}: {
    initial: AnalyticsData;
    permissions: string[];
}) {
    const [tab, setTab] = useState(() => {
        const requested = new URLSearchParams(window.location.search).get(
            "report",
        );
        return requested && titles[requested] ? requested : "overview";
    });
    const [search, setSearch] = useState("");
    const [registerKind, setRegisterKind] = useState("");
    const canExport =
        permissions.includes("*") || permissions.includes("reports.export");
    const currency = d.base_currency ?? "";
    const business = d.business;
    const sections = [
        {
            name: "Management",
            tabs: [
                "overview",
                "pos_sales",
                ...(business?.sales ? ["sales"] : []),
                ...(business?.procurement ? ["procurement"] : []),
            ],
        },
        {
            name: "Receivables & payables",
            tabs: [
                ...(business?.aging?.controls.some(
                    (c) => c.role === "receivable",
                )
                    ? ["debtors"]
                    : []),
                ...(business?.aging?.controls.some((c) => c.role === "payable")
                    ? ["creditors"]
                    : []),
            ],
        },
        {
            name: "Financial statements",
            tabs: d.accounting
                ? [
                      "profit_loss",
                      "balance_sheet",
                      "trial_balance",
                      "general_ledger",
                      "cash_flow",
                  ]
                : [],
        },
    ];
    const allowed = sections.flatMap((s) => s.tabs);
    const view = allowed.includes(tab) ? tab : "overview";
    const query = new URLSearchParams({
        from: d.filters.from,
        to: d.filters.to,
        book: d.filters.book,
        report: view,
    });
    if (d.filters.branch_id)
        query.set("branch_id", String(d.filters.branch_id));
    const branch =
        d.branches.find((b) => b.id === Number(d.filters.branch_id))?.name ??
        "All authorised branches";
    const switchTab = (next: string) => {
        setTab(next);
        setSearch("");
        setRegisterKind("");
        const url = new URL(window.location.href);
        url.searchParams.set("report", next);
        window.history.replaceState(null, "", url);
    };
    const partySummary = (rows: PartyTotal[], label: string) => (
        <>
            <div className="report-subheading">
                <h3>{label}</h3>
            </div>
            <div className="report-table-wrap">
                <table className="report-table">
                    <thead>
                        <tr>
                            <th>Party</th>
                            <th className="num">Net {currency}</th>
                            <th className="num">VAT</th>
                            <th className="num">Total {currency}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td>{r.name}</td>
                                <td className="num">{amount(r.net)}</td>
                                <td className="num">{amount(r.tax)}</td>
                                <td className="num">
                                    <strong>{amount(r.total)}</strong>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
    const register = (rows: RegisterRow[]) => {
        const selected = rows.filter(
            (r) =>
                (!registerKind || r.kind === registerKind) &&
                `${r.number} ${r.party_name} ${r.reference}`
                    .toLowerCase()
                    .includes(search.toLowerCase()),
        );
        return (
            <>
                <div className="report-local-filters no-print">
                    <label>
                        Find document
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Document, party or reference"
                        />
                    </label>
                    <label>
                        Document type
                        <select
                            value={registerKind}
                            onChange={(e) => setRegisterKind(e.target.value)}
                        >
                            <option value="">All document types</option>
                            {[...new Set(rows.map((r) => r.kind))].map((k) => (
                                <option key={k} value={k}>
                                    {k.replaceAll("_", " ")}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <div className="report-table-wrap">
                    <table className="report-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Document / type</th>
                                <th>Customer / supplier</th>
                                <th className="num">Net {currency}</th>
                                <th className="num">VAT</th>
                                <th className="num">Total {currency}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {selected.map((r) => (
                                <tr key={r.id}>
                                    <td>{dateText(r.date)}</td>
                                    <td>
                                        <a
                                            className="report-link"
                                            href={`/trade/print/document/${r.id}`}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            {r.number}
                                        </a>
                                        <small>
                                            {r.kind.replaceAll("_", " ")}
                                        </small>
                                    </td>
                                    <td>
                                        {r.party_name}
                                        <small>{r.reference}</small>
                                    </td>
                                    <td className="num">
                                        {amount(Number(r.net) * r.sign)}
                                    </td>
                                    <td className="num">
                                        {amount(Number(r.tax) * r.sign)}
                                    </td>
                                    <td className="num">
                                        <strong>
                                            {amount(Number(r.total) * r.sign)}
                                        </strong>
                                        {r.currency && (
                                            <small>
                                                {r.currency}{" "}
                                                {amount(
                                                    Number(r.original_total) *
                                                        r.sign,
                                                )}
                                            </small>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {!selected.length && (
                    <Empty>No documents match these filters.</Empty>
                )}
                <p className="report-caption">
                    {selected.length} documents · Base amounts in {currency}.
                    Receipts and payments are settlement activity and are
                    excluded from revenue and purchase totals.
                </p>
            </>
        );
    };
    return (
        <div className="report-centre">
            <aside
                className="report-navigation no-print"
                aria-label="Report catalogue"
            >
                <div className="report-nav-title">
                    <FileText size={17} /> Report centre
                </div>
                {sections
                    .filter((s) => s.tabs.length)
                    .map((s) => (
                        <div className="report-nav-group" key={s.name}>
                            <h3>{s.name}</h3>
                            {s.tabs.map((key) => (
                                <button
                                    key={key}
                                    className={view === key ? "active" : ""}
                                    aria-current={
                                        view === key ? "page" : undefined
                                    }
                                    onClick={() => switchTab(key)}
                                >
                                    {titles[key]}
                                    {view === key && <ArrowUpRight size={14} />}
                                </button>
                            ))}
                        </div>
                    ))}
            </aside>
            <div className="report-main">
                <form
                    method="get"
                    action="/reports"
                    className="report-filter-bar no-print"
                >
                    <input type="hidden" name="report" value={view} />
                    <label>
                        From
                        <input
                            type="date"
                            name="from"
                            defaultValue={d.filters.from}
                            required
                        />
                    </label>
                    <label>
                        Through / ageing as at
                        <input
                            type="date"
                            name="to"
                            defaultValue={d.filters.to}
                            required
                        />
                    </label>
                    <label>
                        Branch
                        <select
                            name="branch_id"
                            defaultValue={d.filters.branch_id ?? ""}
                        >
                            <option value="">All authorised branches</option>
                            {d.branches.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Book
                        <select name="book" defaultValue={d.filters.book}>
                            <option value="training">Training</option>
                            <option value="live">Live</option>
                        </select>
                    </label>
                    <button className="primary" type="submit">
                        Run report
                    </button>
                </form>
                <article className="report-paper">
                    <header className="report-heading">
                        <div>
                            <p className="report-company">
                                {d.company_name ?? "BRetail"}
                            </p>
                            <h2>{titles[view]}</h2>
                            <p>
                                {[
                                    "debtors",
                                    "creditors",
                                    "balance_sheet",
                                ].includes(view)
                                    ? `As at ${dateText(d.filters.to)}`
                                    : `${dateText(d.filters.from)} – ${dateText(d.filters.to)}`}
                            </p>
                            <div className="report-scope">
                                <span>{branch}</span>
                                <span>
                                    {d.filters.book === "live"
                                        ? "Live book"
                                        : "Training book"}
                                </span>
                                <span>Base currency · {currency}</span>
                            </div>
                        </div>
                        <div className="report-actions no-print">
                            <button
                                className="secondary"
                                onClick={() => window.print()}
                            >
                                <Printer size={15} /> Print
                            </button>
                            {canExport && (
                                <a
                                    className="secondary"
                                    href={`/reports/export?${query}`}
                                >
                                    <Download size={15} /> CSV
                                </a>
                            )}
                        </div>
                    </header>
                    {view === "overview" && (
                        <>
                            <div className="report-metrics">
                                <Metric
                                    label="Credit sales, net of returns"
                                    value={`${currency} ${amount(business?.sales?.total)}`}
                                    detail="Including VAT · excludes POS"
                                />
                                <Metric
                                    label="Supplier bills"
                                    value={`${currency} ${amount(business?.procurement?.billed)}`}
                                    detail="Including VAT · period activity"
                                />
                                <Metric
                                    label="POS transactions"
                                    value={d.sales_count.toLocaleString()}
                                    detail="Completed sales in this period"
                                />
                                <Metric
                                    label="Net profit / (loss)"
                                    value={
                                        d.accounting
                                            ? `${currency} ${amount(d.accounting.profit_loss.profit)}`
                                            : "—"
                                    }
                                    detail="Posted revenue less expenses"
                                />
                            </div>
                            <div className="report-overview-grid">
                                <section>
                                    <h3>POS sales by currency</h3>
                                    <p className="report-caption">
                                        Gross sales less refunds. Tax is net of
                                        returns.
                                    </p>
                                    <div className="report-table-wrap">
                                        <table className="report-table">
                                            <thead>
                                                <tr>
                                                    <th>Currency</th>
                                                    <th className="num">
                                                        Sales
                                                    </th>
                                                    <th className="num">
                                                        Refunds
                                                    </th>
                                                    <th className="num">
                                                        Net total
                                                    </th>
                                                    <th className="num">
                                                        Net VAT
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {d.currency_totals.map((r) => (
                                                    <tr key={r.currency}>
                                                        <td>{r.currency}</td>
                                                        {[
                                                            r.sales,
                                                            r.refunds,
                                                            r.net,
                                                            r.tax,
                                                        ].map((v, i) => (
                                                            <td
                                                                className="num"
                                                                key={i}
                                                            >
                                                                {amount(v)}
                                                            </td>
                                                        ))}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    {!d.currency_totals.length && (
                                        <Empty>
                                            No POS sales or refunds in this
                                            period.
                                        </Empty>
                                    )}
                                </section>
                                <section>
                                    <h3>Cash & working capital</h3>
                                    <dl className="report-key-values">
                                        <div>
                                            <dt>Closing cash & bank</dt>
                                            <dd>
                                                {amount(
                                                    d.accounting?.cash_flow
                                                        .closing,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Debtors outstanding</dt>
                                            <dd>
                                                {amount(
                                                    business?.aging?.totals
                                                        .receivable.total,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Creditors outstanding</dt>
                                            <dd>
                                                {amount(
                                                    business?.aging?.totals
                                                        .payable.total,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Current stock value</dt>
                                            <dd>{amount(d.inventory.value)}</dd>
                                        </div>
                                    </dl>
                                    <p className="report-caption">
                                        Stock is the current operational
                                        balance. Financial and ageing balances
                                        use the selected cutoff.
                                    </p>
                                </section>
                                <section>
                                    <h3>Top POS items</h3>
                                    <p className="report-caption">
                                        Gross quantity sold · returns excluded
                                        from this ranking
                                    </p>
                                    <div className="report-table-wrap">
                                        <table className="report-table">
                                            <thead>
                                                <tr>
                                                    <th>Item</th>
                                                    <th className="num">
                                                        Quantity
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {d.top_items.map((r) => (
                                                    <tr key={r.sku}>
                                                        <td>
                                                            {r.name}
                                                            <small>
                                                                {r.sku}
                                                            </small>
                                                        </td>
                                                        <td className="num">
                                                            {Number(
                                                                r.quantity,
                                                            ).toLocaleString()}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    {!d.top_items.length && (
                                        <Empty>
                                            No POS item sales in this period.
                                        </Empty>
                                    )}
                                </section>
                                <section>
                                    <h3>Stock requiring attention</h3>
                                    <p className="report-caption">
                                        {d.inventory.low_stock_count} items at
                                        or below reorder level · current
                                        balances
                                    </p>
                                    <div className="report-table-wrap">
                                        <table className="report-table">
                                            <thead>
                                                <tr>
                                                    <th>Item</th>
                                                    <th className="num">
                                                        On hand
                                                    </th>
                                                    <th className="num">
                                                        Reorder
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {d.inventory.low_stock.map(
                                                    (r) => (
                                                        <tr key={r.sku}>
                                                            <td>
                                                                {r.name}
                                                                <small>
                                                                    {r.sku}
                                                                </small>
                                                            </td>
                                                            <td className="num">
                                                                {Number(
                                                                    r.quantity,
                                                                ).toLocaleString()}
                                                            </td>
                                                            <td className="num">
                                                                {Number(
                                                                    r.reorder_level,
                                                                ).toLocaleString()}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                    {!d.inventory.low_stock.length && (
                                        <Empty>No reorder exceptions.</Empty>
                                    )}
                                </section>
                            </div>
                        </>
                    )}
                    {view === "pos_sales" && (
                        <>
                            <p className="report-caption">
                                POS activity by original transaction currency.
                                Refunds reduce the net total and net VAT in the
                                period they were posted. Credit invoices appear
                                separately in the Credit sales register.
                            </p>
                            <div className="report-table-wrap">
                                <table className="report-table">
                                    <thead>
                                        <tr>
                                            <th>Currency</th>
                                            <th className="num">Sales count</th>
                                            <th className="num">Gross sales</th>
                                            <th className="num">Refunds</th>
                                            <th className="num">Net total</th>
                                            <th className="num">Net VAT</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {d.currency_totals.map((r) => (
                                            <tr key={r.currency}>
                                                <td>{r.currency}</td>
                                                <td className="num">
                                                    {r.count}
                                                </td>
                                                {[
                                                    r.sales,
                                                    r.refunds,
                                                    r.net,
                                                    r.tax,
                                                ].map((v, i) => (
                                                    <td key={i} className="num">
                                                        {amount(v)}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {!d.currency_totals.length && (
                                <Empty>
                                    No POS sales or refunds in this period.
                                </Empty>
                            )}
                            <div className="report-subheading">
                                <h3>Daily POS activity</h3>
                            </div>
                            <div className="report-table-wrap">
                                <table className="report-table">
                                    <thead>
                                        <tr>
                                            <th>Business date</th>
                                            <th className="num">
                                                Completed transactions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {d.daily_sales.map((r) => (
                                            <tr key={r.date}>
                                                <td>{dateText(r.date)}</td>
                                                <td className="num">
                                                    {r.transactions}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th>Total</th>
                                            <td className="num">
                                                {d.sales_count}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </>
                    )}
                    {view === "sales" && business?.sales && (
                        <>
                            <div className="report-metrics">
                                <Metric
                                    label="Net revenue"
                                    value={amount(business.sales.net)}
                                />
                                <Metric
                                    label="VAT"
                                    value={amount(business.sales.tax)}
                                />
                                <Metric
                                    label="Sales incl. VAT"
                                    value={amount(business.sales.total)}
                                />
                                <Metric
                                    label="Customer receipts"
                                    value={amount(business.sales.receipts)}
                                />
                            </div>
                            <p className="report-caption">
                                Credit sales activity in {currency}. Invoice
                                edits are shown with their reversals, and credit
                                returns reduce sales. POS sales are shown
                                separately in Business overview.
                            </p>
                            {partySummary(
                                business.sales.by_party ?? [],
                                "Sales by customer",
                            )}
                            {register(business.sales.documents)}
                        </>
                    )}
                    {view === "procurement" && business?.procurement && (
                        <>
                            <div className="report-metrics">
                                <Metric
                                    label="Supplier bills incl. VAT"
                                    value={amount(business.procurement.billed)}
                                />
                                <Metric
                                    label="Input VAT"
                                    value={amount(business.procurement.tax)}
                                />
                                <Metric
                                    label="Goods received"
                                    value={amount(
                                        business.procurement.received,
                                    )}
                                />
                                <Metric
                                    label="Supplier payments"
                                    value={amount(business.procurement.paid)}
                                />
                            </div>
                            <h3>Procurement activity register</h3>
                            {partySummary(
                                business.procurement.by_party ?? [],
                                "Purchases by supplier",
                            )}
                            {register(business.procurement.documents)}
                            <div className="report-subheading">
                                <h3>
                                    Purchase orders ·{" "}
                                    {business.procurement.order_count}
                                </h3>
                            </div>
                            <p className="report-caption">
                                Orders dated in the selected period. Status
                                reflects the current workflow state; order
                                values are commitments, not expenses.
                            </p>
                            <div className="report-table-wrap">
                                <table className="report-table">
                                    <thead>
                                        <tr>
                                            <th>Order</th>
                                            <th>Supplier</th>
                                            <th>Date</th>
                                            <th>Expected</th>
                                            <th>Current status</th>
                                            <th className="num">
                                                Value {currency}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {business.procurement.orders.map(
                                            (o) => (
                                                <tr key={o.id}>
                                                    <td>
                                                        <a
                                                            className="report-link"
                                                            href={`/trade/print/order/${o.id}`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                        >
                                                            {o.number}
                                                        </a>
                                                    </td>
                                                    <td>{o.supplier_name}</td>
                                                    <td>{dateText(o.date)}</td>
                                                    <td>
                                                        {dateText(
                                                            o.expected_on,
                                                        )}
                                                    </td>
                                                    <td>
                                                        <span className="report-age">
                                                            {o.status.replaceAll(
                                                                "_",
                                                                " ",
                                                            )}
                                                        </span>
                                                    </td>
                                                    <td className="num">
                                                        {amount(o.total)}
                                                        {o.currency && (
                                                            <small>
                                                                {o.currency}{" "}
                                                                {amount(
                                                                    o.original_total,
                                                                )}
                                                            </small>
                                                        )}
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            {!business.procurement.orders.length && (
                                <Empty>
                                    No purchase orders in this period.
                                </Empty>
                            )}
                        </>
                    )}
                    {(view === "debtors" || view === "creditors") &&
                        business?.aging && (
                            <AgingReport
                                key={view}
                                data={business.aging}
                                role={
                                    view === "debtors"
                                        ? "receivable"
                                        : "payable"
                                }
                                currency={currency}
                            />
                        )}
                    {d.accounting &&
                        [
                            "profit_loss",
                            "balance_sheet",
                            "trial_balance",
                            "general_ledger",
                            "cash_flow",
                        ].includes(view) && (
                            <FinancialReport
                                key={view}
                                data={d.accounting}
                                view={view}
                                currency={currency}
                            />
                        )}
                    <footer className="report-footer">
                        <span>
                            {titles[view]} · {currency} · {d.filters.book} book
                        </span>
                        <span>
                            Reporting timezone: {d.timezone}
                            {d.generated_at
                                ? ` · Generated ${new Date(d.generated_at).toLocaleString("en-GB", { timeZone: d.timezone })}`
                                : ""}
                        </span>
                    </footer>
                </article>
            </div>
        </div>
    );
}
