import React from "react";
import { createRoot } from "react-dom/client";
import { createInertiaApp } from "@inertiajs/react";
import Auth from "./pages/Auth";
import Workspace from "./pages/Workspace";
import International from "./pages/International";
import PlatformAdmin from "./pages/PlatformAdmin";
import SubscriptionStatus from "./pages/SubscriptionStatus";
import "../css/app.css";
import "../css/erp.css";
function OfflineNotice() {
    const [online, setOnline] = React.useState(
        typeof navigator === "undefined" ? true : navigator.onLine,
    );
    React.useEffect(() => {
        const update = () => setOnline(navigator.onLine);
        window.addEventListener("online", update);
        window.addEventListener("offline", update);
        return () => {
            window.removeEventListener("online", update);
            window.removeEventListener("offline", update);
        };
    }, []);
    return online ? null : (
        <div className="offline-notice" role="status" aria-live="polite">
            Offline · changes and transactions need a connection. BRetail does
            not store private business data on this device.
        </div>
    );
}

type InstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
};

function InstallAppNotice() {
    const [promptEvent, setPromptEvent] = React.useState<InstallPromptEvent | null>(null);
    const [showIosHelp, setShowIosHelp] = React.useState(false);
    const [dismissed, setDismissed] = React.useState(false);
    const [installed, setInstalled] = React.useState(false);
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const isStandalone = window.matchMedia("(display-mode: standalone)").matches ||
        ("standalone" in navigator && (navigator as Navigator & { standalone?: boolean }).standalone === true);

    React.useEffect(() => {
        const onPrompt = (event: Event) => {
            event.preventDefault();
            setPromptEvent(event as InstallPromptEvent);
        };
        const onInstalled = () => {
            setInstalled(true);
            setPromptEvent(null);
        };
        window.addEventListener("beforeinstallprompt", onPrompt);
        window.addEventListener("appinstalled", onInstalled);
        return () => {
            window.removeEventListener("beforeinstallprompt", onPrompt);
            window.removeEventListener("appinstalled", onInstalled);
        };
    }, []);

    if (installed || isStandalone || dismissed || (!promptEvent && !isIos)) return null;

    const install = async () => {
        if (!promptEvent) {
            setShowIosHelp(true);
            return;
        }
        await promptEvent.prompt();
        const choice = await promptEvent.userChoice;
        if (choice.outcome === "accepted") setPromptEvent(null);
    };

    return (
        <>
            <aside className="pwa-install-notice" aria-label="Install BRetail">
                <span><strong>Use BRetail like an app</strong><small>Add it to your home screen for quick access.</small></span>
                <button type="button" onClick={install}>{isIos ? "How to add" : "Install"}</button>
                <button type="button" className="pwa-install-dismiss" aria-label="Dismiss install suggestion" onClick={() => setDismissed(true)}>×</button>
            </aside>
            {showIosHelp && (
                <div className="pwa-help-backdrop" role="presentation" onClick={() => setShowIosHelp(false)}>
                    <section className="pwa-help-dialog" role="dialog" aria-modal="true" aria-labelledby="pwa-help-title" onClick={event => event.stopPropagation()}>
                        <button type="button" className="pwa-help-close" aria-label="Close" onClick={() => setShowIosHelp(false)}>×</button>
                        <h2 id="pwa-help-title">Add BRetail to your Home Screen</h2>
                        <ol><li>Tap the <strong>Share</strong> button in Safari.</li><li>Scroll down and tap <strong>Add to Home Screen</strong>.</li><li>Tap <strong>Add</strong> to finish.</li></ol>
                        <p>This option is available when BRetail is open in Safari.</p>
                    </section>
                </div>
            )}
        </>
    );
}
if (
    typeof navigator !== "undefined" &&
    "serviceWorker" in navigator &&
    window.isSecureContext
) {
    window.addEventListener(
        "load",
        () => {
            navigator.serviceWorker
                .register("/sw.js", { scope: "/" })
                .catch(() => {
                    // Core online workflows remain available when service workers are unavailable.
                });
        },
        { once: true },
    );
}
createInertiaApp({
    resolve: (name) =>
        ({ Auth, Workspace, International, PlatformAdmin, SubscriptionStatus })[
            name
        ] as React.ComponentType<any>,
    setup({ el, App, props }) {
        createRoot(el!).render(
            <>
                <App {...props} />
                <OfflineNotice />
                <InstallAppNotice />
            </>,
        );
    },
    progress: { color: "#253f58" },
});
