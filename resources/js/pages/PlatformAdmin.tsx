import React, { useEffect, useState } from "react";
import { Link, router, usePage } from "@inertiajs/react";
import { Building2, LogOut, Search, ShieldCheck } from "lucide-react";

type Organization = {
    tenant_id: number;
    name: string;
    owner_name: string | null;
    owner_email: string | null;
    contact_email: string | null;
    branch_count: number;
    user_count: number;
    onboarded_at: string;
    plan_name: string;
    status: string;
    effective_status: string;
    starts_on: string | null;
    ends_on: string | null;
    trial_ends_on: string | null;
    grace_ends_on: string | null;
    amount: string | null;
    currency: string | null;
    billing_reference: string | null;
    notes: string | null;
};
type Props = {
    organizations: {
        data: Organization[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { search: string };
    activity: { id: number; action: string; reason: string | null; created_at: string; actor_name: string | null; company_name: string | null; tenant_name: string | null }[];
    user: { id: number; name: string; email: string };
    support_email: string | null;
};
type Form = {
    plan_name: string;
    status: string;
    starts_on: string;
    ends_on: string;
    trial_ends_on: string;
    grace_ends_on: string;
    amount: string;
    currency: string;
    billing_reference: string;
    notes: string;
    reason: string;
};
const blank = (organization?: Organization): Form => ({
    plan_name: organization?.plan_name ?? "Unassigned",
    status: organization?.status ?? "pending",
    starts_on: organization?.starts_on ?? "",
    ends_on: organization?.ends_on ?? "",
    trial_ends_on: organization?.trial_ends_on ?? "",
    grace_ends_on: organization?.grace_ends_on ?? "",
    amount: organization?.amount ?? "",
    currency: organization?.currency ?? "",
    billing_reference: organization?.billing_reference ?? "",
    notes: organization?.notes ?? "",
    reason: "",
});
const statuses = ["pending", "trialing", "active", "past_due", "suspended", "cancelled"];

export default function PlatformAdmin(p: Props) {
    const [selectedId, setSelectedId] = useState(p.organizations.data[0]?.tenant_id ?? 0);
    const selected = p.organizations.data.find((organization) => organization.tenant_id === selectedId);
    const [form, setForm] = useState<Form>(() => blank(selected));
    const [search, setSearch] = useState(p.filters.search);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState("");
    const flash = (usePage().props as any).flash?.success as string | undefined;

    useEffect(() => setForm(blank(selected)), [selectedId, selected]);
    useEffect(() => {
        if (!p.organizations.data.some((organization) => organization.tenant_id === selectedId)) {
            setSelectedId(p.organizations.data[0]?.tenant_id ?? 0);
        }
    }, [p.organizations.data, selectedId]);
    const submitSearch = (event: React.FormEvent) => {
        event.preventDefault();
        router.get("/platform/organizations", { search }, { preserveState: true, replace: true });
    };
    const save = (event: React.FormEvent) => {
        event.preventDefault();
        if (!selected) return;
        setBusy(true);
        setMessage("");
        router.patch(`/platform/organizations/${selected.tenant_id}/subscription`, form, {
            preserveScroll: true,
            onError: (errors) => setMessage(Object.values(errors).join(" ")),
            onFinish: () => setBusy(false),
        });
    };
    const field = (key: keyof Form, label: string, type = "text", required = false) => (
        <label className="field" key={key}>
            {label}
            <input required={required} type={type} value={form[key]} onChange={(event) => setForm({ ...form, [key]: event.target.value })} />
        </label>
    );

    return (
        <div className="platform-shell">
            <header className="platform-topbar">
                <Link href="/platform/organizations" className="platform-brand"><span className="brand-icon"><Building2 size={19} /></span> BRetail <span className="brand-dot">· Platform</span></Link>
                <div className="platform-user"><Link href="/dashboard">Business workspace</Link><span>{p.user.name}</span><button className="icon-button" aria-label="Sign out" onClick={() => router.post("/logout")}><LogOut size={17} /></button></div>
            </header>
            <main className="platform-content">
                <div className="page-heading"><div><div className="eyebrow">PLATFORM ADMINISTRATION</div><h1>Organizations & subscriptions</h1><p className="muted">Manage organization access and manually record subscription status.</p></div></div>
                {flash && <div className="notice success">{flash}</div>}
                {message && <div className="notice error" role="alert">{message}</div>}
                <div className="notice"><ShieldCheck size={17} /> Suspending access never deletes organization data. Status and date changes are recorded in the platform audit history.</div>

                <section className="panel">
                    <div className="section-heading"><div><h2>Organizations <span className="soft-badge">{p.organizations.total}</span></h2><p className="muted">Select an organization to review or update its access.</p></div>
                        <form className="platform-search" onSubmit={submitSearch}><Search size={16} /><input aria-label="Search organizations" placeholder="Name or owner email" value={search} onChange={(event) => setSearch(event.target.value)} /><button className="secondary">Search</button></form>
                    </div>
                    <div className="table-wrap"><table><thead><tr><th>Organization</th><th>Owner</th><th>Plan</th><th>Status</th><th>Users / branches</th><th /></tr></thead><tbody>
                        {p.organizations.data.map((organization) => <tr key={organization.tenant_id} className={selectedId === organization.tenant_id ? "selected-row" : ""}>
                            <td><strong>{organization.name}</strong><small>ID {organization.tenant_id} · Joined {new Date(organization.onboarded_at).toLocaleDateString()}</small></td>
                            <td>{organization.owner_name ?? "—"}<small>{organization.owner_email ?? organization.contact_email ?? "No email"}</small></td>
                            <td>{organization.plan_name}</td>
                            <td><span className={`subscription-status status-${organization.effective_status}`}>{organization.effective_status.replaceAll("_", " ")}</span></td>
                            <td>{organization.user_count} users · {organization.branch_count} branches</td>
                            <td><button className="secondary small" onClick={() => setSelectedId(organization.tenant_id)}>Manage</button></td>
                        </tr>)}
                        {!p.organizations.data.length && <tr><td colSpan={6} className="empty-state">No organizations match this search.</td></tr>}
                    </tbody></table></div>
                    {p.organizations.last_page > 1 && <div className="platform-pagination">{p.organizations.links.map((link, index) => link.url ? <Link key={index} className={link.active ? "active" : ""} href={link.url} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="disabled" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
                </section>

                {selected && <section className="panel platform-subscription-panel">
                    <div className="section-heading"><div><h2>Subscription for {selected.name}</h2><p className="muted">Access state: <strong>{selected.effective_status.replaceAll("_", " ")}</strong>. Access changes apply to web and API requests after save.</p></div></div>
                    <form onSubmit={save} className="form-grid">
                        <label className="field">Plan name<input required maxLength={100} value={form.plan_name} onChange={(event) => setForm({ ...form, plan_name: event.target.value })} /></label>
                        <label className="field">Subscription status<select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}>{statuses.map((status) => <option key={status} value={status}>{status.replaceAll("_", " ")}</option>)}</select></label>
                        {field("starts_on", "Starts on", "date")}
                        {field("ends_on", "Ends on (blank = no expiry)", "date")}
                        {field("trial_ends_on", "Trial ends on", "date")}
                        {field("grace_ends_on", "Payment grace ends on", "date")}
                        {field("amount", "Subscription amount", "number")}
                        {field("currency", "Billing currency (ISO code)")}
                        {field("billing_reference", "Manual billing reference")}
                        <label className="field platform-wide">Internal notes<textarea maxLength={4000} value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} /></label>
                        <label className="field platform-wide">Reason for this change<textarea required minLength={8} maxLength={1000} value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} placeholder="For example: payment received for the annual plan" /></label>
                        <p className="muted platform-wide">Pending, suspended, cancelled, and expired subscriptions cannot access the ERP. Past-due access remains open through the grace-end date.</p>
                        <button className="primary" disabled={busy}>{busy ? "Saving…" : "Save subscription"}</button>
                    </form>
                </section>}

                <section className="panel"><div className="section-heading"><div><h2>Platform activity</h2><p className="muted">Recent access and subscription administration actions.</p></div></div>
                    <div className="table-wrap"><table><thead><tr><th>When</th><th>Organization</th><th>Action</th><th>By</th><th>Reason</th></tr></thead><tbody>
                        {p.activity.map((entry) => <tr key={entry.id}><td>{new Date(entry.created_at).toLocaleString()}</td><td>{entry.company_name ?? entry.tenant_name ?? "—"}</td><td>{entry.action.replaceAll(".", " ").replaceAll("_", " ")}</td><td>{entry.actor_name ?? "Command line"}</td><td>{entry.reason ?? "—"}</td></tr>)}
                        {!p.activity.length && <tr><td colSpan={5}>No platform changes recorded yet.</td></tr>}
                    </tbody></table></div>
                </section>
                <footer className="platform-footer"><span>© 2026 Betterlink Industries Pvt Ltd</span><span>Signed in as {p.user.email}{p.support_email && <> · Support: <a href={`mailto:${p.support_email}`}>{p.support_email}</a></>}</span></footer>
            </main>
        </div>
    );
}
