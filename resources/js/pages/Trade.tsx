import { AgingReport, AgingSnapshot } from "../components/ReportViews";
import React, { useState } from "react";
import axios from "axios";
import TradeShareModal from "../components/TradeShareModal";
import "./Trade.css";
type Item = {
    prices_include_vat?: boolean;
    tax_rate?: string;
    net?: string;
    tax?: string;
    total?: string;
    original?: Item;
    variant_id?: number | null;
    account_id?: number | null;
    quantity: string;
    unit_cost?: string;
    unit_price?: string;
    discount_percent?: string;
    sku?: string;
    description?: string;
    value?: string;
};
type Doc = {
    invoice_editable?: boolean;
    version?: number;
    has_history?: boolean;
    superseded?: boolean;
    supplier_id?: number;
    order_id?: number;
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
    customer_email?: string;
    customer_phone?: string;
    items: Item[];
};
type Customer = {
    id: number;
    name: string;
    email?: string | null;
    phone?: string | null;
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
    customers: Customer[];
    orders: Doc[];
    documents: Doc[];
    variants: {
        product?: { item_type?: "stock" | "service" };
        id: number;
        sku: string;
        name: string;
        selling_price: string;
    }[];
    cash_accounts: { id: number; code: string; name: string }[];
    accounts?: { id: number; code: string; name: string; type: string }[];
    report: Partial<AgingSnapshot> & {
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
    disabled?: boolean;
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
function TradeForm({
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
                                disabled={f.disabled}
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
function Form(props: Parameters<typeof TradeForm>[0]) {
    const creation =
        props.title.startsWith("Create ") ||
        props.title === "Customer credit settings";
    return creation ? (
        <details className="trade-create">
            <summary>
                {props.title === "Customer credit settings"
                    ? "Manage customer credit"
                    : "+ " + props.title}
            </summary>
            <TradeForm {...props} />
        </details>
    ) : (
        <TradeForm {...props} />
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
    section,
    onAreaChange,
    showBackoffice = true,
}: {
    onAreaChange?: (area: string) => void;
    showBackoffice?: boolean;
    section?: string;
    initial: TradeData;
    permissions: string[];
}) {
    const initialArea =
        section === "backoffice"
            ? "backoffice"
            : section === "sales" && initial.sales
              ? "sales"
              : section === "purchasing" && initial.purchasing
                ? "purchasing"
                : initial.sales
                  ? "sales"
                  : "purchasing";
    const initialVariant = initial.variants.find(
        (v) =>
            initialArea !== "purchasing" || v.product?.item_type !== "service",
    );
    const [area, setArea] = useState(initialArea);
    const [party, setParty] = useState("");
    const [editingCustomer, setEditingCustomer] = useState<Customer | null>(
        null,
    );
    const [viewingCustomer, setViewingCustomer] = useState<Customer | null>(
        null,
    );
    const [deletingCustomer, setDeletingCustomer] = useState<Customer | null>(
        null,
    );
    const [deleting, setDeleting] = useState(false);
    const [search, setSearch] = useState("");
    const [documentStatus, setDocumentStatus] = useState("all");
    const [d, setD] = useState(initial),
        [tab, setTab] = useState(
            initialArea === "backoffice"
                ? "aging"
                : initialArea === "sales"
                  ? "customers"
                  : "suppliers",
        ),
        [notice, setNotice] = useState(""),
        [selected, setSelected] = useState<Doc | null>(null),
        [directBill, setDirectBill] = useState(false);
    const [history, setHistory] = useState<{
        number: string;
        versions: Doc[];
        details: { reference: string; notes?: string; created_at: string }[];
    } | null>(null);
    const [preview, setPreview] = useState<PreviewData | null>(null);
    const [shareDoc, setShareDoc] = useState<Doc | null>(null);
    const [paynowLoading, setPaynowLoading] = useState<number | null>(null);

    const handlePaynow = async (docId: number) => {
        try {
            setPaynowLoading(docId);
            setNotice("Initiating Paynow payment session...");
            const res = await axios.post(`/portal/document/${docId}/paynow`, {}, {
                headers: { Accept: "application/json" }
            });
            if (res.data?.browser_url) {
                window.open(res.data.browser_url, "_blank");
                setNotice("Paynow checkout opened in a new tab. Complete payment on Paynow.");
            } else {
                setNotice("Unable to initiate Paynow checkout.");
            }
        } catch (err: any) {
            setNotice(err.response?.data?.message || "Failed to initiate Paynow payment.");
        } finally {
            setPaynowLoading(null);
        }
    };
    const [items, setItems] = useState<Item[]>([
        {
            variant_id: initialVariant?.id ?? 0,
            quantity: "1",
            unit_cost: "0",
            unit_price: initialVariant?.selling_price ?? "0",
            discount_percent: "0",
        },
    ]);
    const can = (p: string) =>
        permissions.includes("*") || permissions.includes(p);
    const reload = async () => setD((await axios.get("/trade/data")).data.data);
    const post = async (path: string, data: unknown) => {
        await axios.post("/trade/" + path, data);
        await reload();
        setNotice("Saved successfully.");
        setSelected(null);
        setPreview(null);
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
    const groups = {
        sales: {
            title: "Customers & sales",
            description:
                "Manage every customer relationship, from quotation to payment.",
            tabs: [
                "customers",
                "quotes",
                "invoices",
                "customer_receipts",
                "returns",
            ],
            steps: ["quotes", "invoices", "customer_receipts"],
        },
        purchasing: {
            title: "Procurement",
            description:
                "Keep suppliers, orders, deliveries and payments in one place.",
            tabs: [
                "suppliers",
                "orders",
                "receipts",
                "bills",
                "supplier_payments",
            ],
            steps: ["orders", "receipts", "bills", "supplier_payments"],
        },
        backoffice: {
            title: "Back office",
            description:
                "Review outstanding balances, reconcile accounts and allocate opening balances.",
            tabs: ["aging", "openings"],
            steps: [],
        },
    };
    const group = groups[area as keyof typeof groups];
    const labels: Record<string, string> = {
        customers: "Customers",
        quotes: "Quotations",
        invoices: "Invoices",
        customer_receipts: "Customer receipts",
        returns: "Credit returns",
        suppliers: "Suppliers",
        orders: "Purchase orders",
        receipts: "Goods received",
        bills: "Supplier invoices",
        supplier_payments: "Supplier payments",
        aging: "Aging & reconciliation",
        openings: "Opening balances",
        invoice_edit: "Edit invoice",
        invoice_details: "Edit invoice details",
    };
    const descriptions: Record<string, string> = {
        customers:
            "Select a customer to view their quotations, invoices, receipts and returns.",
        suppliers:
            "Select a supplier to view their purchase orders, deliveries, invoices and payments.",
        invoice_edit:
            "Update this unpaid invoice under the same number. Stock and accounting changes are applied together.",
        invoice_details:
            "Change reference or notes without affecting posted amounts, payments or stock.",
        quotes: "Prepare a quotation, confirm customer acceptance, then create the invoice.",
        invoices:
            "Create invoices from accepted quotations. Select an unpaid invoice to receive payment.",
        customer_receipts:
            "Payments received against customer invoices. Start a receipt from an unpaid invoice.",
        returns:
            "Review credit returns, or select unpaid items from an invoice to create a return.",
        orders: "Create a purchase order, obtain approval, then receive the delivered goods.",
        receipts:
            "Receive goods from an approved purchase order, then match the supplier invoice.",
        bills: "Match the supplier invoice to a goods receipt, then pay the outstanding balance.",
        supplier_payments:
            "Payments made against supplier invoices. Start a payment from an unpaid supplier invoice.",
        aging: "Review customer and supplier balances alongside their control accounts.",
        openings:
            "Allocate verified opening balances to customers and suppliers.",
    };
    const navigate = (next: string) => {
        setEditingCustomer(null);
        setViewingCustomer(null);
        setDeletingCustomer(null);
        setHistory(null);
        setTab(next);
        setSelected(null);
        setPreview(null);
        setNotice("");
        setDocumentStatus("all");
        const firstVariant = d.variants.find(
            (v) => next !== "orders" || v.product?.item_type !== "service",
        );
        setItems([
            {
                variant_id: firstVariant?.id ?? 0,
                quantity: "1",
                unit_cost: "0",
                unit_price: firstVariant?.selling_price ?? "0",
                discount_percent: "0",
            },
        ]);
    };
    const switchArea = (next: string) => {
        if (onAreaChange && next !== area) {
            onAreaChange(next);
            return;
        }
        setArea(next);
        setParty("");
        setSearch("");
        setDocumentStatus("all");
        navigate(
            next === "sales"
                ? "customers"
                : next === "purchasing"
                  ? "suppliers"
                  : "aging",
        );
    };
    const partyMatches = (doc: Doc) =>
        !party ||
        String(area === "sales" ? doc.customer_id : doc.supplier_id) === party;
    const matches = (doc: Doc) =>
        partyMatches(doc) &&
        [
            doc.number,
            doc.party_name,
            doc.supplier_name,
            doc.reference,
            doc.status,
        ]
            .join(" ")
            .toLowerCase()
            .includes(search.toLowerCase()) &&
        (documentStatus === "all" ||
            (documentStatus === "outstanding"
                ? Number(doc.outstanding) > 0
                : doc.status === documentStatus));
    const partyName =
        area === "sales"
            ? d.customers.find((c) => String(c.id) === party)?.name
            : d.suppliers.find((s) => String(s.id) === party)?.name;
    const related = (doc: Doc) =>
        (doc.parent_id
            ? d.documents.find((x) => x.id === doc.parent_id)
            : null) ??
        (doc.order_id ? d.orders.find((x) => x.id === doc.order_id) : null);
    const docTab = (doc: Doc) =>
        (
            ({
                quote: "quotes",
                invoice: "invoices",
                receipt: "receipts",
                bill: "bills",
                customer_receipt: "customer_receipts",
                supplier_payment: "supplier_payments",
                credit_return: "returns",
                opening_receivable: "invoices",
                opening_payable: "bills",
            }) as Record<string, string>
        )[doc.kind] ?? "orders";
    const tabKinds: Record<string, string[]> = {
        quotes: ["quote"],
        invoices: ["invoice", "opening_receivable"],
        receipts: ["receipt"],
        bills: ["bill", "opening_payable"],
        customer_receipts: ["customer_receipt"],
        supplier_payments: ["supplier_payment"],
        returns: ["credit_return"],
    };
    const count = (t: string) =>
        t === "customers"
            ? d.customers.length
            : t === "suppliers"
              ? d.suppliers.length
              : t === "orders"
                ? d.orders.filter(partyMatches).length
                : tabKinds[t]
                  ? d.documents.filter(
                        (x) => tabKinds[t].includes(x.kind) && partyMatches(x),
                    ).length
                  : null;
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
                                        <div style={{ display: "flex", flexDirection: "column", gap: "4px" }}>
                                            <select
                                                aria-label={"Product or Account " + (n + 1)}
                                                value={
                                                    i.account_id
                                                        ? `acc_${i.account_id}`
                                                        : i.variant_id
                                                          ? `var_${i.variant_id}`
                                                          : ""
                                                }
                                                onChange={(e) => {
                                                    const val = e.target.value;
                                                    if (val.startsWith("acc_")) {
                                                        const accId = Number(val.replace("acc_", ""));
                                                        const acc = d.accounts?.find((a) => a.id === accId);
                                                        setItems(
                                                            items.map((v, j) =>
                                                                j === n
                                                                    ? {
                                                                          ...v,
                                                                          account_id: accId,
                                                                          variant_id: null,
                                                                          sku: acc?.code ?? "",
                                                                          description: acc ? `${acc.code} ${acc.name}` : "",
                                                                          unit_price: "0",
                                                                      }
                                                                    : v,
                                                            ),
                                                        );
                                                    } else {
                                                        const varId = Number(val.replace("var_", ""));
                                                        const variant = d.variants.find((v) => v.id === varId);
                                                        setItems(
                                                            items.map((v, j) =>
                                                                j === n
                                                                    ? {
                                                                          ...v,
                                                                          variant_id: varId,
                                                                          account_id: null,
                                                                          sku: variant?.sku ?? "",
                                                                          description: variant?.name ?? "",
                                                                          unit_price: variant?.selling_price ?? "0",
                                                                      }
                                                                    : v,
                                                            ),
                                                        );
                                                    }
                                                }}
                                            >
                                                <optgroup label="Physical Stock Items">
                                                    {d.variants
                                                        .filter(
                                                            (v) =>
                                                                kind !== "cost" ||
                                                                v.product?.item_type !== "service",
                                                        )
                                                        .map((v) => (
                                                            <option key={`var_${v.id}`} value={`var_${v.id}`}>
                                                                {v.sku} {v.name}
                                                                {v.product?.item_type === "service" ? " · Service" : ""}
                                                            </option>
                                                        ))}
                                                </optgroup>
                                                {kind === "cost" && d.accounts && d.accounts.length > 0 && (
                                                    <optgroup label="Direct Ledger Accounts (Cost of Sales / Expense)">
                                                        {d.accounts.map((a) => (
                                                            <option key={`acc_${a.id}`} value={`acc_${a.id}`}>
                                                                [{a.type}] {a.code} {a.name}
                                                            </option>
                                                        ))}
                                                    </optgroup>
                                                )}
                                            </select>
                                            {kind === "cost" && i.account_id && (
                                                <input
                                                    placeholder="Expenditure description (e.g. Steel Plates for Project)"
                                                    value={i.description ?? ""}
                                                    onChange={(e) =>
                                                        setItems(
                                                            items.map((v, j) =>
                                                                j === n
                                                                    ? {
                                                                          ...v,
                                                                          description: e.target.value,
                                                                      }
                                                                    : v,
                                                            ),
                                                        )
                                                    }
                                                />
                                            )}
                                        </div>
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
                                variant_id:
                                    d.variants.find(
                                        (v) =>
                                            kind !== "cost" ||
                                            v.product?.item_type !== "service",
                                    )?.id ?? 0,
                                quantity: "1",
                                unit_cost: "0",
                                unit_price:
                                    d.variants.find(
                                        (v) =>
                                            kind !== "cost" ||
                                            v.product?.item_type !== "service",
                                    )?.selling_price ?? "0",
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
    const editQuote = (q: Doc) => {
        setHistory(null);
        setPreview(null);
        setSelected(q);
        setItems(q.items.map((i) => ({ ...i, ...(i.original ?? {}) })));
        setTab(q.kind === "invoice" ? "invoice_edit" : "quotes");
    };
    const showHistory = async (doc: Doc) => {
        try {
            const response = await axios.get(`/trade/history/${doc.id}`);
            setHistory({
                number: doc.number,
                versions: response.data.data,
                details: response.data.details ?? [],
            });
        } catch (e) {
            setNotice(error(e));
        }
    };
    const quantities = () =>
        items
            .filter((i) => i.quantity !== "" && Number(i.quantity) !== 0)
            .map((i) => ({
                variant_id: i.variant_id ?? null,
                account_id: i.account_id ?? null,
                quantity: i.quantity,
            }));
    const list = (docs: Doc[], buttons: (doc: Doc) => React.ReactNode) => (
        <div className="trade-card trade-table">
            <table>
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>{area === "sales" ? "Customer" : "Supplier"}</th>
                        <th>Date</th>
                        <th>Status / total</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {docs.filter(matches).map((doc) => (
                        <tr key={doc.id}>
                            <td>
                                <strong>{doc.number}</strong>
                                {doc.reference && (
                                    <small>{doc.reference}</small>
                                )}
                                {related(doc) && (
                                    <button
                                        className="trade-related"
                                        onClick={() => {
                                            const parent = related(doc)!;
                                            setSearch(parent.number);
                                            setDocumentStatus("all");
                                            navigate(docTab(parent));
                                        }}
                                    >
                                        From {related(doc)!.number}
                                    </button>
                                )}
                            </td>
                            <td>{doc.party_name ?? doc.supplier_name}</td>
                            <td>{doc.date}</td>
                            <td>
                                <span className="trade-status">
                                    {doc.status?.replaceAll("_", " ") ??
                                        (doc.outstanding != null
                                            ? Number(doc.outstanding) > 0
                                                ? "Outstanding"
                                                : "Paid"
                                            : doc.kind === "quote"
                                              ? doc.quote_closed
                                                  ? "Revised / invoiced"
                                                  : "Open quotation"
                                              : "Posted")}
                                </span>
                                {doc.total != null && (
                                    <small>
                                        {d.currency} {doc.total}
                                    </small>
                                )}
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
                                {doc.has_history && (
                                    <button
                                        onClick={() => void showHistory(doc)}
                                    >
                                        History
                                    </button>
                                )}
                                {buttons(doc)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {!docs.filter(matches).length && (
                <div className="trade-empty">
                    <strong>
                        No {labels[tab]?.toLowerCase() ?? "documents"} found
                    </strong>
                    <p>
                        {party || search || documentStatus !== "all"
                            ? "Try changing the customer, supplier or search filters."
                            : descriptions[tab]}
                    </p>
                </div>
            )}
        </div>
    );
    return (
        <div className="trade">
            <header className="trade-header">
                <div>
                    <span className="trade-eyebrow">COMMERCIAL WORKSPACE</span>
                    <h2>{group.title}</h2>
                    <p>{group.description}</p>
                </div>
                <span className="trade-book">
                    {d.book.toUpperCase()} BOOK · {d.currency}
                </span>
            </header>
            <nav className="trade-areas" aria-label="Commercial areas">
                {[
                    ...(d.sales ? ["sales"] : []),
                    ...(d.purchasing ? ["purchasing"] : []),
                    ...(showBackoffice ? ["backoffice"] : []),
                ].map((next) => (
                    <button
                        key={next}
                        aria-current={area === next ? "page" : undefined}
                        className={area === next ? "selected" : ""}
                        onClick={() => switchArea(next)}
                    >
                        {groups[next as keyof typeof groups].title}
                    </button>
                ))}
            </nav>
            {group.steps.length > 0 && (
                <ol className="trade-workflow" aria-label="Document workflow">
                    {group.steps.map((step, index) => (
                        <li key={step}>
                            <button
                                onClick={() => navigate(step)}
                                aria-current={tab === step ? "step" : undefined}
                            >
                                <span>{index + 1}</span>
                                {labels[step]}
                            </button>
                            {index < group.steps.length - 1 && (
                                <span aria-hidden="true">&rarr;</span>
                            )}
                        </li>
                    ))}
                </ol>
            )}
            {area !== "backoffice" && (
                <div className="trade-toolbar">
                    <label>
                        {area === "sales"
                            ? "Customer workspace"
                            : "Supplier workspace"}
                        <select
                            value={party}
                            onChange={(e) => {
                                setParty(e.target.value);
                                navigate(tab);
                            }}
                        >
                            <option value="">
                                {area === "sales"
                                    ? "All customers"
                                    : "All suppliers"}
                            </option>
                            {(area === "sales" ? d.customers : d.suppliers).map(
                                (p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                    <label>
                        Search documents
                        <input
                            type="search"
                            placeholder="Number, reference or name"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </label>
                    <label>
                        Document status
                        <select
                            disabled={
                                !["orders", "invoices", "bills"].includes(tab)
                            }
                            value={documentStatus}
                            onChange={(e) => setDocumentStatus(e.target.value)}
                        >
                            <option value="all">All statuses</option>
                            <option value="outstanding">
                                Outstanding balance
                            </option>
                            {area === "purchasing" && (
                                <>
                                    <option value="draft">Draft orders</option>
                                    <option value="approved">
                                        Approved orders
                                    </option>
                                    <option value="partially_received">
                                        Partially received orders
                                    </option>
                                    <option value="completed">
                                        Completed orders
                                    </option>
                                    <option value="cancelled">
                                        Cancelled orders
                                    </option>
                                </>
                            )}
                        </select>
                    </label>
                </div>
            )}
            {partyName && (
                <div className="trade-party-banner">
                    <div>
                        <strong>{partyName}</strong>
                        <span>
                            Related documents are filtered to this{" "}
                            {area === "sales" ? "customer" : "supplier"}.
                        </span>
                    </div>
                    <button
                        onClick={() => {
                            setParty("");
                            navigate(tab);
                        }}
                    >
                        Clear selection
                    </button>
                </div>
            )}
            <nav className="trade-tabs" aria-label={`${group.title} documents`}>
                {group.tabs.map((t) => (
                    <button
                        className={tab === t ? "selected" : ""}
                        aria-current={tab === t ? "page" : undefined}
                        key={t}
                        onClick={() => navigate(t)}
                    >
                        {labels[t]}
                        {count(t) != null && (
                            <span className="trade-count" aria-hidden="true">
                                {count(t)}
                            </span>
                        )}
                    </button>
                ))}
            </nav>
            <div className="trade-section-heading">
                <h3>{labels[tab]}</h3>
                <p>{descriptions[tab]}</p>
                <small>
                    Document lists show up to the 200 most recent records
                    available in this book and your accessible warehouses.
                </small>
            </div>
            {notice && (
                <p className="trade-banner" role="status">
                    {notice}
                </p>
            )}
            <div key={`${area}-${tab}-${party}`}>
                {["customer_receipts", "supplier_payments", "returns"].includes(
                    tab,
                ) && (
                    <>
                        {tab !== "returns" && (
                            <button
                                onClick={() =>
                                    navigate(
                                        tab === "customer_receipts"
                                            ? "invoices"
                                            : "bills",
                                    )
                                }
                            >
                                {tab === "customer_receipts"
                                    ? "Receive payment from an invoice"
                                    : "Pay a supplier invoice"}
                            </button>
                        )}
                        {tab === "returns" && !selected && (
                            <button onClick={() => navigate("invoices")}>
                                Select an invoice for a return
                            </button>
                        )}
                        {list(
                            d.documents.filter(
                                (x) =>
                                    x.kind ===
                                    (tab === "customer_receipts"
                                        ? "customer_receipt"
                                        : tab === "supplier_payments"
                                          ? "supplier_payment"
                                          : "credit_return"),
                            ),
                            () => null,
                        )}
                    </>
                )}
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
                        <div className="trade-card trade-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Supplier</th>
                                        <th>Payment terms</th>
                                        <th>Status</th>
                                        <th>Related documents</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {d.suppliers
                                        .filter(
                                            (s) =>
                                                (!party ||
                                                    String(s.id) === party) &&
                                                s.name
                                                    .toLowerCase()
                                                    .includes(
                                                        search.toLowerCase(),
                                                    ),
                                        )
                                        .map((s) => (
                                            <tr key={s.id}>
                                                <td>
                                                    <strong>{s.name}</strong>
                                                    <small>{s.code}</small>
                                                </td>
                                                <td>{s.terms_days} days</td>
                                                <td>
                                                    <span className="trade-status">
                                                        {s.active
                                                            ? "Active"
                                                            : "Inactive"}
                                                    </span>
                                                </td>
                                                <td>
                                                    <button
                                                        onClick={() => {
                                                            setParty(
                                                                String(s.id),
                                                            );
                                                            navigate("orders");
                                                        }}
                                                    >
                                                        View supplier documents
                                                    </button>
                                                    {can(
                                                        "purchasing.configure",
                                                    ) && (
                                                        <button
                                                            onClick={() =>
                                                                void action(
                                                                    "suppliers/" +
                                                                        s.id,
                                                                    {
                                                                        code: s.code,
                                                                        name: s.name,
                                                                        terms_days:
                                                                            s.terms_days,
                                                                        active: !s.active,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {s.active
                                                                ? "Deactivate"
                                                                : "Activate"}
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                            {!d.suppliers.length && (
                                <p className="trade-empty">
                                    No suppliers yet. Add your first supplier to
                                    get started.
                                </p>
                            )}
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
                                        value:
                                            area === "purchasing"
                                                ? party
                                                : undefined,
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
                                                    (c) =>
                                                        c.code !== d.currency,
                                                )
                                                .map((c) => ({
                                                    value: c.code,
                                                    label:
                                                        c.code + " · " + c.name,
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
                                            variant_id: i.variant_id ?? null,
                                            account_id: i.account_id ?? null,
                                            description: i.description ?? null,
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
                                            onClick={() =>
                                                choose(o, "receipts")
                                            }
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
                        {!selected && (
                            <button onClick={() => navigate("orders")}>
                                {" "}
                                Choose an approved purchase order{" "}
                            </button>
                        )}
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
                                    Enter delivered quantities only. Receipt
                                    stock value uses the order's historical base
                                    carrying cost.
                                </p>
                                {lines("quantity")}
                            </Form>
                        )}
                        {list(
                            d.documents.filter((x) => x.kind === "receipt"),
                            (r) =>
                                can("purchasing.bill") && (
                                    <button onClick={() => choose(r, "bills")}>
                                        Match supplier invoice
                                    </button>
                                ),
                        )}
                    </>
                )}
                {tab === "bills" && (
                    <>
                        <div style={{ display: "flex", gap: "8px", marginBottom: "16px" }}>
                            {!selected && (
                                <button onClick={() => navigate("receipts")}>
                                    Choose a goods receipt to match
                                </button>
                            )}
                            {can("purchasing.bill") && (
                                <button
                                    onClick={() => {
                                        setDirectBill(!directBill);
                                        setSelected(null);
                                    }}
                                >
                                    {directBill
                                        ? "Hide direct invoice"
                                        : "Record direct supplier invoice (Cost of Sales / Expense)"}
                                </button>
                            )}
                        </div>
                        {directBill && can("purchasing.bill") && (
                            <Form
                                title="Direct Supplier Invoice (Direct to Ledger / Cost of Sales)"
                                fields={[
                                    {
                                        key: "supplier_id",
                                        label: "Supplier",
                                        options: suppliers,
                                    },
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
                                    post("direct-bills", {
                                        ...v,
                                        request_id: id,
                                        items: items.map((i) => ({
                                            variant_id: i.variant_id ?? null,
                                            account_id: i.account_id ?? null,
                                            description: i.description ?? null,
                                            quantity: i.quantity,
                                            unit_cost: i.unit_cost,
                                        })),
                                    })
                                }
                                button="Post direct supplier invoice"
                            >
                                <p>
                                    Reference is the supplier invoice number.
                                    Direct expenditure lines map straight to your selected
                                    Cost of Sales or Expense accounts without requiring prior GRV or PO.
                                </p>
                                {lines("cost")}
                            </Form>
                        )}
                        {selected?.kind === "receipt" &&
                            can("purchasing.bill") && (
                                <Form
                                    key={selected.id}
                                    title={
                                        "Supplier invoice against " +
                                        selected.number
                                    }
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
                                    button="Post supplier invoice"
                                >
                                    <p>
                                        Reference is the supplier invoice
                                        number. Billing clears received
                                        inventory accruals and does not receive
                                        stock again. The bill inherits the order
                                        currency and stores its approved rate
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
                                        Pay supplier invoice
                                    </button>
                                ),
                        )}
                    </>
                )}
                {tab === "customers" && (
                    <>
                        {can("sales.quote") && !editingCustomer && (
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
                        {viewingCustomer && (
                            <section
                                className="trade-card"
                                aria-label="Customer details"
                            >
                                <h3>{viewingCustomer.name}</h3>
                                <p>
                                    Email:{" "}
                                    {viewingCustomer.email || "Not provided"}
                                </p>
                                <p>
                                    Phone:{" "}
                                    {viewingCustomer.phone || "Not provided"}
                                </p>
                                <button
                                    onClick={() => setViewingCustomer(null)}
                                >
                                    Close customer details
                                </button>
                            </section>
                        )}
                        {editingCustomer && can("sales.quote") && (
                            <Form
                                key={editingCustomer.id}
                                title={"Edit customer: " + editingCustomer.name}
                                fields={[
                                    {
                                        key: "name",
                                        label: "Customer name",
                                        value: editingCustomer.name,
                                    },
                                    {
                                        key: "email",
                                        label: "Customer email",
                                        type: "email",
                                        optional: true,
                                        value: editingCustomer.email ?? "",
                                    },
                                    {
                                        key: "phone",
                                        label: "Customer phone",
                                        optional: true,
                                        value: editingCustomer.phone ?? "",
                                    },
                                ]}
                                save={async (v) => {
                                    await axios.patch(
                                        `/trade/customers/${editingCustomer.id}`,
                                        v,
                                    );
                                    await reload();
                                    setEditingCustomer(null);
                                    setNotice("Customer updated successfully.");
                                }}
                                button="Save customer changes"
                            >
                                <button
                                    type="button"
                                    onClick={() => setEditingCustomer(null)}
                                >
                                    Cancel customer editing
                                </button>
                            </Form>
                        )}
                        {deletingCustomer && (
                            <section
                                className="trade-card"
                                role="alertdialog"
                                aria-label="Confirm customer deletion"
                            >
                                <h3>Delete {deletingCustomer.name}?</h3>
                                <p>
                                    Only unused customer records can be deleted.
                                    Customers with documents, sales or loyalty
                                    history are protected.
                                </p>
                                <button
                                    disabled={deleting}
                                    onClick={async () => {
                                        setDeleting(true);
                                        try {
                                            await axios.delete(
                                                `/trade/customers/${deletingCustomer.id}`,
                                            );
                                            await reload();
                                            if (
                                                party ===
                                                String(deletingCustomer.id)
                                            )
                                                setParty("");
                                            setDeletingCustomer(null);
                                            setViewingCustomer(null);
                                            setEditingCustomer(null);
                                            setNotice(
                                                "Customer deleted successfully.",
                                            );
                                        } catch (e) {
                                            setNotice(error(e));
                                        } finally {
                                            setDeleting(false);
                                        }
                                    }}
                                >
                                    {deleting
                                        ? "Deleting…"
                                        : "Confirm delete customer"}
                                </button>
                                <button
                                    disabled={deleting}
                                    onClick={() => setDeletingCustomer(null)}
                                >
                                    Cancel deletion
                                </button>
                            </section>
                        )}
                        <div className="trade-card trade-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Customer / contact</th>
                                        <th>Related documents</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {d.customers
                                        .filter(
                                            (s) =>
                                                (!party ||
                                                    String(s.id) === party) &&
                                                s.name
                                                    .toLowerCase()
                                                    .includes(
                                                        search.toLowerCase(),
                                                    ),
                                        )
                                        .map((s) => (
                                            <tr key={s.id}>
                                                <td>
                                                    <strong>{s.name}</strong>
                                                    <small>
                                                        {s.email || "No email"}
                                                    </small>
                                                    <small>
                                                        {s.phone || "No phone"}
                                                    </small>
                                                </td>
                                                <td>
                                                    <button
                                                        onClick={() => {
                                                            setParty(
                                                                String(s.id),
                                                            );
                                                            navigate("quotes");
                                                        }}
                                                    >
                                                        View customer documents
                                                    </button>
                                                    <button
                                                        onClick={() => {
                                                            setViewingCustomer(
                                                                s,
                                                            );
                                                            setEditingCustomer(
                                                                null,
                                                            );
                                                        }}
                                                    >
                                                        View customer
                                                    </button>
                                                    {can("sales.quote") && (
                                                        <button
                                                            onClick={() => {
                                                                setEditingCustomer(
                                                                    s,
                                                                );
                                                                setViewingCustomer(
                                                                    null,
                                                                );
                                                                setDeletingCustomer(
                                                                    null,
                                                                );
                                                            }}
                                                        >
                                                            Edit customer
                                                        </button>
                                                    )}
                                                    {can("sales.configure") && (
                                                        <button
                                                            onClick={() => {
                                                                setDeletingCustomer(
                                                                    s,
                                                                );
                                                                setNotice("");
                                                            }}
                                                        >
                                                            Delete customer
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                            {!d.customers.length && (
                                <p className="trade-empty">
                                    No customers yet. Add your first customer to
                                    get started.
                                </p>
                            )}
                        </div>
                        {can("sales.configure") && (
                            <Form
                                title="Customer credit settings"
                                fields={[
                                    {
                                        key: "customer_id",
                                        label: "Credit customer",
                                        options: customers,
                                        value:
                                            area === "sales"
                                                ? party
                                                : undefined,
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
                                            {
                                                value: "false",
                                                label: "Blocked",
                                            },
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
                {(tab === "quotes" ||
                    (tab === "invoice_edit" &&
                        selected?.kind === "invoice")) && (
                    <>
                        {can(
                            tab === "invoice_edit"
                                ? "sales.invoice"
                                : "sales.quote",
                        ) && (
                            <Form
                                key={"quote-" + (selected?.id ?? "new")}
                                title={
                                    selected?.id
                                        ? (tab === "invoice_edit"
                                              ? "Edit invoice "
                                              : "Edit quotation ") +
                                          selected.number
                                        : "Create sales quotation"
                                }
                                fields={[
                                    {
                                        key: "currency",
                                        label:
                                            tab === "invoice_edit"
                                                ? "Invoice currency"
                                                : "Quotation currency",
                                        disabled: tab === "invoice_edit",
                                        value: selected?.currency_snapshot
                                            ?.currency,
                                        options: [
                                            {
                                                value: d.currency,
                                                label: d.currency + " | Base",
                                            },
                                            ...(d.currencies ?? [])
                                                .filter(
                                                    (c) =>
                                                        c.code !== d.currency,
                                                )
                                                .map((c) => ({
                                                    value: c.code,
                                                    label:
                                                        c.code + " | " + c.name,
                                                })),
                                        ],
                                    },
                                    {
                                        key: "customer_id",
                                        label: "Customer",
                                        disabled: tab === "invoice_edit",
                                        value: selected?.customer_id
                                            ? String(selected.customer_id)
                                            : party || undefined,
                                        options: customers,
                                    },
                                    {
                                        key: "warehouse_id",
                                        label: "Selling warehouse",
                                        disabled: tab === "invoice_edit",
                                        value: selected?.warehouse_id
                                            ? String(selected.warehouse_id)
                                            : undefined,
                                        options: warehouses,
                                    },
                                    ...basic.map((f) => ({
                                        ...f,
                                        value: selected
                                            ? f.key === "date"
                                                ? tab === "invoice_edit"
                                                    ? d.today
                                                    : selected.date
                                                : (selected.reference ?? "")
                                            : f.value,
                                    })),
                                    {
                                        key: "due_on",
                                        label:
                                            tab === "invoice_edit"
                                                ? "Payment due"
                                                : "Quote expiry",
                                        type: "date",
                                        value: selected?.due_on ?? d.today,
                                    },
                                    {
                                        key: "notes",
                                        label:
                                            tab === "invoice_edit"
                                                ? "Invoice notes"
                                                : "Quote notes",
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
                                                (tab === "invoice_edit"
                                                    ? "/trade/invoice-preview"
                                                    : "/trade/quote-preview") +
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
                                        (tab === "invoice_edit"
                                            ? "invoice-edits"
                                            : "quotes") +
                                            (selected?.id
                                                ? "/" + selected.id
                                                : ""),
                                        quotePayload(v, id),
                                    );
                                    setSelected(null);
                                    setPreview(null);
                                    if (tab === "invoice_edit") {
                                        setTab("invoices");
                                        setNotice(
                                            "Invoice updated successfully.",
                                        );
                                    } else
                                        setNotice(
                                            "Quotation saved successfully.",
                                        );
                                }}
                                button={
                                    selected?.id
                                        ? tab === "invoice_edit"
                                            ? "Save invoice changes"
                                            : "Save quotation changes"
                                        : "Save quotation"
                                }
                            >
                                <p>
                                    Quotes do not reserve stock. Services can be
                                    invoiced without stock. Select whether
                                    entered prices include VAT. Credit and
                                    available stock are checked when invoicing.
                                </p>
                                {selected && (
                                    <p>
                                        Saving keeps the same{" "}
                                        {tab === "invoice_edit"
                                            ? "invoice"
                                            : "quotation"}{" "}
                                        number. Earlier versions are retained in
                                        History.
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
                                            navigate(
                                                tab === "invoice_edit"
                                                    ? "invoices"
                                                    : "quotes",
                                            );
                                        }}
                                    >
                                        Cancel editing
                                    </button>
                                )}
                            </Form>
                        )}
                        {tab === "quotes" &&
                            list(
                                d.documents.filter((x) => x.kind === "quote"),
                                (q) => (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => setShareDoc(q)}
                                            style={{ color: "#2563eb", fontWeight: 600 }}
                                        >
                                            Email / WhatsApp
                                        </button>
                                        {!q.quote_closed && (
                                            <button
                                                type="button"
                                                onClick={() => handlePaynow(q.id)}
                                                disabled={paynowLoading === q.id}
                                                style={{ color: "#008751", fontWeight: 600, display: "inline-flex", alignItems: "center", gap: "3px" }}
                                                title="Accept & Pay with Paynow"
                                            >
                                                {paynowLoading === q.id ? "Opening..." : "⚡ Paynow"}
                                            </button>
                                        )}
                                        {can("sales.quote") &&
                                            !q.quote_closed && (
                                                <button
                                                    onClick={() => editQuote(q)}
                                                >
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
                        {!selected && (
                            <button onClick={() => navigate("quotes")}>
                                {" "}
                                Choose a quotation to invoice{" "}
                            </button>
                        )}
                        {selected?.kind === "quote" && can("sales.invoice") && (
                            <Form
                                key={selected.id}
                                title={
                                    "Invoice accepted quote " + selected.number
                                }
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
                                    Confirm customer acceptance. Posting reduces
                                    physical stock and increases customer credit
                                    outstanding. Services require no stock. This
                                    preview is not a posted invoice; stock,
                                    credit and expiry are checked on posting.
                                </p>
                                <DocumentPreview
                                    logoUrl={d.company_logo_url}
                                    data={selected}
                                    currency={d.currency}
                                    title={
                                        "Invoice preview | " + selected.number
                                    }
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
                                ["invoice", "opening_receivable"].includes(
                                    x.kind,
                                ),
                            ),
                            (i) => (
                                <>
                                    {i.kind === "invoice" && (
                                        <>
                                            <button
                                                type="button"
                                                onClick={() => setShareDoc(i)}
                                                style={{ color: "#2563eb", fontWeight: 600 }}
                                            >
                                                Email / WhatsApp
                                            </button>
                                            {Number(i.outstanding) > 0 && (
                                                <button
                                                    type="button"
                                                    onClick={() => handlePaynow(i.id)}
                                                    disabled={paynowLoading === i.id}
                                                    style={{ color: "#008751", fontWeight: 600, display: "inline-flex", alignItems: "center", gap: "3px" }}
                                                    title="Collect payment online with Paynow"
                                                >
                                                    {paynowLoading === i.id ? "Opening..." : "⚡ Paynow"}
                                                </button>
                                            )}
                                            <button
                                                onClick={() => {
                                                    setPreview(i);
                                                    setSelected(null);
                                                }}
                                            >
                                                Preview invoice
                                            </button>
                                            {can("sales.invoice") && (
                                                <>
                                                    {i.invoice_editable ? (
                                                        <button
                                                            onClick={() =>
                                                                editQuote(i)
                                                            }
                                                        >
                                                            Edit invoice
                                                        </button>
                                                    ) : (
                                                        <small>
                                                            Payments or credit
                                                            returns recorded.
                                                            Use a credit note
                                                            for amount changes.
                                                        </small>
                                                    )}
                                                    <button
                                                        onClick={() => {
                                                            setSelected(i);
                                                            setPreview(null);
                                                            setTab(
                                                                "invoice_details",
                                                            );
                                                        }}
                                                    >
                                                        Edit reference / notes
                                                    </button>
                                                </>
                                            )}
                                        </>
                                    )}
                                    {can("sales.receive") &&
                                        Number(i.outstanding) > 0 && (
                                            <button
                                                onClick={() => setSelected(i)}
                                            >
                                                Receive payment
                                            </button>
                                        )}
                                    {can("sales.return") &&
                                        i.kind === "invoice" &&
                                        Number(i.outstanding) > 0 && (
                                            <button
                                                onClick={() =>
                                                    choose(i, "returns")
                                                }
                                            >
                                                Return unpaid items
                                            </button>
                                        )}
                                </>
                            ),
                        )}
                    </>
                )}
                {tab === "invoice_details" &&
                    selected &&
                    can("sales.invoice") && (
                        <Form
                            key={selected.id}
                            title={"Edit details for " + selected.number}
                            fields={[
                                {
                                    key: "reference",
                                    label: "Reference",
                                    value: selected.reference ?? "",
                                },
                                {
                                    key: "notes",
                                    label: "Invoice notes",
                                    value: selected.notes ?? "",
                                    optional: true,
                                },
                            ]}
                            save={async (v, id) => {
                                await post(`invoice-details/${selected.id}`, {
                                    ...v,
                                    request_id: id,
                                });
                                setTab("invoices");
                            }}
                            button="Save invoice details"
                        >
                            <p>
                                The invoice number, amounts, payment allocations
                                and stock remain unchanged.
                            </p>
                            <button
                                type="button"
                                onClick={() => navigate("invoices")}
                            >
                                Cancel editing
                            </button>
                        </Form>
                    )}
                {tab === "invoices" && preview && (
                    <>
                        <DocumentPreview
                            logoUrl={d.company_logo_url}
                            data={preview}
                            currency={d.currency}
                            title="Invoice preview"
                        />
                        <div style={{ display: "flex", gap: "10px", marginTop: "10px", flexWrap: "wrap" }}>
                            {"id" in preview && (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => setShareDoc(preview as Doc)}
                                        style={{ background: "#2563eb", color: "#fff", fontWeight: 600 }}
                                    >
                                        Email / WhatsApp Document
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handlePaynow((preview as any).id)}
                                        disabled={paynowLoading === (preview as any).id}
                                        style={{
                                            background: "#008751",
                                            color: "#fff",
                                            fontWeight: 600,
                                            display: "inline-flex",
                                            alignItems: "center",
                                            gap: "6px",
                                        }}
                                    >
                                        {paynowLoading === (preview as any).id ? "Opening..." : "⚡ Pay with Paynow"}
                                    </button>
                                </>
                            )}
                            <button onClick={() => setPreview(null)}>
                                Close preview
                            </button>
                        </div>
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
                        <>
                            {area === "sales" && (
                                <div
                                    style={{
                                        margin: "0 0 16px 0",
                                        padding: "16px 20px",
                                        background: "#f0fdf4",
                                        border: "1px solid #86efac",
                                        borderRadius: "8px",
                                        display: "flex",
                                        alignItems: "center",
                                        justifyContent: "space-between",
                                        flexWrap: "wrap",
                                        gap: "12px",
                                    }}
                                >
                                    <div>
                                        <div
                                            style={{
                                                fontSize: "14px",
                                                fontWeight: 700,
                                                color: "#166534",
                                            }}
                                        >
                                            ⚡ Instant Online Payment with Paynow
                                        </div>
                                        <div
                                            style={{
                                                fontSize: "12px",
                                                color: "#15803d",
                                                marginTop: "2px",
                                            }}
                                        >
                                            Collect payment via EcoCash, OneMoney, InnBucks, Zimswitch, or Visa/Mastercard.
                                        </div>
                                        <div
                                            style={{
                                                fontSize: "12px",
                                                color: "#334155",
                                                marginTop: "4px",
                                            }}
                                        >
                                            Outstanding: <strong>{d.currency} {selected.outstanding ?? "0"}</strong>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => handlePaynow(selected.id)}
                                        disabled={paynowLoading === selected.id}
                                        style={{
                                            background: "#008751",
                                            color: "#ffffff",
                                            border: "none",
                                            borderRadius: "6px",
                                            padding: "10px 20px",
                                            fontSize: "13px",
                                            fontWeight: 700,
                                            cursor: "pointer",
                                            display: "inline-flex",
                                            alignItems: "center",
                                            gap: "6px",
                                            boxShadow: "0 2px 6px rgba(0,135,81,0.25)",
                                        }}
                                    >
                                        {paynowLoading === selected.id ? "Opening Paynow..." : "⚡ Launch Paynow Checkout →"}
                                    </button>
                                </div>
                            )}
                            <Form
                                key={"settle" + selected.id}
                                title={
                                    (area === "sales"
                                        ? "Manual cash/bank receipt for "
                                        : "Pay supplier invoice ") + selected.number
                                }
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
                                    value: (
                                        selected.outstanding ?? "0"
                                    ).replace(/(\.\d{2})00$/, "$1"),
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
                    can("currencies.post") &&
                    (d.foreign_accounts ?? []).some(
                        (a) =>
                            a.branch_id ===
                            d.warehouses.find(
                                (w) => w.id === selected.warehouse_id,
                            )?.branch_id,
                    ) && (
                        <Form
                            key={"foreign-settle" + selected.id}
                            title={
                                "Foreign currency settlement " + selected.number
                            }
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
                                                        selected
                                                            .currency_snapshot
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
                            Returns reduce unpaid credit. Physical items return
                            to stock at their original cost; service credits do
                            not affect stock. This workflow does not issue cash
                            refunds for paid invoices.
                        </p>
                        {lines("quantity")}
                    </Form>
                )}
                {tab === "aging" && (
                    <div className="trade-card">
                        <div className="report-subheading">
                            <div>
                                <h3>Debtors & creditors ageing</h3>
                                <p className="report-caption">
                                    Current outstanding balances · {d.currency}{" "}
                                    · {d.book} book
                                </p>
                            </div>
                            {can("reports.view") && (
                                <a
                                    className="secondary"
                                    href="/reports?report=debtors"
                                >
                                    Open report centre
                                </a>
                            )}
                        </div>
                        {d.report.parties ? (
                            <>
                                {d.sales && (
                                    <section>
                                        <h3>Debtors · customer balances</h3>
                                        <AgingReport
                                            data={d.report as AgingSnapshot}
                                            role="receivable"
                                            currency={d.currency}
                                        />
                                    </section>
                                )}
                                {d.purchasing && (
                                    <section style={{ marginTop: 32 }}>
                                        <h3>Creditors · supplier balances</h3>
                                        <AgingReport
                                            data={d.report as AgingSnapshot}
                                            role="payable"
                                            currency={d.currency}
                                        />
                                    </section>
                                )}
                            </>
                        ) : (
                            <div className="trade-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Document</th>
                                            <th>Party</th>
                                            <th>Due</th>
                                            <th>Outstanding</th>
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
                        )}
                    </div>
                )}
                {tab === "openings" && (
                    <>
                        <p className="trade-banner">
                            Assign existing verified control-account balances to
                            parties. This creates an opening subledger record
                            and does not post the ledger again.
                        </p>
                        {d.purchasing && can("purchasing.bill") && (
                            <Form
                                title="Allocate opening supplier balance"
                                fields={[
                                    {
                                        key: "party_id",
                                        label: "Opening supplier",
                                        options: suppliers,
                                        value:
                                            area === "purchasing"
                                                ? party
                                                : undefined,
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
                                        value:
                                            area === "sales"
                                                ? party
                                                : undefined,
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
            {history && (
                <section
                    className="trade-card trade-table"
                    aria-label="Document history"
                >
                    <h3>History · {history.number}</h3>
                    <p>One document number. Earlier versions are read-only.</p>
                    <button onClick={() => setHistory(null)}>
                        Close history
                    </button>
                    <table>
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Date</th>
                                <th>Reference / notes</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Document</th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.versions.map((version, index) => (
                                <tr key={version.id}>
                                    <td>
                                        {version.version ??
                                            history.versions.length - index}
                                    </td>
                                    <td>{version.date}</td>
                                    <td>
                                        {version.reference}
                                        <small>{version.notes}</small>
                                    </td>
                                    <td>
                                        {d.currency} {version.total}
                                    </td>
                                    <td>
                                        {version.superseded
                                            ? "Previous version"
                                            : "Current"}
                                    </td>
                                    <td>
                                        <a
                                            href={`/trade/print/document/${version.id}`}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Print version
                                        </a>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {history.details.length > 0 && (
                        <>
                            <h3>Reference and note changes</h3>
                            {history.details.map((detail, index) => (
                                <p key={index}>
                                    {detail.created_at} · {detail.reference} ·{" "}
                                    {detail.notes}
                                </p>
                            ))}
                        </>
                    )}
                </section>
            )}

            {shareDoc && (
                <TradeShareModal
                    isOpen={true}
                    onClose={() => setShareDoc(null)}
                    documentId={shareDoc.id}
                    documentNumber={shareDoc.number}
                    documentKind={shareDoc.kind}
                    customerName={shareDoc.party_name}
                    totalAmount={shareDoc.total || "0.00"}
                    currency={d.currency}
                    initialEmail={shareDoc.customer_email}
                    initialPhone={shareDoc.customer_phone}
                />
            )}
        </div>
    );
}
