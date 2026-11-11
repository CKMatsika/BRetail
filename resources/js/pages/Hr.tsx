import React, { useEffect, useState } from "react";
import { Link, router, useForm } from "@inertiajs/react";
import { CalendarDays, ChevronLeft, ChevronRight, History, Plus, Search, X } from "lucide-react";
import { useDialog } from "../useDialog";

type Employee = {
    id: number; employee_number: string; legal_name: string; preferred_name: string | null; date_of_birth?: string | null;
    national_id_number?: string | null; nssa_number?: string | null; zimra_tin?: string | null; nationality?: string | null; zimbabwean_citizen?: boolean | null; ordinarily_resident_zimbabwe?: boolean | null;
    employment_category?: string | null; paye_non_fds_reason?: string | null; marital_status?: string | null; blind?: boolean | null; physically_disabled?: boolean | null; disabled_dependents_count?: number | null;
    email: string | null; phone: string | null; job_title: string; employee_group?: "nec" | "general" | "management" | null; job_grade?: string | null; annual_leave_entitlement_days?: number | string; status: "active" | "on_leave" | "terminated";
    branch_id: number; branch: string; manager_id: number | null; manager: string | null; start_date: string; end_date: string | null;
};
export type HrData = {
    employees: Employee[]; branches: { id: number; name: string }[];
    leave_types: { id: number; name: string; annual_entitlement: number | string; paid: boolean }[];
    leave_balances: { id: string; employee: string; employee_number: string; name: string; year: number; entitlement: number; accrued: number; used: number; remaining: number; paid: boolean }[];
    leave_requests: { id: number; start_date: string; end_date: string; days: number | string; reason: string; status: string; decision_note: string | null; employee: string; employee_number: string; leave_type: string; requested_by: number | null }[];
    can: { create: boolean; edit: boolean; personal_manage: boolean; payroll_view: boolean; payroll_enabled: boolean; modules_configure: boolean; modules_view: boolean; nssa_export: boolean; history: boolean; leave_manage: boolean; leave_request: boolean; leave_approve: boolean };
    viewer_id: number;
};
type Event = { id: number; kind: string; effective_date: string; created_at: string; snapshot: Record<string, unknown> };
const today = () => new Date().toISOString().slice(0, 10);
const currentMonth = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`; };

function EmployeeForm({ initial, branches, employees, personalManage, onClose }: { initial?: Employee; branches: HrData["branches"]; employees: Employee[]; personalManage: boolean; onClose: () => void }) {
    useDialog(onClose);
    const form = useForm({
        employee_number: initial?.employee_number ?? "", branch_id: initial?.branch_id ?? branches[0]?.id ?? 0, manager_id: initial?.manager_id ?? "",
        legal_name: initial?.legal_name ?? "", preferred_name: initial?.preferred_name ?? "", email: initial?.email ?? "",
        phone: initial?.phone ?? "", job_title: initial?.job_title ?? "", status: initial?.status ?? "active" as Employee["status"],
        employee_group: initial?.employee_group ?? "general", job_grade: initial?.job_grade ?? "", annual_leave_entitlement_days: String(initial?.annual_leave_entitlement_days ?? 22),
        start_date: initial?.start_date ?? today(), end_date: initial?.end_date ?? "", date_of_birth: initial?.date_of_birth ?? "", effective_date: today(),
        national_id_number: initial?.national_id_number ?? "", nssa_number: initial?.nssa_number ?? "", zimra_tin: initial?.zimra_tin ?? "", nationality: initial?.nationality ?? "",
        zimbabwean_citizen: initial?.zimbabwean_citizen ?? null, ordinarily_resident_zimbabwe: initial?.ordinarily_resident_zimbabwe ?? null, employment_category: initial?.employment_category ?? "", paye_non_fds_reason: initial?.paye_non_fds_reason ?? "", marital_status: initial?.marital_status ?? "",
        blind: initial?.blind ?? null, physically_disabled: initial?.physically_disabled ?? null, disabled_dependents_count: initial?.disabled_dependents_count ?? null,
    });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (initial) form.patch(`/hr/employees/${initial.id}`, { onSuccess: onClose });
        else form.post("/hr/employees", { onSuccess: onClose });
    };
    const textField = (key: "employee_number" | "legal_name" | "preferred_name" | "email" | "phone" | "job_title" | "start_date" | "end_date" | "effective_date", type = "text") => (
        <label className="field" key={key}>{key.replaceAll("_", " ")}
            <input type={type} required={["employee_number", "legal_name", "job_title", "start_date", "effective_date"].includes(key)}
                value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
            {form.errors[key] && <small className="error-text">{form.errors[key]}</small>}
        </label>
    );
    return <div className="modal-backdrop"><section className="modal" role="dialog" aria-modal="true" aria-label="Employee details">
        <div className="section-heading"><h2>{initial ? "Update employee" : "Add employee"}</h2><button type="button" className="icon-button" onClick={onClose} aria-label="Close"><X /></button></div>
        <form onSubmit={submit}>
            <h3>Employment and payroll classification</h3>
            <div className="form-grid">
                {!initial && textField("employee_number")}
                {textField("legal_name")}{textField("preferred_name")}{textField("email", "email")}{textField("phone", "tel")}{textField("job_title")}
                <label className="field">Home branch<select required value={form.data.branch_id} onChange={e => form.setData("branch_id", Number(e.target.value))}>{branches.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
                <label className="field">Reports to<select value={form.data.manager_id} onChange={e => form.setData("manager_id", e.target.value ? Number(e.target.value) : "")}><option value="">No manager</option>{employees.filter(e => e.status === "active" && e.branch_id === Number(form.data.branch_id) && e.id !== initial?.id).map(manager => <option key={manager.id} value={manager.id}>{manager.employee_number} · {manager.preferred_name || manager.legal_name}</option>)}</select>{form.errors.manager_id && <small className="error-text">{form.errors.manager_id}</small>}</label>
                {textField("start_date", "date")}
                <label className="field">Employee group<select value={form.data.employee_group} onChange={e => { const group = e.target.value as typeof form.data.employee_group; form.setData({ ...form.data, employee_group: group, job_grade: group === "management" ? "" : form.data.job_grade }); }}><option value="nec">NEC employee</option><option value="general">General employee</option><option value="management">Management (ungraded)</option></select></label>
                {form.data.employee_group !== "management" && <label className="field">Employee grade<input maxLength={80} value={form.data.job_grade} onChange={e => form.setData("job_grade", e.target.value)} placeholder={form.data.employee_group === "nec" ? "e.g. Grade C2" : "Enter general grade, if applicable"} /></label>}
                <label className="field">Annual leave entitlement (working days/year)<input type="number" min="0" max="366" step="0.01" value={form.data.annual_leave_entitlement_days} onChange={e => form.setData("annual_leave_entitlement_days", e.target.value)} /><small>Default is 22 days; adjust to the applicable NEC agreement or employment terms.</small></label>
                {personalManage && <label className="field">Date of birth (confidential)<input type="date" max={today()} value={form.data.date_of_birth} onChange={e => form.setData("date_of_birth", e.target.value)} />{form.errors.date_of_birth && <small className="error-text">{form.errors.date_of_birth}</small>}</label>}
                {personalManage && <>
                    <label className="field">National ID number (confidential)<input autoComplete="off" value={form.data.national_id_number} onChange={e => form.setData("national_id_number", e.target.value)} /></label>
                    <label className="field">NSSA number (confidential)<input autoComplete="off" value={form.data.nssa_number} onChange={e => form.setData("nssa_number", e.target.value)} /></label>
                    <label className="field">ZIMRA employee TIN (confidential)<input autoComplete="off" value={form.data.zimra_tin} onChange={e => form.setData("zimra_tin", e.target.value)} /></label>
                    <label className="field">Nationality<input value={form.data.nationality} onChange={e => form.setData("nationality", e.target.value)} /></label>
                    <label className="field">Zimbabwean citizen?<select value={form.data.zimbabwean_citizen == null ? "" : String(form.data.zimbabwean_citizen)} onChange={e => form.setData("zimbabwean_citizen", e.target.value === "" ? null : e.target.value === "true")}><option value="">Not recorded</option><option value="true">Yes</option><option value="false">No</option></select></label>
                    <label className="field">Ordinarily resident in Zimbabwe?<select value={form.data.ordinarily_resident_zimbabwe == null ? "" : String(form.data.ordinarily_resident_zimbabwe)} onChange={e => form.setData("ordinarily_resident_zimbabwe", e.target.value === "" ? null : e.target.value === "true")}><option value="">Not recorded</option><option value="true">Yes</option><option value="false">No</option></select></label>
                    <label className="field">Employment category<select value={form.data.employment_category} onChange={e => form.setData("employment_category", e.target.value)}><option value="">Not recorded</option><option value="standard">Standard employee</option><option value="domestic_worker">Domestic worker</option><option value="diplomatic_staff">Diplomatic staff</option><option value="casual_worker">Casual worker</option><option value="civil_servant">Civil servant</option></select></label>
                    <label className="field">Additional Non-FDS case<select value={form.data.paye_non_fds_reason} onChange={e => form.setData("paye_non_fds_reason", e.target.value)}><option value="">None recorded</option><option value="concurrent_employment">More than one employer in the tax year</option><option value="part_time_with_full_time_job">Part-time and fully employed elsewhere</option><option value="pension_income">Receives pension income</option><option value="other_non_fds_case">Other Non-FDS case reviewed with ZIMRA</option></select></label>
                    <label className="field">Marital status for TaRMS<select value={form.data.marital_status} onChange={e => form.setData("marital_status", e.target.value)}><option value="">Not recorded</option><option value="single">Single</option><option value="married">Married</option><option value="widowed">Widowed</option><option value="divorced">Divorced</option></select></label>
                    <label className="field">Blind?<select value={form.data.blind == null ? "" : String(form.data.blind)} onChange={e => form.setData("blind", e.target.value === "" ? null : e.target.value === "true")}><option value="">Not recorded</option><option value="true">Yes</option><option value="false">No</option></select></label>
                    <label className="field">Physical disability?<select value={form.data.physically_disabled == null ? "" : String(form.data.physically_disabled)} onChange={e => form.setData("physically_disabled", e.target.value === "" ? null : e.target.value === "true")}><option value="">Not recorded</option><option value="true">Yes</option><option value="false">No</option></select></label>
                    <label className="field">Disabled dependents count<input type="number" min="0" max="30" step="1" value={form.data.disabled_dependents_count ?? ""} onChange={e => form.setData("disabled_dependents_count", e.target.value === "" ? null : Number(e.target.value))} /></label>
                </>}
                {initial && <label className="field">Employment status<select value={form.data.status} onChange={e => form.setData("status", e.target.value as Employee["status"])}><option value="active">Active</option><option value="on_leave">On leave</option><option value="terminated">Terminated</option></select></label>}
                {initial && form.data.status === "terminated" && textField("end_date", "date")}
                <label className="field">Effective from<input type="date" required value={form.data.effective_date} onChange={e => form.setData("effective_date", e.target.value)} /></label>
            </div>
            <p className="muted">Changes are saved with an effective date and kept in the employment history. Managers must be active in the same branch, and reporting cycles are blocked.</p>
            <div className="section-actions"><button type="button" className="secondary" onClick={onClose}>Cancel</button><button className="primary" disabled={form.processing}>Save employee</button></div>
        </form>
    </section></div>;
}

export default function Hr({ initial }: { initial: HrData }) {
    const [editing, setEditing] = useState<Employee | true | null>(null);
    const [query, setQuery] = useState("");
    const [historyId, setHistoryId] = useState<number | null>(null);
    const [events, setEvents] = useState<Event[]>([]);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [leaveActionError, setLeaveActionError] = useState("");
    const [calendarMonth, setCalendarMonth] = useState(currentMonth());
    const [contributionMonth, setContributionMonth] = useState(currentMonth());
    const [calendarLeaves, setCalendarLeaves] = useState<{ id: number; start_date: string; end_date: string; employee: string; employee_number: string; leave_type: string; status: string }[]>([]);
    const typeForm = useForm({ name: initial.leave_types.some(type => type.name.trim().toLowerCase() === "annual leave") ? "" : "Annual Leave", annual_entitlement: "22", paid: true });
    const leaveForm = useForm({ employee_id: initial.employees[0]?.id ?? 0, leave_type_id: initial.leave_types[0]?.id ?? 0, start_date: today(), end_date: today(), days: "1", reason: "" });
    const filtered = initial.employees.filter(e => [e.employee_number, e.legal_name, e.preferred_name, e.job_title, e.branch].some(value => value?.toLowerCase().includes(query.toLowerCase())));
    useEffect(() => { let active = true; fetch(`/hr/leave-calendar?month=${calendarMonth}`, { headers: { Accept: "application/json" } }).then(r => { if (!r.ok) throw new Error("Could not load leave calendar"); return r.json(); }).then(body => { if (active) setCalendarLeaves(body.data); }).catch(() => { if (active) setCalendarLeaves([]); }); return () => { active = false; }; }, [calendarMonth]);
    const [calendarYear, calendarMonthNumber] = calendarMonth.split("-").map(Number);
    const firstWeekday = (new Date(calendarYear, calendarMonthNumber - 1, 1).getDay() + 6) % 7;
    const calendarDays = new Date(calendarYear, calendarMonthNumber, 0).getDate();
    const calendarEntries = new Map<number, typeof calendarLeaves>();
    calendarLeaves.forEach(leave => { const start = Math.max(1, Number(leave.start_date.slice(8, 10))); const end = Math.min(calendarDays, Number(leave.end_date.slice(8, 10))); for (let day = start; day <= end; day++) calendarEntries.set(day, [...(calendarEntries.get(day) ?? []), leave]); });
    const shiftCalendar = (offset: number) => { const d = new Date(calendarYear, calendarMonthNumber - 1 + offset, 1); setCalendarMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`); };
    const showHistory = async (employee: Employee) => {
        setHistoryId(employee.id); setHistoryLoading(true); setEvents([]);
        try { const response = await fetch(`/hr/employees/${employee.id}/history`, { headers: { Accept: "application/json" } }); if (!response.ok) throw new Error("Could not load history"); const body = await response.json(); setEvents(body.data); }
        finally { setHistoryLoading(false); }
    };
    return <>
        <section className="content-section">
            <div className="section-heading"><div><span className="eyebrow">TIME AWAY</span><h2>Leave</h2><p>Configure company leave entitlements, review balances and track requests.</p></div></div>
            {!initial.leave_types.some(type => type.name.trim().toLowerCase() === "annual leave") && initial.can.leave_manage && <div className="notice">Add a paid leave type named “Annual Leave” to track the monthly payroll accrual and employee balance here.</div>}
            <div className="leave-calendar">
            <div className="section-heading"><div><span className="eyebrow"><CalendarDays size={15} /> SCHEDULE</span><h3>{new Date(calendarYear, calendarMonthNumber - 1, 1).toLocaleDateString(undefined, { month: "long", year: "numeric" })}</h3></div><div className="table-actions"><button className="icon-button" aria-label="Previous month" onClick={() => shiftCalendar(-1)}><ChevronLeft /></button><button className="icon-button" aria-label="Next month" onClick={() => shiftCalendar(1)}><ChevronRight /></button></div></div>
                <div className="leave-calendar-grid">{["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map(day => <strong className="calendar-weekday" key={day}>{day}</strong>)}{Array.from({ length: firstWeekday }, (_, i) => <span className="calendar-blank" key={`blank-${i}`} />)}{Array.from({ length: calendarDays }, (_, i) => { const day = i + 1; const entries = calendarEntries.get(day) ?? []; return <div className="calendar-day" key={day}><strong>{day}</strong>{entries.slice(0, 3).map(entry => <small className={`calendar-leave ${entry.status}`} key={`${entry.id}-${day}`} title={`${entry.employee} · ${entry.leave_type}`}>{entry.employee_number} · {entry.leave_type}</small>)}{entries.length > 3 && <small>+{entries.length - 3} more</small>}</div>; })}</div>
                <small className="muted">Pending and approved leave shown for employees in your branch access.</small>
            </div>
            {initial.leave_balances.length > 0 && <div className="leave-balances">{initial.leave_balances.map(balance => <article className="leave-balance" key={balance.id}><span>{balance.employee} · {balance.employee_number}</span><strong>{balance.name} {balance.year}: {balance.remaining.toFixed(2)} days available</strong><small>{balance.name.toLowerCase() === "annual leave" ? `${balance.accrued.toFixed(2)} accrued · ${balance.used.toFixed(2)} approved leave used · ${balance.entitlement.toFixed(2)} days annual rate` : `${balance.used.toFixed(2)} used of ${balance.entitlement.toFixed(2)}`} · {balance.paid ? "paid" : "unpaid"}</small></article>)}</div>}
            {initial.can.leave_manage && <form className="inline-form" onSubmit={e => { e.preventDefault(); typeForm.post("/hr/leave-types", { onSuccess: () => typeForm.reset() }); }}>
                <strong>Configure a leave type</strong><label className="field">Name<input required maxLength={80} value={typeForm.data.name} onChange={e => typeForm.setData("name", e.target.value)} /></label>
                <label className="field">Annual entitlement (days)<input required type="number" min="0" max="366" step="0.5" value={typeForm.data.annual_entitlement} onChange={e => typeForm.setData("annual_entitlement", e.target.value)} /></label>
                <label className="checkbox"><input type="checkbox" checked={typeForm.data.paid} onChange={e => typeForm.setData("paid", e.target.checked)} /> Paid leave</label>
                {Object.values(typeForm.errors).map(error => <small className="error-text" key={error}>{error}</small>)}
                <button className="secondary" disabled={typeForm.processing}>Add leave type</button>
            </form>}
            {initial.can.leave_request && initial.leave_types.length > 0 && initial.employees.some(employee => employee.status === "active") && <form className="inline-form" onSubmit={e => { e.preventDefault(); leaveForm.transform(data => ({ ...data, request_key: crypto.randomUUID() })); leaveForm.post("/hr/leave-requests", { onSuccess: () => leaveForm.reset("reason") }); }}>
                <strong>Record a leave request</strong>
                <label className="field">Employee<select value={leaveForm.data.employee_id} onChange={e => leaveForm.setData("employee_id", Number(e.target.value))}>{initial.employees.filter(employee => employee.status === "active").map(employee => <option key={employee.id} value={employee.id}>{employee.employee_number} · {employee.legal_name}</option>)}</select></label>
                <label className="field">Leave type<select value={leaveForm.data.leave_type_id} onChange={e => leaveForm.setData("leave_type_id", Number(e.target.value))}>{initial.leave_types.map(type => <option key={type.id} value={type.id}>{type.name}</option>)}</select></label>
                <label className="field">From<input type="date" required value={leaveForm.data.start_date} onChange={e => leaveForm.setData("start_date", e.target.value)} /></label>
                <label className="field">To<input type="date" required value={leaveForm.data.end_date} onChange={e => leaveForm.setData("end_date", e.target.value)} /></label>
                <label className="field">Days requested<input type="number" min="0.01" max="366" step="0.5" required value={leaveForm.data.days} onChange={e => leaveForm.setData("days", e.target.value)} /></label>
                <label className="field">Reason<input required maxLength={2000} value={leaveForm.data.reason} onChange={e => leaveForm.setData("reason", e.target.value)} /></label>
                {Object.values(leaveForm.errors).map(error => <small className="error-text" key={error}>{error}</small>)}
                <button className="primary" disabled={leaveForm.processing}>Submit request</button>
            </form>}
            {leaveActionError && <div className="notice error" role="alert">{leaveActionError}</div>}
            <div className="table-wrap"><table><thead><tr><th>Employee</th><th>Leave</th><th>Dates</th><th>Days</th><th>Status</th><th>Decision</th></tr></thead><tbody>
                {initial.leave_requests.map(request => <tr key={request.id}><td><strong>{request.employee}</strong><small>{request.employee_number}</small></td><td>{request.leave_type}</td><td>{request.start_date} – {request.end_date}</td><td>{Number(request.days).toFixed(2)}</td><td><span className={`status-pill ${request.status}`}>{request.status}</span></td><td>{request.status === "pending" && initial.can.leave_approve && request.requested_by !== initial.viewer_id && <div className="table-actions"><button className="secondary small" onClick={() => { setLeaveActionError(""); router.patch(`/hr/leave-requests/${request.id}`, { decision: "approved" }, { onError: errors => setLeaveActionError(Object.values(errors).join(" ")) }); }}>Approve</button><button className="secondary small" onClick={() => { setLeaveActionError(""); router.patch(`/hr/leave-requests/${request.id}`, { decision: "rejected" }, { onError: errors => setLeaveActionError(Object.values(errors).join(" ")) }); }}>Decline</button></div>}{request.decision_note && <small>{request.decision_note}</small>}</td></tr>)}
                {!initial.leave_requests.length && <tr><td colSpan={6} className="empty-state">No leave requests recorded.</td></tr>}
            </tbody></table></div>
        </section>
        <section className="content-section">
            <div className="section-heading"><div><span className="eyebrow">WORKFORCE</span><h2>People & HR</h2><p>Employee records, branch assignments and employment history.</p></div>
                <div className="section-actions">{initial.can.payroll_view && <Link className="secondary button-link" href="/payroll">Open Payroll</Link>}{!initial.can.payroll_enabled && initial.can.modules_configure && initial.can.modules_view && <Link className="secondary button-link" href="/modules">Enable Payroll</Link>}{!initial.can.payroll_enabled && !(initial.can.modules_configure && initial.can.modules_view) && <small>Payroll is a separate module. Ask an administrator to enable it in Modules.</small>}{initial.can.payroll_enabled && !initial.can.payroll_view && <small>Payroll is enabled, but your role needs `payroll.view` permission.</small>}{initial.can.create && <button className="primary" onClick={() => setEditing(true)}><Plus size={17} /> Add employee</button>}</div>
            </div>
            {initial.can.nssa_export && <div className="inline-form"><strong>NSSA monthly P4 workpaper</strong><label className="field">Contribution month<input type="month" value={contributionMonth} onChange={e => setContributionMonth(e.target.value)} /></label><a className="secondary button-link" href={`/hr/nssa/p4?period=${encodeURIComponent(contributionMonth)}`}>Export P4 reconciliation</a><small>Includes employee identifiers and contribution amounts from approved, non-reversed payroll runs. Reconcile against NSSA P4/P4A in the portal.</small></div>}
            <div className="toolbar"><label className="search-box"><Search size={17} /><input aria-label="Search employees" placeholder="Find a person, role or branch" value={query} onChange={e => setQuery(e.target.value)} /></label><span className="muted">{filtered.length} employee{filtered.length === 1 ? "" : "s"}</span></div>
            <div className="table-wrap"><table><thead><tr><th>Employee</th><th>Role / manager</th><th>Branch</th><th>Started</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>{filtered.map(employee => <tr key={employee.id}>
                    <td><strong>{employee.preferred_name || employee.legal_name}</strong><small className="table-subtitle">{employee.employee_number} · {employee.legal_name}</small></td>
                    <td>{employee.job_title}<small>{employee.employee_group === "management" ? "Management · ungraded" : employee.employee_group === "nec" ? `NEC${employee.job_grade ? ` · Grade ${employee.job_grade}` : ""}` : employee.employee_group === "general" ? `General${employee.job_grade ? ` · Grade ${employee.job_grade}` : ""}` : "Employee group not set"}</small><small>Reports to: {employee.manager || "—"}</small></td><td>{employee.branch}</td><td>{employee.start_date}</td><td><span className={`status-pill ${employee.status}`}>{employee.status.replace("_", " ")}</span></td>
                    <td><div className="table-actions">{initial.can.edit && employee.status !== "terminated" && <button className="secondary small" onClick={() => setEditing(employee)}>Edit</button>}{initial.can.history && <button className="icon-button" aria-label={`View history for ${employee.legal_name}`} onClick={() => showHistory(employee)}><History size={17} /></button>}</div></td>
                </tr>)}{!filtered.length && <tr><td colSpan={6} className="empty-state">{query ? "No employees match this search." : "No employees yet. Add your first employee to start the directory."}</td></tr>}</tbody>
            </table></div>
        </section>
        {editing && <EmployeeForm initial={editing === true ? undefined : editing} branches={initial.branches} employees={initial.employees} personalManage={initial.can.personal_manage} onClose={() => setEditing(null)} />}
        {historyId && <div className="modal-backdrop" onClick={() => setHistoryId(null)}><section className="modal" role="dialog" aria-modal="true" aria-label="Employment history" onClick={e => e.stopPropagation()}>
            <div className="section-heading"><h2>Employment history</h2><button className="icon-button" aria-label="Close history" onClick={() => setHistoryId(null)}><X /></button></div>
            {historyLoading ? <p>Loading history…</p> : events.length ? <ol className="history-list">{events.map(event => <li key={event.id}><strong>{event.kind.replace("_", " ")}</strong><span>{event.effective_date} · recorded {new Date(event.created_at).toLocaleDateString()}</span><p>{String(event.snapshot.job_title)} · {String(event.snapshot.status)} · branch {String(event.snapshot.branch_id)}</p></li>)}</ol> : <p>No recorded changes.</p>}
        </section></div>}
    </>;
}
