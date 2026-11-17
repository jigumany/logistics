import { useEffect } from "react";
import { router } from "@inertiajs/react";
export function useUnsavedChanges(dirty: boolean) {
    useEffect(() => {
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (dirty) {
                event.preventDefault();
                event.returnValue = "";
            }
        };
        window.addEventListener("beforeunload", beforeUnload);
        const off = router.on("before", (event) => {
            if (
                dirty &&
                event.detail.visit.method === "get" &&
                !window.confirm("Discard your unsaved changes?")
            )
                event.preventDefault();
        });
        return () => {
            window.removeEventListener("beforeunload", beforeUnload);
            off();
        };
    }, [dirty]);
}
