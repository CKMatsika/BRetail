import React from "react";
import { Link, router } from "@inertiajs/react";
import { LogOut, ShieldCheck } from "lucide-react";

export default function SubscriptionStatus({ organization, status, message, support_email, platform_staff }: {
    organization: string;
    status: string;
    message: string;
    support_email: string | null;
    platform_staff: boolean;
}) {
    return <main className="subscription-gate">
        <section className="panel subscription-gate-card">
            <div className="platform-brand"><span className="brand-icon"><ShieldCheck size={19} /></span> BRetail<span className="brand-dot">.</span></div>
            <span className={`subscription-status status-${status}`}>{status.replaceAll("_", " ")}</span>
            <h1>Subscription access</h1>
            <p className="muted">{organization}</p>
            <p>{message}</p>
            <p className="muted">Your organization’s records are retained while access is inactive.</p>
            {support_email && <a className="primary button-link" href={`mailto:${support_email}`}>Contact support</a>}
            {!support_email && <p className="muted">Contact your BRetail account administrator to restore access.</p>}
            <div className="subscription-gate-actions">
                {platform_staff && <Link className="secondary button-link" href="/platform/organizations">Open platform administration</Link>}
                <button className="secondary" onClick={() => router.post("/logout")}><LogOut size={15} /> Sign out</button>
            </div>
            <footer className="copyright-footer">© 2026 Betterlink Industries Pvt Ltd</footer>
        </section>
    </main>;
}
