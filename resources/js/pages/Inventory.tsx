import React, { useState } from "react";
import { router, useForm } from "@inertiajs/react";
import { Package, Plus, X, Download } from "lucide-react";
import { useDialog } from "../useDialog";
import AttributeEditor from "./AttributeEditor";
type Variant = {
    id: number;
    product_id: number;
    name: string;
    sku: string;
    barcode: string | null;
    cost_price: string;
    selling_price: string;
    wholesale_price: string;
    reorder_level: string;
    minimum_stock: string;
    maximum_stock: string | null;
    active: boolean;
    attributes: Record<string, string>;
};
type Product = {
    item_type?: "stock" | "service";
    id: number;
    name: string;
    description: string | null;
    brand: string | null;
    unit: string;
    tax_class: string;
    warranty_months: number;
    department_id: number | null;
    category_id: number | null;
    subcategory_id: number | null;
    active: boolean;
    attributes: Record<string, string>;
    variants: Variant[];
};
type Classification = {
    id: number;
    name: string;
    level: string;
    parent_id: number | null;
};
type Warehouse = { id: number; name: string; branch_id: number };
type Count = {
    id: number;
    reference: string;
    warehouse_id: number;
    status: string;
    lines: {
        variant_id: number;
        expected_quantity: string;
        variant: Variant;
    }[];
};
export type InventoryProps = {
    products?: Product[];
    classifications?: Classification[];
    warehouses?: Warehouse[];
    balances?: {
        id: number;
        warehouse_id: number;
        variant_id: number;
        quantity: string;
        average_cost: string;
        value: string;
    }[];
    movements?: {
        id: number;
        warehouse_id: number;
        quantity: string;
        value: string;
        variant: Variant;
        document: { reference: string; type: string; reason: string };
    }[];
    counts?: Count[];
};
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
                className="modal"
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
function Errors({ errors }: { errors: Record<string, string> }) {
    return Object.keys(errors).length ? (
        <div className="notice error" role="alert">
            {Object.values(errors).join(" ")}
        </div>
    ) : null;
}
function ProductForm({
    product,
    classes,
    close,
}: {
    product?: Product;
    classes: Classification[];
    close: () => void;
}) {
    const f = useForm({
        item_type: product?.item_type ?? "stock",
        name: product?.name ?? "",
        description: product?.description ?? "",
        brand: product?.brand ?? "",
        unit: product?.unit ?? "each",
        tax_class: product?.tax_class ?? "standard",
        warranty_months: product?.warranty_months ?? 0,
        department_id: product?.department_id ?? "",
        category_id: product?.category_id ?? "",
        subcategory_id: product?.subcategory_id ?? "",
        attributes: product?.attributes ?? {},
        active: product?.active ?? true,
    });
    const [attributes, setAttributes] = useState(
        JSON.stringify(product?.attributes ?? {}),
    );
    const [error, setError] = useState("");
    return (
        <Modal title={product ? "Edit product" : "New product"} close={close}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    try {
                        const a = JSON.parse(attributes);
                        if (!a || Array.isArray(a) || typeof a !== "object")
                            throw Error();
                        f.transform((d) => ({ ...d, attributes: a }));
                        product
                            ? f.patch("/inventory/products/" + product.id, {
                                  onSuccess: close,
                              })
                            : f.post("/inventory/products", {
                                  onSuccess: close,
                              });
                    } catch {
                        setError("Custom attributes must be a JSON object.");
                    }
                }}
            >
                <label className="field">
                    Item type
                    <select
                        value={f.data.item_type}
                        onChange={(e) =>
                            f.setData(
                                "item_type",
                                e.target.value as "stock" | "service",
                            )
                        }
                    >
                        <option value="stock">Physical stock</option>
                        <option value="service">Service</option>
                    </select>
                    <small className="muted">
                        {f.data.item_type === "service"
                            ? "For software, licences, consulting and other services. No stock balance or opening stock is required to invoice."
                            : "Quantities are tracked by warehouse. Stock is required before selling."}
                    </small>
                </label>
                <div className="form-grid">
                    {(["name", "brand", "unit", "tax_class"] as const).map(
                        (k) => (
                            <label className="field" key={k}>
                                {k === "name"
                                    ? "Product name"
                                    : k.replaceAll("_", " ")}
                                <input
                                    value={f.data[k]}
                                    onChange={(e) =>
                                        f.setData(k, e.target.value)
                                    }
                                    autoFocus={k === "name"}
                                />
                            </label>
                        ),
                    )}
                </div>
                <label className="field">
                    Description
                    <textarea
                        value={f.data.description}
                        onChange={(e) =>
                            f.setData("description", e.target.value)
                        }
                    />
                </label>
                <div className="form-grid">
                    {["department", "category", "subcategory"].map(
                        (level, index) => {
                            const key = (level + "_id") as
                                | "department_id"
                                | "category_id"
                                | "subcategory_id";
                            const parent =
                                index === 1
                                    ? f.data.department_id
                                    : f.data.category_id;
                            return (
                                <label className="field" key={key}>
                                    {level}
                                    <select
                                        value={f.data[key]}
                                        onChange={(e) => {
                                            f.setData(
                                                key,
                                                e.target.value
                                                    ? Number(e.target.value)
                                                    : "",
                                            );
                                            if (index < 2)
                                                f.setData("subcategory_id", "");
                                            if (index === 0)
                                                f.setData("category_id", "");
                                        }}
                                    >
                                        <option value="">Unclassified</option>
                                        {classes
                                            .filter(
                                                (c) =>
                                                    c.level === level &&
                                                    (index === 0 ||
                                                        c.parent_id ===
                                                            Number(parent)),
                                            )
                                            .map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                    </select>
                                </label>
                            );
                        },
                    )}
                    <label className="field">
                        Warranty months
                        <input
                            type="number"
                            min="0"
                            max="240"
                            value={f.data.warranty_months}
                            onChange={(e) =>
                                f.setData(
                                    "warranty_months",
                                    Number(e.target.value),
                                )
                            }
                        />
                    </label>
                </div>
                <AttributeEditor
                    initial={product?.attributes ?? {}}
                    onChange={(value) => setAttributes(JSON.stringify(value))}
                />
                <label className="checkbox">
                    <input
                        type="checkbox"
                        checked={f.data.active}
                        onChange={(e) => f.setData("active", e.target.checked)}
                    />
                    Active product
                </label>
                <Errors errors={f.errors} />
                {error && <p className="error">{error}</p>}
                <p className="muted">
                    After saving, add a SKU and selling price. Services can be
                    invoiced immediately; physical items require stock.
                </p>
                <button className="primary" disabled={f.processing}>
                    Save product
                </button>
            </form>
        </Modal>
    );
}
function VariantForm({
    product,
    variant,
    close,
}: {
    product: Product;
    variant?: Variant;
    close: () => void;
}) {
    const f = useForm({
        name: variant?.name ?? "Standard",
        sku: variant?.sku ?? "",
        barcode: variant?.barcode ?? "",
        cost_price: variant?.cost_price ?? "0",
        selling_price: variant?.selling_price ?? "0",
        wholesale_price: variant?.wholesale_price ?? "0",
        reorder_level: variant?.reorder_level ?? "0",
        minimum_stock: variant?.minimum_stock ?? "0",
        maximum_stock: variant?.maximum_stock ?? "",
        active: variant?.active ?? true,
        attributes: variant?.attributes ?? {},
    });
    const [a, setA] = useState(JSON.stringify(variant?.attributes ?? {}));
    const [error, setError] = useState("");
    return (
        <Modal title={variant ? "Edit variant" : "Add variant"} close={close}>
            <p className="muted">
                {product.name} · each size, colour or specification gets its own
                SKU.
            </p>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    try {
                        const attrs = JSON.parse(a);
                        if (
                            !attrs ||
                            Array.isArray(attrs) ||
                            typeof attrs !== "object"
                        )
                            throw Error();
                        f.transform((d) => ({
                            ...d,
                            barcode: d.barcode || null,
                            maximum_stock: d.maximum_stock || null,
                            attributes: attrs,
                        }));
                        const url =
                            "/inventory/products/" + product.id + "/variants";
                        variant
                            ? f.patch(url + "/" + variant.id, {
                                  onSuccess: close,
                              })
                            : f.post(url, { onSuccess: close });
                    } catch {
                        setError("Attributes must be a JSON object.");
                    }
                }}
            >
                <div className="form-grid">
                    {(
                        [
                            "name",
                            "sku",
                            "barcode",
                            "cost_price",
                            "selling_price",
                            "wholesale_price",
                            "reorder_level",
                            "minimum_stock",
                            "maximum_stock",
                        ] as const
                    )
                        .filter(
                            (k) =>
                                product.item_type !== "service" ||
                                ![
                                    "cost_price",
                                    "reorder_level",
                                    "minimum_stock",
                                    "maximum_stock",
                                ].includes(k),
                        )
                        .map((k) => (
                            <label className="field" key={k}>
                                {k.replaceAll("_", " ")}
                                <input
                                    value={f.data[k]}
                                    onChange={(e) =>
                                        f.setData(k, e.target.value)
                                    }
                                />
                            </label>
                        ))}
                </div>
                <AttributeEditor
                    initial={variant?.attributes ?? {}}
                    onChange={(value) => setA(JSON.stringify(value))}
                />
                <label className="checkbox">
                    <input
                        type="checkbox"
                        checked={f.data.active}
                        onChange={(e) => f.setData("active", e.target.checked)}
                    />
                    Active variant
                </label>
                <Errors errors={f.errors} />
                {error && <p className="error">{error}</p>}
                <button className="primary" disabled={f.processing}>
                    Save variant
                </button>
            </form>
        </Modal>
    );
}
function MovementForm({
    variants,
    warehouses,
    close,
}: {
    variants: Variant[];
    warehouses: Warehouse[];
    close: () => void;
}) {
    const f = useForm({
        request_id: crypto.randomUUID(),
        warehouse_id: warehouses[0]?.id ?? "",
        type: "opening",
        reference: "",
        reason: "",
        lines: [
            { variant_id: variants[0]?.id ?? "", quantity: "", unit_cost: "0" },
        ],
    });
    return (
        <Modal title="Post stock movement" close={close}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    f.post("/inventory/movements", { onSuccess: close });
                }}
            >
                <label className="field">
                    Warehouse
                    <select
                        value={f.data.warehouse_id}
                        onChange={(e) =>
                            f.setData("warehouse_id", Number(e.target.value))
                        }
                    >
                        {warehouses.map((w) => (
                            <option key={w.id} value={w.id}>
                                {w.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="field">
                    Operation
                    <select
                        value={f.data.type}
                        onChange={(e) => f.setData("type", e.target.value)}
                    >
                        <option value="opening">Opening stock</option>
                        <option value="adjustment">Adjustment</option>
                    </select>
                </label>
                {(["reference", "reason"] as const).map((k) => (
                    <label className="field" key={k}>
                        {k}
                        <input
                            value={f.data[k]}
                            onChange={(e) => f.setData(k, e.target.value)}
                        />
                    </label>
                ))}
                {f.data.lines.map((line, index) => (
                    <div className="stock-line" key={index}>
                        <label className="field">
                            Variant
                            <select
                                value={line.variant_id}
                                onChange={(e) =>
                                    f.setData(
                                        "lines",
                                        f.data.lines.map((l, i) =>
                                            i === index
                                                ? {
                                                      ...l,
                                                      variant_id: Number(
                                                          e.target.value,
                                                      ),
                                                  }
                                                : l,
                                        ),
                                    )
                                }
                            >
                                {variants.map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.sku} · {v.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="form-grid">
                            {(["quantity", "unit_cost"] as const).map((k) => (
                                <label className="field" key={k}>
                                    {k === "quantity"
                                        ? "Quantity · negative removes stock"
                                        : "Incoming unit cost"}
                                    <input
                                        inputMode="decimal"
                                        value={line[k]}
                                        onChange={(e) =>
                                            f.setData(
                                                "lines",
                                                f.data.lines.map((l, i) =>
                                                    i === index
                                                        ? {
                                                              ...l,
                                                              [k]: e.target
                                                                  .value,
                                                          }
                                                        : l,
                                                ),
                                            )
                                        }
                                    />
                                </label>
                            ))}
                        </div>
                        {f.data.lines.length > 1 && (
                            <button
                                type="button"
                                className="secondary"
                                onClick={() =>
                                    f.setData(
                                        "lines",
                                        f.data.lines.filter(
                                            (_, i) => i !== index,
                                        ),
                                    )
                                }
                            >
                                Remove line
                            </button>
                        )}
                    </div>
                ))}
                <button
                    type="button"
                    className="secondary"
                    onClick={() =>
                        f.setData("lines", [
                            ...f.data.lines,
                            {
                                variant_id: variants[0]?.id ?? "",
                                quantity: "",
                                unit_cost: "0",
                            },
                        ])
                    }
                >
                    Add line
                </button>
                <div className="notice">
                    Posting is permanent. Correct errors with a new adjustment.
                    Outgoing stock uses weighted-average cost.
                </div>
                <Errors errors={f.errors} />
                <button className="primary" disabled={f.processing}>
                    Post stock movement
                </button>
            </form>
        </Modal>
    );
}
function CountForm({ count, close }: { count: Count; close: () => void }) {
    const f = useForm({
        lines: count.lines.map((l) => ({
            variant_id: l.variant_id,
            counted_quantity: "",
        })),
    });
    return (
        <Modal title={"Count · " + count.reference} close={close}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    f.post("/inventory/counts/" + count.id + "/post", {
                        onSuccess: close,
                    });
                }}
            >
                <p className="muted">
                    Enter all quantities. Stock movements after this snapshot
                    require a new count.
                </p>
                {count.lines.map((line, i) => (
                    <label className="field" key={line.variant_id}>
                        {line.variant.sku} · expected {line.expected_quantity}
                        <input
                            inputMode="decimal"
                            value={f.data.lines[i].counted_quantity}
                            onChange={(e) =>
                                f.setData(
                                    "lines",
                                    f.data.lines.map((l, j) =>
                                        j === i
                                            ? {
                                                  ...l,
                                                  counted_quantity:
                                                      e.target.value,
                                              }
                                            : l,
                                    ),
                                )
                            }
                        />
                    </label>
                ))}
                <Errors errors={f.errors} />
                <button className="primary" disabled={f.processing}>
                    Post count variances
                </button>
            </form>
        </Modal>
    );
}
function Setup({
    classes,
    branches,
    warehouses,
}: {
    classes: Classification[];
    branches: { id: number; name: string }[];
    warehouses: Warehouse[];
}) {
    const c = useForm({
        name: "",
        level: "department",
        parent_id: "" as string | number,
    });
    const w = useForm({ name: "", branch_id: branches[0]?.id ?? "" });
    return (
        <div className="inventory-two-columns">
            <section className="panel">
                <h2>Classifications</h2>
                <p className="muted">Department → Category → Subcategory</p>
                <div className="classification-list">
                    {classes.map((x) => (
                        <div key={x.id}>
                            <span className="soft-badge">{x.level}</span>
                            <strong>{x.name}</strong>
                            <small>
                                {
                                    classes.find((c) => c.id === x.parent_id)
                                        ?.name
                                }
                            </small>
                        </div>
                    ))}
                </div>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        c.transform((d) => ({
                            ...d,
                            parent_id: d.parent_id || null,
                        }));
                        c.post("/inventory/classifications", {
                            onSuccess: () => c.reset("name"),
                        });
                    }}
                >
                    <label className="field">
                        Classification name
                        <input
                            value={c.data.name}
                            onChange={(e) => c.setData("name", e.target.value)}
                        />
                    </label>
                    <label className="field">
                        Level
                        <select
                            value={c.data.level}
                            onChange={(e) => {
                                c.setData("level", e.target.value);
                                c.setData("parent_id", "");
                            }}
                        >
                            {["department", "category", "subcategory"].map(
                                (x) => (
                                    <option key={x}>{x}</option>
                                ),
                            )}
                        </select>
                    </label>
                    {c.data.level !== "department" && (
                        <label className="field">
                            Parent
                            <select
                                value={c.data.parent_id}
                                onChange={(e) =>
                                    c.setData(
                                        "parent_id",
                                        Number(e.target.value),
                                    )
                                }
                            >
                                <option value="">Choose a parent</option>
                                {classes
                                    .filter(
                                        (x) =>
                                            x.level ===
                                            (c.data.level === "category"
                                                ? "department"
                                                : "category"),
                                    )
                                    .map((x) => (
                                        <option key={x.id} value={x.id}>
                                            {x.name}
                                        </option>
                                    ))}
                            </select>
                        </label>
                    )}
                    <Errors errors={c.errors} />
                    <button className="primary" disabled={c.processing}>
                        Add classification
                    </button>
                </form>
            </section>
            <section className="panel">
                <h2>Warehouses</h2>
                {warehouses.map((x) => (
                    <div className="warehouse-row" key={x.id}>
                        <strong>{x.name}</strong>
                        <small>
                            {branches.find((b) => b.id === x.branch_id)?.name}
                        </small>
                    </div>
                ))}
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        w.post("/inventory/warehouses", {
                            onSuccess: () => w.reset("name"),
                        });
                    }}
                >
                    <label className="field">
                        Warehouse name
                        <input
                            value={w.data.name}
                            onChange={(e) => w.setData("name", e.target.value)}
                        />
                    </label>
                    <label className="field">
                        Branch
                        <select
                            value={w.data.branch_id}
                            onChange={(e) =>
                                w.setData("branch_id", Number(e.target.value))
                            }
                        >
                            {branches.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Errors errors={w.errors} />
                    <button className="primary" disabled={w.processing}>
                        Add warehouse
                    </button>
                </form>
            </section>
        </div>
    );
}
function ImportForm() {
    const f = useForm<{ file: File | null }>({ file: null });
    return (
        <section className="panel">
            <h2>Bring your catalogue with you</h2>
            <p className="muted">
                Import up to 1000 new variants from a CSV file, with their
                classifications. Every row must pass before anything is saved.
                Stock is posted separately.
            </p>
            <a className="secondary" href="/inventory/template">
                <Download size={16} />
                Download CSV template
            </a>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    f.post("/inventory/import", {
                        forceFormData: true,
                        onSuccess: () => f.reset(),
                    });
                }}
            >
                <label className="field">
                    Catalogue CSV
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        onChange={(e) =>
                            f.setData("file", e.target.files?.[0] ?? null)
                        }
                    />
                </label>
                <Errors errors={f.errors} />
                <button
                    className="primary"
                    disabled={!f.data.file || f.processing}
                >
                    Validate and import
                </button>
            </form>
            <div className="notice">
                Use plain decimals: quantities allow 3 decimal places, prices
                allow 4. CSV files can be edited in Excel. Existing SKUs are
                rejected rather than overwritten.
            </div>
        </section>
    );
}
export default function Inventory(
    p: InventoryProps & {
        permissions: string[];
        branches: { id: number; name: string }[];
        baseCurrency: string;
    },
) {
    const [tab, setTab] = useState("catalogue"),
        [search, setSearch] = useState(""),
        [warehouse, setWarehouse] = useState("");
    const [product, setProduct] = useState<Product | true | null>(null);
    const [variant, setVariant] = useState<{
        product: Product;
        variant?: Variant;
    } | null>(null);
    const [movement, setMovement] = useState(false);
    const [count, setCount] = useState<Count | null>(null);
    const start = useForm({
        warehouse_id: p.warehouses?.[0]?.id ?? "",
        reference: "",
        variant_ids: [] as number[],
    });
    const products = p.products ?? [],
        warehouses = p.warehouses ?? [],
        classes = p.classifications ?? [],
        variants = products
            .filter((x) => x.item_type !== "service")
            .flatMap((x) => x.variants),
        balances = p.balances ?? [];
    const can = (s: string) =>
        p.permissions.includes("*") || p.permissions.includes(s);
    return (
        <>
            <div className="inventory-tabs">
                {[
                    "catalogue",
                    "stock",
                    "counts",
                    ...(can("inventory.configure") ? ["setup"] : []),
                    ...(can("inventory.import") ? ["import"] : []),
                ].map((t) => (
                    <button
                        key={t}
                        className={tab === t ? "selected" : ""}
                        onClick={() => setTab(t)}
                    >
                        {
                            {
                                catalogue: "Catalogue",
                                stock: "Stock ledger",
                                counts: "Stock counts",
                                setup: "Setup",
                                import: "Import",
                            }[t]
                        }
                    </button>
                ))}
            </div>
            <div className="stat-grid">
                {[
                    { label: "Products & services", value: products.length },
                    {
                        label: "SKU variants",
                        value: products.flatMap((x) => x.variants).length,
                    },
                    { label: "Warehouses", value: warehouses.length },
                    {
                        label: "Open counts",
                        value:
                            p.counts?.filter((x) => x.status === "draft")
                                .length ?? 0,
                    },
                ].map((s) => (
                    <section className="stat-card" key={s.label}>
                        <div>{s.label}</div>
                        <strong>{s.value}</strong>
                    </section>
                ))}
            </div>
            {tab === "catalogue" && (
                <section className="panel">
                    <div className="section-heading">
                        <div>
                            <h2>Products & services catalogue</h2>
                            <p className="muted">
                                Physical products and services, with a SKU and
                                selling price for each option.
                            </p>
                        </div>
                        <div className="inventory-actions">
                            {can("inventory.export") && (
                                <a
                                    className="secondary"
                                    href="/inventory/export"
                                >
                                    Export CSV
                                </a>
                            )}
                            {can("inventory.configure") && (
                                <button
                                    className="primary"
                                    onClick={() => setProduct(true)}
                                >
                                    <Plus size={16} />
                                    Add product
                                </button>
                            )}
                        </div>
                    </div>
                    <input
                        className="search"
                        placeholder="Search product, SKU or barcode"
                        aria-label="Search inventory"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    {!products.length && (
                        <div className="inventory-empty">
                            <Package size={36} />
                            <h3>Your shelves start here.</h3>
                            <p className="muted">
                                Add a product, create its SKU variants, then
                                record opening stock.
                            </p>
                        </div>
                    )}
                    {products
                        .filter((x) =>
                            (
                                x.name +
                                " " +
                                x.brand +
                                " " +
                                x.variants
                                    .map(
                                        (v) =>
                                            v.sku +
                                            " " +
                                            v.barcode +
                                            " " +
                                            v.name,
                                    )
                                    .join(" ")
                            )
                                .toLowerCase()
                                .includes(search.toLowerCase()),
                        )
                        .map((product) => (
                            <article className="product-card" key={product.id}>
                                <div className="section-heading">
                                    <div>
                                        <h3>
                                            {product.name}
                                            <span className="muted">
                                                {" "}
                                                ·{" "}
                                                {product.item_type === "service"
                                                    ? "Service"
                                                    : "Physical stock"}
                                            </span>
                                            {!product.active
                                                ? " · Inactive"
                                                : ""}
                                        </h3>
                                        <p className="muted">
                                            {[
                                                product.brand,
                                                classes.find(
                                                    (c) =>
                                                        c.id ===
                                                        product.department_id,
                                                )?.name,
                                                classes.find(
                                                    (c) =>
                                                        c.id ===
                                                        product.category_id,
                                                )?.name,
                                                classes.find(
                                                    (c) =>
                                                        c.id ===
                                                        product.subcategory_id,
                                                )?.name,
                                            ]
                                                .filter(Boolean)
                                                .join(" / ") ||
                                                "General product"}{" "}
                                            · {product.unit}
                                        </p>
                                    </div>
                                    {can("inventory.configure") && (
                                        <div className="inventory-actions">
                                            <button
                                                className="secondary"
                                                onClick={() =>
                                                    setProduct(product)
                                                }
                                            >
                                                Edit product
                                            </button>
                                            <button
                                                className="secondary"
                                                onClick={() =>
                                                    setVariant({ product })
                                                }
                                            >
                                                Add variant
                                            </button>
                                        </div>
                                    )}
                                </div>
                                {product.variants.length ? (
                                    <div className="table-wrap">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Variant / SKU</th>
                                                    <th>Barcode</th>
                                                    <th>Attributes</th>
                                                    <th>
                                                        Cost · {p.baseCurrency}
                                                    </th>
                                                    <th>Selling price</th>
                                                    <th>Reorder</th>
                                                    <th />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {product.variants.map((v) => (
                                                    <tr key={v.id}>
                                                        <td>
                                                            <strong>
                                                                {v.name}
                                                            </strong>
                                                            <small>
                                                                {v.sku}
                                                                {!v.active
                                                                    ? " · Inactive"
                                                                    : ""}
                                                            </small>
                                                        </td>
                                                        <td>
                                                            {v.barcode || "—"}
                                                        </td>
                                                        <td>
                                                            {Object.entries(
                                                                v.attributes,
                                                            )
                                                                .map(
                                                                    ([k, v]) =>
                                                                        k +
                                                                        ": " +
                                                                        v,
                                                                )
                                                                .join(" · ") ||
                                                                "Standard"}
                                                        </td>
                                                        <td>{v.cost_price}</td>
                                                        <td>
                                                            {v.selling_price}
                                                        </td>
                                                        <td>
                                                            {product.item_type ===
                                                            "service"
                                                                ? "Not applicable"
                                                                : v.reorder_level}
                                                        </td>
                                                        <td>
                                                            {can(
                                                                "inventory.configure",
                                                            ) && (
                                                                <button
                                                                    className="secondary"
                                                                    onClick={() =>
                                                                        setVariant(
                                                                            {
                                                                                product,
                                                                                variant:
                                                                                    v,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Edit variant
                                                                </button>
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <p className="notice">
                                        Add a SKU and selling price before
                                        selling this item.
                                    </p>
                                )}
                            </article>
                        ))}
                </section>
            )}
            {tab === "stock" && (
                <>
                    <section className="panel">
                        <div className="section-heading">
                            <div>
                                <h2>Stock on hand</h2>
                                <p className="muted">
                                    Ledger-derived quantities and
                                    weighted-average valuation in{" "}
                                    {p.baseCurrency}.
                                </p>
                            </div>
                            {can("inventory.adjust") &&
                                variants.length > 0 &&
                                warehouses.length > 0 && (
                                    <button
                                        className="primary"
                                        onClick={() => setMovement(true)}
                                    >
                                        Post movement
                                    </button>
                                )}
                        </div>
                        <label className="field stock-filter">
                            Warehouse
                            <select
                                value={warehouse}
                                onChange={(e) => setWarehouse(e.target.value)}
                            >
                                <option value="">
                                    All accessible warehouses
                                </option>
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Warehouse</th>
                                        <th>On hand</th>
                                        <th>Average cost</th>
                                        <th>Stock value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {balances
                                        .filter(
                                            (b) =>
                                                !warehouse ||
                                                String(b.warehouse_id) ===
                                                    warehouse,
                                        )
                                        .map((b) => (
                                            <tr key={b.id}>
                                                <td>
                                                    {variants.find(
                                                        (v) =>
                                                            v.id ===
                                                            b.variant_id,
                                                    )?.sku ?? b.variant_id}
                                                </td>
                                                <td>
                                                    {
                                                        warehouses.find(
                                                            (w) =>
                                                                w.id ===
                                                                b.warehouse_id,
                                                        )?.name
                                                    }
                                                </td>
                                                <td>{b.quantity}</td>
                                                <td>{b.average_cost}</td>
                                                <td>{b.value}</td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                        </div>
                        {!balances.length && (
                            <p className="inventory-empty muted">
                                No stock posted. Record opening stock to begin.
                            </p>
                        )}
                        <div className="inventory-actions">
                            {warehouses
                                .filter(
                                    (w) =>
                                        !warehouse ||
                                        String(w.id) === warehouse,
                                )
                                .map((w) => (
                                    <button
                                        className="secondary"
                                        key={w.id}
                                        onClick={() =>
                                            router.post(
                                                "/inventory/warehouses/" +
                                                    w.id +
                                                    "/reconcile",
                                            )
                                        }
                                    >
                                        Reconcile {w.name}
                                    </button>
                                ))}
                        </div>
                    </section>
                    <section className="panel">
                        <h2>Movement history</h2>
                        <div className="inventory-actions">
                            <button
                                className="secondary"
                                onClick={() =>
                                    router.get(
                                        "/inventory",
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                Newest movements
                            </button>
                            <button
                                className="secondary"
                                disabled={(p.movements?.length ?? 0) < 100}
                                onClick={() =>
                                    router.get(
                                        "/inventory",
                                        { before_id: p.movements?.at(-1)?.id },
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                Older movements
                            </button>
                        </div>
                        <p className="muted">
                            Latest 100 immutable ledger lines.
                        </p>
                        <div className="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>SKU</th>
                                        <th>Warehouse</th>
                                        <th>Quantity</th>
                                        <th>Value</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {p.movements
                                        ?.filter(
                                            (m) =>
                                                !warehouse ||
                                                String(m.warehouse_id) ===
                                                    warehouse,
                                        )
                                        .map((m) => (
                                            <tr key={m.id}>
                                                <td>
                                                    <strong>
                                                        {m.document.reference}
                                                    </strong>
                                                    <small>
                                                        {m.document.type}
                                                    </small>
                                                </td>
                                                <td>{m.variant.sku}</td>
                                                <td>
                                                    {
                                                        warehouses.find(
                                                            (w) =>
                                                                w.id ===
                                                                m.warehouse_id,
                                                        )?.name
                                                    }
                                                </td>
                                                <td>{m.quantity}</td>
                                                <td>{m.value}</td>
                                                <td>{m.document.reason}</td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </>
            )}
            {tab === "counts" && (
                <section className="panel">
                    <h2>Count with confidence</h2>
                    <p className="muted">
                        Take a snapshot, enter physical quantities and post all
                        variances together.
                    </p>
                    {can("inventory.count") && (
                        <form
                            className="inventory-count-start"
                            onSubmit={(e) => {
                                e.preventDefault();
                                start.post("/inventory/counts", {
                                    onSuccess: () => start.reset("reference"),
                                });
                            }}
                        >
                            <label className="field">
                                Count warehouse
                                <select
                                    value={start.data.warehouse_id}
                                    onChange={(e) =>
                                        start.setData(
                                            "warehouse_id",
                                            Number(e.target.value),
                                        )
                                    }
                                >
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="field">
                                Count reference
                                <input
                                    value={start.data.reference}
                                    onChange={(e) =>
                                        start.setData(
                                            "reference",
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <button
                                className="primary"
                                disabled={start.processing || !variants.length}
                            >
                                Start count
                            </button>
                            <details className="count-selection">
                                <summary>
                                    Count selected variants (optional)
                                </summary>
                                <label className="field">
                                    Variants to count
                                    <select
                                        multiple
                                        value={start.data.variant_ids.map(
                                            String,
                                        )}
                                        onChange={(e) =>
                                            start.setData(
                                                "variant_ids",
                                                Array.from(
                                                    e.target.selectedOptions,
                                                    (o) => Number(o.value),
                                                ),
                                            )
                                        }
                                    >
                                        {variants
                                            .filter((v) => v.active)
                                            .map((v) => (
                                                <option key={v.id} value={v.id}>
                                                    {v.sku} · {v.name}
                                                </option>
                                            ))}
                                    </select>
                                </label>
                                <small className="muted">
                                    Leave empty for all active physical stock
                                    variants. Services are excluded. For large
                                    catalogues, count up to 2000 variants at a
                                    time.
                                </small>
                            </details>
                            <Errors errors={start.errors} />
                        </form>
                    )}
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Count</th>
                                    <th>Warehouse</th>
                                    <th>Variants</th>
                                    <th>Status</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {p.counts?.map((c) => (
                                    <tr key={c.id}>
                                        <td>{c.reference}</td>
                                        <td>
                                            {
                                                warehouses.find(
                                                    (w) =>
                                                        w.id === c.warehouse_id,
                                                )?.name
                                            }
                                        </td>
                                        <td>{c.lines.length}</td>
                                        <td>
                                            <span className="soft-badge">
                                                {c.status}
                                            </span>
                                        </td>
                                        <td>
                                            {c.status === "draft" &&
                                                can("inventory.count") && (
                                                    <div className="inventory-actions">
                                                        <button
                                                            className="secondary"
                                                            onClick={() =>
                                                                setCount(c)
                                                            }
                                                        >
                                                            Enter count
                                                        </button>
                                                        <button
                                                            className="secondary"
                                                            onClick={() =>
                                                                router.post(
                                                                    "/inventory/counts/" +
                                                                        c.id +
                                                                        "/cancel",
                                                                )
                                                            }
                                                        >
                                                            Cancel count
                                                        </button>
                                                    </div>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
            {tab === "setup" && (
                <Setup
                    classes={classes}
                    branches={p.branches}
                    warehouses={warehouses}
                />
            )}
            {tab === "import" && <ImportForm />}
            {product && (
                <ProductForm
                    product={product === true ? undefined : product}
                    classes={classes}
                    close={() => setProduct(null)}
                />
            )}{" "}
            {variant && (
                <VariantForm {...variant} close={() => setVariant(null)} />
            )}{" "}
            {movement && (
                <MovementForm
                    variants={variants.filter(
                        (v) =>
                            v.active &&
                            products.find((p) => p.id === v.product_id)?.active,
                    )}
                    warehouses={warehouses}
                    close={() => setMovement(false)}
                />
            )}{" "}
            {count && <CountForm count={count} close={() => setCount(null)} />}
        </>
    );
}
