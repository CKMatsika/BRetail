import React, { useState, useEffect, useRef } from "react";
import { router } from "@inertiajs/react";
import axios from "axios";
import {
    Search,
    Plus,
    Minus,
    Trash2,
    ShoppingBag,
    Pause,
    Play,
    Printer,
    X,
    ArrowRight,
    UserPlus,
    Check,
    Wallet,
    RotateCcw,
    Camera,
} from "lucide-react";
import {CurrencyDrawerPanel,CurrencyTenderFields,CurrencyRefundFields,CurrencyDrawer} from "./CurrencyPos";
import { useDialog } from "../useDialog";
type Variant = {
    item_type?: "stock" | "service";
    id: number;
    name: string;
    product_name: string;
    sku: string;
    barcode: string | null;
    selling_price: string;
    department_id: number | null;
    brand: string | null;
};
type CartLine = {
    variant_id: number;
    quantity: string;
    discount_percent: string;
};
type Quote = {
    quote_token: string;
    currency: string;
    subtotal: string;
    discount: string;
    tax: string;
    total: string;
    items: {
        variant_id: number;
        description: string;
        quantity: string;
        unit_price: string;
        net: string;
        tax: string;
        total: string;
    }[];
};
type Payment = { method: string; amount: string; reference: string };
type BarcodeDetectorLike = { detect: (source: HTMLVideoElement) => Promise<{ rawValue: string }[]> };
type BarcodeDetectorConstructor = new (options?: { formats?: string[] }) => BarcodeDetectorLike;
type Summary = {
    currency_drawers?: CurrencyDrawer[];
    sale_count: number;
    return_count: number;
    gross_sales: string;
    refunds: string;
    expected_cash: string;
    methods: Record<
        string,
        { received: string; refunded: string; net: string }
    >;
};
type Session = {
    cashier_name?: string;
    closed_by_name?: string | null;
    id: number;
    user_id: number;
    warehouse_id: number;
    status: string;
    currency: string;
    opening_cash: string;
    opened_at: string;
    closed_at: string | null;
    counted_cash: string | null;
    expected_cash: string | null;
    variance: string | null;
    summary: Summary;
};
type SaleRow = {
    mode: string;
    id: number;
    receipt_number: string;
    warehouse_id: number;
    session_id: number;
    cashier_name: string;
    customer_name: string | null;
    total: string;
    tax: string;
    currency: string;
    created_at: string;
};
type SaleDetail = SaleRow & {
    currency_tenders?: any[];
    change: string;
    items: {
        id: number;
        variant_id: number;
        description: string;
        sku: string;
        quantity: string;
        remaining_quantity: string;
        returned_quantity: string;
        total: string;
    }[];
    payments: {
        method: string;
        amount: string;
        tendered: string;
        change: string;
        reference: string | null;
    }[];
    remaining_payments: Record<string, string>;
    refunded_total: string;
    returns: { id: number; receipt_number: string; total: string }[];
};
type Hold = {
    user_id: number;
    id: number;
    label: string;
    warehouse_id: number;
    cart: { customer_id: number | null; lines: CartLine[] };
};
export type PosProps = {
    pos?: {
        foreign_safes?: any[];
        training_available: boolean;
        live_available: boolean;
        current_user_id: number;
        currency: string;
        discount_limit: string;
        daily: {
            date: string;
            timezone: string;
            sale_count: number;
            return_count: number;
            gross_sales: string;
            tax: string;
            refunds: string;
            net_recorded: string;
        };
        warehouses: { id: number; name: string; branch_id: number }[];
        departments: { id: number; name: string }[];
        variants: Variant[];
        stock: { warehouse_id: number; variant_id: number; quantity: string }[];
        customers: {
            id: number;
            name: string;
            email: string | null;
            phone: string | null;
            loyalty_number: string | null;
            loyalty_enrolled: boolean;
        }[];
        loyalty_available: boolean;
        holds: Hold[];
        sessions: Session[];
        own_session: Session | null;
        sales: SaleRow[];
    };
};
const methods = [
    { value: "cash", label: "Cash" },
    { value: "card", label: "Card" },
    { value: "mobile_money", label: "Mobile money" },
    { value: "bank_transfer", label: "Bank transfer" },
    { value: "loyalty_points", label: "Loyalty points" },
];
function scaled(value: string, places = 2): bigint {
    if (!/^\d+(\.\d+)?$/.test(value)) return 0n;
    const [a, b = ""] = value.split(".");
    return (
        BigInt(a) * 10n ** BigInt(places) +
        BigInt((b + "0".repeat(places)).slice(0, places))
    );
}
function decimal(value: bigint, places = 2): string {
    const negative = value < 0n,
        v = negative ? -value : value,
        p = 10n ** BigInt(places);
    return (
        (negative ? "-" : "") +
        (v / p).toString() +
        "." +
        (v % p).toString().padStart(places, "0")
    );
}
function Errors({ error }: { error: string }) {
    return error ? (
        <div className="notice error" role="alert">
            {error}
        </div>
    ) : null;
}
function errorText(e: any): string {
    return e?.response?.data?.errors
        ? Object.values(e.response.data.errors).flat().join(" ")
        : (e?.response?.data?.message ??
              "Connection interrupted. Retry the same request to confirm its result.");
}
function Modal({
    title,
    close,
    children,
}: {
    title: string;
    close: () => void;
    children: React.ReactNode;
}) {
    useDialog(close);
    return (
        <div className="modal-backdrop">
            <section
                className="modal pos-modal"
                role="dialog"
                aria-modal="true"
                aria-label={title}
            >
                <div className="section-heading">
                    <h2>{title}</h2>
                    <button
                        type="button"
                        className="icon-button"
                        onClick={close}
                        aria-label="Close"
                    >
                        <X />
                    </button>
                </div>
                {children}
            </section>
        </div>
    );
}
function Tenders({
    rows,
    setRows,
    disabled = false,
    includeLoyalty = false,
}: {
    rows: Payment[];
    setRows: (p: Payment[]) => void;
    disabled?: boolean;
    includeLoyalty?: boolean;
}) {
    const availableMethods = includeLoyalty ? methods : methods.filter((m) => m.value !== "loyalty_points");
    return (
        <div className="pos-tenders">
            {rows.map((p, i) => (
                <div className="pos-tender" key={i}>
                    <div className="form-grid">
                        <label className="field">
                            Payment method
                            <select
                                disabled={disabled}
                                value={p.method}
                                onChange={(e) =>
                                    setRows(
                                        rows.map((r, j) =>
                                            j === i
                                                ? {
                                                      ...r,
                                                      method: e.target.value,
                                                  }
                                                : r,
                                        ),
                                    )
                                }
                            >
                                {availableMethods.map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="field">
                            Amount
                            <input
                                disabled={disabled}
                                inputMode="decimal"
                                value={p.amount}
                                onChange={(e) =>
                                    setRows(
                                        rows.map((r, j) =>
                                            j === i
                                                ? {
                                                      ...r,
                                                      amount: e.target.value,
                                                  }
                                                : r,
                                        ),
                                    )
                                }
                            />
                        </label>
                    </div>
                    {p.method !== "cash" && p.method !== "loyalty_points" && (
                        <label className="field">
                            External payment reference
                            <input
                                disabled={disabled}
                                value={p.reference}
                                onChange={(e) =>
                                    setRows(
                                        rows.map((r, j) =>
                                            j === i
                                                ? {
                                                      ...r,
                                                      reference: e.target.value,
                                                  }
                                                : r,
                                        ),
                                    )
                                }
                            />
                        </label>
                    )}
                    {rows.length > 1 && (
                        <button
                            type="button"
                            className="secondary"
                            disabled={disabled}
                            onClick={() =>
                                setRows(rows.filter((_, j) => i !== j))
                            }
                        >
                            Remove payment
                        </button>
                    )}
                </div>
            ))}
            {rows.length < 4 && (
                <button
                    type="button"
                    className="secondary"
                    disabled={disabled}
                    onClick={() => {
                        const next = availableMethods.find(
                            (m) => !rows.some((p) => p.method === m.value),
                        );
                        if (next)
                            setRows([
                                ...rows,
                                {
                                    method: next.value,
                                    amount: "",
                                    reference: "",
                                },
                            ]);
                    }}
                >
                    Add split payment
                </button>
            )}
            <p className="muted">
                Noncash payments are recorded manually. No gateway is contacted.
                {includeLoyalty && " Loyalty tender is the value of whole points; balance and limits are checked at checkout."}
            </p>
        </div>
    );
}
function ShiftOpen({
    warehouse,
    currency,
    close,
}: {
    warehouse: number;
    currency: string;
    close: () => void;
}) {
    const [amount, setAmount] = useState("0.00"),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    const [id] = useState(() => crypto.randomUUID());
    return (
        <Modal title="Open cashier shift" close={close}>
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    try {
                        await axios.post("/pos/shifts", {
                            request_id: id,
                            warehouse_id: warehouse,
                            opening_cash: amount,
                        });
                        router.reload({ onSuccess: close });
                    } catch (e) {
                        setError(errorText(e));
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <p className="muted">
                    Count the cash float before opening. This is a cashier
                    drawer in {currency}.
                </p>
                <label className="field">
                    Opening cash float
                    <input
                        autoFocus
                        inputMode="decimal"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                    />
                </label>
                <Errors error={error} />
                <button className="primary" disabled={busy}>
                    Open shift
                </button>
            </form>
        </Modal>
    );
}
function ShiftClose({
    session,
    close,
}: {
    session: Session;
    close: () => void;
}) {
    const [amount, setAmount] = useState(""),
        [note, setNote] = useState(""),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    const [id] = useState(() => crypto.randomUUID());
    return (
        <Modal title="Close cashier shift" close={close}>
            <div className="notice">
                Expected cash:{" "}
                <strong>
                    {session.currency} {session.summary.expected_cash}
                </strong>
                <br />
                Opening float {session.opening_cash} + applied cash sales − cash
                refunds.
            </div>
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    try {
                        await axios.post(
                            "/pos/shifts/" + session.id + "/close",
                            { request_id: id, counted_cash: amount, note },
                        );
                        router.reload({ onSuccess: close });
                    } catch (e) {
                        setError(errorText(e));
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <label className="field">
                    Counted cash
                    <input
                        autoFocus
                        inputMode="decimal"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                    />
                </label>
                <label className="field">
                    Closing note
                    <textarea
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                </label>
                <Errors error={error} />
                <button className="primary" disabled={busy}>
                    Close shift
                </button>
            </form>
        </Modal>
    );
}
function CustomerForm({
    close,
    created,
    loyaltyAvailable,
}: {
    close: () => void;
    created: (id: number) => void;
    loyaltyAvailable: boolean;
}) {
    const [name, setName] = useState(""),
        [email, setEmail] = useState(""),
        [phone, setPhone] = useState(""),
        [loyaltyOptIn, setLoyaltyOptIn] = useState(false),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    return (
        <Modal title="Quick customer" close={close}>
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    try {
                        const r = await axios.post("/pos/customers", {
                            name,
                            email: email || null,
                            phone: phone || null,
                            loyalty_opt_in: loyaltyOptIn,
                        });
                        router.reload({
                            onSuccess: () => {
                                created(r.data.data.id);
                                close();
                            },
                        });
                    } catch (e) {
                        setError(errorText(e));
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                {[
                    ["Name", name, setName],
                    ["Email", email, setEmail],
                    ["Phone", phone, setPhone],
                ].map(([label, value, setter]) => (
                    <label className="field" key={String(label)}>
                        {String(label)}
                        <input
                            value={String(value)}
                            onChange={(e) =>
                                (setter as (s: string) => void)(e.target.value)
                            }
                        />
                    </label>
                ))}
                {loyaltyAvailable && (
                    <label className="field">
                        <span><input type="checkbox" checked={loyaltyOptIn} onChange={(e) => setLoyaltyOptIn(e.target.checked)} /> Enroll in Loyalty & Promotions</span>
                        <small>Customer consent is optional. Their phone or email may be used to identify the rewards account.</small>
                    </label>
                )}
                <Errors error={error} />
                <button className="primary" disabled={busy}>
                    Save customer
                </button>
            </form>
        </Modal>
    );
}
function ReturnForm({
    sale,
    session,
    close,
}: {
    sale: SaleDetail;
    session: Session | null;
    close: () => void;
}) {
    const [quantities, setQuantities] = useState<Record<number, string>>({});
    const [quote, setQuote] = useState<any>(null),
        [reason, setReason] = useState(""),
        [payments, setPayments] = useState<Payment[]>([
            { method: "cash", amount: "", reference: "" },
        ]),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    const [id] = useState(() => crypto.randomUUID());
    const [fxRefunds,setFxRefunds]=useState<Record<number,string>>({}),[fxTargets,setFxTargets]=useState<Record<number,string>>({});
    const [pending, setPending] = useState<any>(null);
    const lines = sale.items
        .filter((i) => scaled(quantities[i.id] ?? "0", 3) > 0n)
        .map((i) => ({ sale_item_id: i.id, quantity: quantities[i.id] }));
    const signature = JSON.stringify(lines);
    useEffect(() => {
        setQuote(null);
        if (!lines.length) return;
        const controller = new AbortController();
        const timer = setTimeout(() => {
            axios
                .post(
                    "/pos/sales/" + sale.id + "/return-quote",
                    { lines },
                    { signal: controller.signal },
                )
                .then((r) => {
                    setQuote(r.data.data);
                    setError("");
                })
                .catch((e) => {
                    if (!axios.isCancel(e)) setError(errorText(e));
                });
        }, 180);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [signature]);
    return (
        <Modal title={"Return · " + sale.receipt_number} close={close}>
            <p className="muted">
                Return to the original warehouse. Remaining payment limits:{" "}
                {Object.entries(sale.remaining_payments)
                    .map(([m, v]) => m.replaceAll("_", " ") + " " + v)
                    .join(" · ")}
                .
            </p>
            {!session || session.warehouse_id !== sale.warehouse_id ? (
                <div className="notice error">
                    Open your own shift in the original warehouse before posting
                    a return.
                </div>
            ) : null}
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    if (!quote || !session) return;
                    setBusy(true);
                    const body = pending ?? {
                        request_id: id,
                        session_id: session.id,
                        reason,
                        lines,
                        quote_token: quote.quote_token,
                        payments: quote.total === "0.00" ? [] : payments.filter(p=>scaled(p.amount)>0n),
                        ...(sale.currency_tenders?.some((o:any)=>o.kind==='sale')?{foreign_refunds:Object.entries(fxRefunds).filter(([_,v])=>scaled(v,4)>0n).map(([id,amount])=>{const source=sale.currency_tenders!.find((o:any)=>o.kind==='sale').tenders.find((t:any)=>t.drawer_id===Number(id));const eligible=session.summary.currency_drawers?.find((d:CurrencyDrawer)=>d.status==='open'&&d.currency===source.snapshot.currency);return {source_drawer_id:Number(id),drawer_id:Number(fxTargets[Number(id)]??eligible?.id),amount};})}:{}),
                    };
                    try {
                        const r = await axios.post(
                            "/pos/sales/" + sale.id + "/returns",
                            body,
                        );
                        setPending(null);
                        window.open(
                            "/pos/returns/" + r.data.data.id + "/receipt",
                            "_blank",
                            "noopener",
                        );
                        router.reload({ onSuccess: close });
                    } catch (e: any) {
                        setError(errorText(e));
                        if (!e.response) setPending(body);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                {sale.items
                    .filter((i) => scaled(i.remaining_quantity, 3) > 0n)
                    .map((item) => (
                        <label className="field" key={item.id}>
                            {item.sku} · returnable {item.remaining_quantity}
                            <input
                                disabled={!!pending}
                                inputMode="decimal"
                                placeholder="0"
                                value={quantities[item.id] ?? ""}
                                onChange={(e) =>
                                    setQuantities({
                                        ...quantities,
                                        [item.id]: e.target.value,
                                    })
                                }
                            />
                        </label>
                    ))}
                <div className="pos-grand-total">
                    <span>Refund total</span>
                    <strong>
                        {sale.currency} {quote?.total ?? "0.00"}
                    </strong>
                </div>
                <label className="field">
                    Return reason
                    <textarea
                        disabled={!!pending}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                </label>
                {quote?.total === "0.00" ? (
                    <p className="notice">
                        No money is due for this return. Only the stock quantity
                        will be restored.
                    </p>
                ) : (
                    <Tenders
                        rows={payments}
                        setRows={setPayments}
                        disabled={!!pending}
                        includeLoyalty={sale.payments.some((payment) => payment.method === "loyalty_points")}
                    />
                )}
                <CurrencyRefundFields sale={sale} session={session} amounts={fxRefunds} setAmounts={setFxRefunds} targets={fxTargets} setTargets={setFxTargets}/>
                <Errors error={error} />
                <button
                    className="primary wide"
                    disabled={
                        busy ||
                        !quote ||
                        !session ||
                        session.warehouse_id !== sale.warehouse_id
                    }
                >
                    {pending
                        ? "Retry same return"
                        : sale.mode === "live"
                          ? "Post return"
                          : "Post training return"}
                </button>
            </form>
        </Modal>
    );
}
export default function Pos({
    pos: p,
    permissions,
}: {
    pos: NonNullable<PosProps["pos"]>;
    permissions: string[];
}) {
    const can = (s: string) =>
        permissions.includes("*") || permissions.includes(s);
    const [tab, setTab] = useState("sell"),
        [search, setSearch] = useState(""),
        [department, setDepartment] = useState("all");
    const [warehouse, setWarehouse] = useState(
        p.own_session?.warehouse_id ?? p.warehouses[0]?.id ?? 0,
    );
    const [customer, setCustomer] = useState<number | null>(null),
        [customerQuery, setCustomerQuery] = useState("");
    const [loyaltyBalance, setLoyaltyBalance] = useState<{ points: string; value: string; currency: string } | null>(null);
    const [cart, setCart] = useState<CartLine[]>([]),
        [quote, setQuote] = useState<Quote | null>(null),
        [quoting, setQuoting] = useState(false),
        [error, setError] = useState("");
    const [modal, setModal] = useState<
        "open" | "close" | "customer" | "pay" | "hold" | null
    >(null);
    const [holdId, setHoldId] = useState<number | null>(null),
        [holdLabel, setHoldLabel] = useState("");
    const [payments, setPayments] = useState<Payment[]>([
        { method: "cash", amount: "", reference: "" },
    ]);
    const [busy, setBusy] = useState(false),
        [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const [pending, setPending] = useState<any>(null);
    const [receipt, setReceipt] = useState<SaleDetail | null>(null),
        [refund, setRefund] = useState<SaleDetail | null>(null);
    const [closingSession, setClosingSession] = useState<Session | null>(null);
    const [receiptSearch, setReceiptSearch] = useState("");
    const [limit, setLimit] = useState(48);
    const input = useRef<HTMLInputElement>(null);
    const videoRef = useRef<HTMLVideoElement>(null);
    useEffect(() => {
        let current = true;
        const selected = p.customers.find((c) => c.id === customer);
        if (!customer || !p.loyalty_available || !selected?.loyalty_enrolled) {
            setLoyaltyBalance(null);
            return () => { current = false; };
        }
        axios.get(`/pos/customers/${customer}/loyalty`).then((response) => {
            if (current) setLoyaltyBalance(response.data.data);
        }).catch(() => {
            if (current) setLoyaltyBalance(null);
        });
        return () => { current = false; };
    }, [customer, p.loyalty_available, p.customers]);
    const cameraStream = useRef<MediaStream | null>(null);
    const [cameraOpen, setCameraOpen] = useState(false);
    const [cameraMessage, setCameraMessage] = useState("");
    const session = p.own_session;
    const [fxTenders,setFxTenders]=useState<Record<number,string>>({}),[fxChange,setFxChange]=useState("");
    const currencyDrawers=(session?.summary.currency_drawers??[]).filter(d=>d.status==="open");
    const cartSignature = JSON.stringify({
        warehouse_id: warehouse,
        customer_id: customer,
        lines: cart,
    });
    useEffect(() => {
        setQuote(null);
        if (!cart.length) {
            setQuoting(false);
            return;
        }
        const controller = new AbortController();
        setQuoting(true);
        const timer = setTimeout(() => {
            axios
                .post("/pos/quote", JSON.parse(cartSignature), {
                    signal: controller.signal,
                })
                .then((r) => {
                    setQuote(r.data.data);
                    setError("");
                })
                .catch((e) => {
                    if (!axios.isCancel(e)) setError(errorText(e));
                })
                .finally(() => {
                    if (!controller.signal.aborted) setQuoting(false);
                });
        }, 180);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [cartSignature]);
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if (e.key === "F2") {
                e.preventDefault();
                input.current?.focus();
            }
            if (
                e.key === "F9" &&
                quote &&
                session &&
                can("pos.sell") &&
                (p.training_available || p.live_available)
            ) {
                e.preventDefault();
                startPayment();
            }
        };
        document.addEventListener("keydown", handler);
        return () => document.removeEventListener("keydown", handler);
    }, [quote, session, p.training_available, p.live_available]);
    const available = (id: number) =>
        p.stock.find((s) => s.warehouse_id === warehouse && s.variant_id === id)
            ?.quantity ?? "0.000";
    const add = (v: Variant) => {
        if (pending || !can("pos.sell")) return;
        if (v.item_type !== "service" && scaled(available(v.id), 3) <= 0n) {
            setError("This product is out of stock in the selected warehouse.");
            return;
        }
        const existing = cart.find((l) => l.variant_id === v.id);
        setCart(
            existing
                ? cart.map((l) =>
                      l.variant_id === v.id
                          ? {
                                ...l,
                                quantity: decimal(
                                    scaled(l.quantity, 3) + 1000n,
                                    3,
                                ),
                            }
                          : l,
                  )
                : [
                      ...cart,
                      {
                          variant_id: v.id,
                          quantity: "1.000",
                          discount_percent: "0.00",
                      },
                  ],
        );
        setError("");
    };
    const addRef = useRef(add);
    useEffect(() => { addRef.current = add; }, [add]);
    const openCameraScanner = async () => {
        const Detector = (window as Window & { BarcodeDetector?: BarcodeDetectorConstructor }).BarcodeDetector;
        if (!Detector) {
            setCameraMessage("Camera barcode scanning is not supported in this browser. Use a hardware scanner or enter the barcode in the search field.");
            setCameraOpen(true);
            return;
        }
        if (!navigator.mediaDevices?.getUserMedia) {
            setCameraMessage("Camera access needs a supported secure browser connection. Use the barcode search field instead.");
            setCameraOpen(true);
            return;
        }
        setCameraMessage("");
        try {
            cameraStream.current = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" } }, audio: false });
            setCameraOpen(true);
        } catch {
            setCameraMessage("Camera access was unavailable or denied. You can still scan with a hardware scanner or type the barcode.");
            setCameraOpen(true);
        }
    };
    useEffect(() => {
        if (!cameraOpen || !cameraStream.current || !videoRef.current) return;
        let stopped = false;
        let frame = 0;
        const stream = cameraStream.current;
        const video = videoRef.current;
        const Detector = (window as Window & { BarcodeDetector?: BarcodeDetectorConstructor }).BarcodeDetector;
        if (!Detector) return;
        let detector: BarcodeDetectorLike;
        try {
            detector = new Detector({ formats: ["ean_13", "ean_8", "upc_a", "upc_e", "code_128", "code_39", "qr_code"] });
        } catch {
            setCameraMessage("This browser does not support the catalogue barcode formats. Use a hardware scanner or enter the barcode.");
            return;
        }
        video.srcObject = stream;
        const scan = async () => {
            if (stopped) return;
            try {
                const [result] = await detector.detect(video);
                if (result?.rawValue) {
                    const code = result.rawValue.trim();
                    const match = p.variants.find(variant => variant.barcode === code || variant.sku === code);
                    if (match) {
                        addRef.current(match);
                        setSearch("");
                        setError("");
                    } else {
                        setSearch(code);
                        setError("No exact SKU or barcode match. Review the catalogue results before adding an item.");
                    }
                    setCameraOpen(false);
                    return;
                }
            } catch {
                if (!stopped) setCameraMessage("Could not read a barcode. Adjust the camera or use the search field.");
            }
            if (!stopped) frame = requestAnimationFrame(scan);
        };
        video.play().then(() => { if (!stopped) frame = requestAnimationFrame(scan); }).catch(() => setCameraMessage("The camera preview could not start. Use the search field instead."));
        return () => {
            stopped = true;
            cancelAnimationFrame(frame);
            stream.getTracks().forEach(track => track.stop());
            cameraStream.current = null;
            video.srcObject = null;
        };
    }, [cameraOpen, p.variants]);
    const startPayment = () => {
        if (!quote || !session) return;
        setPayments(
            pending?.payments ?? [
                { method: "cash", amount: quote.total, reference: "" },
            ],
        );
        if(!pending){setFxTenders({});setFxChange("");}
        setModal("pay");
    };
    const foreignTender=currencyDrawers.reduce((sum,d)=>sum+(scaled(fxTenders[d.id]??"0",4)*scaled(d.rate,12)+50000000000000n)/100000000000000n,0n);
    const tender = payments.reduce((sum, p) => sum + scaled(p.amount), foreignTender),
        change = quote ? tender - scaled(quote.total) : 0n;
    const finish = async () => {
        if (!quote || !session) return;
        setBusy(true);
        setError("");
        const body = pending ?? {
            request_id: requestId,
            session_id: session.id,
            warehouse_id: warehouse,
            customer_id: customer,
            hold_id: holdId,
            lines: cart,
            quote_token: quote.quote_token,
            payments:payments.filter(p=>scaled(p.amount)>0n),
            ...(Object.values(fxTenders).some(v=>scaled(v,4)>0n)?{foreign_tenders:Object.entries(fxTenders).filter(([_,v])=>scaled(v,4)>0n).map(([id,amount])=>({drawer_id:Number(id),amount})),change_drawer_id:fxChange?Number(fxChange):null}:{}),
        };
        try {
            const r = await axios.post("/pos/sales", body);
            const sale = r.data.data;
            setReceipt({
                ...sale,
                items: sale.items.map((i: any) => ({
                    ...i,
                    returned_quantity: "0.000",
                    remaining_quantity: i.quantity,
                })),
                remaining_payments: Object.fromEntries(
                    sale.payments.map((p: any) => [p.method, p.amount]),
                ),
                refunded_total: "0.00",
                returns: [],
            });
            setPending(null);
            setCart([]);
            setQuote(null);
            setHoldId(null);
            setCustomer(null);
            setRequestId(crypto.randomUUID());
            setModal(null);
            router.reload();
        } catch (e: any) {
            setError(errorText(e));
            if (!e.response) setPending(body);
            else if (e.response.data?.errors?.quote) {
                setPending(null);
                setModal(null);
                try {
                    const fresh = await axios.post(
                        "/pos/quote",
                        JSON.parse(cartSignature),
                    );
                    setQuote(fresh.data.data);
                } catch (err) {
                    setQuote(null);
                    setError(errorText(err));
                }
            } else if (
                e.response.data?.errors?.stock ||
                e.response.data?.errors?.session
            ) {
                setPending(null);
                setQuote(null);
                setModal(null);
                router.reload();
            }
        } finally {
            setBusy(false);
        }
    };
    const filtered = p.variants.filter(
        (v) =>
            (department === "all" || String(v.department_id) === department) &&
            [v.product_name, v.name, v.sku, v.barcode, v.brand]
                .join(" ")
                .toLowerCase()
                .includes(search.toLowerCase()),
    );
    return (
        <div className="pos-workspace"><CurrencyDrawerPanel session={session} safes={(p.foreign_safes??[]).filter(a=>a.branch_id===p.warehouses.find(w=>w.id===session?.warehouse_id)?.branch_id)}/>
            <div className="pos-training">
                <span className="pos-training-dot" />
                <div>
                    <strong>
                        {p.live_available
                            ? "LIVE ACCOUNTING"
                            : p.training_available
                              ? "TRAINING MODE"
                              : "LIVE CHECKOUT LOCKED"}
                    </strong>
                    <span>
                        {p.live_available
                            ? "Sales, stock cost and settlements post together to the live ledger. Payments are manually recorded."
                            : p.training_available
                              ? "Training stock and payment records. Accounting, when configured, uses a separate training book. No gateway processing."
                              : "Configure accounting and activate live retail before checkout."}
                    </span>
                </div>
            </div>
            <div className="pos-toolbar">
                <div className="inventory-tabs pos-tabs">
                    {["sell", "transactions", "shifts", "customers"].map(
                        (t) => (
                            <button
                                key={t}
                                className={tab === t ? "selected" : ""}
                                onClick={() => setTab(t)}
                            >
                                {
                                    {
                                        sell: "Sell",
                                        transactions: "Transactions",
                                        shifts: "Cashier shifts",
                                        customers: "Customers",
                                    }[t]
                                }
                            </button>
                        ),
                    )}
                </div>
                <div className="pos-shift-state">
                    <Wallet size={17} />
                    {session ? (
                        <>
                            <span>Shift #{session.id} · Open</span>
                            {can("pos.shift") && (
                                <button
                                    className="secondary"
                                    onClick={() => {
                                        setClosingSession(session);
                                        setModal("close");
                                    }}
                                >
                                    Close shift
                                </button>
                            )}
                        </>
                    ) : can("pos.shift") ? (
                        <button
                            className="primary"
                            disabled={
                                !(p.training_available || p.live_available) ||
                                !warehouse
                            }
                            onClick={() => setModal("open")}
                        >
                            Open shift
                        </button>
                    ) : (
                        <span>Read-only access</span>
                    )}
                </div>
            </div>
            <Errors error={modal ? "" : error} />
            {tab === "sell" && (
                <div className="pos-register">
                    <section className="pos-catalogue">
                        <div className="pos-search">
                            <Search size={19} />
                            <input
                                ref={input}
                                placeholder="Search products, SKU or scan barcode…"
                                aria-label="Search or scan barcode"
                                value={search}
                                onChange={(e) => {
                                    setSearch(e.target.value);
                                    setLimit(48);
                                }}
                                onKeyDown={(e) => {
                                    if (e.key === "Enter") {
                                        e.preventDefault();
                                        const match = p.variants.find(
                                            (v) =>
                                                v.barcode === search.trim() ||
                                                v.sku === search.trim(),
                                        );
                                        if (match) {
                                            add(match);
                                            setSearch("");
                                        } else
                                            setError(
                                                "No exact SKU or barcode match. Select a product from the results.",
                                            );
                                    }
                                }}
                            />
                            <button type="button" className="secondary pos-camera-button" onClick={openCameraScanner} aria-label="Scan barcode with camera"><Camera size={17} /><span>Scan</span></button>
                            <kbd>F2</kbd>
                        </div>
                        <div className="pos-categories">
                            <button
                                className={
                                    department === "all" ? "selected" : ""
                                }
                                onClick={() => setDepartment("all")}
                            >
                                All products
                            </button>
                            {p.departments.map((d) => (
                                <button
                                    className={
                                        department === String(d.id)
                                            ? "selected"
                                            : ""
                                    }
                                    key={d.id}
                                    onClick={() => setDepartment(String(d.id))}
                                >
                                    {d.name}
                                </button>
                            ))}
                        </div>
                        <div className="pos-catalogue-heading">
                            <span>
                                {filtered.length} products · prices before tax
                            </span>
                            <button
                                className="pos-cart-shortcut secondary"
                                onClick={() =>
                                    document
                                        .getElementById("pos-cart")
                                        ?.scrollIntoView({ behavior: "smooth" })
                                }
                            >
                                View cart ({cart.length})
                            </button>
                        </div>
                        <div className="pos-products">
                            {filtered.slice(0, limit).map((v, index) => (
                                <button
                                    key={v.id}
                                    className="pos-product"
                                    disabled={
                                        !can("pos.sell") ||
                                        !!pending ||
                                        (v.item_type !== "service" && scaled(available(v.id), 3) <= 0n)
                                    }
                                    onClick={() => add(v)}
                                >
                                    <div
                                        className={
                                            "pos-product-art tone-" +
                                            (index % 5)
                                        }
                                    >
                                        <PackageTile name={v.product_name} />
                                        <span className="pos-product-add">
                                            <Plus size={17} />
                                        </span>
                                    </div>
                                    <div className="pos-product-info">
                                        <strong>{v.product_name}</strong>
                                        <span>{v.name}</span>
                                        <small>{v.sku}</small>
                                        <div>
                                            <b>
                                                {p.currency} {v.selling_price}
                                            </b>
                                            <small>
                                                {v.item_type === "service" ? "Service · no stock required" : `${available(v.id)} in stock`}
                                            </small>
                                        </div>
                                    </div>
                                </button>
                            ))}
                        </div>
                        {!filtered.length && (
                            <div className="inventory-empty">
                                <ShoppingBag size={32} />
                                <h3>No matching products</h3>
                                <p className="muted">
                                    Active physical items and services appear here.
                                </p>
                            </div>
                        )}
                        {filtered.length > limit && (
                            <button
                                className="secondary wide"
                                onClick={() => setLimit(limit + 48)}
                            >
                                Show more products
                            </button>
                        )}
                    </section>
                    <aside className="pos-cart" id="pos-cart">
                        <div className="pos-cart-heading">
                            <div>
                                <span className="eyebrow">CURRENT SALE</span>
                                <h2>A good day at the till.</h2>
                            </div>
                            <span className="pos-item-count">
                                {cart.length}
                            </span>
                        </div>
                        <label className="field">
                            Selling warehouse
                            <select
                                disabled={
                                    !!session || cart.length > 0 || !!pending
                                }
                                value={warehouse}
                                onChange={(e) =>
                                    setWarehouse(Number(e.target.value))
                                }
                            >
                                {p.warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="pos-customer-select">
                            <label className="field">
                                Customer
                                <select
                                    disabled={!!pending}
                                    value={customer ?? ""}
                                    onChange={(e) =>
                                        (() => {
                                            const next = e.target.value ? Number(e.target.value) : null;
                                            setCustomer(next);
                                            if (!p.customers.find((c) => c.id === next)?.loyalty_enrolled) {
                                                setPayments((rows) => {
                                                    const otherRows = rows.filter((row) => row.method !== "loyalty_points");
                                                    return otherRows.length ? otherRows : rows.map((row) => row.method === "loyalty_points" ? { ...row, method: "cash", reference: "" } : row);
                                                });
                                            }
                                        })()
                                    }
                                >
                                    <option value="">Walk-in customer</option>
                                    {p.customers.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                            {c.phone ? " · " + c.phone : ""}
                                            {c.loyalty_enrolled ? " · rewards member" : ""}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {can("pos.customer") && (
                                <button
                                    className="icon-button"
                                    aria-label="Quick customer"
                                    onClick={() => setModal("customer")}
                                >
                                    <UserPlus size={18} />
                                </button>
                            )}
                        </div>
                        <div className="pos-cart-lines">
                            {!cart.length ? (
                                <div className="pos-empty-cart">
                                    <ShoppingBag size={34} />
                                    <h3>Ready when you are.</h3>
                                    <p>
                                        Select a product or scan a barcode to
                                        start a sale.
                                    </p>
                                </div>
                            ) : (
                                cart.map((line) => {
                                    const v = p.variants.find(
                                        (v) => v.id === line.variant_id,
                                    );
                                    return (
                                        <div
                                            className="pos-cart-line"
                                            key={line.variant_id}
                                        >
                                            <div className="pos-line-title">
                                                <div>
                                                    <strong>
                                                        {v?.product_name ??
                                                            "Unavailable product"}
                                                    </strong>
                                                    <small>
                                                        {v?.name} · {v?.sku}
                                                    </small>
                                                </div>
                                                <button
                                                    disabled={!!pending}
                                                    className="icon-button"
                                                    aria-label={
                                                        "Remove " + v?.sku
                                                    }
                                                    onClick={() =>
                                                        setCart(
                                                            cart.filter(
                                                                (l) =>
                                                                    l.variant_id !==
                                                                    line.variant_id,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 size={16} />
                                                </button>
                                            </div>
                                            <div className="pos-line-controls">
                                                <div className="pos-quantity">
                                                    <button
                                                        type="button"
                                                        disabled={!!pending}
                                                        aria-label={
                                                            "Decrease " + v?.sku
                                                        }
                                                        onClick={() => {
                                                            const n =
                                                                scaled(
                                                                    line.quantity,
                                                                    3,
                                                                ) - 1000n;
                                                            setCart(
                                                                n <= 0n
                                                                    ? cart.filter(
                                                                          (l) =>
                                                                              l.variant_id !==
                                                                              line.variant_id,
                                                                      )
                                                                    : cart.map(
                                                                          (
                                                                              l,
                                                                          ) =>
                                                                              l.variant_id ===
                                                                              line.variant_id
                                                                                  ? {
                                                                                        ...l,
                                                                                        quantity:
                                                                                            decimal(
                                                                                                n,
                                                                                                3,
                                                                                            ),
                                                                                    }
                                                                                  : l,
                                                                      ),
                                                            );
                                                        }}
                                                    >
                                                        <Minus size={14} />
                                                    </button>
                                                    <input
                                                        aria-label={
                                                            "Quantity " + v?.sku
                                                        }
                                                        disabled={!!pending}
                                                        inputMode="decimal"
                                                        value={line.quantity}
                                                        onChange={(e) =>
                                                            setCart(
                                                                cart.map((l) =>
                                                                    l.variant_id ===
                                                                    line.variant_id
                                                                        ? {
                                                                              ...l,
                                                                              quantity:
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : l,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            !!pending || !v
                                                        }
                                                        aria-label={
                                                            "Increase " + v?.sku
                                                        }
                                                        onClick={() =>
                                                            v && add(v)
                                                        }
                                                    >
                                                        <Plus size={14} />
                                                    </button>
                                                </div>
                                                <label className="pos-discount">
                                                    Discount %
                                                    <input
                                                        disabled={!!pending}
                                                        inputMode="decimal"
                                                        value={
                                                            line.discount_percent
                                                        }
                                                        onChange={(e) =>
                                                            setCart(
                                                                cart.map((l) =>
                                                                    l.variant_id ===
                                                                    line.variant_id
                                                                        ? {
                                                                              ...l,
                                                                              discount_percent:
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : l,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                </label>
                                                <strong>
                                                    {quote?.items.find(
                                                        (i) =>
                                                            i.variant_id ===
                                                            line.variant_id,
                                                    )?.total ?? "—"}
                                                </strong>
                                            </div>
                                        </div>
                                    );
                                })
                            )}
                        </div>
                        <div className="pos-totals">
                            <div>
                                <span>Subtotal</span>
                                <span>{quote?.subtotal ?? "0.00"}</span>
                            </div>
                            <div>
                                <span>Discount</span>
                                <span>{quote?.discount ?? "0.00"}</span>
                            </div>
                            <div>
                                <span>Tax</span>
                                <span>{quote?.tax ?? "0.00"}</span>
                            </div>
                            <div className="pos-grand-total">
                                <span>Total · {p.currency}</span>
                                <strong>
                                    {quoting ? "…" : (quote?.total ?? "0.00")}
                                </strong>
                            </div>
                        </div>
                        <div className="pos-cart-actions">
                            <button
                                className="secondary"
                                disabled={!cart.length || !!pending}
                                onClick={() => setModal("hold")}
                            >
                                <Pause size={15} />
                                Hold sale
                            </button>
                            <button
                                className="secondary"
                                disabled={!cart.length || !!pending}
                                onClick={() => {
                                    setCart([]);
                                    setHoldId(null);
                                }}
                            >
                                Clear cart
                            </button>
                        </div>
                        <button
                            className="primary pos-pay"
                            disabled={
                                !quote ||
                                quoting ||
                                !session ||
                                !(p.training_available || p.live_available) ||
                                !can("pos.sell")
                            }
                            onClick={startPayment}
                        >
                            Take payment
                            <ArrowRight size={18} />
                        </button>
                        <small className="pos-shortcut">
                            {session
                                ? "F9 · Take payment"
                                : "Open a shift before checkout"}{" "}
                            ·{" "}
                            {p.live_available
                                ? "Retail receipt"
                                : "Training receipt only"}
                        </small>
                        {p.holds.length > 0 && (
                            <div className="pos-held">
                                <h3>Held sales</h3>
                                {p.holds.map((h) => (
                                    <div key={h.id}>
                                        <button
                                            className="text-link"
                                            disabled={
                                                !!pending ||
                                                h.user_id !== p.current_user_id
                                            }
                                            onClick={() => {
                                                if (
                                                    session &&
                                                    session.warehouse_id !==
                                                        h.warehouse_id
                                                ) {
                                                    setError(
                                                        "Close your current shift and open one in the held cart warehouse first.",
                                                    );
                                                    return;
                                                }
                                                if (cart.length) {
                                                    setError(
                                                        "Hold or clear your current cart before resuming another sale.",
                                                    );
                                                    return;
                                                }
                                                setWarehouse(h.warehouse_id);
                                                setCart(h.cart.lines);
                                                setCustomer(h.cart.customer_id);
                                                setHoldId(h.id);
                                                setError("");
                                            }}
                                        >
                                            <Play size={14} />
                                            {h.label}
                                        </button>
                                        <button
                                            className="icon-button"
                                            aria-label={
                                                "Cancel held sale " + h.label
                                            }
                                            disabled={
                                                !can("pos.sell") || !!pending
                                            }
                                            onClick={async () => {
                                                try {
                                                    await axios.post(
                                                        "/pos/holds/" +
                                                            h.id +
                                                            "/cancel",
                                                    );
                                                    router.reload();
                                                } catch (e) {
                                                    setError(errorText(e));
                                                }
                                            }}
                                        >
                                            <X size={15} />
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </aside>
                </div>
            )}
            {tab === "transactions" && (
                <section className="panel">
                    <div className="section-heading">
                        <div>
                            <h2>Recent transactions</h2>
                            <form
                                className="pos-receipt-search"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.get(
                                        "/pos",
                                        { receipt: receiptSearch },
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    );
                                }}
                            >
                                <input
                                    className="search"
                                    aria-label="Find receipt number"
                                    placeholder="Search receipt number"
                                    value={receiptSearch}
                                    onChange={(e) =>
                                        setReceiptSearch(e.target.value)
                                    }
                                />
                                <button className="secondary">
                                    Search receipts
                                </button>
                            </form>
                            <p className="muted">
                                Latest 50 authorized sales. Open a receipt to
                                view returnable quantities.
                            </p>
                        </div>
                        <button
                            className="secondary"
                            onClick={() => router.reload()}
                        >
                            Refresh
                        </button>
                    </div>
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Receipt</th>
                                    <th>Customer / cashier</th>
                                    <th>Time</th>
                                    <th>Total</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {p.sales.map((s) => (
                                    <tr key={s.id}>
                                        <td>
                                            <strong>{s.receipt_number}</strong>
                                            <small>
                                                {s.mode === "live"
                                                    ? "Live"
                                                    : "Training"}
                                            </small>
                                        </td>
                                        <td>
                                            {s.customer_name ??
                                                "Walk-in customer"}
                                            <small>{s.cashier_name}</small>
                                        </td>
                                        <td>
                                            {new Date(
                                                s.created_at,
                                            ).toLocaleString()}
                                        </td>
                                        <td>
                                            {s.currency} {s.total}
                                        </td>
                                        <td>
                                            <button
                                                className="secondary"
                                                onClick={async () => {
                                                    try {
                                                        const r =
                                                            await axios.get(
                                                                "/pos/sales/" +
                                                                    s.id,
                                                            );
                                                        setReceipt(r.data.data);
                                                    } catch (e) {
                                                        setError(errorText(e));
                                                    }
                                                }}
                                            >
                                                View receipt
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
            {tab === "shifts" && (
                <section className="panel">
                    <div className="section-heading">
                        <h2>Cashier shifts & daily summaries</h2>
                        <label className="field">
                            Summary date
                            <input
                                type="date"
                                value={p.daily.date}
                                onChange={(e) => {
                                    if (e.target.value)
                                        router.get(
                                            "/pos",
                                            { date: e.target.value },
                                            {
                                                preserveState: true,
                                                preserveScroll: true,
                                            },
                                        );
                                }}
                            />
                        </label>
                    </div>
                    <p className="muted">
                        {p.daily.date} · {p.daily.timezone} ·{" "}
                        {p.daily.sale_count} sales / {p.daily.return_count}{" "}
                        returns · authorized records.
                    </p>
                    <div className="stat-grid">
                        {[
                            {
                                label: "Recorded sales",
                                value: p.daily.gross_sales,
                            },
                            { label: "Sales tax", value: p.daily.tax },
                            {
                                label: "Recorded refunds",
                                value: p.daily.refunds,
                            },
                            {
                                label: "Net recorded",
                                value: p.daily.net_recorded,
                            },
                        ].map((m) => (
                            <section key={m.label} className="stat-card">
                                <div>{m.label}</div>
                                <strong>{m.value}</strong>
                                <small>{p.currency}</small>
                            </section>
                        ))}
                    </div>
                    <p className="muted">
                        Each shift shows received payments, refunds, expected
                        cash and closing variance.
                    </p>
                    {p.sessions.map((s) => (
                        <article className="pos-shift-card" key={s.id}>
                            <div className="section-heading">
                                <h3>
                                    Shift #{s.id} ·{" "}
                                    {
                                        p.warehouses.find(
                                            (w) => w.id === s.warehouse_id,
                                        )?.name
                                    }
                                </h3>
                                <div className="inventory-actions">
                                    <span className="soft-badge">
                                        {s.status}
                                    </span>
                                    {s.status === "open" &&
                                        can("pos.manage") &&
                                        can("pos.shift") &&
                                        s.id !== session?.id && (
                                            <button
                                                className="secondary"
                                                onClick={() => {
                                                    setClosingSession(s);
                                                    setModal("close");
                                                }}
                                            >
                                                Reconcile shift #{s.id}
                                            </button>
                                        )}
                                </div>
                            </div>
                            <p className="muted">
                                {s.cashier_name ?? "Cashier"} · Opened{" "}
                                {new Date(s.opened_at).toLocaleString()} ·{" "}
                                {s.summary.sale_count} sales ·{" "}
                                {s.summary.return_count} returns
                                {s.closed_by_name
                                    ? " · Closed by " + s.closed_by_name
                                    : ""}
                            </p>
                            <div className="pos-shift-metrics">
                                <span>
                                    Sales{" "}
                                    <strong>
                                        {s.currency} {s.summary.gross_sales}
                                    </strong>
                                </span>
                                <span>
                                    Refunds <strong>{s.summary.refunds}</strong>
                                </span>
                                <span>
                                    Expected cash{" "}
                                    <strong>
                                        {s.expected_cash ??
                                            s.summary.expected_cash}
                                    </strong>
                                </span>
                                <span>
                                    Counted / variance{" "}
                                    <strong>
                                        {s.counted_cash ?? "—"} /{" "}
                                        {s.variance ?? "—"}
                                    </strong>
                                </span>
                            </div>
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Method</th>
                                            <th>Received</th>
                                            <th>Refunded</th>
                                            <th>Net</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {Object.entries(s.summary.methods).map(
                                            ([method, v]) => (
                                                <tr key={method}>
                                                    <td>
                                                        {method.replaceAll(
                                                            "_",
                                                            " ",
                                                        )}
                                                    </td>
                                                    <td>{v.received}</td>
                                                    <td>{v.refunded}</td>
                                                    <td>{v.net}</td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    ))}
                </section>
            )}
            {tab === "customers" && (
                <section className="panel">
                    <div className="section-heading">
                        <h2>Customers</h2>
                        {can("pos.customer") && (
                            <button
                                className="primary"
                                onClick={() => setModal("customer")}
                            >
                                <Plus size={16} />
                                Add customer
                            </button>
                        )}
                    </div>
                    <input
                        className="search"
                        aria-label="Find customer"
                        placeholder="Search name, phone or email"
                        value={customerQuery}
                        onChange={(e) => setCustomerQuery(e.target.value)}
                    />
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Email</th>
                                    {p.loyalty_available && <th>Rewards</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {p.customers
                                    .filter((c) =>
                                        (c.name + " " + c.phone + " " + c.email)
                                            .toLowerCase()
                                            .includes(
                                                customerQuery.toLowerCase(),
                                            ),
                                    )
                                    .map((c) => (
                                        <tr key={c.id}>
                                            <td>{c.name}</td>
                                            <td>{c.phone ?? "—"}</td>
                                            <td>{c.email ?? "—"}</td>
                                            {p.loyalty_available && <td>{c.loyalty_enrolled ? c.loyalty_number : "Not enrolled"}</td>}
                                        </tr>
                                    ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
            {modal === "open" && (
                <ShiftOpen
                    warehouse={warehouse}
                    currency={p.currency}
                    close={() => setModal(null)}
                />
            )}{" "}
            {modal === "close" && (closingSession ?? session) && (
                <ShiftClose
                    session={(closingSession ?? session)!}
                    close={() => {
                        setModal(null);
                        setClosingSession(null);
                    }}
                />
            )}{" "}
            {modal === "customer" && (
                <CustomerForm
                    close={() => setModal(null)}
                    created={setCustomer}
                    loyaltyAvailable={p.loyalty_available}
                />
            )}{" "}
            {modal === "hold" && (
                <Modal title="Hold current sale" close={() => setModal(null)}>
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            try {
                                await axios.post("/pos/holds", {
                                    request_id: crypto.randomUUID(),
                                    warehouse_id: warehouse,
                                    customer_id: customer,
                                    lines: cart,
                                    label: holdLabel,
                                });
                                setCart([]);
                                setHoldId(null);
                                setHoldLabel("");
                                setModal(null);
                                router.reload();
                            } catch (e) {
                                setError(errorText(e));
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <p className="muted">
                            Held carts reserve neither stock nor price. Prices
                            are checked again at checkout.
                        </p>
                        <label className="field">
                            Sale label
                            <input
                                autoFocus
                                value={holdLabel}
                                onChange={(e) => setHoldLabel(e.target.value)}
                            />
                        </label>
                        <Errors error={error} />
                        <button className="primary" disabled={busy}>
                            Hold sale
                        </button>
                    </form>
                </Modal>
            )}
            {modal === "pay" && quote && (
                <Modal title="Take payment" close={() => setModal(null)}>
                    <div className="pos-grand-total">
                        <span>Total due</span>
                        <strong>
                            {quote.currency} {quote.total}
                        </strong>
                    </div>
                    {loyaltyBalance && <p className="notice">Rewards balance: <strong>{loyaltyBalance.points} points</strong> · value {loyaltyBalance.currency} {loyaltyBalance.value}</p>}
                    <Tenders
                        rows={payments}
                        setRows={setPayments}
                        disabled={!!pending}
                        includeLoyalty={p.loyalty_available && !!p.customers.find((c) => c.id === customer)?.loyalty_enrolled}
                    />
                    <CurrencyTenderFields drawers={currencyDrawers} amounts={fxTenders} setAmounts={setFxTenders} changeDrawer={fxChange} setChangeDrawer={setFxChange} disabled={!!pending}/>
                    <div className="pos-payment-summary">
                        <span>
                            Tendered <strong>{decimal(tender)}</strong>
                        </span>
                        <span>
                            {change < 0n ? "Still due" : "Cash change"}{" "}
                            <strong>
                                {decimal(change < 0n ? -change : change)}
                            </strong>
                        </span>
                    </div>
                    <Errors error={error} />
                    <button
                        className="primary wide"
                        disabled={busy}
                        onClick={finish}
                    >
                        {busy
                            ? "Confirming…"
                            : pending
                              ? "Retry same payment"
                              : p.live_available
                                ? "Complete sale"
                                : "Complete training sale"}
                        <Check size={17} />
                    </button>
                </Modal>
            )}
            {receipt && (
                <Modal
                    title={receipt.receipt_number}
                    close={() => setReceipt(null)}
                >
                    <span className="soft-badge">
                        {receipt.mode === "live"
                            ? "RETAIL RECEIPT"
                            : "TRAINING · NOT A TAX INVOICE"}
                    </span>
                    <div className="pos-grand-total">
                        <span>Total</span>
                        <strong>
                            {receipt.currency} {receipt.total}
                        </strong>
                    </div>
                    {receipt.items.map((i) => (
                        <div className="pos-receipt-line" key={i.id}>
                            <strong>{i.description}</strong>
                            <span>
                                Qty {i.quantity} · Total {i.total}
                            </span>
                            <small>{i.remaining_quantity} returnable</small>
                        </div>
                    ))}
                    <p className="muted">
                        Change base equivalent {receipt.change} · Refunded{" "}
                        {receipt.refunded_total}
                    </p>
                    <div className="inventory-actions">
                        <a
                            className="primary"
                            href={"/pos/sales/" + receipt.id + "/receipt"}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Printer size={16} />
                            Print / reprint
                        </a>
                        {can("pos.refund") &&
                            receipt.items.some(
                                (i) => scaled(i.remaining_quantity, 3) > 0n,
                            ) && (
                                <button
                                    className="secondary"
                                    onClick={() => {
                                        setRefund(receipt);
                                        setReceipt(null);
                                    }}
                                >
                                    <RotateCcw size={16} />
                                    Return items
                                </button>
                            )}
                    </div>
                    {receipt.returns.map((r) => (
                        <p key={r.id}>
                            <a
                                className="text-link"
                                href={"/pos/returns/" + r.id + "/receipt"}
                                target="_blank"
                                rel="noreferrer"
                            >
                                {r.receipt_number} · {r.total}
                            </a>
                        </p>
                    ))}
                </Modal>
            )}
            {refund && (
                <ReturnForm
                    sale={refund}
                    session={session}
                    close={() => setRefund(null)}
                />
            )}
            {cameraOpen && <div className="modal-backdrop"><section className="modal pos-camera-modal" role="dialog" aria-modal="true" aria-label="Scan product barcode"><div className="section-heading"><div><span className="eyebrow">MOBILE SCANNER</span><h2>Scan a product barcode</h2><p>Point the rear camera at a barcode. You can also use a hardware scanner in the product search field.</p></div><button type="button" className="icon-button" aria-label="Close camera scanner" onClick={() => setCameraOpen(false)}><X size={18} /></button></div>{cameraStream.current && <video ref={videoRef} autoPlay muted playsInline style={{ display: "block", width: "100%", maxHeight: "52vh", background: "#10221c", borderRadius: 12, objectFit: "cover" }} />}<p role="status" aria-live="polite" className={cameraMessage ? "error-text" : "muted"}>{cameraMessage || "Hold the barcode steady inside the camera view."}</p><div className="section-actions"><button type="button" className="secondary" onClick={() => { setCameraOpen(false); input.current?.focus(); }}>Use search field</button></div></section></div>}
        </div>
    );
}
function PackageTile({ name }: { name: string }) {
    return (
        <>
            <ShoppingBag size={34} strokeWidth={1.2} />
            <span>
                {name
                    .split(" ")
                    .map((s) => s[0])
                    .slice(0, 2)
                    .join("")
                    .toUpperCase()}
            </span>
        </>
    );
}
