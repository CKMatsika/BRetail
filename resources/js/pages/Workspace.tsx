import React, { useState } from "react";
import CompanyLogo from "./CompanyLogo";
import Inventory, { InventoryProps } from "./Inventory";
import Pos, { PosProps } from "./Pos";
import Accounting, { AccountingData } from "./Accounting";
import Trade, { TradeData } from "./Trade";
import Hr, { HrData } from "./Hr";
import Payroll, { PayrollData } from "./Payroll";
import DeviceManager from "./DeviceManager";
import Reports, { AnalyticsData } from "./Reports";
import { useDialog } from "../useDialog";
import { Link, router, useForm, usePage } from "@inertiajs/react";
import {
    ShoppingBag,
    LayoutDashboard,
    Building2,
    Users,
    Boxes,
    Settings,
    ShieldCheck,
    ArrowUpRight,
    Plus,
    Check,
    ChevronRight,
    Menu,
    X,
    LogOut,
    Sun,
    Moon,
    Warehouse,
    Activity,
    ArrowRight,
    Smartphone,
} from "lucide-react";
type Branch = {
    id: number;
    name: string;
    code: string;
    address: string | null;
    active: boolean;
    warehouses: { id: number; name: string }[];
};
type Module = {
    id: number;
    key: string;
    name: string;
    phase: number;
    available?: boolean;
    enabled: boolean;
    settings: { preferred?: boolean };
};
type Person = {
    id: number;
    name: string;
    email: string;
    active: boolean;
    role: string;
    role_id: number;
    branch_ids: number[];
};
type Props = InventoryProps &
    PosProps & {
        section: string;
        accounting?: AccountingData;
        trade?: TradeData;
        hr?: HrData;
        payroll?: PayrollData;
        analytics?: AnalyticsData;
        user: { id: number; name: string };
        role: string;
        permissions: string[];
        company: {
            name: string;
            industry: string;
            logo_url?: string | null;
            base_currency: string;
            accepted_currencies: string[];
            settings: Record<string, string>;
        };
        branches: Branch[];
        modules: Module[];
        users?: Person[];
        roles?: { id: number; name: string; permissions: string[] }[];
        devices?: { id: number; device_uuid: string; name: string; platform: string; user: string; branch: string; last_seen_at: string | null; revoked_at: string | null }[];
        events?: {
            id: number;
            action: string;
            subject: string;
            created_at: string;
        }[];
        flash?: { success?: string };
    };
const nav = [
    {key: "hr", label: "People & HR", icon: Users, permission: "hr.view"},
    {key: "payroll", label: "Payroll", icon: Activity, permission: "payroll.view"},
    {key: "currencies", label: "Currencies & transfers", icon: Building2, permission: "currencies.view"},
    {key: "purchasing", label: "Purchasing", icon: Boxes, permission: "purchasing.view"},
    {key: "sales", label: "Sales & credit", icon: ShoppingBag, permission: "sales.view"},
    {
        key: "accounting",
        label: "Accounting & finance",
        icon: Activity,
        permission: "accounting.view",
    },
    { key: "reports", label: "Reports & analytics", icon: Activity, permission: "reports.view" },
    {
        key: "pos",
        label: "Point of sale",
        icon: ShoppingBag,
        permission: "pos.view",
    },
    {
        key: "inventory",
        label: "Inventory",
        icon: Boxes,
        permission: "inventory.view",
    },
    {
        key: "dashboard",
        label: "Overview",
        icon: LayoutDashboard,
        permission: "dashboard.view",
    },
    {
        key: "branches",
        label: "Branches & warehouses",
        icon: Building2,
        permission: "branches.view",
    },
    {
        key: "team",
        label: "Team & access",
        icon: Users,
        permission: "users.view",
    },
    {
        key: "devices",
        label: "Devices",
        icon: Smartphone,
        permission: "devices.manage",
    },
    {
        key: "modules",
        label: "Modules",
        icon: Boxes,
        permission: "modules.view",
    },
    {
        key: "company",
        label: "Company settings",
        icon: Settings,
        permission: "company.view",
    },
    {
        key: "audit",
        label: "Activity log",
        icon: Activity,
        permission: "audit.view",
    },
];
function Errors({ errors }: { errors: Record<string, string | undefined> }) {
    return Object.keys(errors).length ? (
        <div className="notice error" role="alert">
            {Object.values(errors).join(" ")}
        </div>
    ) : null;
}
function BranchForm({
    branch,
    onClose,
}: {
    branch?: Branch;
    onClose: () => void;
}) {
    useDialog(onClose);
    const f = useForm({
        name: branch?.name ?? "",
        code: branch?.code ?? "",
        address: branch?.address ?? "",
        warehouse_name: "Main warehouse",
        active: branch?.active ?? true,
    });
    return (
        <div className="modal-backdrop">
            <section
                className="modal"
                role="dialog"
                aria-modal="true"
                aria-label="Branch details"
            >
                <div className="section-heading">
                    <h2>{branch ? "Edit branch" : "A new place to grow"}</h2>
                    <button
                        className="icon-button"
                        onClick={onClose}
                        aria-label="Close"
                    >
                        <X />
                    </button>
                </div>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        branch
                            ? f.patch("/branches/" + branch.id, {
                                  onSuccess: onClose,
                              })
                            : f.post("/branches", { onSuccess: onClose });
                    }}
                >
                    {[
                        "name",
                        ...(branch ? [] : ["code", "warehouse_name"]),
                        "address",
                    ].map((k) => (
                        <label className="field" key={k}>
                            {k.replaceAll("_", " ")}
                            <input
                                autoFocus={k === "name"}
                                value={String(f.data[k as keyof typeof f.data])}
                                onChange={(e) =>
                                    f.setData(
                                        k as keyof typeof f.data,
                                        e.target.value as never,
                                    )
                                }
                            />
                        </label>
                    ))}
                    {branch && (
                        <label className="checkbox">
                            <input
                                type="checkbox"
                                checked={f.data.active}
                                onChange={(e) =>
                                    f.setData("active", e.target.checked)
                                }
                            />
                            Active branch
                        </label>
                    )}
                    <Errors errors={f.errors} />
                    <button className="primary" disabled={f.processing}>
                        Save branch
                    </button>
                </form>
            </section>
        </div>
    );
}
function TeamForm({
    person,
    roles,
    branches,
    onClose,
}: {
    person?: Person;
    roles: NonNullable<Props["roles"]>;
    branches: Branch[];
    onClose: () => void;
}) {
    useDialog(onClose);
    const f = useForm({
        name: person?.name ?? "",
        email: person?.email ?? "",
        password: "",
        role_id:
            person?.role_id ?? roles.find((r) => r.name === "Cashier")?.id ?? 0,
        active: person?.active ?? true,
        branch_ids: person?.branch_ids ?? ([] as number[]),
    });
    return (
        <div className="modal-backdrop">
            <section
                className="modal"
                role="dialog"
                aria-modal="true"
                aria-label="Team member"
            >
                <div className="section-heading">
                    <h2>{person ? "Manage access" : "Add a teammate"}</h2>
                    <button
                        className="icon-button"
                        onClick={onClose}
                        aria-label="Close"
                    >
                        <X />
                    </button>
                </div>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        person
                            ? f.patch("/team/" + person.id, {
                                  onSuccess: onClose,
                              })
                            : f.post("/team", { onSuccess: onClose });
                    }}
                >
                    {!person &&
                        (["name", "email", "password"] as const).map((k) => (
                            <label className="field" key={k}>
                                {k}
                                <input
                                    type={
                                        k === "password"
                                            ? "password"
                                            : k === "email"
                                              ? "email"
                                              : "text"
                                    }
                                    value={f.data[k]}
                                    onChange={(e) =>
                                        f.setData(k, e.target.value)
                                    }
                                />
                            </label>
                        ))}
                    <label className="field">
                        Role
                        <select
                            value={f.data.role_id}
                            onChange={(e) =>
                                f.setData("role_id", Number(e.target.value))
                            }
                        >
                            {roles.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="notice">
                        Permissions:{" "}
                        {roles
                            .find((r) => r.id === f.data.role_id)
                            ?.permissions.join(", ")}
                    </div>
                    <p>Branch access</p>
                    {branches.map((b) => (
                        <label className="checkbox" key={b.id}>
                            <input
                                type="checkbox"
                                checked={f.data.branch_ids.includes(b.id)}
                                onChange={(e) =>
                                    f.setData(
                                        "branch_ids",
                                        e.target.checked
                                            ? [...f.data.branch_ids, b.id]
                                            : f.data.branch_ids.filter(
                                                  (id) => id !== b.id,
                                              ),
                                    )
                                }
                            />
                            {b.name}
                        </label>
                    ))}
                    {person && (
                        <label className="checkbox">
                            <input
                                type="checkbox"
                                checked={f.data.active}
                                onChange={(e) =>
                                    f.setData("active", e.target.checked)
                                }
                            />
                            Account active
                        </label>
                    )}
                    <Errors errors={f.errors} />
                    <button className="primary" disabled={f.processing}>
                        Save team member
                    </button>
                </form>
            </section>
        </div>
    );
}
function CompanyForm({
    company,
    editable,
}: {
    company: Props["company"];
    editable: boolean;
}) {
    const f = useForm({ name: company.name, settings: company.settings });
    return (
        <form
            className="panel settings-form"
            onSubmit={(e) => {
                e.preventDefault();
                f.patch("/company");
            }}
        >
            <h2>Business details</h2>
            <p className="muted">
                Keep your business identity and documents up to date.
            </p>
            <label className="field">
                Business name
                <input
                    disabled={!editable}
                    value={f.data.name}
                    onChange={(e) => f.setData("name", e.target.value)}
                />
            </label>
            <div className="form-grid">
                {[
                    "registration",
                    "tax_id",
                    "contact_email",
                    "phone",
                    "address",
                    "receipt_footer",
                    "invoice_prefix",
                ].map((k) => (
                    <label key={k} className="field">
                        {k.replaceAll("_", " ")}
                        <input
                            disabled={!editable}
                            value={f.data.settings[k] ?? ""}
                            onChange={(e) =>
                                f.setData("settings", {
                                    ...f.data.settings,
                                    [k]: e.target.value,
                                })
                            }
                        />
                    </label>
                ))}
            </div>
            <div className="notice">
                Base currency: {company.base_currency} · Accepted:{" "}
                {company.accepted_currencies.join(", ")}
                <br />
                Financial year: {company.settings.financial_year_start} · Tax:{" "}
                {company.settings.tax_label}, {company.settings.tax_rate}%<br />
                Industry: {company.industry}
            </div>
            <Errors errors={f.errors} />
            {editable && (
                <button className="primary" disabled={f.processing}>
                    Save changes
                </button>
            )}
        </form>
    );
}
export default function Workspace(p: Props) {
    const [menu, setMenu] = useState(false);
    const [dark, setDark] = useState(
        () => localStorage.getItem("bretail-theme") === "dark",
    );
    const [bm, setBm] = useState<Branch | true | null>(null);
    const [tm, setTm] = useState<Person | true | null>(null);
    const [search, setSearch] = useState("");
    const can = (s: string) =>
        p.permissions.includes("*") || p.permissions.includes(s);
    const title = nav.find((n) => n.key === p.section)?.label ?? "Overview";
    const errors = (usePage().props as any).errors ?? {};
    return (
        <div className={"workspace " + (dark ? "dark" : "")}>
            <aside className={"sidebar " + (menu ? "open" : "")}>
                <Link href="/dashboard" className="brand">
                    <span className="brand-icon">
                        <ShoppingBag size={21} />
                    </span>
                    BRetail<span className="brand-dot">.</span>
                </Link>
                <div className="company-switch">
                    <span className="company-avatar">
                        {p.company.logo_url ? (
                            <img
                                src={p.company.logo_url}
                                alt="Company logo"
                                style={{
                                    width: "100%",
                                    height: "100%",
                                    objectFit: "contain",
                                }}
                            />
                        ) : (
                            p.company.name.slice(0, 2).toUpperCase()
                        )}
                    </span>
                    <div>
                        <strong>{p.company.name}</strong>
                        <small>Business workspace</small>
                    </div>
                    <span className="status-dot" />
                </div>
                <span className="nav-label">WORKSPACE</span>
                <nav>
                    {nav
                        .filter(
                            (n) =>
                                can(n.permission) &&
                                p.modules.some(
                                    (m) =>
                                        m.key ===
                                            ((
                                                {
                                                    team: "access",
                                                    devices: "access",
                                                    company: "administration",
                                                    modules: "administration",
                                                    audit: "administration",
                                                } as Record<string, string>
                                            )[n.key] ?? n.key) && m.enabled,
                                ),
                        )
                        .map((n) => (
                            <Link
                                key={n.key}
                                href={"/" + n.key}
                                onClick={() => setMenu(false)}
                                className={p.section === n.key ? "active" : ""}
                            >
                                <n.icon size={19} />
                                {n.label}
                                {p.section === n.key && (
                                    <span className="nav-active-dot" />
                                )}
                            </Link>
                        ))}
                </nav>
                <div className="sidebar-bottom">
                    <div className="foundation-label">
                        <ShieldCheck size={17} />
                        <div>
                            <strong>A strong start.</strong>
                            <small>Foundation · Phase 1</small>
                        </div>
                    </div>
                    <div className="profile">
                        <span className="avatar">
                            {p.user.name
                                .split(" ")
                                .map((n) => n[0])
                                .slice(0, 2)
                                .join("")}
                        </span>
                        <div>
                            <strong>{p.user.name}</strong>
                            <small>{p.role}</small>
                        </div>
                        <button
                            className="icon-button"
                            aria-label="Sign out"
                            onClick={() => router.post("/logout")}
                        >
                            <LogOut size={17} />
                        </button>
                    </div>
                </div>
            </aside>
            {menu && (
                <button
                    className="mobile-overlay"
                    onClick={() => setMenu(false)}
                    aria-label="Close navigation"
                />
            )}
            <main className="main">
                <header className="topbar">
                    <div className="breadcrumbs">
                        <button
                            className="icon-button mobile-menu"
                            onClick={() => setMenu(!menu)}
                            aria-label="Open navigation"
                        >
                            <Menu />
                        </button>
                        <span>Workspace</span>
                        <ChevronRight size={14} />
                        <strong>{title}</strong>
                    </div>
                    <div className="topbar-right">
                        <span className="secure-label">
                            <span className="status-dot" />
                            Workspace active
                        </span>
                        <button
                            className="icon-button"
                            aria-label="Toggle colour theme"
                            onClick={() => {
                                localStorage.setItem(
                                    "bretail-theme",
                                    dark ? "light" : "dark",
                                );
                                setDark(!dark);
                            }}
                        >
                            {dark ? <Sun size={18} /> : <Moon size={18} />}
                        </button>
                        <span className="currency-tag">
                            {p.company.base_currency}
                        </span>
                    </div>
                </header>
                <div className="content">
                    {p.flash?.success && (
                        <div className="notice success" role="status">
                            <Check size={17} />
                            {p.flash.success}
                        </div>
                    )}
                    <Errors errors={errors} />
                    <div className="page-heading">
                        <div>
                            <div className="eyebrow">
                                {p.section === "dashboard"
                                    ? "A CLEARER PICTURE, EVERY DAY"
                                    : "YOUR BUSINESS, CONNECTED"}
                            </div>
                            <h1>
                                {p.section === "dashboard"
                                    ? `Welcome back, ${p.user.name.split(" ")[0]}.`
                                    : title}
                            </h1>
                            <p className="muted">
                                {p.section === "dashboard"
                                    ? "Your next chapter starts with a strong foundation. Here’s where you stand."
                                    : p.section === "branches"
                                      ? "Every location. One connected business."
                                      : p.section === "team"
                                        ? "The right people, with the right access."
                                        : p.section === "reports"
                                          ? "A clear view of sales, stock, and financial performance."
                                        : "A workspace that grows at your pace."}
                            </p>
                        </div>
                        {p.section === "branches" && can("branches.create") && (
                            <button
                                className="primary"
                                onClick={() => setBm(true)}
                            >
                                <Plus size={17} />
                                Add branch
                            </button>
                        )}
                        {p.section === "team" && can("users.configure") && (
                            <button
                                className="primary"
                                onClick={() => setTm(true)}
                            >
                                <Plus size={17} />
                                Add team member
                            </button>
                        )}
                        <span className="phase-pill">
                            PHASE 05 <span>Purchasing & back office</span>
                        </span>
                    </div>
                    {p.section === "dashboard" && (
                        <>
                            <section className="welcome-banner">
                                <div>
                                    <span className="banner-tag">
                                        <span className="status-dot" />
                                        YOUR WORKSPACE IS READY
                                    </span>
                                    <h2>
                                        Small beginnings.
                                        <br />
                                        Connected possibilities.
                                    </h2>
                                    <p>
                                        Your company, branches and people —
                                        together in one place.
                                        <br />
                                        You’re building on a foundation made for
                                        growth.
                                    </p>
                                    {can("company.view") && (
                                        <Link
                                            className="banner-link"
                                            href="/company"
                                        >
                                            Review your business setup
                                            <ArrowRight size={16} />
                                        </Link>
                                    )}
                                </div>
                                <div className="banner-art" aria-hidden="true">
                                    <div className="orbit orbit-one" />
                                    <div className="orbit orbit-two" />
                                    <div className="art-main">
                                        <ShoppingBag size={44} />
                                    </div>
                                    <div className="art-small art-branch">
                                        <Building2 size={24} />
                                    </div>
                                    <div className="art-small art-team">
                                        <Users size={23} />
                                    </div>
                                    <div className="art-small art-check">
                                        <Check size={22} />
                                    </div>
                                </div>
                            </section>
                            <div className="stat-grid">
                                {[
                                    {
                                        label: "Your branches",
                                        value: p.branches.length,
                                        detail: "Locations in your access",
                                        icon: Building2,
                                    },
                                    {
                                        label: "Warehouses",
                                        value: p.branches.reduce(
                                            (s, b) => s + b.warehouses.length,
                                            0,
                                        ),
                                        detail: "Ready for inventory",
                                        icon: Warehouse,
                                    },
                                    {
                                        label: "Active modules",
                                        value: p.modules.filter(
                                            (m) => m.enabled,
                                        ).length,
                                        detail: "Core foundations enabled",
                                        icon: Boxes,
                                    },
                                    {
                                        label: "Base currency",
                                        value: p.company.base_currency,
                                        detail:
                                            p.company.accepted_currencies
                                                .length +
                                            " accepted currencies",
                                        icon: ShieldCheck,
                                    },
                                ].map((s) => (
                                    <section
                                        className="stat-card"
                                        key={s.label}
                                    >
                                        <div>
                                            <span>{s.label}</span>
                                            <s.icon size={19} />
                                        </div>
                                        <strong>{s.value}</strong>
                                        <small>{s.detail}</small>
                                    </section>
                                ))}
                            </div>
                            <div className="dashboard-grid">
                                <section className="panel">
                                    <div className="section-heading">
                                        <div>
                                            <h2>Your foundation checklist</h2>
                                            <p className="muted">
                                                The essentials, all in place.
                                            </p>
                                        </div>
                                        <span className="soft-badge">
                                            3 of 3 ready
                                        </span>
                                    </div>
                                    <div className="checklist">
                                        {[
                                            {
                                                title: "Company profile created",
                                                detail:
                                                    p.company.name +
                                                    " · " +
                                                    p.company.base_currency +
                                                    " base currency",
                                                url: "company",
                                                permission: "company.view",
                                            },
                                            {
                                                title: "First branch connected",
                                                detail:
                                                    p.branches[0]?.name ??
                                                    "No branch assigned",
                                                url: "branches",
                                                permission: "branches.view",
                                            },
                                            {
                                                title: "Secure access configured",
                                                detail: "Role permissions and tenant isolation are active",
                                                url: "team",
                                                permission: "users.view",
                                            },
                                        ].map((item) => (
                                            <div
                                                className="checklist-row"
                                                key={item.title}
                                            >
                                                <span className="check-icon">
                                                    <Check size={16} />
                                                </span>
                                                <div>
                                                    <strong>
                                                        {item.title}
                                                    </strong>
                                                    <small>{item.detail}</small>
                                                </div>
                                                {can(item.permission) && (
                                                    <Link
                                                        href={"/" + item.url}
                                                        aria-label={
                                                            "View " + item.title
                                                        }
                                                    >
                                                        <ArrowUpRight
                                                            size={18}
                                                        />
                                                    </Link>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </section>
                                <section className="panel next-panel">
                                    <span className="mini-icon">
                                        <Boxes size={23} />
                                    </span>
                                    <span className="eyebrow">WHAT’S NEXT</span>
                                    <h2>
                                        Make stock
                                        <br />
                                        work smarter.
                                    </h2>
                                    <p className="muted">
                                        Products, variants and stock movements
                                        are now available. Enable Inventory in
                                        Modules to get started.
                                    </p>
                                    <div className="planned-label">
                                        Inventory · Available in Phase 2
                                    </div>
                                    {can("modules.view") && (
                                        <Link href="/modules">
                                            Explore the roadmap
                                            <ArrowRight size={16} />
                                        </Link>
                                    )}
                                </section>
                            </div>
                            <section className="panel">
                                <div className="section-heading">
                                    <div>
                                        <h2>A home for every branch</h2>
                                        <p className="muted">
                                            Your connected locations at a
                                            glance.
                                        </p>
                                    </div>
                                    {can("branches.view") && (
                                        <Link
                                            className="text-link"
                                            href="/branches"
                                        >
                                            View branches
                                            <ArrowRight size={16} />
                                        </Link>
                                    )}
                                </div>
                                <div className="branch-preview">
                                    {p.branches.slice(0, 3).map((b) => (
                                        <div key={b.id}>
                                            <span className="branch-icon">
                                                <Building2 size={21} />
                                            </span>
                                            <div>
                                                <strong>{b.name}</strong>
                                                <small>
                                                    {b.warehouses.length}{" "}
                                                    warehouse · {b.code}
                                                </small>
                                            </div>
                                            <span className="soft-badge">
                                                {b.active
                                                    ? "Active"
                                                    : "Inactive"}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        </>
                    )}
                    {p.section === "branches" && (
                        <section className="panel">
                            <div className="section-heading">
                                <h2>
                                    Branch directory{" "}
                                    <span className="count">
                                        {p.branches.length}
                                    </span>
                                </h2>
                                <input
                                    className="search"
                                    placeholder="Find a branch…"
                                    aria-label="Find a branch"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </div>
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Branch</th>
                                            <th>Code</th>
                                            <th>Warehouse</th>
                                            <th>Status</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {p.branches
                                            .filter((b) =>
                                                (b.name + " " + b.code)
                                                    .toLowerCase()
                                                    .includes(
                                                        search.toLowerCase(),
                                                    ),
                                            )
                                            .map((b) => (
                                                <tr key={b.id}>
                                                    <td>
                                                        <strong>
                                                            {b.name}
                                                        </strong>
                                                        <small>
                                                            {b.address ||
                                                                "No address added"}
                                                        </small>
                                                    </td>
                                                    <td>{b.code}</td>
                                                    <td>
                                                        {b.warehouses
                                                            .map((w) => w.name)
                                                            .join(", ")}
                                                    </td>
                                                    <td>
                                                        <span className="soft-badge">
                                                            {b.active
                                                                ? "Active"
                                                                : "Inactive"}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        {can(
                                                            "branches.edit",
                                                        ) && (
                                                            <button
                                                                className="secondary"
                                                                onClick={() =>
                                                                    setBm(b)
                                                                }
                                                            >
                                                                Edit
                                                            </button>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}
                    {p.section === "team" && (
                        <section className="panel">
                            <h2>
                                Your team{" "}
                                <span className="count">{p.users?.length}</span>
                            </h2>
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Team member</th>
                                            <th>Role</th>
                                            <th>Branch access</th>
                                            <th>Status</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {p.users?.map((u) => (
                                            <tr key={u.id}>
                                                <td>
                                                    <strong>{u.name}</strong>
                                                    <small>{u.email}</small>
                                                </td>
                                                <td>{u.role}</td>
                                                <td>
                                                    {u.branch_ids
                                                        .map(
                                                            (id) =>
                                                                p.branches.find(
                                                                    (b) =>
                                                                        b.id ===
                                                                        id,
                                                                )?.name,
                                                        )
                                                        .filter(Boolean)
                                                        .join(", ") ||
                                                        "Company-wide"}
                                                </td>
                                                <td>
                                                    {u.active
                                                        ? "Active"
                                                        : "Inactive"}
                                                </td>
                                                <td>
                                                    {can("users.configure") &&
                                                        u.id !== p.user.id && (
                                                            <button
                                                                className="secondary"
                                                                onClick={() =>
                                                                    setTm(u)
                                                                }
                                                            >
                                                                Manage
                                                            </button>
                                                        )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}
                    {p.section === "devices" && (
                        <DeviceManager devices={p.devices ?? []} branches={p.branches.map(branch => ({ id: branch.id, name: branch.name }))} />
                    )}
                    {p.section === "modules" && (
                        <>
                            <section className="panel">
                                <h2>Your foundation</h2>
                                <p className="muted">
                                    Required modules keep your workspace
                                    reliable.
                                </p>
                                <div className="module-grid">
                                    {p.modules
                                        .filter((m) => m.phase === 1)
                                        .map((m) => (
                                            <div
                                                className="module-card"
                                                key={m.key}
                                            >
                                                <ShieldCheck size={21} />
                                                <h3>{m.name}</h3>
                                                <span className="soft-badge">
                                                    Enabled · required
                                                </span>
                                            </div>
                                        ))}
                                </div>
                            </section>
                            <section className="panel">
                                <h2>Build at your pace</h2>
                                <p className="muted">
                                    Activate available modules or save
                                    preferences for future phases.
                                </p>
                                <div className="module-grid">
                                    {p.modules
                                        .filter((m) => m.phase > 1)
                                        .map((m) => (
                                            <div
                                                className="module-card"
                                                key={m.key}
                                            >
                                                <span className="eyebrow">
                                                    PHASE {m.phase}
                                                </span>
                                                <h3>{m.name}</h3>
                                                <span className="muted">
                                                    {m.available
                                                        ? m.enabled
                                                            ? "Enabled"
                                                            : "Available"
                                                        : "Planned"}
                                                </span>
                                                {m.available &&
                                                    can(
                                                        "modules.configure",
                                                    ) && (
                                                        <button
                                                            className="secondary"
                                                            onClick={() =>
                                                                router.patch(
                                                                    "/modules/" +
                                                                        m.key,
                                                                    {
                                                                        enabled:
                                                                            !m.enabled,
                                                                        preferred:
                                                                            !!m
                                                                                .settings
                                                                                .preferred,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {(m.enabled
                                                                ? "Disable "
                                                                : "Enable ") +
                                                                m.name.toLowerCase()}
                                                        </button>
                                                    )}
                                                {can("modules.configure") && (
                                                    <label className="checkbox">
                                                        <input
                                                            type="checkbox"
                                                            checked={
                                                                !!m.settings
                                                                    .preferred
                                                            }
                                                            onChange={(e) =>
                                                                router.patch(
                                                                    "/modules/" +
                                                                        m.key,
                                                                    {
                                                                        enabled:
                                                                            m.enabled,
                                                                        preferred:
                                                                            e
                                                                                .target
                                                                                .checked,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                        Include in my roadmap
                                                    </label>
                                                )}
                                            </div>
                                        ))}
                                </div>
                            </section>
                        </>
                    )}
                    {p.section === "pos" && p.pos && (
                        <Pos pos={p.pos} permissions={p.permissions} />
                    )}
                    {p.section === "accounting" && p.accounting && (
                        <Accounting initial={p.accounting} permissions={p.permissions} />
                    )}
                    {p.section === "hr" && p.hr && <Hr initial={p.hr} />}
                    {p.section === "payroll" && p.payroll && <Payroll initial={p.payroll} />}
                    {p.section === "reports" && p.analytics && <Reports initial={p.analytics} permissions={p.permissions} />}
                    {['purchasing','sales','backoffice'].includes(p.section) && p.trade && <Trade initial={p.trade} permissions={p.permissions} />}
                    {p.section === "inventory" && (
                        <Inventory
                            {...p}
                            baseCurrency={p.company.base_currency}
                        />
                    )}
                    {p.section === "company" && (
                        <>
                            <CompanyForm
                                company={p.company}
                                editable={can("company.configure")}
                            />
                            {can("company.configure") && <CompanyLogo />}
                        </>
                    )}
                    {p.section === "audit" && (
                        <section className="panel">
                            <h2>Recent activity</h2>
                            <p className="muted">
                                Latest 100 events in this company. Credentials
                                are never recorded.
                            </p>
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Action</th>
                                            <th>Record</th>
                                            <th>Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {p.events?.map((e) => (
                                            <tr key={e.id}>
                                                <td>{e.action}</td>
                                                <td>{e.subject}</td>
                                                <td>
                                                    {new Date(
                                                        e.created_at,
                                                    ).toLocaleString()}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}
                    <footer className="footer">
                        <span>BRetail · Built for your next chapter.</span>
                        <span>
                            <ShieldCheck size={13} />
                            Tenant-isolated workspace
                        </span>
                    </footer>
                </div>
            </main>
            {bm && (
                <BranchForm
                    branch={bm === true ? undefined : bm}
                    onClose={() => setBm(null)}
                />
            )}{" "}
            {tm && (
                <TeamForm
                    person={tm === true ? undefined : tm}
                    roles={p.roles ?? []}
                    branches={p.branches}
                    onClose={() => setTm(null)}
                />
            )}
        </div>
    );
}
