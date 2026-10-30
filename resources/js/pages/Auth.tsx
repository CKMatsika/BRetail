import React, { useState } from "react";
import { Link, useForm } from "@inertiajs/react";
import { ArrowRight, Check, ShoppingBag } from "lucide-react";
type Props = {
    mode: string;
    templates?: Record<string, { label: string }>;
    modules?: Record<string, { name: string; phase: number }>;
};
export default function Auth({ mode, templates = {}, modules = {} }: Props) {
    const register = mode === "register";
    const [step, setStep] = useState(0);
    const f = useForm({
        name: "",
        email: "",
        password: "",
        password_confirmation: "",
        company_name: "",
        industry: "general",
        base_currency: "USD",
        accepted_currencies: [] as string[],
        branch_name: "Main branch",
        branch_code: "MAIN",
        warehouse_name: "Main warehouse",
        registration: "",
        tax_id: "",
        contact_email: "",
        phone: "",
        address: "",
        financial_year_start: "01-01",
        tax_label: "Tax",
        tax_rate: "0",
        receipt_footer: "Thank you for shopping with us.",
        invoice_prefix: "INV",
        preferred_modules: [] as string[],
    });
    const field = (key: keyof typeof f.data, label: string, type = "text") => (
        <label className="field" key={key}>
            {label}
            <input
                type={type}
                value={String(f.data[key])}
                onChange={(e) => f.setData(key, e.target.value as never)}
            />
            {f.errors[key] && <small className="error">{f.errors[key]}</small>}
        </label>
    );
    return (
        <div className="auth-layout">
            <aside className="auth-story">
                <div className="brand">
                    <ShoppingBag /> BRetail.
                </div>
                <div>
                    <span className="eyebrow">YOUR BUSINESS, CONNECTED</span>
                    <h1>
                        A little simpler.
                        <br />A lot more
                        <br />
                        <em>in control.</em>
                    </h1>
                    <p>
                        One home for your people, your branches, and your next
                        chapter.
                    </p>
                    <div className="story-check">
                        <Check size={18} />
                        Built for the way small businesses grow
                    </div>
                </div>
                <small>SMART RETAIL · STRONG FOUNDATIONS</small>
            </aside>
            <main className="auth-main">
                <div className="auth-card">
                    <span className="eyebrow">
                        {register ? "LET’S GET YOU STARTED" : "WELCOME BACK"}
                    </span>
                    <h2>
                        {register
                            ? "Make room for growth."
                            : "Your business awaits."}
                    </h2>
                    <p className="muted">
                        {register
                            ? "Set up your workspace in three simple steps."
                            : "Sign in to your BRetail workspace."}
                    </p>
                    {register && (
                        <div className="steps">
                            {[
                                "Your account",
                                "Your business",
                                "Your first branch",
                            ].map((s, i) => (
                                <button
                                    key={s}
                                    type="button"
                                    className={step === i ? "selected" : ""}
                                    onClick={() => setStep(i)}
                                >
                                    {i + 1}. {s}
                                </button>
                            ))}
                        </div>
                    )}
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            f.post(register ? "/register" : "/login", {
                                onError: () => setStep(0),
                            });
                        }}
                    >
                        {(!register || step === 0) && (
                            <>
                                {register && field("name", "Your name")}
                                {field("email", "Email address", "email")}
                                {field(
                                    "password",
                                    register
                                        ? "Password · 12+ characters, letters and numbers"
                                        : "Password",
                                    "password",
                                )}
                                {register &&
                                    field(
                                        "password_confirmation",
                                        "Confirm password",
                                        "password",
                                    )}
                            </>
                        )}
                        {register && step === 1 && (
                            <>
                                <div className="form-grid">
                                    {field("company_name", "Business name")}
                                    <label className="field">
                                        Business type
                                        <select
                                            value={f.data.industry}
                                            onChange={(e) =>
                                                f.setData(
                                                    "industry",
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            {Object.entries(templates).map(
                                                ([k, v]) => (
                                                    <option key={k} value={k}>
                                                        {v.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                    {field(
                                        "base_currency",
                                        "Base currency · 3-letter code",
                                    )}
                                    {field(
                                        "registration",
                                        "Registration number",
                                    )}
                                    {field("tax_id", "Tax identification")}
                                    {field(
                                        "contact_email",
                                        "Business email",
                                        "email",
                                    )}
                                    {field("phone", "Phone")}
                                    {field(
                                        "financial_year_start",
                                        "Financial year starts · MM-DD",
                                    )}
                                    {field("tax_label", "Tax label")}
                                    {field("tax_rate", "Tax rate %")}
                                </div>
                                <label className="field">
                                    Additional accepted currencies
                                    <input
                                        placeholder="e.g. ZWG, ZAR"
                                        value={f.data.accepted_currencies.join(
                                            ",",
                                        )}
                                        onChange={(e) =>
                                            f.setData(
                                                "accepted_currencies",
                                                e.target.value
                                                    .toUpperCase()
                                                    .split(",")
                                                    .map((s) => s.trim())
                                                    .filter(Boolean),
                                            )
                                        }
                                    />
                                </label>
                            </>
                        )}
                        {register && step === 2 && (
                            <>
                                <div className="form-grid">
                                    {field("branch_name", "Branch name")}
                                    {field("branch_code", "Branch code")}
                                    {field(
                                        "warehouse_name",
                                        "Default warehouse",
                                    )}
                                    {field("invoice_prefix", "Invoice prefix")}
                                </div>
                                {field("address", "Business address")}
                                {field("receipt_footer", "Receipt footer")}
                                <p className="muted">
                                    Save preferences for future modules.
                                    Operations arrive in later phases.
                                </p>
                                <div className="preferences">
                                    {Object.entries(modules)
                                        .filter(([, m]) => m.phase > 1)
                                        .map(([k, m]) => (
                                            <label key={k}>
                                                <input
                                                    type="checkbox"
                                                    checked={f.data.preferred_modules.includes(
                                                        k,
                                                    )}
                                                    onChange={(e) =>
                                                        f.setData(
                                                            "preferred_modules",
                                                            e.target.checked
                                                                ? [
                                                                      ...f.data
                                                                          .preferred_modules,
                                                                      k,
                                                                  ]
                                                                : f.data.preferred_modules.filter(
                                                                      (x) =>
                                                                          x !==
                                                                          k,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {m.name}
                                            </label>
                                        ))}
                                </div>
                            </>
                        )}
                        {Object.keys(f.errors).length > 0 && (
                            <div className="notice error" role="alert">
                                {Object.values(f.errors).join(" ")}
                            </div>
                        )}
                        {register && step < 2 ? (
                            <button
                                className="primary wide"
                                type="button"
                                key="continue"
                                onClick={(e) => {
                                    e.preventDefault();
                                    setStep(step + 1);
                                }}
                            >
                                Continue
                                <ArrowRight size={17} />
                            </button>
                        ) : (
                            <button
                                key="submit"
                                type="submit"
                                className="primary wide"
                                disabled={f.processing}
                            >
                                {f.processing
                                    ? "Please wait…"
                                    : register
                                      ? "Create my workspace"
                                      : "Sign in"}
                                <ArrowRight size={17} />
                            </button>
                        )}
                    </form>
                    <p className="auth-switch">
                        {register
                            ? "Already have a workspace?"
                            : "New to BRetail?"}{" "}
                        <Link href={register ? "/login" : "/register"}>
                            {register ? "Sign in" : "Set up your business"}
                        </Link>
                    </p>
                </div>
            </main>
        </div>
    );
}
