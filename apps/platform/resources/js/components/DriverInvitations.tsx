import { useState } from "react";
import { usePage } from "@inertiajs/react";
import type { SharedProps } from "@logistics/types";
import { quoteRequest } from "../features/quotes/http";
export type UnlinkedDriver = {
    public_id: string;
    full_name: string;
    email: string | null;
};
export default function DriverInvitations({
    drivers,
}: {
    drivers: UnlinkedDriver[];
}) {
    const { company } = usePage<SharedProps>().props;
    const [link, setLink] = useState("");
    const [error, setError] = useState("");
    const [message, setMessage] = useState("");
    const [busy, setBusy] = useState(false);
    async function invite(id: string) {
        setBusy(true);
        setError("");
        try {
            const result = await quoteRequest<{
                url?: string;
                message: string;
            }>("/c/" + company?.slug + "/drivers/" + id + "/invitation", {});
            setLink(result.url ?? "");
            setMessage(result.message);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : "Invitation could not be created.",
            );
        } finally {
            setBusy(false);
        }
    }
    if (!drivers.length) return null;
    return (
        <details className="mb-6 rounded-2xl border bg-white p-5">
            <summary className="cursor-pointer font-bold">
                Driver account invitations
            </summary>
            <p className="mt-3 text-sm text-slate-500">
                Send a private invitation to the driver’s email address. Links
                expire after 24 hours and can be used once. A new invitation
                replaces any previous unused link.
            </p>
            <ul className="mt-4 divide-y">
                {drivers.map((d) => (
                    <li
                        key={d.public_id}
                        className="flex flex-wrap items-center justify-between gap-3 py-3"
                    >
                        <div>
                            <strong>{d.full_name}</strong>
                            <p className="text-sm text-slate-500">
                                {d.email ||
                                    "Add an email address on the staff record first."}
                            </p>
                        </div>
                        <button
                            disabled={busy || !d.email}
                            onClick={() => void invite(d.public_id)}
                            className="rounded-lg border px-4 py-2 text-sm font-semibold text-teal-800 disabled:opacity-40"
                        >
                            Create invitation
                        </button>
                    </li>
                ))}
            </ul>
            {message && (
                <p role="status" className="mt-3 text-sm text-teal-800">
                    {message}
                </p>
            )}
            {error && (
                <p role="alert" className="mt-4 text-sm text-red-800">
                    {error}
                </p>
            )}
            {link && (
                <label className="mt-4 block text-sm font-medium">
                    Driver invitation link
                    <input
                        value={link}
                        readOnly
                        onFocus={(e) => e.currentTarget.select()}
                        className="mt-2 block w-full rounded-lg border-slate-300"
                    />
                    <span className="mt-2 block text-xs text-slate-500">
                        Copy this link and share it privately with the intended
                        driver.
                    </span>
                </label>
            )}
        </details>
    );
}
