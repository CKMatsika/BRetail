import React, { useState } from "react";
import axios from "axios";
type Item = {
    prices_include_vat?: boolean;
    tax_rate?: string;
    net?: string;
    tax?: string;
    total?: string;
    original?: Item;
    variant_id: number;
    quantity: string;
    unit_cost?: string;
    unit_price?: string;
    discount_percent?: string;
    sku?: string;
    description?: string;
    value?: string;
};
type Doc = {
    customer_id?: number;
    reference?: string;
    notes?: string;
    parent_id?: number;
    quote_closed?: boolean;
    net?: string;
    tax?: string;
    id: number;
    currency_snapshot?: {
        currency: string;
        original_amount: string;
        exchange_rate: string;
    };
    original_outstanding?: string;
    warehouse_id?: number;
    kind: string;
    number: string;
    party_name: string;
    supplier_name?: string;
    date: string;
    due_on?: string;
    status?: string;
    total?: string;
    outstanding?: string;
    items: Item[];
};
export type TradeData = {
    company_logo_url?: string | null;
    book: string;
    currencies?: { code: string; name: string }[];
    today: string;
    currency: string;
    purchasing: boolean;
    sales: boolean;
    warehouses: { id: number; branch_id?: number; name: string }[];
    foreign_accounts?: {
        id: number;
        branch_id: number;
        name: string;
        currency: string;
    }[];
    suppliers: {
        id: number;
        code: string;
        name: string;
        terms_days: number;
        active: boolean;
    }[];
    customers: { id: number; name: string }[];
    orders: Doc[];
    documents: Doc[];
    variants: {
        id: number;
        sku: string;
        name: string;
        selling_price: string;
    }[];
    cash_accounts: { id: number; code: string; name: string }[];
    report: {
        aging: {
            id: number;
            number: string;
            party_name: string;
            kind: string;
            due_on: string;
            outstanding: string;
            bucket: string;
        }[];
        controls: {
            role: string;
            branch_id: number;
            ledger: string;
            subledger: string;
            unallocated: string;
        }[];
    };
};
type F = {
    key: string;
    label: string;
    value?: string;
    type?: string;
    optional?: boolean;
    options?: { value: string; label: string }[];
};
const error = (e: unknown) =>
    axios.isAxiosError(e)
        ? Object.values(
              e.response?.data?.errors ?? {
                  error: e.response?.data?.message ?? e.message,
              },
          )
              .flat()
              .join(" ")
        : String(e);
function Form({
    title,
    fields,
    save,
    children,
    preview,
    onChange,
    button = "Save",
}: {
    title: string;
    fields: F[];
    save: (v: Record<string, string>, id: string) => Promise<void>;
    children?: React.ReactNode;
    preview?: (v: Record<string, string>, id: string) => Promise<void>;
    onChange?: () => void;
    button?: string;
}) {
    const [v, setV] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            fields.map((f) => [f.key, f.value ?? f.options?.[0]?.value ?? ""]),
        ),
    );
    const [id, setId] = useState(() => crypto.randomUUID());
    const [busy, setBusy] = useState(false);
    const [status, setStatus] = useState("");
    return (
        <form
            className="trade-card"
            onChange={onChange}
            onSubmit={async (e) => {
                e.preventDefault();
                if (busy) return;
                setBusy(true);
                setStatus("");
                try {
                    await save(
                        Object.fromEntries(
                            fields.map((f) => [
                                f.key,
                                v[f.key] || f.options?.[0]?.value || "",
                            ]),
                        ),
                        id,
                    );
                    setId(crypto.randomUUID());
                    setStatus("Saved successfully.");
                } catch (e) {
                    setStatus(error(e));
                } finally {
                    setBusy(false);
                }
            }}
        >
            <h3>{title}</h3>
            <div className="trade-fields">
                {fields.map((f) => (
                    <label key={f.key}>
                        {f.label}
                        {f.options ? (
                            <select
                                aria-label={f.label}
                                value={v[f.key] || f.options?.[0]?.value || ""}
                                onChange={(e) =>
                                    setV({ ...v, [f.key]: e.target.value })
                                }
                            >
                                {f.options.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <input
                                aria-label={f.label}
                                required={!f.optional}
                                type={f.type ?? "text"}
                                value={v[f.key]}
                                onChange={(e) =>
                                    setV({ ...v, [f.key]: e.target.value })
                                }
                            />
                        )}
                    </label>
                ))}
            </div>
            {children}
            <p role="status">{status}</p>
            {preview && (
                <button
                    type="button"
                    disabled={busy}
                    onClick={async () => {
                        setBusy(true);
                        setStatus("");
                        try {
                            await preview(
                                Object.fromEntries(
                                    fields.map((f) => [
                                        f.key,
                                        v[f.key] || f.options?.[0]?.value || "",
                                    ]),
                                ),
                                id,
                            );
                        } catch (e) {
                            setStatus(error(e));
                        } finally {
                            setBusy(false);
                        }
                    }}
                >
                    Preview totals
                </button>
            )}
            <button disabled={busy} type="submit">
                {busy ? "Saving…" : button}
            </button>
        </form>
    );
}

type PreviewData = {
    items: Item[];
    net?: string;
    tax?: string;
    total?: string;
    currency_snapshot?: Doc["currency_snapshot"];
};
function DocumentPreview({
    data,
    currency,
    title,
    logoUrl,
}: {
    logoUrl?: string | null;
    data: PreviewData;
    currency: string;
    title: string;
}) {
    const original = Boolean(data.currency_snapshot),
        code = data.currency_snapshot?.currency ?? currency;
    const sum = (key: "net" | "tax" | "total") =>
        original
            ? data.items
                  .reduce(
                      (n, i) => n + Number(i.original?.[key] ?? i[key] ?? 0),
                      0,
                  )
                  .toFixed(2)
            : Number(data[key] ?? 0).toFixed(2);
    return (
        <section className="trade-card" aria-label={title}>
            {logoUrl && (
                <img
                    src={logoUrl}
                    alt="Company logo"
                    style={{
                        display: "block",
                        maxWidth: 180,
                        maxHeight: 80,
                        objectFit: "contain",
                        marginBottom: 16,
                    }}
                />
            )}
            <h3>{title}</h3>
            <p>
                {code} | Review quantities, price basis and VAT before posting.
            </p>
            <div className="trade-table">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Quantity</th>
                            <th>Unit price</th>
                            <th>Price basis</th>
                            <th>Discount %</th>
                            <th>VAT %</th>
                            <th>Net</th>
                            <th>VAT</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.items.map((row, n) => {
                            const i = row.original ?? row;
                            return (
                                <tr key={n}>
                                    <td>
                                        {i.sku} {i.description}
                                    </td>
                                    <td>{i.quantity}</td>
                                    <td>{i.unit_price}</td>
                                    <td>
                                        {i.prices_include_vat
                                            ? "VAT included"
                                            : "VAT excluded"}
                                    </td>
                                    <td>{i.discount_percent}</td>
                                    <td>{i.tax_rate}</td>
                                    <td>{i.net}</td>
                                    <td>{i.tax}</td>
                                    <td>{i.total}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <p>
                Net: {code} {sum("net")} | VAT: {code} {sum("tax")} |{" "}
                <strong>
                    Total: {code} {sum("total")}
                </strong>
            </p>
            {original && (
                <p>
                    Base total: {currency} {data.total} | Rate:{" "}
                    {data.currency_snapshot?.exchange_rate}
                </p>
            )}
        </section>
    );
}
export default function Trade({
    initial,
    permissions,
}: {
    initial: TradeData;
    permissions: string[];
}) {
    const [d, setD] = useState(initial),
        [tab, setTab] = useState(
            initial.purchasing ? "suppliers" : "customers",
        ),
        [notice, setNotice] = useState(""),
        [selected, setSelected] = useState<Doc | null>(null);
    const [preview, setPreview] = useState<PreviewData | null>(null);
    const [items, setItems] = useState<Item[]>([
        {
            variant_id: initial.variants[0]?.id ?? 0,
            quantity: "1",
            unit_cost: "0",
            unit_price: initial.variants[0]?.selling_price ?? "0",
            discount_percent: "0",
        },
    ]);
    const can = (p: string) =>
        permissions.includes("*") || permissions.includes(p);
    const reload = async () => setD((await axios.get("/trade/data")).data.data);
    const post = async (path: string, data: unknown) => {
        await axios.post("/trade/" + path, data);
        await reload();
    };
    const action = async (path: string, data: unknown = {}) => {
        setNotice("");
        try {
            await post(path, data);
            setNotice("Saved successfully.");
        } catch (e) {
            setNotice(error(e));
        }
    };
    const warehouses = d.warehouses.map((w) => ({
            value: String(w.id),
            label: w.name,
        })),
        suppliers = d.suppliers
            .filter((s) => s.active)
            .map((s) => ({
                value: String(s.id),
                label: s.code + " " + s.name,
            })),
        customers = d.customers.map((c) => ({
            value: String(c.id),
            label: c.name,
        }));
    const basic: F[] = [
        { key: "date", label: "Document date", type: "date", value: d.today },
        { key: "reference", label: "Reference" },
    ];
    const choose = (doc: Doc, t: string) => {
        setSelected(doc);
        setItems(doc.items.map((i) => ({ ...i, quantity: "0" })));
        setTab(t);
    };
    const lines = (kind: "cost" | "price" | "quantity") => (
        <>
            <div className="trade-table">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            {kind !== "quantity" && (
                                <th>
                                    {kind === "cost"
                                        ? "Unit cost"
                                        : "Unit price"}
                                </th>
                            )}
                            {kind === "price" && <th>Discount %</th>}
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((i, n) => (
                            <tr key={n}>
                                <td>
                                    {kind === "quantity" ? (
                                        i.sku + " " + i.description
                                    ) : (
                                        <select
                                            aria-label={"Product " + (n + 1)}
                                            value={i.variant_id}
                                            onChange={(e) =>
                                                setItems(
                                                    items.map((v, j) =>
                                                        j === n
                                                            ? {
                                                                  ...v,
                                                                  variant_id:
                                                                      Number(
                                                                          e
                                                                              .target
                                                                              .value,
                                                                      ),
                                                                  unit_price:
                                                                      d.variants.find(
                                                                          (v) =>
                                                                              v.id ===
                                                                              Number(
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                              ),
                                                                      )
                                                                          ?.selling_price ??
                                                                      "0",
                                                              }
                                                            : v,
                                                    ),
                                                )
                                            }
                                        >
                                            {d.variants.map((v) => (
                                                <option key={v.id} value={v.id}>
                                                    {v.sku} {v.name}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </td>
                                <td>
                                    <input
                                        aria-label={"Quantity " + (n + 1)}
                                        value={i.quantity}
                                        onChange={(e) =>
                                            setItems(
                                                items.map((v, j) =>
                                                    j === n
                                                        ? {
                                                              ...v,
                                                              quantity:
                                                                  e.target
                                                                      .value,
                                                          }
                                                        : v,
                                                ),
                                            )
                                        }
                                    />
                                </td>
                                {kind !== "quantity" && (
                                    <td>
                                        <input
                                            aria-label={
                                                (kind === "cost"
                                                    ? "Unit cost "
                                                    : "Unit price ") +
                                                (n + 1)
                                            }
                                            value={
                                                kind === "cost"
                                                    ? i.unit_cost
                                                    : i.unit_price
                                            }
                                            onChange={(e) =>
                                                setItems(
                                                    items.map((v, j) =>
                                                        j === n
                                                            ? {
                                                                  ...v,
                                                                  [kind ===
                                                                  "cost"
                                                                      ? "unit_cost"
                                                                      : "unit_price"]:
                                                                      e.target
                                                                          .value,
                                                              }
                                                            : v,
                                                    ),
                                                )
                                            }
                                        />
                                    </td>
                                )}
                                {kind === "price" && (
                                    <td>
                                        <input
                                            aria-label={"Discount " + (n + 1)}
                                            value={i.discount_percent}
                                            onChange={(e) =>
                                                setItems(
                                                    items.map((v, j) =>
                                                        j === n
                                                            ? {
                                                                  ...v,
                                                                  discount_percent:
                                                                      e.target
                                                                          .value,
                                                              }
                                                            : v,
                                                    ),
                                                )
                                            }
                                        />
                                    </td>
                                )}
                                <td>
                                    {kind !== "quantity" && (
                                        <button
                                            type="button"
                                            disabled={items.length === 1}
                                            onClick={() => (
                                                setPreview(null),
                                                setItems(
                                                    items.filter(
                                                        (_, j) => j !== n,
                                                    ),
                                                )
                                            )}
                                        >
                                            Remove
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {kind !== "quantity" && (
                <button
                    type="button"
                    onClick={() => {
                        setPreview(null);
                        setItems([
                            ...items,
                            {
                                variant_id: d.variants[0]?.id ?? 0,
                                quantity: "1",
                                unit_cost: "0",
                                unit_price: d.variants[0]?.selling_price ?? "0",
                                discount_percent: "0",
                            },
                        ]);
                    }}
                >
                    Add item
                </button>
            )}
        </>
    );
    const quotePayload = (v: Record<string, string>, id: string) => ({
        ...v,
        prices_include_vat: v.prices_include_vat === "true",
        request_id: id,
        items: items.map((i) => ({
            variant_id: i.variant_id,
            quantity: i.quantity,
            unit_price: i.unit_price,
            discount_percent: i.discount_percent,
        })),
    });
    const editQuote = (q: Doc, copy = false) => {
        setPreview(null);
        setSelected(
            copy
                ? {
                      ...q,
                      id: 0,
                      reference: "",
                      notes:
                          "Correction for invoice " +
                          q.number +
                          ". The original invoice remains posted; reverse eligible unpaid items before posting a replacement.",
                      date: d.today,
                      due_on: d.today,
                  }
                : q,
        );
        setItems(q.items.map((i) => ({ ...i, ...(i.original ?? {}) })));
        setTab("quotes");
    };
    const quantities = () =>
        items
            .filter((i) => i.quantity !== "" && Number(i.quantity) !== 0)
            .map((i) => ({ variant_id: i.variant_id, quantity: i.quantity }));
    const list = (docs: Doc[], buttons: (doc: Doc) => React.ReactNode) => (
        <div className="trade-card trade-table">
            <table>
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>Party</th>
                        <th>Date</th>
                        <th>Status / total</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {docs.map((doc) => (
                        <tr key={doc.id}>
                            <td>{doc.number}</td>
                            <td>{doc.party_name ?? doc.supplier_name}</td>
                            <td>{doc.date}</td>
                            <td>
                                {doc.status ?? doc.total}
                                {doc.currency_snapshot && (
                                    <small>
                                        {doc.currency_snapshot.currency}{" "}
                                        {doc.currency_snapshot.original_amount}{" "}
                                        · Rate{" "}
                                        {doc.currency_snapshot.exchange_rate}
                                        {doc.original_outstanding != null &&
                                            " · Open " +
                                                doc.original_outstanding}
                                    </small>
                                )}
                                {doc.outstanding != null && (
                                    <small>Open: {doc.outstanding}</small>
                                )}
                            </td>
                            <td>
                                <a
                                    target="_blank"
                                    rel="noreferrer"
                                    href={
                                        "/trade/print/" +
                                        (doc.kind ? "document" : "order") +
                                        "/" +
                                        doc.id
                                    }
                                >
                                    Print
                                </a>
                                {buttons(doc)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {!docs.length && <p>No documents yet.</p>}
        </div>
    );
    return (
        <div className="trade">
            <style>{`.trade{max-width:1400px;min-width:0}.trade-card{background:var(--surface,#fff);border:1px solid #d7e1da;border-radius:12px;padding:20px;margin:16px 0}.trade h2{font-size:26px}.trade h3{font-size:19px;margin-bottom:16px}.trade-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}.trade label{display:flex;flex-direction:column;gap:7px;font-size:13px}.trade input,.trade select{padding:9px;border:1px solid #bdcec3;border-radius:6px;background:var(--surface,#fff);color:inherit;width:100%;min-width:90px}.trade button,.trade a{padding:8px 12px;margin:4px;border:1px solid #abc5b3;border-radius:6px;display:inline-block;cursor:pointer;background:var(--surface,#fff);color:inherit}.trade button[type=submit],.trade button.selected{background:#236744;color:white}.trade button:disabled{opacity:.5}.trade-tabs{display:flex;flex-wrap:wrap;gap:5px}.trade-table{overflow:auto}.trade table{width:100%;border-collapse:collapse;font-size:13px}.trade th,.trade td{padding:12px;border-bottom:1px solid #e0e7e2;text-align:left;white-space:nowrap}.trade small{display:block}.trade-banner{padding:15px;background:#eef5ef;border-radius:8px}.trade [role=status]{overflow-wrap:anywhere}@media(max-width:600px){.trade-card{padding:12px}.trade-fields{grid-template-columns:1fr}}`}</style>
            <h2>Purchasing & back office</h2>
            <p className="trade-banner">
                {d.book.toUpperCase()} BOOK · {d.currency} · Receipts, invoices
                and credit balances
            </p>
            {notice && <p role="alert">{notice}</p>}
            <div className="trade-tabs">
                {[
                    ...(d.purchasing
                        ? ["suppliers", "orders", "receipts", "bills"]
                        : []),
                    ...(d.sales ? ["customers", "quotes", "invoices"] : []),
                    "aging",
                    "openings",
                ].map((t) => (
                    <button
                        className={tab === t ? "selected" : ""}
                        key={t}
                        onClick={() => {
                            setTab(t);
                            setSelected(null);
                            setPreview(null);
                            setItems([
                                {
                                    variant_id: d.variants[0]?.id ?? 0,
                                    quantity: "1",
                                    unit_cost: "0",
                                    unit_price:
                                        d.variants[0]?.selling_price ?? "0",
                                    discount_percent: "0",
                                },
                            ]);
                        }}
                    >
                        {t[0].toUpperCase() + t.slice(1)}
                    </button>
                ))}
            </div>
            {tab === "suppliers" && (
                <>
                    {can("purchasing.configure") && (
                        <Form
                            title="Create supplier"
                            fields={[
                                { key: "code", label: "Supplier code" },
                                { key: "name", label: "Supplier name" },
                                {
                                    key: "contact_name",
                                    label: "Contact name",
                                    optional: true,
                                },
                                {
                                    key: "email",
                                    label: "Email",
                                    type: "email",
                                    optional: true,
                                },
                                {
                                    key: "phone",
                                    label: "Phone",
                                    optional: true,
                                },
                                {
                                    key: "address",
                                    label: "Address",
                                    optional: true,
                                },
                                {
                                    key: "terms_days",
                                    label: "Payment terms (days)",
                                    value: "30",
                                },
                            ]}
                            save={(v) =>
                                post("suppliers", { ...v, active: true })
                            }
                        />
                    )}
                    <div className="trade-card">
                        {d.suppliers.map((s) => (
                            <p key={s.id}>
                                {s.code} · {s.name} · {s.terms_days} days ·{" "}
                                {s.active ? "Active" : "Inactive"}
                                {can("purchasing.configure") && (
                                    <button
                                        onClick={() =>
                                            void action("suppliers/" + s.id, {
                                                code: s.code,
                                                name: s.name,
                                                terms_days: s.terms_days,
                                                active: !s.active,
                                            })
                                        }
                                    >
                                        {s.active ? "Deactivate" : "Activate"}
                                    </button>
                                )}
                            </p>
                        ))}
                    </div>
                </>
            )}
            {tab === "orders" && (
                <>
                    {can("purchasing.create") && (
                        <Form
                            title="Create purchase order"
                            fields={[
                                {
                                    key: "supplier_id",
                                    label: "Supplier",
                                    options: suppliers,
                                },
                                {
                                    key: "warehouse_id",
                                    label: "Receiving warehouse",
                                    options: warehouses,
                                },
                                {
                                    key: "currency",
                                    label: "Order currency",
                                    options: [
                                        {
                                            value: d.currency,
                                            label: d.currency + " · Base",
                                        },
                                        ...(d.currencies ?? [])
                                            .filter(
                                                (c) => c.code !== d.currency,
                                            )
                                            .map((c) => ({
                                                value: c.code,
                                                label: c.code + " · " + c.name,
                                            })),
                                    ],
                                },
                                {
                                    key: "date",
                                    label: "Order date",
                                    type: "date",
                                    value: d.today,
                                },
                                {
                                    key: "expected_on",
                                    label: "Expected delivery",
                                    type: "date",
                                    optional: true,
                                },
                                {
                                    key: "notes",
                                    label: "Notes",
                                    optional: true,
                                },
                            ]}
                            save={(v, id) =>
                                post("orders", {
                                    ...v,
                                    expected_on: v.expected_on || null,
                                    request_id: id,
                                    items: items.map((i) => ({
                                        variant_id: i.variant_id,
                                        quantity: i.quantity,
                                        unit_cost: i.unit_cost,
                                    })),
                                })
                            }
                            button="Create order"
                        >
                            {lines("cost")}
                        </Form>
                    )}
                    {list(d.orders, (o) => (
                        <>
                            {o.status === "draft" &&
                                can("purchasing.approve") && (
                                    <button
                                        onClick={() =>
                                            void action("approve/" + o.id)
                                        }
                                    >
                                        Approve
                                    </button>
                                )}
                            {[
                                "draft",
                                "approved",
                                "partially_received",
                            ].includes(o.status ?? "") &&
                                can("purchasing.create") && (
                                    <button
                                        onClick={() =>
                                            void action("cancel/" + o.id)
                                        }
                                    >
                                        Cancel
                                    </button>
                                )}
                            {["approved", "partially_received"].includes(
                                o.status ?? "",
                            ) &&
                                can("purchasing.receive") && (
                                    <button
                                        onClick={() => choose(o, "receipts")}
                                    >
                                        Receive goods
                                    </button>
                                )}
                        </>
                    ))}
                </>
            )}
            {tab === "receipts" && (
                <>
                    {selected && can("purchasing.receive") && (
                        <Form
                            key={selected.id}
                            title={"Receive " + selected.number}
                            fields={basic}
                            save={(v, id) =>
                                post("receipts/" + selected.id, {
                                    ...v,
                                    request_id: id,
                                    items: quantities(),
                                })
                            }
                            button="Post goods receipt"
                        >
                            <p>
                                Enter delivered quantities only. Receipt stock
                                value uses the order's historical base carrying
                                cost.
                            </p>
                            {lines("quantity")}
                        </Form>
                    )}
                    {list(
                        d.documents.filter((x) => x.kind === "receipt"),
                        (r) =>
                            can("purchasing.bill") && (
                                <button onClick={() => choose(r, "bills")}>
                                    Match supplier bill
                                </button>
                            ),
                    )}
                </>
            )}
            {tab === "bills" && (
                <>
                    {selected?.kind === "receipt" && can("purchasing.bill") && (
                        <Form
                            key={selected.id}
                            title={"Bill against " + selected.number}
                            fields={[
                                ...basic,
                                {
                                    key: "due_on",
                                    label: "Payment due",
                                    type: "date",
                                    value: d.today,
                                },
                                {
                                    key: "tax_rate",
                                    label: "Input tax %",
                                    value: "0",
                                },
                            ]}
                            save={(v, id) =>
                                post("bills/" + selected.id, {
                                    ...v,
                                    request_id: id,
                                    items: quantities(),
                                })
                            }
                            button="Post supplier bill"
                        >
                            <p>
                                Reference is the supplier invoice number.
                                Billing clears received inventory accruals and
                                does not receive stock again. The bill inherits
                                the order currency and stores its approved rate
                                for the bill date.
                            </p>
                            {lines("quantity")}
                        </Form>
                    )}
                    {list(
                        d.documents.filter((x) =>
                            ["bill", "opening_payable"].includes(x.kind),
                        ),
                        (b) =>
                            can("purchasing.pay") &&
                            Number(b.outstanding) > 0 && (
                                <button onClick={() => setSelected(b)}>
                                    Pay bill
                                </button>
                            ),
                    )}
                </>
            )}
            {tab === "customers" && (
                <>
                    {can("sales.quote") && (
                        <Form
                            title="Create customer"
                            fields={[
                                { key: "name", label: "Customer name" },
                                {
                                    key: "email",
                                    label: "Customer email",
                                    type: "email",
                                    optional: true,
                                },
                                {
                                    key: "phone",
                                    label: "Customer phone",
                                    optional: true,
                                },
                            ]}
                            save={(v) => post("customers", v)}
                        />
                    )}
                    <div className="trade-card">
                        {d.customers.map((c) => (
                            <p key={c.id}>{c.name}</p>
                        ))}
                    </div>
                    {can("sales.configure") && (
                        <Form
                            title="Customer credit settings"
                            fields={[
                                {
                                    key: "customer_id",
                                    label: "Credit customer",
                                    options: customers,
                                },
                                {
                                    key: "credit_limit",
                                    label: "Credit limit",
                                    value: "0",
                                },
                                {
                                    key: "terms_days",
                                    label: "Credit terms (days)",
                                    value: "30",
                                },
                                {
                                    key: "active",
                                    label: "Credit status",
                                    options: [
                                        { value: "true", label: "Active" },
                                        { value: "false", label: "Blocked" },
                                    ],
                                },
                            ]}
                            save={(v) =>
                                post("credits", {
                                    ...v,
                                    active: v.active === "true",
                                })
                            }
                        />
                    )}
                </>
            )}
            {tab === "quotes" && (
                <>
                    {can("sales.quote") && (
                        <Form
                            key={"quote-" + (selected?.id ?? "new")}
                            title={
                                selected?.id
                                    ? "Edit quotation " + selected.number
                                    : "Create sales quotation"
                            }
                            fields={[
                                {
                                    key: "currency",
                                    label: "Quotation currency",
                                    value: selected?.currency_snapshot
                                        ?.currency,
                                    options: [
                                        {
                                            value: d.currency,
                                            label: d.currency + " | Base",
                                        },
                                        ...(d.currencies ?? [])
                                            .filter(
                                                (c) => c.code !== d.currency,
                                            )
                                            .map((c) => ({
                                                value: c.code,
                                                label: c.code + " | " + c.name,
                                            })),
                                    ],
                                },
                                {
                                    key: "customer_id",
                                    label: "Customer",
                                    value: selected?.customer_id
                                        ? String(selected.customer_id)
                                        : undefined,
                                    options: customers,
                                },
                                {
                                    key: "warehouse_id",
                                    label: "Selling warehouse",
                                    value: selected?.warehouse_id
                                        ? String(selected.warehouse_id)
                                        : undefined,
                                    options: warehouses,
                                },
                                ...basic.map((f) => ({
                                    ...f,
                                    value: selected
                                        ? f.key === "date"
                                            ? selected.date
                                            : (selected.reference ?? "")
                                        : f.value,
                                })),
                                {
                                    key: "due_on",
                                    label: "Quote expiry",
                                    type: "date",
                                    value: selected?.due_on ?? d.today,
                                },
                                {
                                    key: "notes",
                                    label: "Quote notes",
                                    optional: true,
                                    value: selected?.notes ?? "",
                                },
                                {
                                    key: "prices_include_vat",
                                    label: "Unit prices",
                                    value: String(
                                        selected?.items[0]
                                            ?.prices_include_vat ?? false,
                                    ),
                                    options: [
                                        {
                                            value: "false",
                                            label: "Exclude VAT: add VAT on top",
                                        },
                                        {
                                            value: "true",
                                            label: "Include VAT: extract VAT from price",
                                        },
                                    ],
                                },
                            ]}
                            onChange={() => setPreview(null)}
                            preview={async (v, id) =>
                                setPreview(
                                    (
                                        await axios.post(
                                            "/trade/quote-preview" +
                                                (selected?.id
                                                    ? "/" + selected.id
                                                    : ""),
                                            quotePayload(v, id),
                                        )
                                    ).data.data,
                                )
                            }
                            save={async (v, id) => {
                                await post(
                                    "quotes" +
                                        (selected?.id ? "/" + selected.id : ""),
                                    quotePayload(v, id),
                                );
                                setSelected(null);
                                setPreview(null);
                                setNotice("Quotation saved successfully.");
                            }}
                            button={
                                selected?.id
                                    ? "Save revised quotation"
                                    : "Save quotation"
                            }
                        >
                            <p>
                                Quotes do not reserve stock. Select whether
                                entered prices include VAT. Credit and available
                                stock are checked when invoicing.
                            </p>
                            {selected?.id === 0 && (
                                <p>
                                    This creates a new quotation. The original
                                    invoice remains posted. Reverse eligible
                                    unpaid items before posting a replacement
                                    invoice to avoid duplicate charges and stock
                                    deductions.
                                </p>
                            )}
                            {Boolean(selected?.id) && (
                                <p>
                                    Saving creates a revision; the previous
                                    version remains in history and cannot be
                                    invoiced.
                                </p>
                            )}
                            {lines("price")}
                            {preview && (
                                <DocumentPreview
                                    logoUrl={d.company_logo_url}
                                    data={preview}
                                    currency={d.currency}
                                    title="Quotation / invoice totals preview"
                                />
                            )}
                            {selected && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSelected(null);
                                        setPreview(null);
                                    }}
                                >
                                    Cancel editing
                                </button>
                            )}
                        </Form>
                    )}
                    {list(
                        d.documents.filter((x) => x.kind === "quote"),
                        (q) => (
                            <>
                                {can("sales.quote") && !q.quote_closed && (
                                    <button onClick={() => editQuote(q)}>
                                        Edit quotation
                                    </button>
                                )}
                                {q.quote_closed ? (
                                    <span>Revised / invoiced</span>
                                ) : (
                                    can("sales.invoice") && (
                                        <button
                                            onClick={() => {
                                                setSelected(q);
                                                setPreview(null);
                                                setTab("invoices");
                                            }}
                                        >
                                            Accept and invoice
                                        </button>
                                    )
                                )}
                            </>
                        ),
                    )}
                </>
            )}
            {tab === "invoices" && (
                <>
                    {selected?.kind === "quote" && can("sales.invoice") && (
                        <Form
                            key={selected.id}
                            title={"Invoice accepted quote " + selected.number}
                            fields={[
                                {
                                    key: "date",
                                    label: "Invoice date",
                                    type: "date",
                                    value: d.today,
                                },
                            ]}
                            save={(v, id) =>
                                post("invoices/" + selected.id, {
                                    ...v,
                                    accepted: true,
                                    request_id: id,
                                })
                            }
                            button="Accept quote and post credit invoice"
                        >
                            <p>
                                Confirm customer acceptance. Posting consumes
                                stock and increases customer credit outstanding.
                                This preview is not a posted invoice; stock,
                                credit and expiry are checked on posting.
                            </p>
                            <DocumentPreview
                                logoUrl={d.company_logo_url}
                                data={selected}
                                currency={d.currency}
                                title={"Invoice preview | " + selected.number}
                            />
                            {can("sales.quote") && (
                                <button
                                    type="button"
                                    onClick={() => editQuote(selected)}
                                >
                                    Edit quotation before invoicing
                                </button>
                            )}
                        </Form>
                    )}
                    {list(
                        d.documents.filter((x) =>
                            ["invoice", "opening_receivable"].includes(x.kind),
                        ),
                        (i) => (
                            <>
                                {i.kind === "invoice" && (
                                    <>
                                        <button
                                            onClick={() => {
                                                setPreview(i);
                                                setSelected(null);
                                            }}
                                        >
                                            Preview invoice
                                        </button>
                                        {can("sales.quote") && (
                                            <button
                                                onClick={() =>
                                                    editQuote(i, true)
                                                }
                                            >
                                                Create corrected quote
                                            </button>
                                        )}
                                    </>
                                )}
                                {can("sales.receive") &&
                                    Number(i.outstanding) > 0 && (
                                        <button onClick={() => setSelected(i)}>
                                            Receive payment
                                        </button>
                                    )}
                                {can("sales.return") &&
                                    i.kind === "invoice" &&
                                    Number(i.outstanding) > 0 && (
                                        <button
                                            onClick={() => choose(i, "returns")}
                                        >
                                            Return unpaid items
                                        </button>
                                    )}
                            </>
                        ),
                    )}
                </>
            )}
            {tab === "invoices" && preview && (
                <>
                    <DocumentPreview
                        logoUrl={d.company_logo_url}
                        data={preview}
                        currency={d.currency}
                        title="Invoice preview"
                    />
                    <button onClick={() => setPreview(null)}>
                        Close preview
                    </button>
                </>
            )}
            {["bills", "invoices"].includes(tab) &&
                selected &&
                [
                    "bill",
                    "invoice",
                    "opening_payable",
                    "opening_receivable",
                ].includes(selected.kind) &&
                !selected.currency_snapshot && (
                    <Form
                        key={"settle" + selected.id}
                        title={"Settle " + selected.number}
                        fields={[
                            ...basic,
                            {
                                key: "account_id",
                                label: "Settlement cash / bank account",
                                options: d.cash_accounts.map((a) => ({
                                    value: String(a.id),
                                    label: a.code + " " + a.name,
                                })),
                            },
                            {
                                key: "amount",
                                label: "Payment amount",
                                value: (selected.outstanding ?? "0").replace(
                                    /(\.\d{2})00$/,
                                    "$1",
                                ),
                            },
                        ]}
                        save={(v, id) =>
                            post("settlements/" + selected.id, {
                                ...v,
                                request_id: id,
                            })
                        }
                        button="Post payment"
                    />
                )}
            {["bills", "invoices"].includes(tab) &&
                selected &&
                [
                    "bill",
                    "invoice",
                    "opening_payable",
                    "opening_receivable",
                ].includes(selected.kind) &&
                can("currencies.post") &&
                (d.foreign_accounts ?? []).some(
                    (a) =>
                        a.branch_id ===
                        d.warehouses.find((w) => w.id === selected.warehouse_id)
                            ?.branch_id,
                ) && (
                    <Form
                        key={"foreign-settle" + selected.id}
                        title={"Foreign currency settlement " + selected.number}
                        fields={[
                            ...basic,
                            {
                                key: "foreign_account_id",
                                label: "Foreign settlement account",
                                options: (d.foreign_accounts ?? [])
                                    .filter(
                                        (a) =>
                                            a.branch_id ===
                                                d.warehouses.find(
                                                    (w) =>
                                                        w.id ===
                                                        selected.warehouse_id,
                                                )?.branch_id &&
                                            (!selected.currency_snapshot ||
                                                a.currency ===
                                                    selected.currency_snapshot
                                                        .currency),
                                    )
                                    .map((a) => ({
                                        value: String(a.id),
                                        label: a.name + " · " + a.currency,
                                    })),
                            },
                            {
                                key: "original_amount",
                                label: "Amount in account currency",
                            },
                        ]}
                        save={(v, id) =>
                            post("settlements/" + selected.id, {
                                ...v,
                                request_id: id,
                            })
                        }
                        button="Post foreign payment"
                    >
                        <p>
                            {selected.currency_snapshot
                                ? "Use the invoice currency; its historical carrying value is allocated and settlement-date exchange differences are recorded."
                                : "The approved rate for the payment date converts this amount to " +
                                  d.currency +
                                  ". The result must be exact base cents and stay within the open balance."}
                        </p>
                    </Form>
                )}
            {tab === "returns" && selected && (
                <Form
                    key={selected.id}
                    title={"Credit return against " + selected.number}
                    fields={[
                        ...basic,
                        { key: "notes", label: "Return reason" },
                    ]}
                    save={(v, id) =>
                        post("returns/" + selected.id, {
                            ...v,
                            request_id: id,
                            items: quantities(),
                        })
                    }
                    button="Post credit return"
                >
                    <p>
                        Returns reduce unpaid credit and restore original stock
                        cost. This workflow does not issue cash refunds for paid
                        invoices.
                    </p>
                    {lines("quantity")}
                </Form>
            )}
            {tab === "aging" && (
                <>
                    <div className="trade-card trade-table">
                        <h3>Outstanding balances and aging</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Party</th>
                                    <th>Due</th>
                                    <th>Open</th>
                                    <th>Age</th>
                                </tr>
                            </thead>
                            <tbody>
                                {d.report.aging.map((a) => (
                                    <tr key={a.id}>
                                        <td>{a.number}</td>
                                        <td>{a.party_name}</td>
                                        <td>{a.due_on}</td>
                                        <td>{a.outstanding}</td>
                                        <td>{a.bucket}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="trade-card">
                        <h3>Control account reconciliation</h3>
                        {d.report.controls.map((c) => (
                            <p key={c.role + c.branch_id}>
                                {c.role} · Branch {c.branch_id} · Ledger{" "}
                                {c.ledger} · Party balances {c.subledger} ·
                                Unallocated {c.unallocated}
                            </p>
                        ))}
                    </div>
                </>
            )}
            {tab === "openings" && (
                <>
                    <p className="trade-banner">
                        Assign existing verified control-account balances to
                        parties. This creates an opening subledger record and
                        does not post the ledger again.
                    </p>
                    {d.purchasing && can("purchasing.bill") && (
                        <Form
                            title="Allocate opening supplier balance"
                            fields={[
                                {
                                    key: "party_id",
                                    label: "Opening supplier",
                                    options: suppliers,
                                },
                                {
                                    key: "warehouse_id",
                                    label: "Opening branch warehouse",
                                    options: warehouses,
                                },
                                ...basic,
                                {
                                    key: "due_on",
                                    label: "Opening due date",
                                    type: "date",
                                    value: d.today,
                                },
                                {
                                    key: "amount",
                                    label: "Opening payable amount",
                                },
                            ]}
                            save={(v, id) =>
                                post("openings", {
                                    ...v,
                                    request_id: id,
                                    kind: "opening_payable",
                                })
                            }
                        />
                    )}{" "}
                    {d.sales && can("sales.configure") && (
                        <Form
                            title="Allocate opening customer balance"
                            fields={[
                                {
                                    key: "party_id",
                                    label: "Opening customer",
                                    options: customers,
                                },
                                {
                                    key: "warehouse_id",
                                    label: "Opening branch warehouse",
                                    options: warehouses,
                                },
                                ...basic,
                                {
                                    key: "due_on",
                                    label: "Opening due date",
                                    type: "date",
                                    value: d.today,
                                },
                                {
                                    key: "amount",
                                    label: "Opening receivable amount",
                                },
                            ]}
                            save={(v, id) =>
                                post("openings", {
                                    ...v,
                                    request_id: id,
                                    kind: "opening_receivable",
                                })
                            }
                        />
                    )}
                </>
            )}
        </div>
    );
}
