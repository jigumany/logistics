import { useState } from "react";
import { csrfToken } from "../features/quotes/http";
export default function YocoConfiguration({
    configured,
    webhookUrl,
}: {
    configured: { mode: string } | null;
    webhookUrl: string;
}) {
    const [mode, setMode] = useState(configured?.mode ?? "test");
    const [secret, setSecret] = useState("");
    const [webhook, setWebhook] = useState("");
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState("");
    const [error, setError] = useState("");
    async function save() {
        setBusy(true);
        setMessage("");
        setError("");
        try {
            const r = await fetch(window.location.pathname + "/yoco", {
                method: "PUT",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-XSRF-TOKEN": csrfToken(),
                },
                body: JSON.stringify({
                    mode,
                    secret_key: secret || null,
                    webhook_secret: webhook || null,
                }),
            });
            const data = await r.json();
            if (!r.ok)
                throw new Error(
                    data.errors
                        ? Object.values(data.errors).flat().join(" ")
                        : (data.message ??
                              "Could not save Yoco configuration."),
                );
            setSecret("");
            setWebhook("");
            setMessage(
                "Yoco configuration saved. Secrets are encrypted and are never returned to this screen.",
            );
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : "Could not save Yoco configuration.",
            );
        } finally {
            setBusy(false);
        }
    }
    return (
        <details className="my-6 rounded-2xl border bg-white p-5">
            <summary className="cursor-pointer font-bold">
                Yoco payment configuration{" "}
                {configured
                    ? "· " + configured.mode + " mode"
                    : "· not configured"}
            </summary>
            <p className="mt-3 text-sm text-slate-500">
                Create a Yoco checkout webhook for payment.succeeded,
                payment.failed and checkout.expired at the URL below. Use its
                signing secret here. Switching between test and live mode
                requires both credentials.
            </p>
            <p className="mt-3 break-all rounded-lg bg-slate-50 p-3 text-sm">
                {webhookUrl}
            </p>
            <form
                className="mt-5 grid gap-4 md:grid-cols-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    void save();
                }}
            >
                <label className="text-sm font-medium">
                    Yoco mode
                    <select
                        value={mode}
                        onChange={(e) => setMode(e.target.value)}
                        className="mt-1 block w-full rounded-lg border-slate-300"
                    >
                        <option value="test">Test</option>
                        <option value="live">Live</option>
                    </select>
                </label>
                <label className="text-sm font-medium">
                    Yoco secret key
                    <input
                        type="password"
                        autoComplete="new-password"
                        value={secret}
                        onChange={(e) => setSecret(e.target.value)}
                        placeholder={
                            configured
                                ? "Leave blank to retain current key"
                                : "sk_test_…"
                        }
                        className="mt-1 block w-full rounded-lg border-slate-300"
                        maxLength={250}
                    />
                </label>
                <label className="text-sm font-medium">
                    Yoco webhook signing secret
                    <input
                        type="password"
                        autoComplete="new-password"
                        value={webhook}
                        onChange={(e) => setWebhook(e.target.value)}
                        placeholder={
                            configured
                                ? "Leave blank to retain current secret"
                                : "whsec_…"
                        }
                        className="mt-1 block w-full rounded-lg border-slate-300"
                        maxLength={250}
                    />
                </label>
                <button
                    disabled={busy}
                    className="w-fit rounded-lg bg-slate-800 px-4 py-2 font-semibold text-white disabled:opacity-40"
                >
                    Save Yoco configuration
                </button>
            </form>
            {error && (
                <p role="alert" className="mt-3 text-sm text-red-800">
                    {error}
                </p>
            )}
            {message && (
                <p role="status" className="mt-3 text-sm text-teal-800">
                    {message}
                </p>
            )}
        </details>
    );
}
