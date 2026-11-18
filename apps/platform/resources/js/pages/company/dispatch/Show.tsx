import { Head, Link, usePage } from "@inertiajs/react";
import { useRef, useState } from "react";
import type { SharedProps } from "@logistics/types";
import CompanyLayout from "../../../layouts/CompanyLayout";
import { quoteRequest, csrfToken } from "../../../features/quotes/http";
import {
    statusLabel,
    type DispatchJob,
} from "../../../features/dispatch/types";
export default function Show({
    job: initial,
    operator,
}: {
    job: DispatchJob;
    operator: boolean;
}) {
    const { company } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug + "/dispatch";
    const [job, setJob] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [note, setNote] = useState("");
    const keys = useRef(new Map<string, string>());
    const proofKey = useRef(crypto.randomUUID());
    const keyFor = (s: string) => {
        if (!keys.current.has(s)) keys.current.set(s, crypto.randomUUID());
        return keys.current.get(s)!;
    };
    async function advance(target: string | null = job.next_status) {
        setBusy(true);
        setError("");
        try {
            setJob(
                await quoteRequest<DispatchJob>(
                    base + "/" + job.public_id + "/transition",
                    {
                        status: target,
                        version: job.lock_version,
                        idempotency_key: keyFor(job.status + target + note),
                        note: note || null,
                    },
                ),
            );
            setNote("");
        } catch (e) {
            setError(
                e instanceof Error ? e.message : "Could not update this job.",
            );
        } finally {
            setBusy(false);
        }
    }
    async function proof(form: HTMLFormElement) {
        setBusy(true);
        setError("");
        try {
            const data = new FormData(form);
            data.set("idempotency_key", proofKey.current);
            const r = await fetch(base + "/" + job.public_id + "/proof", {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-XSRF-TOKEN": csrfToken(),
                },
                body: data,
            });
            const result = await r.json();
            if (!r.ok)
                throw new Error(
                    result.errors
                        ? Object.values(result.errors).flat().join(" ")
                        : (result.message ?? "Proof upload failed."),
                );
            setJob(result);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : "Could not upload delivery proof.",
            );
        } finally {
            setBusy(false);
        }
    }
    return (
        <CompanyLayout>
            <Head title={job.work_snapshot.reference} />
            <Link href={base} className="text-sm font-semibold text-teal-700">
                ← {operator ? "Dispatch board" : "My jobs"}
            </Link>
            <div className="my-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-bold">
                        {job.work_snapshot.reference}
                    </h1>
                    <p className="mt-2 text-slate-500">
                        {new Date(job.work_snapshot.starts_at).toLocaleString(
                            "en-ZA",
                            { timeZone: "Africa/Johannesburg" },
                        )}
                    </p>
                </div>
                <span className="rounded-full bg-teal-100 px-4 py-2 font-semibold capitalize text-teal-900">
                    {statusLabel(job.status)}
                </span>
            </div>
            {error && (
                <p
                    role="alert"
                    className="mb-5 rounded-xl bg-red-50 p-4 text-red-800"
                >
                    {error}
                </p>
            )}
            <div className="grid gap-6 xl:grid-cols-[1fr_360px]">
                <div className="space-y-6">
                    <section className="rounded-2xl border bg-white p-6">
                        <h2 className="text-lg font-bold">Move instructions</h2>
                        <p className="mt-3 font-semibold">
                            {job.work_snapshot.contact.name}
                        </p>
                        {job.work_snapshot.contact.phone && (
                            <a
                                className="text-teal-700 underline"
                                href={"tel:" + job.work_snapshot.contact.phone}
                            >
                                {job.work_snapshot.contact.phone}
                            </a>
                        )}
                        <ol className="mt-5 space-y-4">
                            {job.work_snapshot.stops.map((s) => (
                                <li
                                    key={s.position}
                                    className="border-l-4 border-teal-500 pl-4"
                                >
                                    <p className="text-xs font-semibold uppercase tracking-widest text-teal-700">
                                        {s.kind}
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {s.address_line}, {s.city}
                                    </p>
                                    {s.instructions && (
                                        <p className="mt-1 text-sm text-slate-600">
                                            {s.instructions}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>
                        <dl className="mt-5 grid grid-cols-2 gap-3 text-sm">
                            {Object.entries(job.work_snapshot.access).map(
                                ([k, v]) => (
                                    <div key={k}>
                                        <dt className="capitalize text-slate-500">
                                            {statusLabel(k)}
                                        </dt>
                                        <dd>
                                            {typeof v === "boolean"
                                                ? v
                                                    ? "Yes"
                                                    : "No"
                                                : String(v ?? "—")}
                                        </dd>
                                    </div>
                                ),
                            )}
                        </dl>
                    </section>
                    <section className="rounded-2xl border bg-white p-6">
                        <h2 className="text-lg font-bold">
                            Inventory checklist
                        </h2>
                        <ul className="mt-4 divide-y">
                            {job.work_snapshot.inventory.map((i, n) => (
                                <li className="py-3" key={n}>
                                    <label className="flex gap-3">
                                        <input
                                            type="checkbox"
                                            className="mt-1 rounded"
                                        />
                                        <span>
                                            <strong>
                                                {i.quantity} × {i.name}
                                            </strong>
                                            {i.fragile && (
                                                <span className="ml-2 text-xs text-amber-700">
                                                    Fragile
                                                </span>
                                            )}
                                            <span className="block text-sm text-slate-500">
                                                {i.instructions}
                                            </span>
                                        </span>
                                    </label>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-3 text-xs text-slate-500">
                            Checklist ticks are a local aid. Delivery evidence
                            is saved separately.
                        </p>
                    </section>
                    {job.status === "in_transit" && !job.proof && !operator && (
                        <section className="rounded-2xl border bg-white p-6">
                            <h2 className="text-lg font-bold">
                                Capture proof of delivery
                            </h2>
                            <p className="mt-2 text-sm text-slate-500">
                                Record the recipient’s acknowledgement and a
                                delivery photo. Saved evidence cannot be
                                overwritten.
                            </p>
                            <form
                                className="mt-5 space-y-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    void proof(e.currentTarget);
                                }}
                            >
                                <label className="block text-sm font-medium">
                                    Recipient name
                                    <input
                                        name="recipient_name"
                                        required
                                        maxLength={120}
                                        className="mt-1 block w-full rounded-lg border-slate-300"
                                    />
                                </label>
                                <label className="block text-sm font-medium">
                                    Delivery photo
                                    <input
                                        type="file"
                                        name="photo"
                                        accept="image/jpeg,image/png,image/webp"
                                        capture="environment"
                                        required
                                        className="mt-2 block w-full text-sm"
                                    />
                                </label>
                                <label className="block text-sm font-medium">
                                    Delivery notes
                                    <textarea
                                        name="notes"
                                        maxLength={2000}
                                        className="mt-1 block w-full rounded-lg border-slate-300"
                                    />
                                </label>
                                <label className="flex gap-3 text-sm">
                                    <input
                                        type="checkbox"
                                        name="recipient_acknowledged"
                                        value="1"
                                        required
                                        className="mt-1 rounded"
                                    />
                                    The named recipient acknowledges receiving
                                    this delivery.
                                </label>
                                <button
                                    disabled={busy}
                                    className="rounded-lg bg-teal-700 px-5 py-3 font-semibold text-white disabled:opacity-50"
                                >
                                    Save delivery proof
                                </button>
                            </form>
                        </section>
                    )}
                    {job.proof && (
                        <section className="rounded-2xl border border-teal-200 bg-teal-50 p-6">
                            <h2 className="text-lg font-bold">
                                Delivery proof recorded
                            </h2>
                            <p className="mt-2">
                                Acknowledged by {job.proof.recipient_name}
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                {job.proof.notes}
                            </p>
                            <a
                                href={base + "/" + job.public_id + "/proof"}
                                className="mt-4 inline-block font-semibold text-teal-800 underline"
                            >
                                Download delivery photo
                            </a>
                        </section>
                    )}
                </div>
                <aside className="space-y-6">
                    {operator &&
                        ["assigned", "accepted"].includes(job.status) && (
                            <section className="rounded-2xl border border-amber-200 bg-amber-50 p-6">
                                <h2 className="font-bold">
                                    Recall before departure
                                </h2>
                                <p className="mt-2 text-sm">
                                    Recall before changing the booking or
                                    reserved team. History is retained.
                                </p>
                                <label className="mt-3 block text-sm">
                                    Recall reason
                                    <textarea
                                        value={note}
                                        onChange={(e) =>
                                            setNote(e.target.value)
                                        }
                                        className="mt-1 block w-full rounded-lg border-slate-300"
                                        maxLength={2000}
                                    />
                                </label>
                                <button
                                    disabled={busy || note.trim().length < 5}
                                    onClick={() => void advance("recalled")}
                                    className="mt-4 rounded-lg border border-amber-400 bg-white px-4 py-2 font-semibold text-amber-900 disabled:opacity-40"
                                >
                                    Recall dispatch
                                </button>
                            </section>
                        )}
                    <section className="rounded-2xl border bg-white p-6">
                        <h2 className="text-lg font-bold">Next step</h2>
                        {job.next_status ? (
                            <>
                                <p className="mt-2 text-sm text-slate-500">
                                    {operator
                                        ? "An operator override requires a reason and is recorded in the audit history."
                                        : "Update each stage when it has happened."}
                                </p>
                                {operator && (
                                    <label className="mt-4 block text-sm font-medium">
                                        Operator reason
                                        <textarea
                                            value={note}
                                            onChange={(e) =>
                                                setNote(e.target.value)
                                            }
                                            className="mt-1 block w-full rounded-lg border-slate-300"
                                            maxLength={2000}
                                        />
                                    </label>
                                )}
                                <button
                                    onClick={() => void advance()}
                                    disabled={
                                        busy ||
                                        (job.next_status === "delivered" &&
                                            !job.proof) ||
                                        (operator && note.trim().length < 5)
                                    }
                                    className="mt-5 w-full rounded-lg bg-teal-700 px-4 py-3 font-semibold capitalize text-white disabled:opacity-40"
                                >
                                    Mark {statusLabel(job.next_status)}
                                </button>
                                {job.next_status === "delivered" &&
                                    !job.proof && (
                                        <p className="mt-3 text-sm text-amber-800">
                                            The assigned driver must capture
                                            delivery proof first.
                                        </p>
                                    )}
                            </>
                        ) : (
                            <p className="mt-3 text-teal-800">
                                {job.status === "recalled"
                                    ? "This dispatch was recalled before departure."
                                    : "This job is complete."}
                            </p>
                        )}
                    </section>
                    <section className="rounded-2xl border bg-white p-6">
                        <h2 className="text-lg font-bold">Reserved team</h2>
                        <ul className="mt-4 space-y-3">
                            {job.resource_snapshot.map((r) => (
                                <li key={r.resource_public_id}>
                                    <p className="text-xs uppercase tracking-wide text-slate-400">
                                        {r.kind}
                                    </p>
                                    <p className="font-medium">{r.label}</p>
                                </li>
                            ))}
                        </ul>
                    </section>
                    <section className="rounded-2xl border bg-white p-6">
                        <h2 className="text-lg font-bold">Job history</h2>
                        <ol className="mt-4 space-y-4">
                            {job.events.map((e, n) => (
                                <li key={n}>
                                    <p className="font-medium capitalize">
                                        {statusLabel(e.to_status)}
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        {new Date(e.created_at).toLocaleString(
                                            "en-ZA",
                                            { timeZone: "Africa/Johannesburg" },
                                        )}
                                    </p>
                                    {e.note && (
                                        <p className="mt-1 text-sm text-slate-600">
                                            {e.note}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </section>
                </aside>
            </div>
        </CompanyLayout>
    );
}
