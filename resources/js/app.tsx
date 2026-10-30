import React from "react";
import { createRoot } from "react-dom/client";
import { createInertiaApp } from "@inertiajs/react";
import Auth from "./pages/Auth";
import Workspace from "./pages/Workspace";
import International from "./pages/International";
import PlatformAdmin from "./pages/PlatformAdmin";
import SubscriptionStatus from "./pages/SubscriptionStatus";
import "../css/app.css";

function OfflineNotice() {
    const [online, setOnline] = React.useState(typeof navigator === "undefined" ? true : navigator.onLine);
    React.useEffect(() => {
        const update = () => setOnline(navigator.onLine);
        window.addEventListener("online", update);
        window.addEventListener("offline", update);
        return () => {
            window.removeEventListener("online", update);
            window.removeEventListener("offline", update);
        };
    }, []);
    return online ? null : <div className="offline-notice" role="status" aria-live="polite">Offline · changes and transactions need a connection. BRetail does not store private business data on this device.</div>;
}

if (typeof navigator !== "undefined" && "serviceWorker" in navigator && window.isSecureContext) {
    window.addEventListener("load", () => {
        navigator.serviceWorker.register("/sw.js", { scope: "/" }).catch(() => {
            // Core online workflows remain available when service workers are unavailable.
        });
    }, { once: true });
}

createInertiaApp({
    resolve: (name) => ({ Auth, Workspace, International, PlatformAdmin, SubscriptionStatus })[name] as React.ComponentType<any>,
    setup({ el, App, props }) {
        createRoot(el!).render(<><App {...props} /><OfflineNotice /></>);
    },
    progress: { color: "#19745c" },
});
