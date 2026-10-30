import React, { useState } from "react";
import { router } from "@inertiajs/react";
import { Plus, Save, UserRoundX } from "lucide-react";

type Program = {
    active: boolean;
    earn_points_per_currency: string;
    value_per_point: string;
    max_redemption_percent: string;
    minimum_redemption_points: string;
    expiry_days: number;
    consent_version: string;
};
type Customer = {
    id: number;
    name: string;
    loyalty_number: string | null;
    consented_at: string | null;
    opted_out_at: string | null;
    points: string;
};
type Promotion = {
    id: number;
    name: string;
    multiplier: string;
    minimum_spend: string;
    starts_on: string;
    ends_on: string;
    active: boolean;
};
export type LoyaltyData = {
    program: Program;
    base_currency: string;
    customers: Customer[];
    entries: {
        id: number;
        customer: string | null;
        loyalty_number: string | null;
        kind: string;
        points: string;
        currency: string;
        amount_base: string;
        reference: string;
        expires_on: string | null;
        occurred_at: string;
    }[];
    promotions: Promotion[];
    branches: { id: number; name: string }[];
    enrolled_count: number;
    permissions: string[];
};

const localDate = () => {
    const date = new Date();
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
};

export default function Loyalty({ initial }: { initial: LoyaltyData }) {
    const [program, setProgram] = useState(initial.program);
    const [name, setName] = useState("");
    const [multiplier, setMultiplier] = useState("2");
    const [minimumSpend, setMinimumSpend] = useState("0");
    const [startsOn, setStartsOn] = useState(localDate);
    const [endsOn, setEndsOn] = useState(localDate);
    const canManage = initial.permissions.includes("*") || initial.permissions.includes("loyalty.manage");
    const saveProgram = (event: React.FormEvent) => {
        event.preventDefault();
        router.patch("/loyalty/program", program, { preserveScroll: true });
    };
    const savePromotion = (event: React.FormEvent) => {
        event.preventDefault();
        router.post("/loyalty/promotions", { name, multiplier, minimum_spend: minimumSpend, starts_on: startsOn, ends_on: endsOn, active: true }, {
            preserveScroll: true,
            onSuccess: () => setName(""),
        });
    };

    return (
        <div className="module-grid">
            <section className="stat-grid">
                <article className="stat-card"><span>Active members</span><strong>{initial.enrolled_count}</strong><small>Customer consent recorded</small></article>
                <article className="stat-card"><span>Reward rate</span><strong>{program.earn_points_per_currency} pts</strong><small>Per {initial.base_currency} 1 eligible spend</small></article>
                <article className="stat-card"><span>Point value</span><strong>{initial.base_currency} {program.value_per_point}</strong><small>Redeemable per point</small></article>
            </section>

            <section className="panel">
                <div className="section-heading"><div><h2>Program rules</h2><p className="muted">Changes affect new sales. Existing ledger entries remain unchanged.</p></div></div>
                <form className="form-grid" onSubmit={saveProgram}>
                    <label className="field"><span><input type="checkbox" checked={program.active} disabled={!canManage} onChange={(e) => setProgram({ ...program, active: e.target.checked })} /> Program active</span></label>
                    <label className="field">Points per {initial.base_currency} 1<input type="number" min="0.001" step="0.001" value={program.earn_points_per_currency} disabled={!canManage} onChange={(e) => setProgram({ ...program, earn_points_per_currency: e.target.value })} /></label>
                    <label className="field">Value per point ({initial.base_currency})<input type="number" min="0.01" step="0.01" value={program.value_per_point} disabled={!canManage} onChange={(e) => setProgram({ ...program, value_per_point: e.target.value })} /></label>
                    <label className="field">Maximum sale redemption (%)<input type="number" min="0.01" max="100" step="0.01" value={program.max_redemption_percent} disabled={!canManage} onChange={(e) => setProgram({ ...program, max_redemption_percent: e.target.value })} /></label>
                    <label className="field">Minimum redemption points<input type="number" min="1" step="1" value={program.minimum_redemption_points} disabled={!canManage} onChange={(e) => setProgram({ ...program, minimum_redemption_points: e.target.value })} /></label>
                    <label className="field">Points expire after (days; 0 = never)<input type="number" min="0" max="3650" step="1" value={program.expiry_days} disabled={!canManage} onChange={(e) => setProgram({ ...program, expiry_days: Number(e.target.value) })} /></label>
                    <p className="muted">Consent wording version: {program.consent_version}. Points are calculated on base currency spend after discounts and exclude loyalty tender. Promotions use the highest eligible multiplier.</p>
                    {canManage && <button className="primary"><Save size={16} /> Save rules</button>}
                </form>
            </section>

            <section className="panel">
                <div className="section-heading"><div><h2>Promotions</h2><p className="muted">A promotion multiplies earned points when minimum spend and dates match. Promotions do not stack.</p></div></div>
                {canManage && <form className="form-grid" onSubmit={savePromotion}>
                    <label className="field">Promotion name<input required maxLength={120} value={name} onChange={(e) => setName(e.target.value)} /></label>
                    <label className="field">Points multiplier<input required type="number" min="1.01" max="10" step="0.01" value={multiplier} onChange={(e) => setMultiplier(e.target.value)} /></label>
                    <label className="field">Minimum eligible spend ({initial.base_currency})<input required type="number" min="0" step="0.01" value={minimumSpend} onChange={(e) => setMinimumSpend(e.target.value)} /></label>
                    <label className="field">Starts<input required type="date" value={startsOn} onChange={(e) => setStartsOn(e.target.value)} /></label>
                    <label className="field">Ends<input required type="date" value={endsOn} onChange={(e) => setEndsOn(e.target.value)} /></label>
                    <button className="primary"><Plus size={16} /> Add promotion</button>
                </form>}
                <div className="table-wrap"><table><thead><tr><th>Promotion</th><th>Multiplier</th><th>Minimum spend</th><th>Dates</th><th>Status</th>{canManage && <th />}</tr></thead><tbody>
                    {initial.promotions.map((promo) => <tr key={promo.id}><td>{promo.name}</td><td>{promo.multiplier}×</td><td>{initial.base_currency} {promo.minimum_spend}</td><td>{promo.starts_on} – {promo.ends_on}</td><td>{promo.active ? "Active" : "Paused"}</td>{canManage && <td><button className="secondary" onClick={() => router.patch(`/loyalty/promotions/${promo.id}`, { ...promo, active: !promo.active }, { preserveScroll: true })}>{promo.active ? "Pause" : "Resume"}</button></td>}</tr>)}
                    {!initial.promotions.length && <tr><td colSpan={canManage ? 6 : 5}>No promotions configured.</td></tr>}
                </tbody></table></div>
            </section>

            <section className="panel">
                <div className="section-heading"><div><h2>Enrolled customers</h2><p className="muted">Points are visible only to staff with loyalty access. Withdrawal blocks future earning and redemption; ledger history is retained.</p></div></div>
                <div className="table-wrap"><table><thead><tr><th>Customer</th><th>Rewards number</th><th>Available points</th><th>Consent</th>{canManage && <th />}</tr></thead><tbody>
                    {initial.customers.map((customer) => <tr key={customer.id}><td>{customer.name}</td><td>{customer.loyalty_number}</td><td>{customer.points}</td><td>{customer.opted_out_at ? "Withdrawn" : customer.consented_at ? "Consented" : "—"}</td>{canManage && <td>{customer.consented_at && !customer.opted_out_at ? <button className="secondary" onClick={() => router.post(`/loyalty/customers/${customer.id}/withdraw`, {}, { preserveScroll: true })}><UserRoundX size={15} /> Withdraw consent</button> : <button className="secondary" onClick={() => router.post(`/loyalty/customers/${customer.id}/enroll`, {}, { preserveScroll: true })}>Confirm consent & enroll</button>}</td>}</tr>)}
                    {!initial.customers.length && <tr><td colSpan={canManage ? 5 : 4}>No rewards customers yet. Ask customers for consent when adding them at POS.</td></tr>}
                </tbody></table></div>
            </section>

            <section className="panel">
                <div className="section-heading"><div><h2>Recent points ledger</h2><p className="muted">Append-only activity across earning, redemption, expiry, and returns.</p></div></div>
                <div className="table-wrap"><table><thead><tr><th>When</th><th>Customer</th><th>Activity</th><th>Points</th><th>Sale value</th><th>Reference</th><th>Expiry</th></tr></thead><tbody>
                    {initial.entries.map((entry) => <tr key={entry.id}><td>{new Date(entry.occurred_at).toLocaleString()}</td><td>{entry.customer ?? entry.loyalty_number ?? "Customer"}</td><td>{entry.kind.replaceAll("_", " ")}</td><td>{entry.points}</td><td>{entry.currency} {entry.amount_base}</td><td>{entry.reference}</td><td>{entry.expires_on ?? "Never"}</td></tr>)}
                    {!initial.entries.length && <tr><td colSpan={7}>No points activity yet.</td></tr>}
                </tbody></table></div>
            </section>
        </div>
    );
}
