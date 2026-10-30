import React from "react";
import { useForm } from "@inertiajs/react";
export default function CompanyLogo() {
    const f = useForm<{ logo: File | null }>({ logo: null });
    return (
        <form
            className="panel"
            onSubmit={(e) => {
                e.preventDefault();
                f.post("/company/logo", { forceFormData: true });
            }}
        >
            <h2>Company logo</h2>
            <p className="muted">
                PNG, JPEG or WebP. Maximum 2 MB and 4096 × 4096 pixels.
            </p>
            <label className="field">
                Upload logo
                <input
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={(e) =>
                        f.setData("logo", e.target.files?.[0] ?? null)
                    }
                />
            </label>
            {f.errors.logo && (
                <p className="error" role="alert">
                    {f.errors.logo}
                </p>
            )}
            <button className="primary" disabled={!f.data.logo || f.processing}>
                Save logo
            </button>
        </form>
    );
}
