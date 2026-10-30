import React from "react";
import { Download, TrendingUp, Boxes, ReceiptText, WalletCards } from "lucide-react";

type CurrencyTotal = { currency: string; sales: string; refunds: string; net: string; tax: string; count: number };
type Day = { date: string; transactions: number };
export type AnalyticsData = {
    filters: { from: string; to: string; branch_id: number | null; book: "training" | "live" };
    branches: { id: number; name: string }[];
    currency_totals: CurrencyTotal[];
    sales_count: number;
    daily_sales: Day[];
    top_items: { name: string; sku: string; quantity: string }[];
    inventory: { variant_count: number; quantity: string; value: string; low_stock_count: number; low_stock: { name: string; sku: string; quantity: string; reorder_level: string }[] };
    accounting: null | { profit_loss: { income: string; expense: string; profit: string }; cash_flow: { opening: string; closing: string; movement: string }; balance_sheet: { assets: string; liabilities: string; equity_including_earnings: string; difference: string } };
    base_currency: string | null;
    timezone: string;
};

function money(value: string | number, currency: string | null) {
    const amount = Number(value);
    return `${currency ?? ""} ${Number.isFinite(amount) ? amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : "0.00"}`.trim();
}

export default function Reports({ initial, permissions }: { initial: AnalyticsData; permissions: string[] }) {
    const canExport = permissions.includes("*") || permissions.includes("reports.export");
    const query = new URLSearchParams({ from: initial.filters.from, to: initial.filters.to, book: initial.filters.book });
    if (initial.filters.branch_id) query.set("branch_id", String(initial.filters.branch_id));
    const maxTransactions = Math.max(1, ...initial.daily_sales.map((day) => day.transactions));

    return <div className="reports-page">
        <section className="panel reports-filters">
            <form method="get" action="/reports" className="reports-filter-form">
                <label className="field">From<input type="date" name="from" value={initial.filters.from} required /></label>
                <label className="field">To<input type="date" name="to" value={initial.filters.to} required /></label>
                <label className="field">Branch<select name="branch_id" value={initial.filters.branch_id ?? ""}>
                    <option value="">All branches I can access</option>
                    {initial.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                </select></label>
                <label className="field">Book<select name="book" value={initial.filters.book}>
                    <option value="training">Training</option><option value="live">Live</option>
                </select></label>
                <div className="reports-filter-actions"><button className="primary" type="submit">Apply filters</button>{canExport && <a className="secondary" href={`/reports/export?${query.toString()}`}><Download size={16} /> Export CSV</a>}</div>
            </form>
            <small className="muted">Sales totals remain grouped by transaction currency. Reporting day uses {initial.timezone}.</small>
        </section>

        <div className="stat-grid reports-kpis">
            <section className="stat-card"><div><span>Transactions</span><ReceiptText size={18} /></div><strong>{initial.sales_count.toLocaleString()}</strong><small>Completed sales in this period</small></section>
            <section className="stat-card"><div><span>Stock value</span><Boxes size={18} /></div><strong>{money(initial.inventory.value, initial.base_currency)}</strong><small>{initial.inventory.variant_count.toLocaleString()} stocked variants</small></section>
            <section className="stat-card"><div><span>Items at reorder level</span><TrendingUp size={18} /></div><strong>{initial.inventory.low_stock_count.toLocaleString()}</strong><small>Across your selected branch scope</small></section>
            <section className="stat-card"><div><span>Cash movement</span><WalletCards size={18} /></div><strong>{initial.accounting ? money(initial.accounting.cash_flow.movement, initial.base_currency) : "—"}</strong><small>Posted accounting activity</small></section>
        </div>

        <div className="reports-grid">
            <section className="panel">
                <div className="section-heading"><div><h2>Sales by currency</h2><p className="muted">Sales, refunds and net recorded value</p></div></div>
                {initial.currency_totals.length ? <div className="table-wrap"><table><thead><tr><th>Currency</th><th>Sales</th><th>Refunds</th><th>Net</th><th>Tax</th></tr></thead><tbody>
                    {initial.currency_totals.map((row) => <tr key={row.currency}><td><strong>{row.currency}</strong><small>{row.count} sales</small></td><td>{money(row.sales, row.currency)}</td><td>{money(row.refunds, row.currency)}</td><td><strong>{money(row.net, row.currency)}</strong></td><td>{money(row.tax, row.currency)}</td></tr>)}
                </tbody></table></div> : <p className="muted">No sales are recorded for this period and book.</p>}
            </section>
            <section className="panel">
                <div className="section-heading"><div><h2>Daily transaction volume</h2><p className="muted">Completed sales per local business day</p></div></div>
                <div className="reports-bars" role="img" aria-label="Daily transaction volume bar chart">
                    {initial.daily_sales.map((day) => <div className="reports-bar-column" key={day.date} title={`${day.date}: ${day.transactions} transactions`}><span>{day.transactions || ""}</span><div style={{ height: `${Math.max(3, day.transactions / maxTransactions * 100)}%` }} /><small>{day.date.slice(5)}</small></div>)}
                </div>
            </section>
            {initial.accounting && <section className="panel">
                <div className="section-heading"><div><h2>Financial summary</h2><p className="muted">Posted {initial.filters.book} book · {initial.base_currency}</p></div></div>
                <dl className="reports-summary"><div><dt>Income</dt><dd>{money(initial.accounting.profit_loss.income, initial.base_currency)}</dd></div><div><dt>Expenses</dt><dd>{money(initial.accounting.profit_loss.expense, initial.base_currency)}</dd></div><div><dt>Profit / loss</dt><dd>{money(initial.accounting.profit_loss.profit, initial.base_currency)}</dd></div><div><dt>Assets</dt><dd>{money(initial.accounting.balance_sheet.assets, initial.base_currency)}</dd></div><div><dt>Cash movement</dt><dd>{money(initial.accounting.cash_flow.movement, initial.base_currency)}</dd></div></dl>
            </section>}
            <section className="panel">
                <div className="section-heading"><div><h2>Top selling items</h2><p className="muted">Ranked by quantity sold in the selected period</p></div></div>
                {initial.top_items.length ? <div className="table-wrap"><table><thead><tr><th>Item</th><th>SKU</th><th>Quantity</th></tr></thead><tbody>{initial.top_items.map((item) => <tr key={item.sku}><td>{item.name}</td><td>{item.sku}</td><td><strong>{item.quantity}</strong></td></tr>)}</tbody></table></div> : <p className="muted">No item sales in this period.</p>}
            </section>
            <section className="panel">
                <div className="section-heading"><div><h2>Stock to review</h2><p className="muted">Active variants at or below their reorder level</p></div></div>
                {initial.inventory.low_stock.length ? <div className="table-wrap"><table><thead><tr><th>Item</th><th>SKU</th><th>On hand</th><th>Reorder at</th></tr></thead><tbody>{initial.inventory.low_stock.map((item) => <tr key={item.sku}><td>{item.name}</td><td>{item.sku}</td><td>{item.quantity}</td><td>{item.reorder_level}</td></tr>)}</tbody></table></div> : <p className="muted">No items are at their reorder level.</p>}
            </section>
        </div>
    </div>;
}
