import DriverInvitations, {
    type UnlinkedDriver,
} from "../../../components/DriverInvitations";
import { Head, Link, usePage, router } from "@inertiajs/react";
import { useState } from "react";
import type { SharedProps } from "@logistics/types";
import CompanyLayout from "../../../layouts/CompanyLayout";
import { quoteRequest } from "../../../features/quotes/http";
import {
    statusLabel,
    type DispatchJob,
} from "../../../features/dispatch/types";
export default function Index({
    jobs,
    operator,
    awaiting,
    drivers = [],
}: {
    drivers?: UnlinkedDriver[];
    jobs: {
        data: DispatchJob[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    operator: boolean;
    awaiting: {
        public_id: string;
        reference: string;
        service_starts_at: string;
    }[];
}) {
    const { company } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug;
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    async function dispatch(id: string) {
        setBusy(true);
        setError("");
        try {
            const j = await quoteRequest<{ public_id: string }>(
                base + "/dispatch/booking/" + id,
                {},
            );
            router.visit(base + "/dispatch/" + j.public_id);
        } catch (e) {
            setError(e instanceof Error ? e.message : "Dispatch failed.");
        } finally {
            setBusy(false);
        }
    }
    return (
        <CompanyLayout>
            <Head title={operator ? "Dispatch board" : "My driving jobs"} />
            <div className="mb-7">
                <p className="text-sm font-semibold uppercase tracking-widest text-teal-700">
                    Operations
                </p>
                <h1 className="mt-2 text-3xl font-bold">
                    {operator ? "Dispatch board" : "My driving jobs"}
                </h1>
                <p className="mt-2 text-slate-500">
                    {operator
                        ? "Release confirmed bookings to their reserved vehicle, driver and crew."
                        : "Your assigned moves, customer instructions and delivery checklist."}
                </p>
            </div>
            {error && (
                <p
                    role="alert"
                    className="mb-5 rounded-xl bg-red-50 p-4 text-red-800"
                >
                    {error}
                </p>
            )}
            <DriverInvitations drivers={drivers} />
            {operator && (
                <section className="mb-7 rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-bold">Ready for dispatch</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        The booking must be confirmed. Dispatch fixes the
                        reserved team and prevents reservation changes.
                    </p>
                    <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {awaiting.map((b) => (
                            <div
                                key={b.public_id}
                                className="rounded-xl border border-slate-200 p-4"
                            >
                                <strong>{b.reference}</strong>
                                <p className="my-2 text-sm text-slate-500">
                                    {new Date(
                                        b.service_starts_at,
                                    ).toLocaleString("en-ZA", {
                                        timeZone: "Africa/Johannesburg",
                                    })}
                                </p>
                                <button
                                    disabled={busy}
                                    onClick={() => void dispatch(b.public_id)}
                                    className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                >
                                    Dispatch reserved team
                                </button>
                            </div>
                        ))}
                        {!awaiting.length && (
                            <p className="py-4 text-slate-500">
                                No confirmed bookings are awaiting dispatch.
                            </p>
                        )}
                    </div>
                </section>
            )}
            <section className="rounded-2xl border border-slate-200 bg-white p-5">
                <div className="flex flex-wrap justify-between gap-4">
                    <h2 className="text-lg font-bold">
                        {operator ? "Dispatched moves" : "Assigned moves"}
                    </h2>
                    <label className="text-sm">
                        Status{" "}
                        <select
                            aria-label="Job status"
                            className="ml-2 rounded-lg border-slate-300"
                            defaultValue={
                                new URLSearchParams(window.location.search).get(
                                    "status",
                                ) ?? ""
                            }
                            onChange={(e) =>
                                router.get(
                                    base + "/dispatch",
                                    e.target.value
                                        ? { status: e.target.value }
                                        : {},
                                )
                            }
                        >
                            <option value="">All jobs</option>
                            {[
                                "assigned",
                                "accepted",
                                "en_route",
                                "arrived",
                                "loading",
                                "in_transit",
                                "delivered",
                                "completed",
                            ].map((s) => (
                                <option key={s} value={s}>
                                    {statusLabel(s)}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {jobs.data.map((j) => (
                        <Link
                            key={j.public_id}
                            href={base + "/dispatch/" + j.public_id}
                            className="rounded-xl border border-slate-200 p-5 transition hover:border-teal-500 hover:shadow-sm"
                        >
                            <span className="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold capitalize text-teal-800">
                                {statusLabel(j.status)}
                            </span>
                            <h3 className="mt-4 text-lg font-bold">
                                {j.work_snapshot.reference}
                            </h3>
                            <p className="mt-1 text-sm text-slate-500">
                                {new Date(
                                    j.work_snapshot.starts_at,
                                ).toLocaleString("en-ZA", {
                                    timeZone: "Africa/Johannesburg",
                                })}
                            </p>
                            <p className="mt-4 text-sm">
                                {j.work_snapshot.contact.name}
                            </p>
                            <p className="mt-2 text-sm text-slate-500">
                                {j.resource_snapshot
                                    .filter((r) => r.kind === "vehicle")
                                    .map((r) => r.label)
                                    .join(", ")}
                            </p>
                            <p className="mt-4 font-semibold text-teal-700">
                                Open job →
                            </p>
                        </Link>
                    ))}
                    {!jobs.data.length && (
                        <p className="py-7 text-slate-500">
                            No jobs match this view.
                        </p>
                    )}
                </div>
                <div className="mt-5 flex gap-5 text-sm font-semibold text-teal-700">
                    {jobs.prev_page_url && (
                        <Link href={jobs.prev_page_url}>Previous</Link>
                    )}
                    {jobs.next_page_url && (
                        <Link href={jobs.next_page_url}>Next</Link>
                    )}
                </div>
            </section>
        </CompanyLayout>
    );
}
