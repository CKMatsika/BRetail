import React, { useState } from "react";

export const amount = (value: string | number | null | undefined) =>
    value == null
        ? "—"
        : Number(value).toLocaleString("en-GB", {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          });
export const dateText = (value: string | null | undefined) =>
    value
        ? new Date(value.slice(0, 10) + "T12:00:00").toLocaleDateString(
              "en-GB",
              { day: "2-digit", month: "short", year: "numeric" },
          )
        : "—";
export type FinancialSnapshot = {
    trial_balance: {
        id: number;
        code: string;
        name: string;
        type: string;
        group?: string;
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
        description?: string;
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
        opening: string;
        closing: string;
        movement: string;
        exchange_effect?: string;
        categories: Record<string, string>;
    };
    inventory_reconciliation?: {
        ledger: string;
        stock: string | null;
        difference: string | null;
        basis: string;
    };
};
export type AgingSnapshot = {
    date: string;
    book: string;
    aging: {
        id: number;
        number: string;
        kind: string;
        party_name: string;
        party_key: string;
        role: string;
        date: string;
        due_on: string | null;
        outstanding: string;
        days_overdue: number;
        bucket: string;
        branch_name: string;
        original_currency: string | null;
        original_outstanding: string | null;
    }[];
    parties: ({
        key: string;
        party_name: string;
        role: string;
        documents: number;
        total: string;
    } & Record<string, any>)[];
    totals: Record<string, Record<string, string>>;
    controls: {
        role: string;
        branch_id: number;
        branch_name?: string;
        ledger: string;
        subledger: string;
        unallocated: string;
    }[];
};
export function Empty({ children }: { children: React.ReactNode }) {
    return <div className="report-empty">{children}</div>;
}
export function Metric({
    label,
    value,
    detail,
}: {
    label: string;
    value: string;
    detail?: string;
}) {
    return (
        <div className="report-metric">
            <span>{label}</span>
            <strong>{value}</strong>
            {detail && <small>{detail}</small>}
        </div>
    );
}
export function FinancialReport({
    data,
    view,
    currency,
}: {
    data: FinancialSnapshot;
    view: string;
    currency: string;
}) {
    const [search, setSearch] = useState("");
    const [account, setAccount] = useState("");
    const [hideZero, setHideZero] = useState(true);
    const rows = data.trial_balance.filter(
        (r) =>
            !hideZero ||
            [r.opening, r.debit, r.credit, r.closing].some(
                (v) => Number(v) !== 0,
            ),
    );
    const statement = (type: string, movement = false) =>
        rows
            .filter((r) => r.type === type)
            .map((r) => ({
                label: `${r.code} · ${r.name}`,
                value: movement
                    ? type === "income"
                        ? Number(r.credit) - Number(r.debit)
                        : Number(r.debit) - Number(r.credit)
                    : type === "asset"
                      ? Number(r.closing)
                      : -Number(r.closing),
            }));
    const lines = (
        title: string,
        entries: { label: string; value: string | number }[],
        total?: { label: string; value: string },
    ) => (
        <section className="statement-section">
            <h3>{title}</h3>
            <dl>
                {entries.map((r, i) => (
                    <div key={i}>
                        <dt>{r.label}</dt>
                        <dd>{amount(r.value)}</dd>
                    </div>
                ))}
                {total && (
                    <div className="statement-total">
                        <dt>{total.label}</dt>
                        <dd>{amount(total.value)}</dd>
                    </div>
                )}
            </dl>
        </section>
    );
    if (view === "profit_loss")
        return (
            <div className="statement-layout">
                {lines("Revenue", statement("income", true), {
                    label: "Total revenue",
                    value: data.profit_loss.income,
                })}
                {lines("Expenses", statement("expense", true), {
                    label: "Total expenses",
                    value: data.profit_loss.expense,
                })}
                {lines("Result", [], {
                    label: "Net profit / (loss)",
                    value: data.profit_loss.profit,
                })}
            </div>
        );
    if (view === "balance_sheet")
        return (
            <>
                <div className="statement-layout">
                    {lines("Assets", statement("asset"), {
                        label: "Total assets",
                        value: data.balance_sheet.assets,
                    })}
                    {lines("Liabilities", statement("liability"), {
                        label: "Total liabilities",
                        value: data.balance_sheet.liabilities,
                    })}
                    {lines(
                        "Equity",
                        [
                            ...statement("equity"),
                            {
                                label: "Unclosed earnings",
                                value: data.balance_sheet.unclosed_earnings,
                            },
                        ],
                        {
                            label: "Total equity including earnings",
                            value: data.balance_sheet.equity_including_earnings,
                        },
                    )}
                </div>
                <p
                    className={
                        Math.abs(Number(data.balance_sheet.difference)) >
                        0.00005
                            ? "report-warning"
                            : "report-reconciled"
                    }
                >
                    Balance check · Assets less liabilities and equity:{" "}
                    {currency} {amount(data.balance_sheet.difference)}
                </p>
            </>
        );
    if (view === "cash_flow")
        return (
            <>
                {lines(
                    "Cash flow reconciliation",
                    [
                        {
                            label: "Opening cash & bank",
                            value: data.cash_flow.opening,
                        },
                        ...Object.entries(data.cash_flow.categories).map(
                            ([k, v]) => ({
                                label:
                                    k === "none"
                                        ? "Unclassified / internal movements"
                                        : k[0].toUpperCase() +
                                          k.slice(1) +
                                          " activities",
                                value: v,
                            }),
                        ),
                        {
                            label: "Effect of exchange rate changes",
                            value: data.cash_flow.exchange_effect ?? "0",
                        },
                        {
                            label: "Net cash movement",
                            value: data.cash_flow.movement,
                        },
                    ],
                    {
                        label: "Closing cash & bank",
                        value: data.cash_flow.closing,
                    },
                )}
                {data.inventory_reconciliation &&
                    lines("Inventory control reconciliation", [
                        {
                            label: "Ledger balance",
                            value: data.inventory_reconciliation.ledger,
                        },
                        ...(data.inventory_reconciliation.stock !== null
                            ? [
                                  {
                                      label: "Stock valuation",
                                      value: data.inventory_reconciliation
                                          .stock,
                                  },
                                  {
                                      label: "Difference",
                                      value:
                                          data.inventory_reconciliation
                                              .difference ?? "0",
                                  },
                              ]
                            : []),
                    ])}
                <p className="report-caption">
                    {data.inventory_reconciliation?.basis}
                </p>
            </>
        );
    if (view === "general_ledger") {
        const ledger = data.general_ledger.filter(
            (r) =>
                (!account || r.account === account) &&
                `${r.reference} ${r.description} ${r.journal}`
                    .toLowerCase()
                    .includes(search.toLowerCase()),
        );
        return (
            <>
                <div className="report-local-filters no-print">
                    <label>
                        Account
                        <select
                            value={account}
                            onChange={(e) => setAccount(e.target.value)}
                        >
                            <option value="">All accounts</option>
                            {[
                                ...new Set(
                                    data.general_ledger.map((r) => r.account),
                                ),
                            ]
                                .sort()
                                .map((a) => (
                                    <option key={a}>{a}</option>
                                ))}
                        </select>
                    </label>
                    <label>
                        Find a posting
                        <input
                            placeholder="Reference, journal or description"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </label>
                </div>
                <div className="report-table-wrap">
                    <table className="report-table">
                        <thead>
                            <tr>
                                <th>Date / journal</th>
                                <th>Account</th>
                                <th>Reference / description</th>
                                <th className="num">Debit</th>
                                <th className="num">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {ledger.map((r) => (
                                <tr key={r.id}>
                                    <td>
                                        {dateText(r.date)}
                                        <small>{r.journal}</small>
                                    </td>
                                    <td>{r.account}</td>
                                    <td>
                                        {r.reference}
                                        <small>{r.description}</small>
                                    </td>
                                    <td className="num">{amount(r.debit)}</td>
                                    <td className="num">{amount(r.credit)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colSpan={3}>
                                    Selected postings · {ledger.length}
                                </th>
                                <td className="num">
                                    {amount(
                                        ledger.reduce(
                                            (s, r) => s + Number(r.debit),
                                            0,
                                        ),
                                    )}
                                </td>
                                <td className="num">
                                    {amount(
                                        ledger.reduce(
                                            (s, r) => s + Number(r.credit),
                                            0,
                                        ),
                                    )}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                {!ledger.length && (
                    <Empty>No postings match these filters.</Empty>
                )}
            </>
        );
    }
    return (
        <>
            <label className="report-checkbox no-print">
                <input
                    type="checkbox"
                    checked={hideZero}
                    onChange={(e) => setHideZero(e.target.checked)}
                />{" "}
                Hide inactive balances (no opening or movement)
            </label>
            <div className="report-table-wrap">
                <table className="report-table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th className="num">Opening Dr / (Cr)</th>
                            <th className="num">Period debit</th>
                            <th className="num">Period credit</th>
                            <th className="num">Closing Dr / (Cr)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td>
                                    <span className="account-code">
                                        {r.code}
                                    </span>{" "}
                                    {r.name}
                                </td>
                                {[r.opening, r.debit, r.credit, r.closing].map(
                                    (v, i) => (
                                        <td className="num" key={i}>
                                            {amount(v)}
                                        </td>
                                    ),
                                )}
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total</th>
                            {["opening", "debit", "credit", "closing"].map(
                                (key) => (
                                    <td className="num" key={key}>
                                        {amount(
                                            rows.reduce(
                                                (s, r) =>
                                                    s +
                                                    Number(r[key as "opening"]),
                                                0,
                                            ),
                                        )}
                                    </td>
                                ),
                            )}
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p className="report-caption">
                Positive balances are debits; negative balances are credits. All
                amounts in {currency}.
            </p>
        </>
    );
}
export function AgingReport({
    data,
    role,
    currency,
}: {
    data: AgingSnapshot;
    role: "receivable" | "payable";
    currency: string;
}) {
    const [party, setParty] = useState("");
    const [search, setSearch] = useState("");
    const [overdueOnly, setOverdueOnly] = useState(false);
    const buckets = ["current", "1–30", "31–60", "61–90", "90+"];
    const parties = data.parties.filter(
        (p) =>
            p.role === role &&
            p.party_name.toLowerCase().includes(search.toLowerCase()) &&
            (!overdueOnly || Number(p.total) > Number(p.current)),
    );
    const docs = data.aging.filter(
        (r) =>
            r.role === role &&
            (!party || r.party_key === party) &&
            r.party_name.toLowerCase().includes(search.toLowerCase()) &&
            (!overdueOnly || r.days_overdue > 0),
    );
    const total = data.totals[role];
    return (
        <>
            <div className="report-metrics">
                <Metric
                    label={
                        role === "receivable"
                            ? "Total receivables"
                            : "Total payables"
                    }
                    value={`${currency} ${amount(total.total)}`}
                />
                <Metric label="Not yet overdue" value={amount(total.current)} />
                <Metric
                    label="Overdue"
                    value={amount(Number(total.total) - Number(total.current))}
                />
                <Metric label="Over 90 days" value={amount(total["90+"])} />
            </div>
            <div className="report-local-filters no-print">
                <label>
                    Find {role === "receivable" ? "customer" : "supplier"}
                    <input
                        value={search}
                        onChange={(e) => {
                            setSearch(e.target.value);
                            setParty("");
                        }}
                        placeholder="Search by name"
                    />
                </label>
                <label className="report-checkbox">
                    <input
                        type="checkbox"
                        checked={overdueOnly}
                        onChange={(e) => setOverdueOnly(e.target.checked)}
                    />{" "}
                    Overdue balances only
                </label>
            </div>
            <div className="report-table-wrap">
                <table className="report-table">
                    <thead>
                        <tr>
                            <th>
                                {role === "receivable"
                                    ? "Customer"
                                    : "Supplier"}
                            </th>
                            {buckets.map((b) => (
                                <th className="num" key={b}>
                                    {b === "current" ? "Current" : b + " days"}
                                </th>
                            ))}
                            <th className="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {parties.map((p) => (
                            <tr
                                key={p.key}
                                className={
                                    party === p.key ? "report-selected" : ""
                                }
                            >
                                <td>
                                    <button
                                        className="report-link"
                                        onClick={() =>
                                            setParty(
                                                party === p.key ? "" : p.key,
                                            )
                                        }
                                        aria-pressed={party === p.key}
                                    >
                                        {p.party_name}
                                    </button>
                                    <small>
                                        {p.documents} open document
                                        {p.documents !== 1 ? "s" : ""}
                                    </small>
                                </td>
                                {[...buckets, "total"].map((b) => (
                                    <td className="num" key={b}>
                                        {amount(p[b])}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Selected parties</th>
                            {[...buckets, "total"].map((b) => (
                                <td className="num" key={b}>
                                    {amount(
                                        parties.reduce(
                                            (s, p) => s + Number(p[b]),
                                            0,
                                        ),
                                    )}
                                </td>
                            ))}
                        </tr>
                    </tfoot>
                </table>
            </div>
            {!parties.length && (
                <Empty>No outstanding balances match this selection.</Empty>
            )}
            <div className="report-subheading">
                <div>
                    <h3>Open document detail</h3>
                    <p className="report-caption">
                        {party
                            ? data.parties.find((p) => p.key === party)
                                  ?.party_name
                            : "All selected parties"}{" "}
                        · balances as at {dateText(data.date)}
                    </p>
                </div>
                {party && (
                    <button
                        className="secondary no-print"
                        onClick={() => setParty("")}
                    >
                        Show all parties
                    </button>
                )}
            </div>
            <div className="report-table-wrap">
                <table className="report-table">
                    <thead>
                        <tr>
                            <th>Document / party</th>
                            <th>Branch</th>
                            <th>Issued</th>
                            <th>Due</th>
                            <th className="num">Days overdue</th>
                            <th>Age band</th>
                            <th className="num">Outstanding {currency}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {docs.map((r) => (
                            <tr key={r.id}>
                                <td>
                                    <a
                                        className="report-link"
                                        href={`/trade/print/document/${r.id}`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {r.number}
                                    </a>
                                    <small>{r.party_name}</small>
                                </td>
                                <td>{r.branch_name}</td>
                                <td>{dateText(r.date)}</td>
                                <td>{dateText(r.due_on)}</td>
                                <td className="num">{r.days_overdue || "—"}</td>
                                <td>
                                    <span
                                        className={
                                            r.days_overdue > 90
                                                ? "report-age overdue"
                                                : "report-age"
                                        }
                                    >
                                        {r.bucket}
                                    </span>
                                </td>
                                <td className="num">
                                    <strong>{amount(r.outstanding)}</strong>
                                    {r.original_currency && (
                                        <small>
                                            {r.original_currency}{" "}
                                            {amount(r.original_outstanding)}
                                        </small>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colSpan={6}>Selected documents</th>
                            <td className="num">
                                {amount(
                                    docs.reduce(
                                        (s, r) => s + Number(r.outstanding),
                                        0,
                                    ),
                                )}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div className="report-subheading">
                <h3>Control account reconciliation</h3>
            </div>
            <div className="report-table-wrap">
                <table className="report-table">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th className="num">Control balance</th>
                            <th className="num">Party balances</th>
                            <th className="num">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.controls
                            .filter((c) => c.role === role)
                            .map((c) => (
                                <tr key={c.branch_id}>
                                    <td>
                                        {c.branch_name ??
                                            `Branch ${c.branch_id}`}
                                    </td>
                                    <td className="num">{amount(c.ledger)}</td>
                                    <td className="num">
                                        {amount(c.subledger)}
                                    </td>
                                    <td
                                        className={`num ${Math.abs(Number(c.unallocated)) > 0.00005 ? "report-exception" : ""}`}
                                    >
                                        {amount(c.unallocated)}
                                    </td>
                                </tr>
                            ))}
                    </tbody>
                </table>
            </div>
            <p className="report-caption">
                Aged by days past due: Current, 1–30, 31–60, 61–90 and over 90.
                Includes opening balances and only allocations dated on or
                before the cutoff. Base balances use historical carrying values;
                differences can include unallocated journals, rounding or
                currency revaluation.
            </p>
        </>
    );
}
