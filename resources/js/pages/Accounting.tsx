import React, { useState } from "react";
import axios from "axios";
type Account = {
    id: number;
    code: string;
    name: string;
    type: string;
    report_group: string;
    is_cash: boolean;
    active: boolean;
};
type Line = {
    id: number;
    account_id: number;
    debit: string;
    credit: string;
    account?: Account;
    batch?: { number: string; date: string; reference: string };
};
type Journal = {
    id: number;
    number: string;
    date: string;
    reference: string;
    description: string;
    source_type: string;
    branch_id: number;
    lines: Line[];
};
type StatementLine = {
    id: number;
    date: string;
    reference: string;
    description: string;
    amount: string;
    matches: { journal_line_id: number }[];
};
type Statement = {
    id: number;
    bank_account_id: number;
    starts_on: string;
    ends_on: string;
    opening_balance: string;
    closing_balance: string;
    status: string;
    outstanding: string | null;
    lines: StatementLine[];
};
export type AccountingData = {
    setting: null | { currency: string; live_enabled: boolean };
    today: string;
    book: string;
    branches: { id: number; name: string }[];
    warehouses: { id: number; name: string }[];
    variants: { id: number; sku: string; name: string }[];
    accounts: Account[];
    mappings: { role: string; account_id: number }[];
    periods: {
        id: number;
        name: string;
        starts_on: string;
        ends_on: string;
        locked: boolean;
    }[];
    journals: Journal[];
    banks: { id: number; name: string; account_id: number }[];
    statements: Statement[];
    purchases: {
        id: number;
        supplier_name: string;
        invoice_number: string;
        total: string;
        date: string;
    }[];
};
type Values = Record<string, string | boolean>;
type Field = {
    key: string;
    label: string;
    type?: string;
    value?: string | boolean;
    options?: { value: string; label: string }[];
    optional?: boolean;
};
function message(e: unknown): string {
    if (axios.isAxiosError(e)) {
        const d = e.response?.data;
        return d?.errors
            ? Object.values(d.errors).flat().join(" ")
            : (d?.message ?? e.message);
    }
    return e instanceof Error ? e.message : "Unable to save.";
}
function Form({
    title,
    fields,
    save,
    children,
    button = "Save",
}: {
    title: string;
    fields: Field[];
    save: (v: Values, id: string) => Promise<void>;
    children?: React.ReactNode;
    button?: string;
}) {
    const defaults = () =>
        Object.fromEntries(
            fields.map((f) => [
                f.key,
                f.value ??
                    (f.type === "checkbox"
                        ? false
                        : (f.options?.[0]?.value ?? "")),
            ]),
        );
    const [v, setV] = useState<Values>(defaults);
    const [request, setRequest] = useState(() => crypto.randomUUID());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [done, setDone] = useState("");
    return (
        <form
            className="finance-card"
            onSubmit={async (e) => {
                e.preventDefault();
                if (busy) return;
                setBusy(true);
                setError("");
                setDone("");
                try {
                    await save(v, request);
                    setRequest(crypto.randomUUID());
                    setDone("Saved successfully.");
                } catch (e) {
                    setError(message(e));
                } finally {
                    setBusy(false);
                }
            }}
        >
            <h3>{title}</h3>
            <div className="finance-fields">
                {fields.map((f) => (
                    <label className="field" key={f.key}>
                        {f.label}
                        {f.options ? (
                            <select
                                aria-label={f.label}
                                value={String(v[f.key])}
                                onChange={(e) => {
                                    setV({ ...v, [f.key]: e.target.value });
                                }}
                            >
                                {f.options.map((o) => (
                                    <option value={o.value} key={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                        ) : f.type === "textarea" ? (
                            <textarea
                                aria-label={f.label}
                                required={!f.optional}
                                value={String(v[f.key])}
                                onChange={(e) => {
                                    setV({ ...v, [f.key]: e.target.value });
                                }}
                            />
                        ) : (
                            <input
                                aria-label={f.label}
                                type={f.type ?? "text"}
                                required={!f.optional}
                                checked={
                                    f.type === "checkbox"
                                        ? Boolean(v[f.key])
                                        : undefined
                                }
                                value={
                                    f.type === "checkbox"
                                        ? undefined
                                        : String(v[f.key])
                                }
                                onChange={(e) => {
                                    setV({
                                        ...v,
                                        [f.key]:
                                            f.type === "checkbox"
                                                ? e.target.checked
                                                : e.target.value,
                                    });
                                }}
                            />
                        )}
                    </label>
                ))}
            </div>
            {children}
            {error && (
                <p role="alert" className="finance-error">
                    {error}
                </p>
            )}
            {done && <p role="status">{done}</p>}
            <button className="btn-primary" disabled={busy}>
                {busy ? "Saving…" : button}
            </button>
        </form>
    );
}
type Report = {
    trial_balance: {
        id: number;
        code: string;
        name: string;
        type: string;
        opening: string;
        debit: string;
        credit: string;
        closing: string;
    }[];
    general_ledger: {
        id: number;
        date: string;
        journal: string;
        account: string;
        reference: string;
        debit: string;
        credit: string;
    }[];
    profit_loss: { income: string; expense: string; profit: string };
    balance_sheet: {
        assets: string;
        liabilities: string;
        equity_including_earnings: string;
        unclosed_earnings: string;
        difference: string;
    };
    cash_flow: {
        exchange_effect?: string;
        opening: string;
        closing: string;
        movement: string;
        categories: Record<string, string>;
    };
    inventory_reconciliation: {
        ledger: string;
        stock: string | null;
        difference: string | null;
        basis: string;
    };
};
export default function Accounting({
    initial,
    permissions,
}: {
    initial: AccountingData;
    permissions: string[];
}) {
    const [d, setD] = useState(initial);
    const [tab, setTab] = useState("reports");
    const [book, setBook] = useState(initial.book);
    const [error, setError] = useState("");
    const [report, setReport] = useState<Report | null>(null);
    const [from, setFrom] = useState(initial.today.slice(0, 4) + "-01-01");
    const [to, setTo] = useState(initial.today);
    const [branch, setBranch] = useState("");
    const [edit, setEdit] = useState<Account | null>(null);
    const [reverse, setReverse] = useState<Journal | null>(null);
    const [jlines, setJlines] = useState([
        {
            account_id: String(d.accounts[0]?.id ?? ""),
            debit: "0",
            credit: "0",
        },
        {
            account_id: String(d.accounts[1]?.id ?? ""),
            debit: "0",
            credit: "0",
        },
    ]);
    const [items, setItems] = useState([
        {
            variant_id: String(d.variants?.[0]?.id ?? ""),
            quantity: "1",
            unit_cost: "0",
        },
    ]);
    const [statement, setStatement] = useState<Statement | null>(null);
    const [candidates, setCandidates] = useState<Line[]>([]);
    const [statementLine, setStatementLine] = useState("");
    const [selected, setSelected] = useState<number[]>([]);
    const can = (p: string) =>
        permissions.includes("*") || permissions.includes("accounting." + p);
    const reload = async (b = book) => {
        const r = await axios.get("/accounting/data", { params: { book: b } });
        if (!d.accounts.length && r.data.data.accounts.length) {
            setJlines(
                r.data.data.accounts
                    .slice(0, 2)
                    .map((a: Account) => ({
                        account_id: String(a.id),
                        debit: "0",
                        credit: "0",
                    })),
            );
        }
        setD(r.data.data);
    };
    const post = async (action: string, v: unknown) => {
        await axios.post("/accounting/" + action, v);
        await reload();
    };
    const safe = async (fn: () => Promise<void>) => {
        setError("");
        try {
            await fn();
        } catch (e) {
            setError(message(e));
        }
    };
    const branchOptions = d.branches.map((b) => ({
        value: String(b.id),
        label: b.name,
    }));
    const accounts = d.accounts
        .filter((a) => a.active)
        .map((a) => ({ value: String(a.id), label: a.code + " " + a.name }));
    const accountField = (key: string, label: string): Field => ({
        key,
        label,
        options: accounts,
    });
    const basics: Field[] = [
        { key: "branch_id", label: "Branch", options: branchOptions },
        { key: "date", label: "Posting date", type: "date", value: d.today },
        { key: "reference", label: "Reference" },
        { key: "description", label: "Description" },
        {
            key: "cashflow",
            label: "Cash flow",
            options: ["operating", "investing", "financing", "none"].map(
                (v) => ({ value: v, label: v }),
            ),
        },
    ];
    const reportParams = { book, from, to, branch_id: branch || undefined };
    const openStatement = async (id: number) => {
        const r = await axios.get("/accounting/statements/" + id);
        setStatement(r.data.data.statement);
        setCandidates(r.data.data.candidates);
        setSelected([]);
        setStatementLine("");
    };
    return (
        <div className="finance">
            <style>{`.finance{max-width:1400px}.finance-card{background:var(--surface,#fff);border:1px solid #dce4df;border-radius:12px;padding:22px;margin:16px 0}.finance h2{font-size:24px;margin:8px 0}.finance h3{font-size:18px;margin:0 0 16px}.finance-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin:16px 0}.finance input,.finance select,.finance textarea{width:100%;min-width:0;border:1px solid #c9d4cd;border-radius:6px;padding:9px;background:var(--surface,#fff);color:inherit}.finance input[type=checkbox]{width:auto}.finance button{cursor:pointer;padding:9px 14px;border:1px solid #baccc0;border-radius:6px;margin:4px;background:var(--surface,#fff);color:inherit}.finance button.btn-primary{background:#236744;color:white}.finance button:disabled{opacity:.5}.finance-tabs{display:flex;gap:6px;flex-wrap:wrap}.finance-tabs .selected{background:#236744;color:#fff}.finance-table{overflow-x:auto;margin:16px 0}.finance table{width:100%;border-collapse:collapse;font-size:13px}.finance th,.finance td{padding:12px 10px;border-bottom:1px solid #e3e9e5;text-align:left;white-space:nowrap}.finance th{font-size:11px;text-transform:uppercase;color:#586d60}.finance-error{background:#fff0ed;color:#922a19;padding:12px}.finance-banner{padding:16px;background:#edf5ee;border-radius:8px;margin-bottom:16px}.finance-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.finance-metrics p{border:1px solid #dce4df;padding:18px;border-radius:8px}.finance-metrics strong{display:block;font-size:22px;margin-top:8px}.finance-line{display:grid;grid-template-columns:minmax(160px,2fr) repeat(2,minmax(85px,1fr)) auto;gap:8px;margin:8px 0}.finance small{display:block;color:#637467}@media(max-width:600px){.finance-card{padding:14px}.finance-line{grid-template-columns:1fr 1fr}.finance-fields{grid-template-columns:1fr}}@media print{.finance-tabs,.finance button,.finance form,aside,header{display:none!important}.finance-card{border:0}.finance-table{overflow:visible}.finance table{font-size:9px}}`}</style>
            <h2>Accounting & finance</h2>
            <p>
                Balanced books, cash control and financial reports ·{" "}
                {d.setting?.currency ?? "Base currency"}
            </p>
            {error && (
                <p role="alert" className="finance-error">
                    {error}
                </p>
            )}
            {!d.setting ? (
                <>
                    <div className="finance-banner">
                        Initialize a chart of accounts and an open period.
                        Existing inventory becomes an opening balance in the
                        training book. Live activation requires a company with
                        no training sales.
                    </div>
                    {can("configure") && (
                        <Form
                            title="Initialize accounting"
                            fields={[
                                {
                                    key: "name",
                                    label: "Period name",
                                    value:
                                        "Financial year " + d.today.slice(0, 4),
                                },
                                {
                                    key: "starts_on",
                                    label: "Period starts",
                                    type: "date",
                                    value: d.today.slice(0, 4) + "-01-01",
                                },
                                {
                                    key: "ends_on",
                                    label: "Period ends",
                                    type: "date",
                                    value: d.today.slice(0, 4) + "-12-31",
                                },
                            ]}
                            save={(v) => post("setup", v)}
                            button="Initialize accounting"
                        />
                    )}
                </>
            ) : (
                <>
                    <div className="finance-banner">
                        <strong>
                            {book === "live" ? "LIVE BOOK" : "TRAINING BOOK"}
                        </strong>{" "}
                        ·{" "}
                        {d.setting.live_enabled
                            ? "Live retail is activated."
                            : "Live retail requires explicit activation below."}
                        <label>
                            {" "}
                            View book{" "}
                            <select
                                aria-label="View book"
                                value={book}
                                onChange={(e) => {
                                    const b = e.target.value;
                                    setBook(b);
                                    setReport(null);
                                    setStatement(null);
                                    void safe(() => reload(b));
                                }}
                            >
                                <option value="training">Training</option>
                                {d.setting.live_enabled && (
                                    <option value="live">Live</option>
                                )}
                            </select>
                        </label>
                    </div>
                    <div className="finance-tabs">
                        {[
                            "reports",
                            "journals",
                            "cashbook",
                            "purchases",
                            "banking",
                            "configuration",
                        ]
                            .filter((t) => t !== "banking" || can("bank"))
                            .map((t) => (
                                <button
                                    key={t}
                                    className={tab === t ? "selected" : ""}
                                    onClick={() => setTab(t)}
                                >
                                    {t[0].toUpperCase() + t.slice(1)}
                                </button>
                            ))}
                    </div>
                    {tab === "reports" && (
                        <section className="finance-card">
                            <h3>Financial reports</h3>
                            <div className="finance-fields">
                                <label className="field">
                                    From
                                    <input
                                        type="date"
                                        value={from}
                                        onChange={(e) =>
                                            setFrom(e.target.value)
                                        }
                                    />
                                </label>
                                <label className="field">
                                    Through
                                    <input
                                        type="date"
                                        value={to}
                                        onChange={(e) => setTo(e.target.value)}
                                    />
                                </label>
                                <label className="field">
                                    Branch
                                    <select
                                        value={branch}
                                        onChange={(e) =>
                                            setBranch(e.target.value)
                                        }
                                    >
                                        <option value="">
                                            All authorized branches
                                        </option>
                                        {branchOptions.map((b) => (
                                            <option
                                                key={b.value}
                                                value={b.value}
                                            >
                                                {b.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <button
                                onClick={() =>
                                    void safe(async () =>
                                        setReport(
                                            (
                                                await axios.get(
                                                    "/accounting/reports",
                                                    { params: reportParams },
                                                )
                                            ).data.data,
                                        ),
                                    )
                                }
                            >
                                Run reports
                            </button>
                            {report && (
                                <>
                                    <button onClick={() => window.print()}>
                                        Print reports
                                    </button>
                                    {can("export") &&
                                        ["trial_balance", "general_ledger"].map(
                                            (r) => (
                                                <a
                                                    key={r}
                                                    href={
                                                        "/accounting/export?" +
                                                        new URLSearchParams({
                                                            ...reportParams,
                                                            branch_id: branch,
                                                            report: r,
                                                        } as Record<
                                                            string,
                                                            string
                                                        >).toString()
                                                    }
                                                >
                                                    {" "}
                                                    Export{" "}
                                                    {r.replaceAll(
                                                        "_",
                                                        " ",
                                                    )}{" "}
                                                </a>
                                            ),
                                        )}
                                    <div className="finance-metrics">
                                        <p>
                                            Income
                                            <strong>
                                                {report.profit_loss.income}
                                            </strong>
                                        </p>
                                        <p>
                                            Expenses
                                            <strong>
                                                {report.profit_loss.expense}
                                            </strong>
                                        </p>
                                        <p>
                                            Profit / loss
                                            <strong>
                                                {report.profit_loss.profit}
                                            </strong>
                                        </p>
                                        <p>
                                            Closing cash & bank
                                            <strong>
                                                {report.cash_flow.closing}
                                            </strong>
                                        </p>
                                    </div>
                                    <h3>Trial balance</h3>
                                    <div className="finance-table">
                                        <table>
                                            <thead>
                                                <tr>
                                                    {[
                                                        "Account",
                                                        "Opening Dr/(Cr)",
                                                        "Period debit",
                                                        "Period credit",
                                                        "Closing Dr/(Cr)",
                                                    ].map((s) => (
                                                        <th key={s}>{s}</th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {report.trial_balance.map(
                                                    (r) => (
                                                        <tr key={r.id}>
                                                            <td>
                                                                {r.code}{" "}
                                                                {r.name}
                                                            </td>
                                                            <td>{r.opening}</td>
                                                            <td>{r.debit}</td>
                                                            <td>{r.credit}</td>
                                                            <td>{r.closing}</td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                    <h3>Balance sheet at {to}</h3>
                                    <p>
                                        Assets {report.balance_sheet.assets} ·
                                        Liabilities{" "}
                                        {report.balance_sheet.liabilities} ·
                                        Equity including earnings{" "}
                                        {
                                            report.balance_sheet
                                                .equity_including_earnings
                                        }
                                    </p>
                                    <p>
                                        Unclosed earnings{" "}
                                        {report.balance_sheet.unclosed_earnings}{" "}
                                        · Balance difference{" "}
                                        {report.balance_sheet.difference}
                                    </p>
                                    <h3>Cash flow</h3>
                                    <p>
                                        Opening {report.cash_flow.opening} ·
                                        Movement {report.cash_flow.movement} ·
                                        Closing {report.cash_flow.closing} · Exchange effect {report.cash_flow.exchange_effect ?? "0.0000"}
                                    </p>
                                    {Object.entries(
                                        report.cash_flow.categories,
                                    ).map(([k, v]) => (
                                        <p key={k}>
                                            {k}: {v}
                                        </p>
                                    ))}
                                    <h3>Inventory reconciliation</h3>
                                    <p>
                                        {report.inventory_reconciliation.basis}
                                    </p>
                                    <p>
                                        Ledger{" "}
                                        {report.inventory_reconciliation.ledger}{" "}
                                        · Stock{" "}
                                        {report.inventory_reconciliation
                                            .stock ?? "Current date only"}{" "}
                                        · Difference{" "}
                                        {report.inventory_reconciliation
                                            .difference ?? "—"}
                                    </p>
                                    <h3>General ledger</h3>
                                    <div className="finance-table">
                                        <table>
                                            <thead>
                                                <tr>
                                                    {[
                                                        "Date",
                                                        "Journal",
                                                        "Account",
                                                        "Reference",
                                                        "Debit",
                                                        "Credit",
                                                    ].map((s) => (
                                                        <th key={s}>{s}</th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {report.general_ledger.map(
                                                    (r) => (
                                                        <tr key={r.id}>
                                                            <td>{r.date}</td>
                                                            <td>{r.journal}</td>
                                                            <td>{r.account}</td>
                                                            <td>
                                                                {r.reference}
                                                            </td>
                                                            <td>{r.debit}</td>
                                                            <td>{r.credit}</td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </>
                            )}
                        </section>
                    )}
                    {tab === "journals" && (
                        <>
                            {can("post") && (
                                <Form
                                    title="Post balanced journal"
                                    fields={[
                                        ...basics,
                                        {
                                            key: "opening",
                                            label: "Opening financial balance",
                                            type: "checkbox",
                                            optional: true,
                                        },
                                    ]}
                                    save={(v, id) =>
                                        post("journals", {
                                            ...v,
                                            request_id: id,
                                            book,
                                            lines: jlines,
                                        })
                                    }
                                    button="Post journal"
                                >
                                    <p>
                                        Enter debits and credits to four
                                        decimals. Cash accounts use two.
                                        Inventory and drawer controls use their
                                        business workflows.
                                    </p>
                                    {jlines.map((l, i) => (
                                        <div key={i} className="finance-line">
                                            <select
                                                aria-label={
                                                    "Journal account " + (i + 1)
                                                }
                                                value={l.account_id}
                                                onChange={(e) =>
                                                    setJlines(
                                                        jlines.map((v, n) =>
                                                            n === i
                                                                ? {
                                                                      ...v,
                                                                      account_id:
                                                                          e
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : v,
                                                        ),
                                                    )
                                                }
                                            >
                                                {accounts.map((a) => (
                                                    <option
                                                        key={a.value}
                                                        value={a.value}
                                                    >
                                                        {a.label}
                                                    </option>
                                                ))}
                                            </select>
                                            {(["debit", "credit"] as const).map(
                                                (k) => (
                                                    <input
                                                        key={k}
                                                        aria-label={
                                                            k + " " + (i + 1)
                                                        }
                                                        value={l[k]}
                                                        inputMode="decimal"
                                                        onChange={(e) =>
                                                            setJlines(
                                                                jlines.map(
                                                                    (v, n) =>
                                                                        n === i
                                                                            ? {
                                                                                  ...v,
                                                                                  [k]: e
                                                                                      .target
                                                                                      .value,
                                                                              }
                                                                            : v,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                ),
                                            )}
                                            <button
                                                type="button"
                                                disabled={jlines.length <= 2}
                                                onClick={() =>
                                                    setJlines(
                                                        jlines.filter(
                                                            (_, n) => n !== i,
                                                        ),
                                                    )
                                                }
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    ))}
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setJlines([
                                                ...jlines,
                                                {
                                                    account_id:
                                                        accounts[0]?.value ??
                                                        "",
                                                    debit: "0",
                                                    credit: "0",
                                                },
                                            ])
                                        }
                                    >
                                        Add journal line
                                    </button>
                                </Form>
                            )}
                            <section className="finance-card">
                                <h3>Recent 100 journals</h3>
                                {d.journals.map((j) => (
                                    <details key={j.id}>
                                        <summary>
                                            {j.number} · {j.date} ·{" "}
                                            {j.reference} · {j.description}
                                        </summary>
                                        <div className="finance-table">
                                            <table>
                                                <tbody>
                                                    {j.lines.map((l) => (
                                                        <tr key={l.id}>
                                                            <td>
                                                                {
                                                                    l.account
                                                                        ?.code
                                                                }{" "}
                                                                {
                                                                    l.account
                                                                        ?.name
                                                                }
                                                            </td>
                                                            <td>
                                                                Dr {l.debit}
                                                            </td>
                                                            <td>
                                                                Cr {l.credit}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                        {can("reverse") &&
                                            [
                                                "manual",
                                                "opening_financial",
                                                "cashbook",
                                            ].includes(j.source_type) && (
                                                <button
                                                    onClick={() =>
                                                        setReverse(j)
                                                    }
                                                >
                                                    Reverse {j.number}
                                                </button>
                                            )}
                                    </details>
                                ))}
                                {!d.journals.length && (
                                    <p>No journals in this book.</p>
                                )}
                                <button
                                    disabled={!d.journals.length}
                                    onClick={() =>
                                        void safe(async () => {
                                            const r = await axios.get(
                                                "/accounting/data",
                                                {
                                                    params: {
                                                        book,
                                                        before_id:
                                                            d.journals.at(-1)
                                                                ?.id,
                                                    },
                                                },
                                            );
                                            setD({
                                                ...d,
                                                journals: [
                                                    ...d.journals,
                                                    ...r.data.data.journals,
                                                ],
                                            });
                                        })
                                    }
                                >
                                    Load older journals
                                </button>
                            </section>
                            {reverse && (
                                <Form
                                    key={reverse.id}
                                    title={"Reverse " + reverse.number}
                                    fields={[
                                        {
                                            key: "date",
                                            label: "Reversal date",
                                            type: "date",
                                            value: d.today,
                                        },
                                        { key: "reason", label: "Reason" },
                                    ]}
                                    save={async (v) => {
                                        await post("reverse/" + reverse.id, v);
                                        setReverse(null);
                                    }}
                                    button="Post reversal"
                                />
                            )}
                        </>
                    )}
                    {tab === "cashbook" && can("post") && (
                        <Form
                            title="Cashbook receipt, payment or transfer"
                            fields={[
                                ...basics,
                                {
                                    key: "kind",
                                    label: "Transaction",
                                    options: [
                                        "receipt",
                                        "payment",
                                        "transfer",
                                    ].map((v) => ({ value: v, label: v })),
                                },
                                accountField(
                                    "cash_account_id",
                                    "Cash / bank account",
                                ),
                                accountField(
                                    "offset_account_id",
                                    "Counterpart account",
                                ),
                                {
                                    key: "amount",
                                    label: "Amount (two decimals)",
                                },
                            ]}
                            save={(v, id) =>
                                post("cashbook", { ...v, book, request_id: id })
                            }
                            button="Post cashbook entry"
                        >
                            <p>
                                Receipts debit cash; payments credit cash.
                                Transfers move cash to the counterpart cash
                                account. Cash payments require recorded funds.
                            </p>
                        </Form>
                    )}
                    {tab === "purchases" && (
                        <>
                            {can("purchase") && (
                                <Form
                                    title="Post supplier invoice and stock receipt"
                                    fields={[
                                        {
                                            key: "warehouse_id",
                                            label: "Receiving warehouse",
                                            options: d.warehouses.map((w) => ({
                                                value: String(w.id),
                                                label: w.name,
                                            })),
                                        },
                                        {
                                            key: "date",
                                            label: "Invoice date",
                                            type: "date",
                                            value: d.today,
                                        },
                                        {
                                            key: "supplier_name",
                                            label: "Supplier name",
                                        },
                                        {
                                            key: "invoice_number",
                                            label: "Supplier invoice number",
                                        },
                                        {
                                            key: "tax_rate",
                                            label: "Input tax %",
                                            value: "0",
                                        },
                                        {
                                            key: "settlement",
                                            label: "Settlement",
                                            options: [
                                                "payable",
                                                "cash_safe",
                                                "bank",
                                                "card",
                                                "mobile_money",
                                            ].map((v) => ({
                                                value: v,
                                                label: v.replaceAll("_", " "),
                                            })),
                                        },
                                    ]}
                                    save={(v, id) =>
                                        post("purchases", {
                                            ...v,
                                            request_id: id,
                                            items,
                                        })
                                    }
                                    button="Post purchase"
                                >
                                    <p>
                                        Posts stock, input tax, payable or
                                        settlement together. Use Purchasing for
                                        supplier and purchase-order workflows.
                                    </p>
                                    {items.map((l, i) => (
                                        <div className="finance-line" key={i}>
                                            <select
                                                aria-label={
                                                    "Purchase product " +
                                                    (i + 1)
                                                }
                                                value={l.variant_id}
                                                onChange={(e) =>
                                                    setItems(
                                                        items.map((v, n) =>
                                                            n === i
                                                                ? {
                                                                      ...v,
                                                                      variant_id:
                                                                          e
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : v,
                                                        ),
                                                    )
                                                }
                                            >
                                                {d.variants.map((v) => (
                                                    <option
                                                        key={v.id}
                                                        value={v.id}
                                                    >
                                                        {v.sku} {v.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <input
                                                aria-label={
                                                    "Purchase quantity " +
                                                    (i + 1)
                                                }
                                                value={l.quantity}
                                                onChange={(e) =>
                                                    setItems(
                                                        items.map((v, n) =>
                                                            n === i
                                                                ? {
                                                                      ...v,
                                                                      quantity:
                                                                          e
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : v,
                                                        ),
                                                    )
                                                }
                                            />
                                            <input
                                                aria-label={
                                                    "Purchase unit cost " +
                                                    (i + 1)
                                                }
                                                value={l.unit_cost}
                                                onChange={(e) =>
                                                    setItems(
                                                        items.map((v, n) =>
                                                            n === i
                                                                ? {
                                                                      ...v,
                                                                      unit_cost:
                                                                          e
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : v,
                                                        ),
                                                    )
                                                }
                                            />
                                            <button
                                                type="button"
                                                disabled={items.length <= 1}
                                                onClick={() =>
                                                    setItems(
                                                        items.filter(
                                                            (_, n) => n !== i,
                                                        ),
                                                    )
                                                }
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    ))}
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setItems([
                                                ...items,
                                                {
                                                    variant_id: String(
                                                        d.variants[0]?.id ?? "",
                                                    ),
                                                    quantity: "1",
                                                    unit_cost: "0",
                                                },
                                            ])
                                        }
                                    >
                                        Add purchase item
                                    </button>
                                </Form>
                            )}
                            <section className="finance-card">
                                <h3>Recent supplier invoices</h3>
                                {d.purchases.map((p) => (
                                    <p key={p.id}>
                                        {p.date} · {p.supplier_name} ·{" "}
                                        {p.invoice_number} · {p.total}
                                    </p>
                                ))}
                            </section>
                        </>
                    )}
                    {tab === "configuration" && (
                        <>
                            {can("configure") && !d.setting.live_enabled && (
                                <Form
                                    title="Activate live retail"
                                    fields={[
                                        {
                                            key: "opening_stock_confirmed",
                                            label: "I verified the current opening stock quantities and values",
                                            type: "checkbox",
                                        },
                                        {
                                            key: "base_currency_precision_confirmed",
                                            label: "I confirm the base currency uses two decimal monetary amounts",
                                            type: "checkbox",
                                        },
                                    ]}
                                    save={(v) => post("activate", v)}
                                    button="Activate live retail"
                                >
                                    <p>
                                        Live accounting starts with a separate
                                        inventory opening snapshot. Companies
                                        containing training sales must use a
                                        fresh company for live retail. Close
                                        shifts and resolve held carts and draft
                                        counts first.
                                    </p>
                                </Form>
                            )}
                            <section className="finance-card">
                                <h3>Chart of accounts</h3>
                                <div className="finance-table">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Code</th>
                                                <th>Name</th>
                                                <th>Type</th>
                                                <th>Cash</th>
                                                <th>Status</th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {d.accounts.map((a) => (
                                                <tr key={a.id}>
                                                    <td>{a.code}</td>
                                                    <td>{a.name}</td>
                                                    <td>{a.type}</td>
                                                    <td>
                                                        {a.is_cash
                                                            ? "Yes"
                                                            : "No"}
                                                    </td>
                                                    <td>
                                                        {a.active
                                                            ? "Active"
                                                            : "Inactive"}
                                                    </td>
                                                    <td>
                                                        {can("configure") && (
                                                            <button
                                                                onClick={() =>
                                                                    setEdit(a)
                                                                }
                                                            >
                                                                Edit {a.code}
                                                            </button>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                            {can("configure") && (
                                <>
                                    <Form
                                        key={edit?.id ?? "new"}
                                        title={
                                            edit
                                                ? "Edit account " + edit.code
                                                : "Create account"
                                        }
                                        fields={[
                                            {
                                                key: "code",
                                                label: "Account code",
                                                value: edit?.code,
                                            },
                                            {
                                                key: "name",
                                                label: "Account name",
                                                value: edit?.name,
                                            },
                                            {
                                                key: "type",
                                                label: "Type",
                                                value: edit?.type ?? "asset",
                                                options: [
                                                    "asset",
                                                    "liability",
                                                    "equity",
                                                    "income",
                                                    "expense",
                                                ].map((v) => ({
                                                    value: v,
                                                    label: v,
                                                })),
                                            },
                                            {
                                                key: "report_group",
                                                label: "Reporting group",
                                                value:
                                                    edit?.report_group ??
                                                    "asset",
                                            },
                                            {
                                                key: "is_cash",
                                                label: "Cash or bank account",
                                                type: "checkbox",
                                                value: edit?.is_cash ?? false,
                                                optional: true,
                                            },
                                            {
                                                key: "active",
                                                label: "Active",
                                                type: "checkbox",
                                                value: edit?.active ?? true,
                                                optional: true,
                                            },
                                        ]}
                                        save={async (v) => {
                                            await post(
                                                "accounts" +
                                                    (edit ? "/" + edit.id : ""),
                                                v,
                                            );
                                            setEdit(null);
                                        }}
                                    />
                                    {edit && (
                                        <button onClick={() => setEdit(null)}>
                                            Create another account
                                        </button>
                                    )}
                                    <Form
                                        title="Posting account mappings"
                                        fields={[
                                            {
                                                key: "role",
                                                label: "Posting role",
                                                options: d.mappings.map(
                                                    (m) => ({
                                                        value: m.role,
                                                        label:
                                                            m.role +
                                                            " → " +
                                                            d.accounts.find(
                                                                (a) =>
                                                                    a.id ===
                                                                    m.account_id,
                                                            )?.code,
                                                    }),
                                                ),
                                            },
                                            accountField(
                                                "account_id",
                                                "Mapped account",
                                            ),
                                        ]}
                                        save={(v) => post("mappings", v)}
                                    >
                                        <p>
                                            Mappings become fixed once journals
                                            exist. Account names and codes
                                            remain editable.
                                        </p>
                                    </Form>
                                </>
                            )}
                            <section className="finance-card">
                                <h3>Accounting periods</h3>
                                {d.periods.map((p) => (
                                    <p key={p.id}>
                                        {p.name} · {p.starts_on} — {p.ends_on} ·{" "}
                                        {p.locked ? (
                                            "Locked"
                                        ) : (
                                            <>
                                                {"Open "}
                                                {can("periods") && (
                                                    <button
                                                        onClick={() =>
                                                            void safe(() =>
                                                                post(
                                                                    "lock/" +
                                                                        p.id,
                                                                    {},
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        Lock {p.name}
                                                    </button>
                                                )}
                                            </>
                                        )}
                                    </p>
                                ))}
                            </section>
                            {can("periods") && (
                                <Form
                                    title="Create accounting period"
                                    fields={[
                                        { key: "name", label: "Period name" },
                                        {
                                            key: "starts_on",
                                            label: "Starts",
                                            type: "date",
                                        },
                                        {
                                            key: "ends_on",
                                            label: "Ends",
                                            type: "date",
                                        },
                                    ]}
                                    save={(v) => post("periods", v)}
                                />
                            )}
                        </>
                    )}
                    {tab === "banking" && can("bank") && (
                        <>
                            <Form
                                title="Register bank account"
                                fields={[
                                    { key: "name", label: "Bank name" },
                                    accountField(
                                        "account_id",
                                        "Unused cash asset account",
                                    ),
                                    {
                                        key: "branch_id",
                                        label: "Branch",
                                        options: [
                                            {
                                                value: "",
                                                label: "Company wide",
                                            },
                                            ...branchOptions,
                                        ],
                                        optional: true,
                                    },
                                ]}
                                save={(v) =>
                                    post("banks", {
                                        ...v,
                                        branch_id: v.branch_id || null,
                                    })
                                }
                            />
                            <Form
                                title="Import bank statement CSV"
                                fields={[
                                    {
                                        key: "bank_account_id",
                                        label: "Bank account",
                                        options: d.banks.map((b) => ({
                                            value: String(b.id),
                                            label: b.name,
                                        })),
                                    },
                                    {
                                        key: "starts_on",
                                        label: "Statement starts",
                                        type: "date",
                                        value: d.today,
                                    },
                                    {
                                        key: "ends_on",
                                        label: "Statement ends",
                                        type: "date",
                                        value: d.today,
                                    },
                                    {
                                        key: "opening_balance",
                                        label: "Opening statement balance",
                                        value: "0.00",
                                    },
                                    {
                                        key: "closing_balance",
                                        label: "Closing statement balance",
                                        value: "0.00",
                                    },
                                    {
                                        key: "csv",
                                        label: "CSV contents",
                                        type: "textarea",
                                        value: "date,reference,description,amount\n",
                                    },
                                    {
                                        key: "baseline_confirmed",
                                        label: "I verified the opening ledger baseline / prior statement",
                                        type: "checkbox",
                                    },
                                ]}
                                save={(v, id) =>
                                    post("statements", {
                                        ...v,
                                        book,
                                        request_id: id,
                                    })
                                }
                                button="Import statement"
                            >
                                <p>
                                    CSV columns:
                                    date,reference,description,amount. Receipts
                                    are positive; payments negative. Statements
                                    must continue the prior closed statement.
                                </p>
                            </Form>
                            <section className="finance-card">
                                <h3>Bank statements</h3>
                                {d.statements.map((s) => (
                                    <p key={s.id}>
                                        {
                                            d.banks.find(
                                                (b) =>
                                                    b.id === s.bank_account_id,
                                            )?.name
                                        }{" "}
                                        · {s.starts_on} — {s.ends_on} ·{" "}
                                        {s.status} · Closing {s.closing_balance}
                                        <button
                                            onClick={() =>
                                                void safe(() =>
                                                    openStatement(s.id),
                                                )
                                            }
                                        >
                                            Open statement {s.id}
                                        </button>
                                    </p>
                                ))}
                            </section>
                            {statement && (
                                <section className="finance-card">
                                    <h3>
                                        Reconcile statement {statement.id} ·{" "}
                                        {statement.status}
                                    </h3>
                                    <p>
                                        Match each statement line to one or more
                                        book entries with the same signed total.
                                        Unmatched book entries remain
                                        outstanding.
                                    </p>
                                    <div className="finance-table">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Select</th>
                                                    <th>Date</th>
                                                    <th>Reference</th>
                                                    <th>Amount</th>
                                                    <th>Match</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {statement.lines.map((l) => (
                                                    <tr key={l.id}>
                                                        <td>
                                                            <input
                                                                aria-label={
                                                                    "Statement line " +
                                                                    l.id
                                                                }
                                                                type="radio"
                                                                name="statementLine"
                                                                value={l.id}
                                                                checked={
                                                                    statementLine ===
                                                                    String(l.id)
                                                                }
                                                                disabled={
                                                                    !!l.matches
                                                                        .length ||
                                                                    statement.status !==
                                                                        "draft"
                                                                }
                                                                onChange={(e) =>
                                                                    setStatementLine(
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                        <td>{l.date}</td>
                                                        <td>
                                                            {l.reference}{" "}
                                                            {l.description}
                                                        </td>
                                                        <td>{l.amount}</td>
                                                        <td>
                                                            {l.matches.length
                                                                ? "Matched"
                                                                : "Unmatched"}
                                                            {l.matches.length >
                                                                0 &&
                                                                statement.status ===
                                                                    "draft" && (
                                                                    <button
                                                                        onClick={() =>
                                                                            void safe(
                                                                                async () => {
                                                                                    await post(
                                                                                        "unmatch/" +
                                                                                            statement.id,
                                                                                        {
                                                                                            statement_line_id:
                                                                                                l.id,
                                                                                        },
                                                                                    );
                                                                                    await openStatement(
                                                                                        statement.id,
                                                                                    );
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        Unmatch
                                                                    </button>
                                                                )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    {statement.status === "draft" && (
                                        <>
                                            <h3>Unmatched book entries</h3>
                                            {candidates.map((l) => (
                                                <label
                                                    className="field"
                                                    key={l.id}
                                                >
                                                    <span>
                                                        <input
                                                            type="checkbox"
                                                            checked={selected.includes(
                                                                l.id,
                                                            )}
                                                            onChange={(e) =>
                                                                setSelected(
                                                                    e.target
                                                                        .checked
                                                                        ? [
                                                                              ...selected,
                                                                              l.id,
                                                                          ]
                                                                        : selected.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  l.id,
                                                                          ),
                                                                )
                                                            }
                                                        />{" "}
                                                        {l.batch?.date} ·{" "}
                                                        {l.batch?.number} ·{" "}
                                                        {l.batch?.reference} ·
                                                        Dr {l.debit} Cr{" "}
                                                        {l.credit}
                                                    </span>
                                                </label>
                                            ))}
                                            <button
                                                disabled={
                                                    !selected.length ||
                                                    !statementLine
                                                }
                                                onClick={() =>
                                                    void safe(async () => {
                                                        await post(
                                                            "match/" +
                                                                statement.id,
                                                            {
                                                                statement_line_id:
                                                                    statementLine,
                                                                journal_line_ids:
                                                                    selected,
                                                            },
                                                        );
                                                        await openStatement(
                                                            statement.id,
                                                        );
                                                    })
                                                }
                                            >
                                                Match selected entries
                                            </button>
                                            <button
                                                onClick={() =>
                                                    void safe(async () => {
                                                        await post(
                                                            "close/" +
                                                                statement.id,
                                                            {},
                                                        );
                                                        await openStatement(
                                                            statement.id,
                                                        );
                                                    })
                                                }
                                            >
                                                Finalize reconciliation
                                            </button>
                                            <button
                                                onClick={() =>
                                                    void safe(async () => {
                                                        await post(
                                                            "cancel/" +
                                                                statement.id,
                                                            {},
                                                        );
                                                        await openStatement(
                                                            statement.id,
                                                        );
                                                    })
                                                }
                                            >
                                                Cancel draft statement
                                            </button>
                                        </>
                                    )}
                                    <p>
                                        Outstanding at finalization:{" "}
                                        {statement.outstanding ??
                                            "Not finalized"}
                                    </p>
                                </section>
                            )}
                        </>
                    )}
                </>
            )}
        </div>
    );
}
