import { useEffect } from "react";
export function useDialog(onClose: () => void) {
    useEffect(() => {
        const previous = document.activeElement as HTMLElement | null;
        const handler = (event: KeyboardEvent) => {
            const dialog = document.querySelector('[role="dialog"]');
            if (!dialog) return;
            if (event.key === "Escape") {
                event.preventDefault();
                onClose();
            }
            if (event.key === "Tab") {
                const elements = Array.from(
                    dialog.querySelectorAll<HTMLElement>(
                        "button,input,select,a[href]",
                    ),
                ).filter((e) => !e.hasAttribute("disabled"));
                const first = elements[0],
                    last = elements[elements.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last?.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first?.focus();
                }
            }
        };
        document.addEventListener("keydown", handler);
        return () => {
            document.removeEventListener("keydown", handler);
            previous?.focus();
        };
    }, [onClose]);
}
