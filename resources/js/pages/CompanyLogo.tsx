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
                PNG, JPEG, WebP, GIF or BMP. Maximum 2 MB and 4096 × 4096 pixels.
                Logos are saved as PNG; animated GIFs use the first frame.
            </p>
            <label className="field">
                Upload logo
                <input
                    type="file"
                    accept=".png,.jpg,.jpeg,.webp,.gif,.bmp,image/png,image/jpeg,image/webp,image/gif,image/bmp"
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
